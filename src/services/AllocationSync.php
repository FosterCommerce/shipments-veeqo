<?php

declare(strict_types=1);

namespace fostercommerce\shipments\veeqo\services;

use Craft;
use craft\commerce\elements\Order;
use craft\helpers\Db;
use DateTime;
use fostercommerce\shipments\elements\Shipment;
use fostercommerce\shipments\enums\Status;
use fostercommerce\shipments\errors\IntegrationException;
use fostercommerce\shipments\errors\PermanentIntegrationException;
use fostercommerce\shipments\models\Integration;
use fostercommerce\shipments\Plugin as ShipmentsPlugin;
use fostercommerce\shipments\veeqo\errors\VeeqoApiException;
use fostercommerce\shipments\veeqo\helpers\VeeqoReference;
use fostercommerce\shipments\veeqo\Plugin;
use fostercommerce\shipments\veeqo\providers\VeeqoProvider;
use fostercommerce\shipments\veeqo\records\OrderPush;
use yii\base\Component;

/**
 * Veeqo allocation push service.
 */
class AllocationSync extends Component
{
	/**
	 * Reshape a Veeqo order's allocations to match the order's Craft shipments.
	 *
	 * Stamps `dateAllocationsSynced`, which {@see ShipmentPoller} reads to tell whether Craft or
	 * Veeqo wrote the split more recently.
	 *
	 * @throws IntegrationException
	 * @throws PermanentIntegrationException
	 */
	public function pushAllocations(Order $order, VeeqoProvider $provider): void
	{
		$integration = $provider->getSourceIntegration();
		if (! $integration instanceof Integration || $integration->id === null) {
			throw new PermanentIntegrationException(Craft::t(Plugin::HANDLE, 'error.push.noIntegration'));
		}

		$orderPush = OrderPush::findOne([
			'orderId' => $order->id,
			'integrationId' => $integration->id,
		]);

		// An order that was never pushed has no Veeqo order to allocate against.
		if (! $orderPush instanceof OrderPush || $orderPush->veeqoOrderId === null) {
			return;
		}

		$mutex = Craft::$app->getMutex();
		$lockKey = 'shipments-veeqo:push:order:' . $order->id;
		if (! $mutex->acquire($lockKey, 15)) {
			throw new IntegrationException(Craft::t(Plugin::HANDLE, 'error.push.inProgress'));
		}

		try {
			$this->reconcile($order, $provider, $orderPush, $integration);
		} finally {
			$mutex->release($lockKey);
		}
	}

	/**
	 * @throws IntegrationException
	 */
	private function reconcile(Order $order, VeeqoProvider $provider, OrderPush $orderPush, Integration $integration): void
	{
		$client = $provider->getClient();
		$veeqoOrderId = (int) $orderPush->veeqoOrderId;

		try {
			$veeqoOrder = $client->get("/orders/{$veeqoOrderId}");

			$existingAllocations = $this->readAllocations($veeqoOrder);
			$sellableIdByLineItemId = $this->resolveSellableIds($order, $veeqoOrder);

			$shipments = $this->openShipments((int) $order->id);
			$allocationIdByShipmentId = $this->allocationIdsForShipments($shipments, (int) $integration->id);

			$desiredByShipmentId = [];
			foreach ($shipments as $shipment) {
				$desiredByShipmentId[(int) $shipment->id] = $this->desiredQtys($shipment, $sellableIdByLineItemId);
			}

			// Veeqo ignores line-item edits on an existing allocation, so a changed one is replaced
			// rather than updated. Deletes all run first to free the units the new ones claim.
			$matched = $this->releasePass($veeqoOrderId, $client, $existingAllocations, array_flip($allocationIdByShipmentId), $desiredByShipmentId);
			$this->claimPass($veeqoOrderId, $client, $matched, $shipments, $desiredByShipmentId, $allocationIdByShipmentId, $provider, $integration);
		} catch (VeeqoApiException $veeqoApiException) {
			throw $veeqoApiException->toIntegrationException();
		}

		$orderPush->dateAllocationsSynced = Db::prepareDateForDb(new DateTime());
		$orderPush->save(false);
	}

	/**
	 * Delete every allocation whose contents no longer match a Craft shipment, returning the ids of
	 * those left untouched.
	 *
	 * @param array<int, array{warehouseId: ?int, qtys: array<int, int>}> $existingAllocations
	 * @param array<int, int> $shipmentIdByAllocationId
	 * @param array<int, array<int, int>> $desiredByShipmentId
	 * @return array<int, array{warehouseId: ?int, qtys: array<int, int>}>
	 * @throws VeeqoApiException
	 */
	private function releasePass(int $veeqoOrderId, VeeqoApi $client, array $existingAllocations, array $shipmentIdByAllocationId, array $desiredByShipmentId): array
	{
		$matched = [];
		foreach ($existingAllocations as $allocationId => $existing) {
			$shipmentId = $shipmentIdByAllocationId[$allocationId] ?? null;
			$desired = $shipmentId === null ? [] : ($desiredByShipmentId[$shipmentId] ?? []);

			if ($desired !== [] && $desired === $existing['qtys']) {
				$matched[$allocationId] = $existing;
				continue;
			}

			$client->delete("/orders/{$veeqoOrderId}/allocations/{$allocationId}");
		}

		return $matched;
	}

	/**
	 * Create an allocation for every open Craft shipment that no longer has a matching one.
	 *
	 * @param array<int, array{warehouseId: ?int, qtys: array<int, int>}> $matchedAllocations
	 * @param list<Shipment> $shipments
	 * @param array<int, array<int, int>> $desiredByShipmentId
	 * @param array<int, int> $allocationIdByShipmentId
	 * @throws VeeqoApiException
	 */
	private function claimPass(int $veeqoOrderId, VeeqoApi $client, array $matchedAllocations, array $shipments, array $desiredByShipmentId, array $allocationIdByShipmentId, VeeqoProvider $provider, Integration $integration): void
	{
		$warehouseId = $this->resolveWarehouseId($matchedAllocations, $client);
		$knownAllocationIds = array_fill_keys(array_keys($matchedAllocations), true);

		foreach ($shipments as $shipment) {
			$shipmentId = (int) $shipment->id;
			$desired = $desiredByShipmentId[$shipmentId] ?? [];
			if ($desired === []) {
				continue;
			}

			$allocationId = $allocationIdByShipmentId[$shipmentId] ?? null;
			if ($allocationId !== null && isset($matchedAllocations[$allocationId])) {
				continue;
			}

			$response = $client->post("/orders/{$veeqoOrderId}/allocations", [
				'warehouse_id' => $warehouseId,
				'line_items_attributes' => $this->lineItemAttributes($desired),
			]);

			$createdId = $this->createdAllocationId($response, $knownAllocationIds);
			if ($createdId === 0) {
				Craft::error("Veeqo returned no new allocation for shipment {$shipmentId}; the poll adopts it instead.", Plugin::HANDLE);
				continue;
			}

			$knownAllocationIds[$createdId] = true;

			$this->shipments()->integrationReferences->setIntegrationReference(
				$shipment,
				(string) $provider->handle,
				VeeqoReference::allocation($createdId),
				$integration->buildUrl((string) $veeqoOrderId),
			);
		}
	}

	/**
	 * The warehouse the order already allocates to, else the company's first.
	 *
	 * @param array<int, array{warehouseId: ?int, qtys: array<int, int>}> $allocations
	 * @throws VeeqoApiException
	 */
	private function resolveWarehouseId(array $allocations, VeeqoApi $client): ?int
	{
		foreach ($allocations as $allocation) {
			if ($allocation['warehouseId'] !== null) {
				return $allocation['warehouseId'];
			}
		}

		foreach ($client->get('/warehouses') as $warehouse) {
			if (is_array($warehouse) && is_numeric($warehouse['id'] ?? null)) {
				return (int) $warehouse['id'];
			}
		}

		return null;
	}

	/**
	 * Veeqo sellable id per Craft line item.
	 *
	 * Custom line items have no mapping row, so their one-off sellable is read back off the Veeqo order.
	 *
	 * @param array<array-key, mixed> $veeqoOrder
	 * @return array<int, int> lineItemId => sellableId
	 */
	private function resolveSellableIds(Order $order, array $veeqoOrder): array
	{
		$lineItemIdByPurchasableId = [];
		$lineItemIdByCustomSku = [];
		foreach ($order->getLineItems() as $lineItem) {
			if ($lineItem->purchasableId === null) {
				$lineItemIdByCustomSku[ProductSync::CUSTOM_SKU_PREFIX . $lineItem->id] = (int) $lineItem->id;
			} else {
				$lineItemIdByPurchasableId[(int) $lineItem->purchasableId] = (int) $lineItem->id;
			}
		}

		$sellableIdByLineItemId = [];
		$mappings = Plugin::instance()->getSellableMappings()->getSellableIdsByPurchasableId(array_keys($lineItemIdByPurchasableId));
		foreach ($mappings as $purchasableId => $sellableId) {
			$sellableIdByLineItemId[$lineItemIdByPurchasableId[$purchasableId]] = $sellableId;
		}

		foreach ($veeqoOrder['line_items'] ?? [] as $veeqoLineItem) {
			$sellable = is_array($veeqoLineItem) && is_array($veeqoLineItem['sellable'] ?? null) ? $veeqoLineItem['sellable'] : [];
			$lineItemId = $lineItemIdByCustomSku[(string) ($sellable['sku_code'] ?? '')] ?? null;
			if ($lineItemId !== null && is_numeric($sellable['id'] ?? null)) {
				$sellableIdByLineItemId[$lineItemId] = (int) $sellable['id'];
			}
		}

		return $sellableIdByLineItemId;
	}

	/**
	 * @param array<int, int> $sellableIdByLineItemId
	 * @return array<int, int> sellableId => qty
	 */
	private function desiredQtys(Shipment $shipment, array $sellableIdByLineItemId): array
	{
		$qtyBySellableId = [];
		foreach ($this->shipments()->shipmentLineItems->findForShipmentId((int) $shipment->id) as $shipmentLineItem) {
			$sellableId = $sellableIdByLineItemId[(int) $shipmentLineItem->lineItemId] ?? null;
			if ($sellableId === null) {
				Craft::warning("Craft shipment {$shipment->id} line item {$shipmentLineItem->lineItemId} has no Veeqo sellable, so it is left out of the allocation.", Plugin::HANDLE);
				continue;
			}

			$qtyBySellableId[$sellableId] = ($qtyBySellableId[$sellableId] ?? 0) + (int) $shipmentLineItem->qty;
		}

		// Sorted so comparisons against Veeqo's maps test contents, not insertion order.
		ksort($qtyBySellableId);

		return $qtyBySellableId;
	}

	/**
	 * A shipment past `new` is a fulfilment record rather than an intent, so it is never restructured.
	 *
	 * @return list<Shipment>
	 */
	private function openShipments(int $orderId): array
	{
		return array_values(array_filter(
			$this->shipments()->shipments->findByOrderId($orderId),
			static fn (Shipment $shipment): bool => $shipment->getStatus() === Status::New->value,
		));
	}

	/**
	 * @param list<Shipment> $shipments
	 * @return array<int, int> shipmentId => allocationId
	 */
	private function allocationIdsForShipments(array $shipments, int $integrationId): array
	{
		$shipmentIds = array_map(static fn (Shipment $shipment): int => (int) $shipment->id, $shipments);

		$allocationIdByShipmentId = [];
		foreach ($this->shipments()->integrationReferences->getReferencesForShipmentIds($shipmentIds) as $shipmentId => $references) {
			foreach ($references as $reference) {
				if ($reference->integrationId !== $integrationId) {
					continue;
				}

				$allocationId = VeeqoReference::parseAllocationId($reference->externalId);
				if ($allocationId !== null) {
					$allocationIdByShipmentId[$shipmentId] = $allocationId;
				}
			}
		}

		return $allocationIdByShipmentId;
	}

	/**
	 * @param array<array-key, mixed> $veeqoOrder
	 * @return array<int, array{warehouseId: ?int, qtys: array<int, int>}>
	 */
	private function readAllocations(array $veeqoOrder): array
	{
		$allocations = [];
		foreach ($veeqoOrder['allocations'] ?? [] as $allocation) {
			if (! is_array($allocation)) {
				continue;
			}

			if (! is_numeric($allocation['id'] ?? null)) {
				continue;
			}

			$qtys = [];
			foreach ($allocation['line_items'] ?? [] as $line) {
				$sellable = is_array($line) && is_array($line['sellable'] ?? null) ? $line['sellable'] : [];
				$qty = is_array($line) && is_numeric($line['quantity'] ?? null) ? (int) $line['quantity'] : 0;
				if (! is_numeric($sellable['id'] ?? null)) {
					continue;
				}

				if (! is_numeric($line['id'] ?? null)) {
					continue;
				}

				if ($qty <= 0) {
					continue;
				}

				$sellableId = (int) $sellable['id'];
				$qtys[$sellableId] = ($qtys[$sellableId] ?? 0) + $qty;
			}

			ksort($qtys);

			$warehouse = $allocation['warehouse'] ?? null;
			$allocations[(int) $allocation['id']] = [
				'warehouseId' => is_array($warehouse) && is_numeric($warehouse['id'] ?? null) ? (int) $warehouse['id'] : null,
				'qtys' => $qtys,
			];
		}

		return $allocations;
	}

	/**
	 * @param array<int, int> $qtyBySellableId
	 * @return list<array{sellable_id: int, quantity: int}>
	 */
	private function lineItemAttributes(array $qtyBySellableId): array
	{
		$attributes = [];
		foreach ($qtyBySellableId as $sellableId => $qty) {
			$attributes[] = [
				'sellable_id' => $sellableId,
				'quantity' => $qty,
			];
		}

		return $attributes;
	}

	/**
	 * The allocation `POST /orders/{id}/allocations` created. It answers with the whole order, so the
	 * new allocation is the one whose id was not already known.
	 *
	 * @param array<array-key, mixed> $response
	 * @param array<int, true> $knownAllocationIds
	 */
	private function createdAllocationId(array $response, array $knownAllocationIds): int
	{
		foreach ($response['allocations'] ?? [] as $allocation) {
			if (! is_array($allocation)) {
				continue;
			}

			if (! is_numeric($allocation['id'] ?? null)) {
				continue;
			}

			$allocationId = (int) $allocation['id'];
			if (! isset($knownAllocationIds[$allocationId])) {
				return $allocationId;
			}
		}

		return 0;
	}

	private function shipments(): ShipmentsPlugin
	{
		/** @var ShipmentsPlugin $plugin */
		$plugin = ShipmentsPlugin::getInstance();
		return $plugin;
	}
}
