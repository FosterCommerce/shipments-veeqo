<?php

declare(strict_types=1);

namespace fostercommerce\shipmentsveeqo\services;

use Craft;
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
use fostercommerce\shipmentsveeqo\errors\VeeqoApiException;
use fostercommerce\shipmentsveeqo\Plugin;
use fostercommerce\shipmentsveeqo\providers\VeeqoProvider;
use Throwable;
use yii\base\Component;

/**
 * Polls Veeqo for shipped orders and writes tracking back onto the matching shipments.
 *
 * Veeqo has no webhooks, so this is the only inbound path. Resolves shipments by the Veeqo order id
 * stored as the integration reference during push.
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

		// Veeqo rejects multiple statuses in one query, so poll each separately.
		foreach (['shipped', 'partially_shipped', 'cancelled'] as $status) {
			$this->pollStatus($client, $status, $createdAtMin, $handle, $integration);
		}
	}

	/**
	 * @throws IntegrationException
	 * @throws PermanentIntegrationException
	 */
	private function pollStatus(VeeqoApi $client, string $status, string $createdAtMin, string $handle, Integration $integration): void
	{
		$page = 1;
		do {
			try {
				$result = $client->getPage('/orders', [
					'status' => $status,
					'created_at_min' => $createdAtMin,
					'page' => $page,
					'page_size' => self::PAGE_SIZE,
				]);
			} catch (VeeqoApiException $veeqoApiException) {
				$this->rethrow($veeqoApiException);
			}

			foreach ($result['items'] as $veeqoOrder) {
				if (is_array($veeqoOrder)) {
					$this->applyOrder($veeqoOrder, $handle, $integration);
				}
			}

			$page++;
		} while ($page <= $result['totalPages']);
	}

	/**
	 * @param array<array-key, mixed> $veeqoOrder
	 */
	private function applyOrder(array $veeqoOrder, string $handle, Integration $integration): void
	{
		$veeqoOrderId = isset($veeqoOrder['id']) && is_numeric($veeqoOrder['id']) ? (int) $veeqoOrder['id'] : 0;
		if ($veeqoOrderId === 0) {
			return;
		}

		$shipment = $this->shipments()
			->integrationReferences
			->findByIntegrationReference($handle, (string) $veeqoOrderId);
		if (! $shipment instanceof Shipment) {
			// Expected for Veeqo orders that did not originate from this Craft store.
			Craft::info("Veeqo order {$veeqoOrderId}: no matching Craft shipment; skipped.", Plugin::HANDLE);
			return;
		}

		$veeqoStatus = isset($veeqoOrder['status']) ? (string) $veeqoOrder['status'] : '';
		$targetStatus = $this->mapStatus($veeqoStatus);
		if ($targetStatus === null) {
			return;
		}

		$payload = new ShipmentUpdatePayload();
		$payload->targetStatusCode = $targetStatus->value;

		// Only the shipped path carries tracking; a cancellation flips status with none.
		if ($targetStatus === Status::Shipped) {
			$tracking = $this->extractTracking($veeqoOrder);
			if ($tracking === null) {
				Craft::warning("Veeqo order {$veeqoOrderId} matched shipment {$shipment->id} but has no tracking number; skipped.", Plugin::HANDLE);
				return;
			}

			$payload->trackingNumber = $tracking['trackingNumber'];
			$payload->trackingUrl = $tracking['trackingUrl'];
			$payload->carrier = $tracking['carrier'];
			$payload->service = $tracking['service'];
		}

		if (! $payload->validate()) {
			Craft::warning(
				"Skipping Veeqo order {$veeqoOrderId}: invalid update payload " . implode(', ', $payload->getFirstErrors()),
				Plugin::HANDLE,
			);
			return;
		}

		$externalCode = $veeqoStatus !== '' ? $veeqoStatus : $targetStatus->value;

		try {
			$this->shipments()->shipments->applyUpdate($shipment, $payload, null, $integration, $externalCode);
		} catch (Throwable $throwable) {
			Craft::error(
				"Failed to apply Veeqo update for shipment {$shipment->id}: " . $throwable->getMessage(),
				Plugin::HANDLE,
			);
		}
	}

	private function mapStatus(string $veeqoStatus): ?Status
	{
		return match ($veeqoStatus) {
			'shipped', 'partially_shipped' => Status::Shipped,
			'cancelled' => Status::Cancelled,
			default => null,
		};
	}

	/**
	 * Pull the first allocation that carries a shipment with a tracking number.
	 *
	 * Veeqo nests the shipment inside each allocation; carrier is an object, not a string.
	 *
	 * @param array<array-key, mixed> $veeqoOrder
	 * @return array{trackingNumber: string, trackingUrl: ?string, carrier: ?string, service: ?string}|null
	 */
	private function extractTracking(array $veeqoOrder): ?array
	{
		$allocations = $veeqoOrder['allocations'] ?? [];
		if (! is_array($allocations)) {
			return null;
		}

		foreach ($allocations as $allocation) {
			if (! is_array($allocation)) {
				continue;
			}

			$shipment = $allocation['shipment'] ?? null;
			if (! is_array($shipment)) {
				continue;
			}

			// Veeqo nests the tracking number inside a tracking_number object ({tracking_number: "..."}).
			$trackingField = $shipment['tracking_number'] ?? null;
			$trackingNumber = match (true) {
				is_array($trackingField) => trim((string) ($trackingField['tracking_number'] ?? '')),
				is_string($trackingField) => trim($trackingField),
				default => '',
			};
			if ($trackingNumber === '') {
				continue;
			}

			$carrier = '';
			if (isset($shipment['carrier'])) {
				$carrier = is_array($shipment['carrier'])
					? (string) ($shipment['carrier']['name'] ?? '')
					: (string) $shipment['carrier'];
			}

			$trackingUrl = isset($shipment['tracking_url']) ? trim((string) $shipment['tracking_url']) : '';
			$service = isset($shipment['service_carrier_name']) ? trim((string) $shipment['service_carrier_name']) : '';

			return [
				'trackingNumber' => $trackingNumber,
				'trackingUrl' => $trackingUrl !== '' ? $trackingUrl : null,
				'carrier' => $carrier !== '' ? $carrier : null,
				'service' => $service !== '' ? $service : null,
			];
		}

		return null;
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
