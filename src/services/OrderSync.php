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
use fostercommerce\shipmentsveeqo\errors\VeeqoApiException;
use fostercommerce\shipmentsveeqo\helpers\AddressFields;
use fostercommerce\shipmentsveeqo\helpers\VeeqoReference;
use fostercommerce\shipmentsveeqo\jobs\NotifyCancellationJob;
use fostercommerce\shipmentsveeqo\Plugin;
use fostercommerce\shipmentsveeqo\providers\VeeqoProvider;
use fostercommerce\shipmentsveeqo\records\SellableMapping;
use Throwable;
use yii\base\Component;

/**
 * Pushes a Commerce order to Veeqo as one Veeqo order. Veeqo auto-allocates it; the poll mirrors
 * each Veeqo allocation back as a Craft shipment.
 *
 * Veeqo has no idempotency keys, so the deterministic order number (prefix + order reference) is the
 * idempotency key: a per-order mutex plus a number lookup short-circuit a duplicate push.
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
		if ($order->id === null) {
			throw new PermanentIntegrationException('Cannot push an unsaved order to Veeqo.');
		}

		$mutex = Craft::$app->getMutex();
		$lockKey = 'shipments-veeqo:push:order:' . $order->id;
		if (! $mutex->acquire($lockKey, 15)) {
			throw new IntegrationException("Another push is in progress for order {$order->id}.");
		}

		try {
			$this->doPushOrder($order, $provider);
		} finally {
			$mutex->release($lockKey);
		}
	}

	/**
	 * Queues a note on the order's Veeqo order flagging a Craft-side cancellation. Veeqo cannot cancel
	 * an order via API, so the note prompts a warehouse user to do it. No-op when the order was never
	 * pushed; the job resolves the Veeqo order by its number off the synchronous path.
	 */
	public function queueCancellationNote(Order $order, string $reason): void
	{
		$provider = $this->plugin()->getVeeqoProvider();
		if (! $provider instanceof VeeqoProvider) {
			return;
		}

		$number = VeeqoReference::orderNumber($provider->orderIdPrefix, (string) $order->reference);
		if ($number === '') {
			return;
		}

		Craft::$app->getQueue()->push(new NotifyCancellationJob([
			'orderNumber' => $number,
			'message' => "Craft order {$order->reference}: {$reason}. Please cancel this order in Veeqo.",
		]));
	}

	/**
	 * @throws IntegrationException
	 * @throws PermanentIntegrationException
	 * @throws Throwable
	 */
	private function doPushOrder(Order $order, VeeqoProvider $provider): void
	{
		if ($provider->channelId === null) {
			throw new PermanentIntegrationException('Veeqo channel id is not configured on the integration.');
		}

		$client = $provider->getClient();
		$number = VeeqoReference::orderNumber($provider->orderIdPrefix, (string) $order->reference);

		try {
			if ($client->getOrderIdByNumber($number) !== null) {
				Craft::info("Veeqo order {$number} already exists; skipping push.", Plugin::HANDLE);
				return;
			}

			$lineItemAttributes = $this->buildLineItemAttributes($order, $provider);
			if ($lineItemAttributes === []) {
				throw new PermanentIntegrationException("Order {$order->id} has no line items to push to Veeqo.");
			}

			$customerId = $this->plugin()->customerResolver->resolveCustomerId($order, $client);

			$response = $client->post('/orders', [
				'order' => [
					'channel_id' => $provider->channelId,
					'customer_id' => $customerId,
					'number' => $number,
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
			throw new PermanentIntegrationException("Veeqo order create for order {$order->id} returned no id.");
		}
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
