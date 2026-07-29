<?php

declare(strict_types=1);

namespace fostercommerce\shipments\veeqo\services;

use Craft;
use craft\commerce\elements\Order;
use craft\db\Query;
use craft\db\Table as CraftTable;
use fostercommerce\shipments\db\Table as ShipmentsTable;
use fostercommerce\shipments\elements\Shipment;
use fostercommerce\shipments\enums\Status;
use fostercommerce\shipments\errors\IntegrationException;
use fostercommerce\shipments\errors\PermanentIntegrationException;
use fostercommerce\shipments\models\Integration;
use fostercommerce\shipments\models\ShipmentUpdatePayload;
use fostercommerce\shipments\Plugin as ShipmentsPlugin;
use fostercommerce\shipments\veeqo\db\Table;
use fostercommerce\shipments\veeqo\errors\VeeqoApiException;
use fostercommerce\shipments\veeqo\helpers\VeeqoReference;
use fostercommerce\shipments\veeqo\Plugin;
use fostercommerce\shipments\veeqo\providers\VeeqoProvider;
use Throwable;
use yii\base\Component;

/**
 * Veeqo shipment poller.
 *
 * Whichever side changed the split last wins: a pass mirrors Veeqo's allocations onto the Craft
 * shipments unless Craft has edited them since {@see AllocationSync} last pushed.
 */
class ShipmentPoller extends Component
{
	public const PAGE_SIZE = 100;

	/**
	 * Shipment statuses that keep an order in the poll set. A terminal status drops it, including one
	 * a CP user set by hand.
	 */
	private const OPEN_STATUSES = [
		Status::New->value,
		Status::InProgress->value,
		Status::OnHold->value,
	];

	/**
	 * Set while a pass writes Craft shipments; {@see Plugin} reads it to tell those writes from a CP edit.
	 */
	public bool $isMirroring = false;

	/**
	 * @throws IntegrationException
	 * @throws PermanentIntegrationException
	 */
	public function poll(VeeqoProvider $provider): void
	{
		$handle = (string) $provider->handle;
		$integration = $this->shipments()->integrations->getIntegrationByHandle($handle);
		if (! $integration instanceof Integration) {
			throw new PermanentIntegrationException("No Shipments integration found for handle “{$handle}”.");
		}

		$client = $provider->getClient();

		// An order can ship or be cancelled any length of time after it was raised, so the set to poll
		// is whatever Craft still counts as unfinished rather than a window over Veeqo's dates.
		foreach (array_chunk($this->openVeeqoOrderIds((int) $integration->id), self::PAGE_SIZE) as $veeqoOrderIds) {
			try {
				// Fetching by id returns cancelled orders, which an unfiltered list leaves out.
				$veeqoOrders = $client->get('/orders', [
					'order_ids' => $veeqoOrderIds,
					'page_size' => self::PAGE_SIZE,
				]);
			} catch (VeeqoApiException $veeqoApiException) {
				throw $veeqoApiException->toIntegrationException();
			}

			$this->isMirroring = true;

			try {
				foreach ($veeqoOrders as $veeqoOrder) {
					if (is_array($veeqoOrder)) {
						$this->reconcileOrder($veeqoOrder, $provider, $integration);
					}
				}
			} finally {
				$this->isMirroring = false;
			}
		}
	}

	/**
	 * Veeqo ids of the orders still holding an open shipment.
	 *
	 * @return list<int>
	 */
	private function openVeeqoOrderIds(int $integrationId): array
	{
		$veeqoOrderIds = (new Query())
			->select(['pushes.veeqoOrderId'])
			->distinct()
			->from([
				'pushes' => Table::ORDER_PUSHES,
			])
			->innerJoin([
				'shipments' => ShipmentsTable::SHIPMENTS,
			], '[[shipments.orderId]] = [[pushes.orderId]]')
			->innerJoin([
				'elements' => CraftTable::ELEMENTS,
			], '[[elements.id]] = [[shipments.id]]')
			->where([
				'pushes.integrationId' => $integrationId,
				'shipments.status' => self::OPEN_STATUSES,
				'elements.dateDeleted' => null,
			])
			->andWhere([
				'not', [
					'pushes.veeqoOrderId' => null,
				]])
			->column();

		return array_map(intval(...), $veeqoOrderIds);
	}

	/**
	 * @param array<array-key, mixed> $veeqoOrder
	 */
	private function reconcileOrder(array $veeqoOrder, VeeqoProvider $provider, Integration $integration): void
	{
		$order = $this->resolveCraftOrder($veeqoOrder, $provider);
		if (! $order instanceof Order || $order->id === null) {
			return;
		}

		// Veeqo strips a cancelled order's allocations, so this runs ahead of the guard below.
		$veeqoStatus = $this->stringField($veeqoOrder, 'status');
		if ($veeqoStatus === 'cancelled') {
			$this->cancelOpenShipments($order, $integration);
			return;
		}

		$allocations = $veeqoOrder['allocations'] ?? [];
		if (! is_array($allocations) || $allocations === []) {
			// Never reconcile off an empty set: a momentary zero-allocation read (mid-reallocation)
			// would orphan every shipment. An order with no allocations is left as-is.
			return;
		}

		$veeqoOrderId = $this->intField($veeqoOrder, 'id');
		$integrationId = (int) $integration->id;
		$handle = (string) $provider->handle;

		// Index the order's current shipments by their allocation id; ones with no allocation ref yet
		// (a fresh push, before its first reconcile) are adoptable by the next allocation.
		$shipments = $this->shipments()->shipments->findByOrderId($order->id);
		$allocationIdByShipmentId = $this->allocationIdsForShipments($shipments, $integrationId);

		$shipmentByAllocationId = [];
		$adoptable = [];
		foreach ($shipments as $shipment) {
			$allocationId = $allocationIdByShipmentId[$shipment->id] ?? null;
			if ($allocationId !== null) {
				$shipmentByAllocationId[$allocationId] = $shipment;
			} else {
				$adoptable[] = $shipment;
			}
		}

		$lineItemIndex = $this->buildLineItemIndex($order);
		$craftSplitIsNewer = $this->craftSplitIsNewer($order->id, $integrationId);

		$seenAllocationIds = [];
		foreach ($allocations as $allocation) {
			if (! is_array($allocation)) {
				continue;
			}

			$allocationId = $this->intField($allocation, 'id');
			if ($allocationId === 0) {
				continue;
			}

			// Tracking still applies to a shipment Craft has restructured; only its line items are
			// left for the queued push to send.
			if ($craftSplitIsNewer) {
				$shipment = $shipmentByAllocationId[$allocationId] ?? null;
				if ($shipment instanceof Shipment) {
					$this->applyAllocationTracking($shipment, $allocation, $veeqoStatus, $integration);
				}

				continue;
			}

			$lineItemQtys = $this->resolveAllocationLineItems($allocation, $lineItemIndex);
			if ($lineItemQtys === []) {
				Craft::warning("Veeqo allocation {$allocationId} on order {$order->reference}: no mappable line items; skipped.", Plugin::HANDLE);
				continue;
			}

			$seenAllocationIds[$allocationId] = true;
			$shipment = $shipmentByAllocationId[$allocationId] ?? array_shift($adoptable);
			$shipment = $this->mirrorAllocation($order, $shipment, $lineItemQtys, $handle, $allocationId, $veeqoOrderId, $integration);
			if ($shipment instanceof Shipment) {
				$this->applyAllocationTracking($shipment, $allocation, $veeqoStatus, $integration);
			}
		}

		if (! $craftSplitIsNewer) {
			$this->deleteOrphanedShipments($shipmentByAllocationId, $seenAllocationIds, $integrationId);
		}
	}

	/**
	 * Whether Craft's shipments have been edited since the last successful allocation push, meaning
	 * Veeqo's allocations are the stale side and a queued push is about to correct them.
	 */
	private function craftSplitIsNewer(int $orderId, int $integrationId): bool
	{
		$dateAllocationsSynced = (new Query())
			->select(['dateAllocationsSynced'])
			->from(Table::ORDER_PUSHES)
			->where([
				'orderId' => $orderId,
				'integrationId' => $integrationId,
			])
			->scalar();

		if (! is_string($dateAllocationsSynced)) {
			return false;
		}

		return (new Query())
			->from([
				'lineItems' => ShipmentsTable::SHIPMENT_LINE_ITEMS,
			])
			->innerJoin([
				'shipments' => ShipmentsTable::SHIPMENTS,
			], '[[shipments.id]] = [[lineItems.shipmentId]]')
			->where([
				'shipments.orderId' => $orderId,
			])
			->andWhere(['>', 'lineItems.dateUpdated', $dateAllocationsSynced])
			->exists();
	}

	private function cancelOpenShipments(Order $order, Integration $integration): void
	{
		$payload = new ShipmentUpdatePayload();
		$payload->targetStatusCode = Status::Cancelled->value;

		foreach ($this->shipments()->shipments->findByOrderId((int) $order->id) as $shipment) {
			if (! in_array($shipment->status, self::OPEN_STATUSES, true)) {
				continue;
			}

			try {
				$this->shipments()->shipments->applyUpdate($shipment, $payload, null, $integration, 'cancelled');
			} catch (Throwable $throwable) {
				Craft::error("Failed to cancel shipment {$shipment->id} from Veeqo: " . $throwable->getMessage(), Plugin::HANDLE);
			}
		}
	}

	/**
	 * @param array<array-key, mixed> $veeqoOrder
	 */
	private function resolveCraftOrder(array $veeqoOrder, VeeqoProvider $provider): ?Order
	{
		$reference = VeeqoReference::referenceFromNumber($provider->orderIdPrefix, $this->stringField($veeqoOrder, 'number'));
		if ($reference === null || $reference === '') {
			return null;
		}

		$order = Order::find()
			->reference($reference)
			->one();

		return $order instanceof Order ? $order : null;
	}

	/**
	 * Veeqo allocation id per shipment, in one reference query for the whole order.
	 *
	 * @param list<Shipment> $shipments
	 * @return array<int, int>
	 */
	private function allocationIdsForShipments(array $shipments, int $integrationId): array
	{
		$shipmentIds = [];
		foreach ($shipments as $shipment) {
			if ($shipment->id !== null) {
				$shipmentIds[] = $shipment->id;
			}
		}

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
	 * @param array<int, int> $lineItemQtys
	 */
	private function mirrorAllocation(Order $order, ?Shipment $shipment, array $lineItemQtys, string $handle, int $allocationId, int $veeqoOrderId, Integration $integration): ?Shipment
	{
		try {
			if ($shipment instanceof Shipment) {
				$this->shipments()->shipments->saveLineItems($shipment, $lineItemQtys);
			} else {
				$created = $this->shipments()->shipments->createFromAllocations($order, [$lineItemQtys]);
				$shipment = $created[0] ?? null;
				if (! $shipment instanceof Shipment) {
					return null;
				}
			}

			$this->shipments()->integrationReferences->setIntegrationReference(
				$shipment,
				$handle,
				VeeqoReference::allocation($allocationId),
				$integration->buildUrl((string) $veeqoOrderId),
			);
		} catch (Throwable $throwable) {
			Craft::error("Failed to mirror Veeqo allocation {$allocationId} on order {$order->reference}: " . $throwable->getMessage(), Plugin::HANDLE);
			return null;
		}

		return $shipment;
	}

	/**
	 * @param array<int, Shipment> $shipmentByAllocationId
	 * @param array<int, true> $seenAllocationIds
	 */
	private function deleteOrphanedShipments(array $shipmentByAllocationId, array $seenAllocationIds, int $integrationId): void
	{
		foreach ($shipmentByAllocationId as $allocationId => $shipment) {
			if (isset($seenAllocationIds[$allocationId])) {
				continue;
			}

			// Only a still-open shipment is safe to remove; a shipped one is a real fulfilment record,
			// so keep it even though Veeqo no longer lists its allocation.
			if ($shipment->getStatus() !== Status::New->value) {
				Craft::warning("Veeqo allocation {$allocationId} is gone but Craft shipment {$shipment->id} is {$shipment->getStatus()}; keeping it.", Plugin::HANDLE);
				continue;
			}

			try {
				$this->releaseAllocationReference($shipment, $integrationId);
				Craft::$app->getElements()->deleteElement($shipment);
			} catch (Throwable $throwable) {
				Craft::error("Failed to delete Craft shipment {$shipment->id} for merged-away Veeqo allocation {$allocationId}: " . $throwable->getMessage(), Plugin::HANDLE);
			}
		}
	}

	/**
	 * Frees the allocation id for reuse: references are unique per (integration, externalId) and a
	 * trashed shipment keeps its row, so leaving it blocks the next shipment for that allocation.
	 */
	private function releaseAllocationReference(Shipment $shipment, int $integrationId): void
	{
		foreach ($this->shipments()->integrationReferences->getReferencesForShipmentId((int) $shipment->id) as $integrationReference) {
			if ($integrationReference->integrationId === $integrationId && $integrationReference->id !== null) {
				$this->shipments()->integrationReferences->deleteReferenceById($integrationReference->id);
			}
		}
	}

	/**
	 * @param array<array-key, mixed> $allocation
	 */
	private function applyAllocationTracking(Shipment $shipment, array $allocation, string $veeqoStatus, Integration $integration): void
	{
		// An allocation is shipped when it carries a shipment with tracking, regardless of the order's
		// rollup status: a backordered order stays awaiting_stock with shipped allocations. Tracking can
		// be absent though, since a warehouse can ship without a label, and allocations carry no status
		// of their own, so a shipped rollup is the only remaining signal.
		$tracking = $this->extractTracking($allocation);
		if ($tracking === null && $veeqoStatus !== 'shipped') {
			return;
		}

		$payload = new ShipmentUpdatePayload();
		$payload->targetStatusCode = Status::Shipped->value;

		if ($tracking !== null) {
			$payload->trackingNumber = $tracking['trackingNumber'];
			$payload->trackingUrl = $tracking['trackingUrl'];
			$payload->carrier = $tracking['carrier'];
			$payload->service = $tracking['service'];
		}

		if (! $payload->validate()) {
			Craft::warning("Skipping Veeqo update for shipment {$shipment->id}: invalid payload " . implode(', ', $payload->getFirstErrors()), Plugin::HANDLE);
			return;
		}

		$externalCode = $veeqoStatus !== '' ? $veeqoStatus : $payload->targetStatusCode;

		try {
			$this->shipments()->shipments->applyUpdate($shipment, $payload, null, $integration, $externalCode);
		} catch (Throwable $throwable) {
			Craft::error("Failed to apply Veeqo update for shipment {$shipment->id}: " . $throwable->getMessage(), Plugin::HANDLE);
		}
	}

	/**
	 * Tracking from an allocation's nested shipment, or null when it has not shipped. Veeqo nests the
	 * tracking number inside a tracking_number object and carrier as an object, not a string.
	 *
	 * @param array<array-key, mixed> $allocation
	 * @return array{trackingNumber: string, trackingUrl: ?string, carrier: ?string, service: ?string}|null
	 */
	private function extractTracking(array $allocation): ?array
	{
		$shipment = $allocation['shipment'] ?? null;
		if (! is_array($shipment)) {
			return null;
		}

		$trackingField = $shipment['tracking_number'] ?? null;
		$trackingNumber = match (true) {
			is_array($trackingField) => trim((string) ($trackingField['tracking_number'] ?? '')),
			is_string($trackingField) => trim($trackingField),
			default => '',
		};
		if ($trackingNumber === '') {
			return null;
		}

		$carrier = '';
		if (isset($shipment['carrier'])) {
			$carrier = is_array($shipment['carrier'])
				? (string) ($shipment['carrier']['name'] ?? '')
				: (string) $shipment['carrier'];
		}

		$trackingUrl = $this->stringField($shipment, 'tracking_url');
		$service = $this->stringField($shipment, 'service_carrier_name');

		return [
			'trackingNumber' => $trackingNumber,
			'trackingUrl' => $trackingUrl !== '' ? $trackingUrl : null,
			'carrier' => $carrier !== '' ? $carrier : null,
			'service' => $service !== '' ? $service : null,
		];
	}

	/**
	 * The order's line items indexed the two ways an allocation line can name them. Built once per
	 * order, since every allocation on it resolves against the same set.
	 *
	 * @return array{bySellableId: array<int, int>, bySku: array<string, int>}
	 */
	private function buildLineItemIndex(Order $order): array
	{
		$lineItemIdBySku = [];
		$lineItemIdByPurchasableId = [];
		foreach ($order->getLineItems() as $lineItem) {
			if ($lineItem->id === null) {
				continue;
			}

			$sku = trim($lineItem->getSku());
			if ($sku !== '') {
				$lineItemIdBySku[$sku] = $lineItem->id;
			}

			if ($lineItem->purchasableId !== null) {
				$lineItemIdByPurchasableId[(int) $lineItem->purchasableId] = $lineItem->id;
			}
		}

		$sellableIds = Plugin::instance()->getSellableMappings()->getSellableIdsByPurchasableId(array_keys($lineItemIdByPurchasableId));

		$lineItemIdBySellableId = [];
		foreach ($sellableIds as $purchasableId => $sellableId) {
			$lineItemIdBySellableId[$sellableId] = $lineItemIdByPurchasableId[$purchasableId];
		}

		return [
			'bySellableId' => $lineItemIdBySellableId,
			'bySku' => $lineItemIdBySku,
		];
	}

	/**
	 * Maps a Veeqo allocation's line items to Craft order line item quantities.
	 *
	 * @param array<array-key, mixed> $allocation
	 * @param array{bySellableId: array<int, int>, bySku: array<string, int>} $lineItemIndex
	 * @return array<int, int> lineItemId => qty
	 */
	private function resolveAllocationLineItems(array $allocation, array $lineItemIndex): array
	{
		$lineItemIdBySellableId = $lineItemIndex['bySellableId'];
		$lineItemIdBySku = $lineItemIndex['bySku'];

		$lines = $allocation['line_items'] ?? [];
		if (! is_array($lines)) {
			return [];
		}

		$qtyByLineItemId = [];
		foreach ($lines as $line) {
			if (! is_array($line)) {
				continue;
			}

			$qty = $this->intField($line, 'quantity');
			if ($qty <= 0) {
				continue;
			}

			$lineItemId = $this->matchAllocationLine($line, $lineItemIdBySellableId, $lineItemIdBySku);
			if ($lineItemId === null) {
				continue;
			}

			$qtyByLineItemId[$lineItemId] = ($qtyByLineItemId[$lineItemId] ?? 0) + $qty;
		}

		return $qtyByLineItemId;
	}

	/**
	 * @param array<array-key, mixed> $line
	 * @param array<int, int> $lineItemIdBySellableId
	 * @param array<string, int> $lineItemIdBySku
	 */
	private function matchAllocationLine(array $line, array $lineItemIdBySellableId, array $lineItemIdBySku): ?int
	{
		$sellable = is_array($line['sellable'] ?? null) ? $line['sellable'] : [];

		$sellableId = $this->intField($line, 'sellable_id') !== 0 ? $this->intField($line, 'sellable_id') : $this->intField($sellable, 'id');
		if ($sellableId !== 0 && isset($lineItemIdBySellableId[$sellableId])) {
			return $lineItemIdBySellableId[$sellableId];
		}

		$sku = in_array($this->stringField($line, 'sku_code'), ['', '0'], true) ? $this->stringField($sellable, 'sku_code') : $this->stringField($line, 'sku_code');
		if ($sku === '') {
			return null;
		}

		if (isset($lineItemIdBySku[$sku])) {
			return $lineItemIdBySku[$sku];
		}

		if (str_starts_with($sku, ProductSync::CUSTOM_SKU_PREFIX)) {
			return (int) substr($sku, strlen(ProductSync::CUSTOM_SKU_PREFIX));
		}

		return null;
	}

	/**
	 * @param array<array-key, mixed> $data
	 */
	private function intField(array $data, string $key): int
	{
		return isset($data[$key]) && is_numeric($data[$key]) ? (int) $data[$key] : 0;
	}

	/**
	 * @param array<array-key, mixed> $data
	 */
	private function stringField(array $data, string $key): string
	{
		return isset($data[$key]) && is_scalar($data[$key]) ? trim((string) $data[$key]) : '';
	}

	private function shipments(): ShipmentsPlugin
	{
		/** @var ShipmentsPlugin $plugin */
		$plugin = ShipmentsPlugin::getInstance();
		return $plugin;
	}
}
