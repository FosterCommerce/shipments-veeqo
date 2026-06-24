<?php

declare(strict_types=1);

namespace fostercommerce\shipments\veeqo\services;

use Craft;
use craft\commerce\elements\Order;
use craft\helpers\DateTimeHelper;
use DateTime;
use DateTimeZone;
use fostercommerce\shipments\elements\Shipment;
use fostercommerce\shipments\enums\Status;
use fostercommerce\shipments\errors\IntegrationException;
use fostercommerce\shipments\errors\PermanentIntegrationException;
use fostercommerce\shipments\models\Integration;
use fostercommerce\shipments\models\ShipmentUpdatePayload;
use fostercommerce\shipments\Plugin as ShipmentsPlugin;
use fostercommerce\shipments\veeqo\errors\VeeqoApiException;
use fostercommerce\shipments\veeqo\helpers\VeeqoReference;
use fostercommerce\shipments\veeqo\Plugin;
use fostercommerce\shipments\veeqo\providers\VeeqoProvider;
use fostercommerce\shipments\veeqo\records\SellableMapping;
use Throwable;
use yii\base\Component;

/**
 * Polls Veeqo for shipped and cancelled orders and reconciles the Craft shipments for each order to
 * mirror that order's Veeqo allocations.
 *
 * Veeqo has no webhooks, so this is the only inbound path. Each Veeqo allocation maps to one Craft
 * shipment (keyed by the allocation id). Veeqo is the source of truth after push: a reconcile pass
 * creates, resizes, or deletes Craft shipments to match the allocation set, then writes per-allocation
 * tracking. The Veeqo order is matched to a Craft order by the order number, not a stored reference.
 */
class ShipmentPoller extends Component
{
	public const PAGE_SIZE = 100;

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
		$createdAtMin = DateTimeHelper::now()
			->modify('-' . $provider->pollLookbackHours . ' hours')
			->setTimezone(new DateTimeZone('UTC'))
			->format(DateTime::ATOM);

		// The order's rollup status sits at its least-progressed allocation, so a shipped allocation
		// hides under any pre-shipped status. Reconcile every recent order rather than filter by
		// status; cancelled orders are excluded from the default list, so pull them separately.
		$this->pollOrders($client, $provider, [
			'created_at_min' => $createdAtMin,
		], $integration);
		$this->pollOrders($client, $provider, [
			'status' => 'cancelled',
			'created_at_min' => $createdAtMin,
		], $integration);
	}

	/**
	 * @param array<string, mixed> $query
	 * @throws IntegrationException
	 * @throws PermanentIntegrationException
	 */
	private function pollOrders(VeeqoApi $client, VeeqoProvider $provider, array $query, Integration $integration): void
	{
		$page = 1;
		do {
			try {
				$result = $client->getPage('/orders', $query + [
					'page' => $page,
					'page_size' => self::PAGE_SIZE,
				]);
			} catch (VeeqoApiException $veeqoApiException) {
				$this->rethrow($veeqoApiException);
			}

			foreach ($result['items'] as $veeqoOrder) {
				if (is_array($veeqoOrder)) {
					$this->reconcileOrder($veeqoOrder, $provider, $integration);
				}
			}

			$page++;
		} while ($page <= $result['totalPages']);
	}

	/**
	 * Reconciles one Craft order's shipments to its Veeqo order's allocations.
	 *
	 * @param array<array-key, mixed> $veeqoOrder
	 */
	private function reconcileOrder(array $veeqoOrder, VeeqoProvider $provider, Integration $integration): void
	{
		$order = $this->resolveCraftOrder($veeqoOrder, $provider);
		if (! $order instanceof Order || $order->id === null) {
			return;
		}

		$allocations = $veeqoOrder['allocations'] ?? [];
		if (! is_array($allocations) || $allocations === []) {
			// Never reconcile off an empty set: a momentary zero-allocation read (mid-reallocation)
			// would orphan every shipment. An order with no allocations is left as-is.
			return;
		}

		$veeqoOrderId = $this->intField($veeqoOrder, 'id');
		$veeqoStatus = $this->stringField($veeqoOrder, 'status');
		$integrationId = (int) $integration->id;
		$handle = (string) $provider->handle;

		// Index the order's current shipments by their allocation id; ones with no allocation ref yet
		// (a fresh push, before its first reconcile) are adoptable by the next allocation.
		$shipmentByAllocationId = [];
		$adoptable = [];
		foreach ($this->shipments()->shipments->findByOrderId($order->id) as $shipment) {
			if (! $shipment instanceof Shipment) {
				continue;
			}

			$allocationId = $this->shipmentAllocationId($shipment, $integrationId);
			if ($allocationId !== null) {
				$shipmentByAllocationId[$allocationId] = $shipment;
			} else {
				$adoptable[] = $shipment;
			}
		}

		$seenAllocationIds = [];
		foreach ($allocations as $allocation) {
			if (! is_array($allocation)) {
				continue;
			}

			$allocationId = $this->intField($allocation, 'id');
			if ($allocationId === 0) {
				continue;
			}

			$lineItemQtys = $this->resolveAllocationLineItems($allocation, $order);
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

		$this->deleteOrphanedShipments($shipmentByAllocationId, $seenAllocationIds);
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

	private function shipmentAllocationId(Shipment $shipment, int $integrationId): ?int
	{
		if ($shipment->id === null) {
			return null;
		}

		foreach ($this->shipments()->integrationReferences->getReferencesForShipmentId($shipment->id) as $integrationReference) {
			if ($integrationReference->integrationId === $integrationId) {
				return VeeqoReference::parseAllocationId($integrationReference->externalId);
			}
		}

		return null;
	}

	/**
	 * Creates a shipment for a new allocation, adopts a fresh one, or resizes a matched one, then tags
	 * it with the allocation id so the next poll updates the right shipment.
	 *
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
	private function deleteOrphanedShipments(array $shipmentByAllocationId, array $seenAllocationIds): void
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
				Craft::$app->getElements()->deleteElement($shipment);
			} catch (Throwable $throwable) {
				Craft::error("Failed to delete Craft shipment {$shipment->id} for merged-away Veeqo allocation {$allocationId}: " . $throwable->getMessage(), Plugin::HANDLE);
			}
		}
	}

	/**
	 * Writes the allocation's status onto its shipment: cancelled flips status with no tracking; an
	 * allocation that has shipped writes tracking; one not yet shipped is left open.
	 *
	 * @param array<array-key, mixed> $allocation
	 */
	private function applyAllocationTracking(Shipment $shipment, array $allocation, string $veeqoStatus, Integration $integration): void
	{
		$payload = new ShipmentUpdatePayload();

		if ($veeqoStatus === 'cancelled') {
			$payload->targetStatusCode = Status::Cancelled->value;
		} else {
			// An allocation is shipped when it carries a shipment with tracking, regardless of the
			// order's rollup status: a backordered order stays awaiting_stock with shipped allocations.
			$tracking = $this->extractTracking($allocation);
			if ($tracking === null) {
				return;
			}

			$payload->targetStatusCode = Status::Shipped->value;
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
	 * Maps a Veeqo allocation's line items to Craft order line item quantities. Purchasables resolve
	 * through the sellable mapping; custom items resolve by SKU, including the synthetic custom code.
	 *
	 * @param array<array-key, mixed> $allocation
	 * @return array<int, int> lineItemId => qty
	 */
	private function resolveAllocationLineItems(array $allocation, Order $order): array
	{
		$lineItemIdBySellableId = [];
		$lineItemIdBySku = [];
		foreach ($order->getLineItems() as $lineItem) {
			if ($lineItem->id === null) {
				continue;
			}

			$sku = trim($lineItem->getSku());
			if ($sku !== '') {
				$lineItemIdBySku[$sku] = $lineItem->id;
			}

			if ($lineItem->purchasableId !== null) {
				$mapping = $this->plugin()->sellableMappings->findByPurchasableId((int) $lineItem->purchasableId);
				if ($mapping instanceof SellableMapping) {
					$lineItemIdBySellableId[$mapping->veeqoSellableId] = $lineItem->id;
				}
			}
		}

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

	private function plugin(): Plugin
	{
		/** @var Plugin $plugin */
		$plugin = Plugin::getInstance();
		return $plugin;
	}

	private function shipments(): ShipmentsPlugin
	{
		/** @var ShipmentsPlugin $plugin */
		$plugin = ShipmentsPlugin::getInstance();
		return $plugin;
	}

	/**
	 * @throws IntegrationException
	 * @throws PermanentIntegrationException
	 */
	private function rethrow(VeeqoApiException $veeqoApiException): never
	{
		if ($veeqoApiException->isRetryable()) {
			throw new IntegrationException($veeqoApiException->getMessage(), 0, $veeqoApiException);
		}

		throw new PermanentIntegrationException($veeqoApiException->getMessage(), 0, $veeqoApiException);
	}
}
