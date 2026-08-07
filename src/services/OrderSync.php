<?php

declare(strict_types=1);

namespace fostercommerce\shipments\veeqo\services;

use Craft;
use craft\commerce\elements\Order;
use craft\commerce\elements\Product;
use craft\commerce\elements\Variant;
use craft\commerce\models\LineItem;
use craft\commerce\records\Transaction as TransactionRecord;
use craft\elements\Address;
use craft\helpers\Db;
use craft\helpers\Json;
use DateTime;
use fostercommerce\shipments\elements\Shipment;
use fostercommerce\shipments\errors\IntegrationException;
use fostercommerce\shipments\errors\PermanentIntegrationException;
use fostercommerce\shipments\models\Integration;
use fostercommerce\shipments\veeqo\errors\VeeqoApiException;
use fostercommerce\shipments\veeqo\helpers\AddressFields;
use fostercommerce\shipments\veeqo\helpers\VeeqoPrice;
use fostercommerce\shipments\veeqo\helpers\VeeqoReference;
use fostercommerce\shipments\veeqo\jobs\NotifyCancellationJob;
use fostercommerce\shipments\veeqo\Plugin;
use fostercommerce\shipments\veeqo\providers\VeeqoProvider;
use fostercommerce\shipments\veeqo\records\OrderPush;
use fostercommerce\shipments\veeqo\records\SellableMapping;
use Throwable;
use yii\base\Component;
use yii\db\IntegrityException;

/**
 * Veeqo order push service.
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
			throw new IntegrationException(Craft::t(Plugin::HANDLE, 'error.push.inProgress'));
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
		$provider = Plugin::instance()->getVeeqoProvider();
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
	 * Settles a claim whose push never reported a Veeqo order id, adopting the order when Veeqo has
	 * it and releasing the claim when it does not.
	 *
	 * @throws IntegrationException
	 */
	public function settleUnfinishedPush(Order $order, VeeqoProvider $provider): void
	{
		$integration = $provider->getSourceIntegration();
		if (! $integration instanceof Integration) {
			return;
		}

		$orderPush = OrderPush::findOne([
			'orderId' => $order->id,
			'integrationId' => $integration->id,
			'veeqoOrderId' => null,
		]);

		if (! $orderPush instanceof OrderPush) {
			return;
		}

		try {
			$veeqoOrderId = $provider->getClient()->getOrderIdByNumber($orderPush->veeqoOrderNumber);
		} catch (VeeqoApiException $veeqoApiException) {
			throw $veeqoApiException->toIntegrationException();
		}

		if ($veeqoOrderId === null) {
			$orderPush->delete();
			return;
		}

		$orderPush->veeqoOrderId = $veeqoOrderId;
		$orderPush->save(false);
	}

	/**
	 * @throws IntegrationException
	 * @throws PermanentIntegrationException
	 * @throws Throwable
	 */
	private function doPushOrder(Order $order, VeeqoProvider $provider): void
	{
		$channelId = $provider->getResolvedChannelId();
		if ($channelId === null) {
			throw new PermanentIntegrationException(Craft::t(Plugin::HANDLE, 'error.push.noChannelId'));
		}

		$integration = $provider->getSourceIntegration();
		if (! $integration instanceof Integration || $integration->id === null) {
			throw new PermanentIntegrationException(Craft::t(Plugin::HANDLE, 'error.push.noIntegration'));
		}

		$orderId = (int) $order->id;
		$integrationId = $integration->id;
		$client = $provider->getClient();
		$number = VeeqoReference::orderNumber($provider->orderIdPrefix, (string) $order->reference);

		$lineItemAttributes = $this->buildLineItemAttributes($order, $provider);
		if ($lineItemAttributes === []) {
			throw new PermanentIntegrationException(Craft::t(Plugin::HANDLE, 'error.push.noLineItems'));
		}

		try {
			$customerId = Plugin::instance()->getCustomerResolver()->resolveCustomerId($order, $client);
		} catch (VeeqoApiException $veeqoApiException) {
			throw $veeqoApiException->toIntegrationException();
		}

		if (! $this->claimPush($orderId, $integrationId, $number)) {
			Craft::info("Veeqo order {$number} already pushed; skipping push.", Plugin::HANDLE);
			return;
		}

		try {
			$response = $client->post('/orders', [
				'order' => [
					'channel_id' => $channelId,
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
			// A status code means Veeqo answered and created nothing, so dropping the claim lets a retry
			// push. A transport error (status 0) may still have landed, so that claim stands until
			// {@see settleUnfinishedPush} asks Veeqo which it was.
			if ($veeqoApiException->getStatusCode() !== 0) {
				$this->releaseClaim($orderId, $integrationId);
			}

			throw $veeqoApiException->toIntegrationException();
		}

		$veeqoOrderId = isset($response['id']) && is_numeric($response['id']) ? (int) $response['id'] : 0;
		if ($veeqoOrderId === 0) {
			throw new PermanentIntegrationException(Craft::t(Plugin::HANDLE, 'error.push.noOrderId'));
		}

		// Stamped here as well as in AllocationSync so a first Craft-side split counts as newer than
		// the push; an unstamped row would let a poll mirror Veeqo back over it.
		OrderPush::updateAll([
			'veeqoOrderId' => $veeqoOrderId,
			'dateAllocationsSynced' => Db::prepareDateForDb(new DateTime()),
		], [
			'orderId' => $orderId,
			'integrationId' => $integrationId,
		]);
	}

	/**
	 * Records the intent to push, returning false when a push already holds this order.
	 *
	 * @throws PermanentIntegrationException
	 */
	private function claimPush(int $orderId, int $integrationId, string $number): bool
	{
		$orderPush = new OrderPush();
		$orderPush->orderId = $orderId;
		$orderPush->integrationId = $integrationId;
		$orderPush->veeqoOrderNumber = $number;

		try {
			$saved = $orderPush->save();
		} catch (IntegrityException) {
			return false;
		}

		// Veeqo accepts duplicate order numbers and has no idempotency key, so this row is the only
		// thing stopping a second push. Only the unique index means "already pushed"; any other
		// refusal would strand the order silently.
		if (! $saved) {
			throw new PermanentIntegrationException(
				"Could not claim the Veeqo push for order {$orderId}: " . Json::encode($orderPush->getErrors()),
			);
		}

		return true;
	}

	private function releaseClaim(int $orderId, int $integrationId): void
	{
		OrderPush::deleteAll([
			'orderId' => $orderId,
			'integrationId' => $integrationId,
		]);
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
				'price_per_unit' => VeeqoPrice::decimal((float) $lineItem->salePrice, $currencyCode),
			];
		}

		return $attributes;
	}

	/**
	 * Resolve a purchasable line item to its Veeqo sellable id, syncing the product on demand
	 * when no mapping exists yet.
	 *
	 * @throws PermanentIntegrationException when the variant cannot be synced (e.g. no SKU)
	 */
	private function resolvePurchasableSellableId(LineItem $lineItem, VeeqoProvider $provider): int
	{
		$sellableMappings = Plugin::instance()->getSellableMappings();
		$purchasableId = (int) $lineItem->purchasableId;

		$mapping = $sellableMappings->findByPurchasableId($purchasableId);
		if ($mapping instanceof SellableMapping) {
			return $mapping->veeqoSellableId;
		}

		$variant = Craft::$app->getElements()->getElementById($purchasableId, Variant::class);
		$product = $variant instanceof Variant ? $variant->getProduct() : null;
		if (! $product instanceof Product) {
			throw new PermanentIntegrationException(Craft::t(Plugin::HANDLE, 'error.push.notAVariant', [
				'description' => $lineItem->getDescription(),
			]));
		}

		Plugin::instance()->getProductSync()->syncProduct($product, $provider);

		$mapping = $sellableMappings->findByPurchasableId($purchasableId);
		if ($mapping instanceof SellableMapping) {
			return $mapping->veeqoSellableId;
		}

		// Veeqo drops sellables without an id from a product update, so a variant added after its
		// product synced is unreachable there and only a product of its own can carry it.
		$sellable = Plugin::instance()->getProductSync()->syncLineItemAsOwnProduct($lineItem, $provider);
		if ($sellable === null) {
			throw new PermanentIntegrationException(Craft::t(Plugin::HANDLE, 'error.push.variantNotSynced', [
				'description' => $lineItem->getDescription(),
			]));
		}

		$sellableMappings->upsert($purchasableId, $sellable['sku'], $sellable['sellableId'], $sellable['productId']);

		return $sellable['sellableId'];
	}

	/**
	 * Veeqo sellable id for a custom (non-purchasable) line item, created on demand.
	 *
	 * Custom items have no purchasable to map against, so the sellable is matched by SKU instead.
	 *
	 * @throws PermanentIntegrationException
	 */
	private function resolveCustomSellableId(LineItem $lineItem, VeeqoProvider $provider): int
	{
		$sellable = Plugin::instance()->getProductSync()->syncLineItemAsOwnProduct($lineItem, $provider);
		if ($sellable === null) {
			throw new PermanentIntegrationException(Craft::t(Plugin::HANDLE, 'error.push.customItemFailed', [
				'description' => $lineItem->getDescription(),
			]));
		}

		return $sellable['sellableId'];
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
			'phone' => AddressFields::phone($address, (string) Plugin::instance()->getSettings()->phoneFieldHandle),
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
}
