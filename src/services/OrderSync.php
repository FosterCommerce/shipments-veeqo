<?php

declare(strict_types=1);

namespace fostercommerce\shipmentsveeqo\services;

use Craft;
use craft\commerce\elements\Order;
use craft\commerce\elements\Product;
use craft\commerce\elements\Variant;
use craft\commerce\models\LineItem;
use craft\commerce\records\Transaction as TransactionRecord;
use craft\elements\Address;
use craft\helpers\MoneyHelper;
use fostercommerce\shipments\elements\Shipment;
use fostercommerce\shipments\errors\IntegrationException;
use fostercommerce\shipments\errors\PermanentIntegrationException;
use fostercommerce\shipments\models\Integration;
use fostercommerce\shipments\Plugin as ShipmentsPlugin;
use fostercommerce\shipmentsveeqo\errors\VeeqoApiException;
use fostercommerce\shipmentsveeqo\helpers\AddressFields;
use fostercommerce\shipmentsveeqo\jobs\NotifyCancellationJob;
use fostercommerce\shipmentsveeqo\Plugin;
use fostercommerce\shipmentsveeqo\providers\VeeqoProvider;
use fostercommerce\shipmentsveeqo\records\SellableMapping;
use Throwable;
use yii\base\Component;

/**
 * Pushes a Commerce order to Veeqo as a Veeqo order, then records the Veeqo order id as the
 * shipment's integration reference.
 *
 * The whole Commerce order is sent; Veeqo auto-allocates it into a single allocation, which mirrors
 * the order's one shipment. Assumes one shipment per order: with several, each would re-push the
 * full order. Veeqo has no idempotency keys, so a stored reference short-circuits re-pushes.
 */
class OrderSync extends Component
{
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

		// Veeqo has no idempotency keys, so serialize the already-pushed check and the create. Without
		// it a queue retry or a manual push racing the auto-push creates a second order.
		$mutex = Craft::$app->getMutex();
		$lockKey = 'shipments-veeqo:push:' . $shipment->id;
		if (! $mutex->acquire($lockKey, 15)) {
			throw new IntegrationException("Another push is in progress for shipment {$shipment->id}.");
		}

		try {
			$this->doPushShipment($shipment, $order, $provider);
		} finally {
			$mutex->release($lockKey);
		}
	}

	/**
	 * Queues a note on the Veeqo order behind every shipment of a Craft order. Used when the order
	 * is deleted or stops requiring shipping; Veeqo can't be cancelled via API, so we flag it.
	 *
	 * @throws Throwable
	 */
	public function queueCancellationNotesForOrder(int $orderId, string $reason): void
	{
		$shipmentsService = $this->shipments()->shipments;
		$shipments = [
			...$shipmentsService->findByOrderId($orderId),
			...$shipmentsService->findTrashedByOrderId($orderId),
		];

		foreach ($shipments as $shipment) {
			if ($shipment instanceof Shipment) {
				$this->queueCancellationNoteForShipment($shipment, $reason);
			}
		}
	}

	/**
	 * Queues a note on the Veeqo order behind a shipment. No-op when the shipment was never pushed.
	 *
	 * @throws Throwable
	 */
	public function queueCancellationNoteForShipment(Shipment $shipment, string $reason): void
	{
		if ($shipment->id === null) {
			return;
		}

		$provider = $this->plugin()->getVeeqoProvider();
		if (! $provider instanceof VeeqoProvider) {
			return;
		}

		$integration = $this->shipments()->integrations->getIntegrationByHandle((string) $provider->handle);
		if (! $integration instanceof Integration || $integration->id === null) {
			return;
		}

		$veeqoOrderId = 0;
		foreach ($this->shipments()->integrationReferences->getReferencesForShipmentId($shipment->id) as $integrationReference) {
			if ($integrationReference->integrationId === $integration->id && $integrationReference->externalId !== '') {
				$veeqoOrderId = (int) $integrationReference->externalId;
				break;
			}
		}

		if ($veeqoOrderId === 0) {
			return;
		}

		Craft::$app->getQueue()->push(new NotifyCancellationJob([
			'veeqoOrderId' => $veeqoOrderId,
			'message' => "Craft shipment {$shipment->reference}: {$reason}. Please cancel this order in Veeqo.",
		]));
	}

	/**
	 * @throws IntegrationException
	 * @throws PermanentIntegrationException
	 * @throws Throwable
	 */
	private function doPushShipment(Shipment $shipment, Order $order, VeeqoProvider $provider): void
	{
		$handle = (string) $provider->handle;
		$integration = $this->resolveIntegration($handle);

		if ($this->alreadyPushed((int) $shipment->id, (int) $integration->id)) {
			Craft::info("Shipment {$shipment->id} already pushed to Veeqo; skipping.", Plugin::HANDLE);
			return;
		}

		if ($provider->channelId === null) {
			throw new PermanentIntegrationException('Veeqo channel id is not configured on the integration.');
		}

		$lineItemAttributes = $this->buildLineItemAttributes($order, $provider);
		if ($lineItemAttributes === []) {
			throw new PermanentIntegrationException("Order {$order->id} has no line items to push to Veeqo.");
		}

		$client = $provider->getClient();

		try {
			$customerId = $this->plugin()->customerResolver->resolveCustomerId($order, $client);

			$response = $client->post('/orders', [
				'order' => [
					'channel_id' => $provider->channelId,
					'customer_id' => $customerId,
					'number' => $provider->orderIdPrefix . $order->reference,
					'send_notification_email' => $provider->notifyCustomer,
					'deliver_to_attributes' => $this->buildDeliverTo($order),
					'line_items_attributes' => $lineItemAttributes,
					// Veeqo has no settable status; including a payment marks the order paid so it leaves
					// awaiting_payment. Veeqo derives the paid total from the line items, so none is sent.
					'payment_attributes' => [
						'payment_type' => $this->resolvePaymentType($order),
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
	 * Map each order line item to a Veeqo sellable, creating the sellable on demand so a push
	 * never requires a separate product sync first.
	 *
	 * @return list<array{sellable_id: int, quantity: int, price_per_unit: string}>
	 * @throws PermanentIntegrationException
	 */
	private function buildLineItemAttributes(Order $order, VeeqoProvider $provider): array
	{
		$currencyCode = (string) $order->currency;

		$attributes = [];
		foreach ($order->getLineItems() as $lineItem) {
			$sellableId = $lineItem->purchasableId === null
				? $this->resolveCustomSellableId($lineItem, $provider)
				: $this->resolvePurchasableSellableId($lineItem, $provider);

			$attributes[] = [
				'sellable_id' => $sellableId,
				'quantity' => $lineItem->qty,
				'price_per_unit' => $this->priceString((float) $lineItem->salePrice, $currencyCode),
			];
		}

		return $attributes;
	}

	/**
	 * Format a price as the decimal string Veeqo expects, routing through Money so float dollar
	 * values do not drift before they leave Craft.
	 *
	 * @throws PermanentIntegrationException
	 */
	private function priceString(float $amount, string $currencyCode): string
	{
		if ($currencyCode === '') {
			throw new PermanentIntegrationException('Cannot format a Veeqo price without an order currency.');
		}

		$money = MoneyHelper::toMoney([
			'value' => (string) $amount,
			'currency' => $currencyCode,
		]);
		$decimal = $money === false ? false : MoneyHelper::toDecimal($money);

		if ($decimal === false) {
			throw new PermanentIntegrationException("Could not format price for currency “{$currencyCode}”.");
		}

		return $decimal;
	}

	/**
	 * Resolve a purchasable line item to its Veeqo sellable id, syncing the product on demand
	 * when no mapping exists yet.
	 *
	 * @throws PermanentIntegrationException when the variant cannot be synced (e.g. no SKU)
	 */
	private function resolvePurchasableSellableId(LineItem $lineItem, VeeqoProvider $provider): int
	{
		$sellableMappings = $this->plugin()->sellableMappings;
		$purchasableId = (int) $lineItem->purchasableId;

		$mapping = $sellableMappings->findByPurchasableId($purchasableId);
		if ($mapping instanceof SellableMapping) {
			return $mapping->veeqoSellableId;
		}

		$variant = Craft::$app->getElements()->getElementById($purchasableId, Variant::class);
		$product = $variant instanceof Variant ? $variant->getProduct() : null;
		if (! $product instanceof Product) {
			throw new PermanentIntegrationException(
				"Purchasable {$purchasableId} is not a Commerce product variant; cannot sync to Veeqo.",
			);
		}

		$this->plugin()->productSync->syncProduct($product, $provider);

		$mapping = $sellableMappings->findByPurchasableId($purchasableId);
		if (! $mapping instanceof SellableMapping) {
			throw new PermanentIntegrationException(
				"Purchasable {$purchasableId} could not be synced to Veeqo; check that the variant has a SKU.",
			);
		}

		return $mapping->veeqoSellableId;
	}

	/**
	 * Create a one-off Veeqo sellable for a custom (non-purchasable) line item and return its id.
	 * Custom items have no purchasable to cache against and belong to a single order, so the
	 * sellable is created fresh per push.
	 *
	 * @throws PermanentIntegrationException
	 */
	private function resolveCustomSellableId(LineItem $lineItem, VeeqoProvider $provider): int
	{
		$sellableId = $this->plugin()->productSync->syncCustomLineItem($lineItem, $provider);
		if ($sellableId === 0) {
			throw new PermanentIntegrationException(
				"Custom line item {$lineItem->id} could not be created as a Veeqo sellable.",
			);
		}

		return $sellableId;
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
			'first_name' => AddressFields::firstName($address),
			'last_name' => (string) $address->lastName,
			'company' => (string) $address->organization,
			'address1' => (string) $address->addressLine1,
			'address2' => (string) $address->addressLine2,
			'city' => (string) $address->locality,
			'state' => (string) $address->administrativeArea,
			'zip' => (string) $address->postalCode,
			'country' => $address->countryCode,
			'phone' => AddressFields::phone($address),
		];
	}

	/**
	 * Veeqo payment_type for the order, read from its last successful payment's gateway. Veeqo's enum
	 * has no generic "paid online", so an unrecognised gateway reports none rather than mislabelling it.
	 */
	private function resolvePaymentType(Order $order): string
	{
		foreach (array_reverse($order->getTransactions()) as $transaction) {
			$isSuccessfulPayment = $transaction->status === TransactionRecord::STATUS_SUCCESS
				&& in_array($transaction->type, [TransactionRecord::TYPE_PURCHASE, TransactionRecord::TYPE_CAPTURE], true);
			if (! $isSuccessfulPayment) {
				continue;
			}

			$gateway = $transaction->getGateway();
			$name = strtolower(($gateway?->handle ?? '') . ' ' . ($gateway?->name ?? ''));

			return match (true) {
				str_contains($name, 'paypal') => 'paypal',
				str_contains($name, 'sagepay'), str_contains($name, 'opayo') => 'sagepay',
				str_contains($name, 'bank') => 'bank_transfer',
				default => 'none',
			};
		}

		return 'none';
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
