<?php

declare(strict_types=1);

namespace fostercommerce\shipmentsveeqo\services;

use Craft;
use craft\commerce\elements\Order;
use craft\commerce\models\LineItem;
use craft\elements\Address;
use fostercommerce\shipments\elements\Shipment;
use fostercommerce\shipments\errors\IntegrationException;
use fostercommerce\shipments\errors\PermanentIntegrationException;
use fostercommerce\shipments\models\Integration;
use fostercommerce\shipments\Plugin as ShipmentsPlugin;
use fostercommerce\shipmentsveeqo\errors\VeeqoApiException;
use fostercommerce\shipmentsveeqo\Plugin;
use fostercommerce\shipmentsveeqo\providers\VeeqoProvider;
use fostercommerce\shipmentsveeqo\records\SellableMapping;
use Throwable;
use yii\base\Component;

/**
 * Pushes a Shipment to Veeqo as an order, then records the Veeqo order id as the shipment's
 * integration reference.
 *
 * Each Shipment maps to its own Veeqo order. Veeqo has no idempotency keys, so a stored reference
 * short-circuits re-pushes to avoid duplicate orders on queue retry.
 */
class OrderSync extends Component
{
	/**
	 * Veeqo order create 504s past ~60 line items; cap below that.
	 */
	public const MAX_LINE_ITEMS = 50;

	/**
	 * @throws IntegrationException
	 * @throws PermanentIntegrationException
	 * @throws Throwable
	 */
	public function pushShipment(Shipment $shipment, Order $order, VeeqoProvider $provider): void
	{
		if ($shipment->id === null) {
			throw new PermanentIntegrationException('Cannot push an unsaved shipment to Veeqo.');
		}

		$handle = (string) $provider->handle;
		$integration = $this->resolveIntegration($handle);

		if ($this->alreadyPushed($shipment->id, (int) $integration->id)) {
			Craft::info("Shipment {$shipment->id} already pushed to Veeqo; skipping.", Plugin::HANDLE);
			return;
		}

		if ($provider->channelId === null) {
			throw new PermanentIntegrationException('Veeqo channel id is not configured on the integration.');
		}

		$lineItemAttributes = $this->buildLineItemAttributes($shipment, $order);
		if ($lineItemAttributes === []) {
			throw new PermanentIntegrationException("Shipment {$shipment->id} has no Veeqo-mapped line items to push.");
		}

		$client = $provider->getClient();

		try {
			$customerId = $this->plugin()->customerResolver->resolveCustomerId($order, $client);

			$response = $client->post('/orders', [
				'order' => [
					'channel_id' => $provider->channelId,
					'customer_id' => $customerId,
					'send_notification_email' => $provider->notifyCustomer,
					'deliver_to_attributes' => $this->buildDeliverTo($order),
					'line_items_attributes' => $lineItemAttributes,
					// Veeqo has no settable status; a payment advances the order to awaiting_fulfillment so it can ship.
					'payment_attributes' => [
						'payment_type' => 'other',
						'total_paid' => $order->getTotalPrice(),
						'currency_code' => (string) $order->currency,
					],
				],
			]);
		} catch (VeeqoApiException $veeqoApiException) {
			$this->rethrow($veeqoApiException);
		}

		$veeqoOrderId = isset($response['id']) && is_numeric($response['id']) ? (int) $response['id'] : 0;
		if ($veeqoOrderId === 0) {
			throw new PermanentIntegrationException("Veeqo order create for shipment {$shipment->id} returned no id.");
		}

		$this->shipments()->integrationReferences->setIntegrationReference(
			$shipment,
			$handle,
			(string) $veeqoOrderId,
			$integration->buildUrl((string) $veeqoOrderId),
		);
	}

	/**
	 * @throws PermanentIntegrationException
	 */
	private function resolveIntegration(string $handle): Integration
	{
		$integration = $this->shipments()->integrations->getIntegrationByHandle($handle);
		if (! $integration instanceof Integration || $integration->id === null) {
			throw new PermanentIntegrationException("No Shipments integration found for handle “{$handle}”.");
		}

		return $integration;
	}

	private function alreadyPushed(int $shipmentId, int $integrationId): bool
	{
		$references = $this->shipments()->integrationReferences->getReferencesForShipmentId($shipmentId);
		foreach ($references as $reference) {
			if ($reference->integrationId === $integrationId && $reference->externalId !== '') {
				return true;
			}
		}

		return false;
	}

	/**
	 * Map each shipment line item to a Veeqo sellable via the mapping table.
	 *
	 * @return list<array{sellable_id: int, quantity: int, price_per_unit: float}>
	 * @throws PermanentIntegrationException
	 */
	private function buildLineItemAttributes(Shipment $shipment, Order $order): array
	{
		$orderLineItemsById = [];
		foreach ($order->getLineItems() as $lineItem) {
			if ($lineItem->id !== null) {
				$orderLineItemsById[$lineItem->id] = $lineItem;
			}
		}

		$sellableMappings = $this->plugin()->sellableMappings;

		$attributes = [];
		foreach ($shipment->getLineItems() as $shipmentLineItem) {
			$lineItem = $orderLineItemsById[$shipmentLineItem->lineItemId] ?? null;
			if (! $lineItem instanceof LineItem) {
				continue;
			}

			if ($lineItem->purchasableId === null) {
				continue;
			}

			$mapping = $sellableMappings->findByPurchasableId($lineItem->purchasableId);
			if (! $mapping instanceof SellableMapping) {
				throw new PermanentIntegrationException(
					"Purchasable {$lineItem->purchasableId} is not synced to Veeqo; sync products before pushing.",
				);
			}

			$attributes[] = [
				'sellable_id' => $mapping->veeqoSellableId,
				'quantity' => $shipmentLineItem->qty,
				'price_per_unit' => (float) $lineItem->salePrice,
			];
		}

		if (count($attributes) > self::MAX_LINE_ITEMS) {
			throw new PermanentIntegrationException(
				'Shipment exceeds the Veeqo ' . self::MAX_LINE_ITEMS . '-line-item push limit.',
			);
		}

		return $attributes;
	}

	/**
	 * @return array<string, mixed>
	 */
	private function buildDeliverTo(Order $order): array
	{
		$address = $order->getShippingAddress();
		if (! $address instanceof Address) {
			return [];
		}

		return [
			'first_name' => (string) $address->fullName,
			'company' => (string) $address->organization,
			'address1' => (string) $address->addressLine1,
			'address2' => (string) $address->addressLine2,
			'city' => (string) $address->locality,
			'state' => (string) $address->administrativeArea,
			'zip' => (string) $address->postalCode,
			'country' => $address->countryCode,
			'email' => (string) $order->getEmail(),
		];
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
