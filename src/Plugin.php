<?php

declare(strict_types=1);

namespace fostercommerce\shipments\veeqo;

use Craft;
use craft\base\Model;
use craft\commerce\elements\Order;
use craft\commerce\elements\Product;
use craft\console\Application as ConsoleApplication;
use craft\db\ActiveRecord;
use craft\events\ModelEvent;
use craft\helpers\ElementHelper;
use craft\log\MonologTarget;
use fostercommerce\shipments\elements\Shipment;
use fostercommerce\shipments\enums\Status;
use fostercommerce\shipments\enums\TrackedOrderShippable;
use fostercommerce\shipments\enums\TrackedOrderState;
use fostercommerce\shipments\events\RegisterIntegrationsEvent;
use fostercommerce\shipments\events\ShipmentStatusChangedEvent;
use fostercommerce\shipments\models\Integration;
use fostercommerce\shipments\Plugin as ShipmentsPlugin;
use fostercommerce\shipments\queue\jobs\PushShipmentJob;
use fostercommerce\shipments\records\TrackedOrder;
use fostercommerce\shipments\services\Integrations;
use fostercommerce\shipments\services\Shipments;
use fostercommerce\shipments\veeqo\helpers\SettingsFieldOptions;
use fostercommerce\shipments\veeqo\jobs\SyncProductJob;
use fostercommerce\shipments\veeqo\models\Settings;
use fostercommerce\shipments\veeqo\providers\VeeqoProvider;
use fostercommerce\shipments\veeqo\records\OrderPush;
use fostercommerce\shipments\veeqo\services\CustomerResolver;
use fostercommerce\shipments\veeqo\services\OrderSync;
use fostercommerce\shipments\veeqo\services\ProductSync;
use fostercommerce\shipments\veeqo\services\SellableMappings;
use fostercommerce\shipments\veeqo\services\ShipmentPoller;
use fostercommerce\shipments\veeqo\services\StockSync;
use Psr\Log\LogLevel;
use Throwable;
use yii\base\Event;
use yii\db\AfterSaveEvent;

/**
 * @property-read Settings $settings
 */
class Plugin extends \craft\base\Plugin
{
	public const HANDLE = 'shipments-veeqo';

	public bool $hasCpSettings = true;

	public string $schemaVersion = '1.1.0';

	public function init(): void
	{
		parent::init();

		Craft::getLogger()->dispatcher->targets[] = new MonologTarget([
			'name' => self::HANDLE,
			'categories' => [self::HANDLE],
			'level' => LogLevel::INFO,
			'logContext' => false,
			'allowLineBreaks' => false,
		]);

		$this->setComponents([
			'productSync' => ProductSync::class,
			'sellableMappings' => SellableMappings::class,
			'orderSync' => OrderSync::class,
			'shipmentPoller' => ShipmentPoller::class,
			'stockSync' => StockSync::class,
			'customerResolver' => CustomerResolver::class,
		]);

		if (Craft::$app instanceof ConsoleApplication) {
			$this->controllerNamespace = 'fostercommerce\\shipments\\veeqo\\console\\controllers';
		}

		Event::on(
			Integrations::class,
			Integrations::EVENT_REGISTER_INTEGRATIONS,
			static function (RegisterIntegrationsEvent $event): void {
				$event->types[] = VeeqoProvider::class;
			},
		);

		Event::on(
			Product::class,
			Product::EVENT_AFTER_SAVE,
			$this->queueSyncOnProductSaved(...),
		);

		Event::on(
			Shipments::class,
			Shipments::EVENT_SHIPMENT_STATUS_CHANGED,
			$this->pushOnStatusReached(...),
		);

		Event::on(
			Shipment::class,
			Shipment::EVENT_AFTER_DELETE,
			$this->noteShipmentDeleted(...),
		);

		Event::on(
			Order::class,
			Order::EVENT_AFTER_DELETE,
			$this->noteOrderDeleted(...),
		);

		Event::on(
			TrackedOrder::class,
			ActiveRecord::EVENT_AFTER_UPDATE,
			$this->noteOrderIgnored(...),
		);
	}

	/**
	 * Type-narrowed {@see getInstance}, which is never null from inside the plugin.
	 */
	public static function instance(): self
	{
		/** @var self $plugin */
		$plugin = self::getInstance();
		return $plugin;
	}

	public function getSettings(): Settings
	{
		/** @var Settings $settings */
		$settings = parent::getSettings();
		return $settings;
	}

	public function getProductSync(): ProductSync
	{
		/** @var ProductSync $service */
		$service = $this->get('productSync');
		return $service;
	}

	public function getSellableMappings(): SellableMappings
	{
		/** @var SellableMappings $service */
		$service = $this->get('sellableMappings');
		return $service;
	}

	public function getOrderSync(): OrderSync
	{
		/** @var OrderSync $service */
		$service = $this->get('orderSync');
		return $service;
	}

	public function getShipmentPoller(): ShipmentPoller
	{
		/** @var ShipmentPoller $service */
		$service = $this->get('shipmentPoller');
		return $service;
	}

	public function getStockSync(): StockSync
	{
		/** @var StockSync $service */
		$service = $this->get('stockSync');
		return $service;
	}

	public function getCustomerResolver(): CustomerResolver
	{
		/** @var CustomerResolver $service */
		$service = $this->get('customerResolver');
		return $service;
	}

	/**
	 * The first enabled integration bound to a Veeqo provider, or null when none is configured.
	 *
	 * @throws Throwable
	 */
	public function getVeeqoProvider(): ?VeeqoProvider
	{
		/** @var ShipmentsPlugin $shipmentsPlugin */
		$shipmentsPlugin = ShipmentsPlugin::getInstance();

		foreach ($shipmentsPlugin->integrations->getAllIntegrations() as $allIntegration) {
			if (! $allIntegration instanceof Integration) {
				continue;
			}

			if (! $allIntegration->isEnabled()) {
				continue;
			}

			$provider = $allIntegration->getProvider();
			if ($provider instanceof VeeqoProvider) {
				return $provider;
			}
		}

		return null;
	}

	protected function settingsHtml(): ?string
	{
		return Craft::$app->getView()->renderTemplate(self::HANDLE . '/settings/index', [
			'settings' => $this->getSettings(),
			'imageFieldOptions' => SettingsFieldOptions::productImageFields(),
			'phoneFieldOptions' => SettingsFieldOptions::addressTextFields(),
			'statusOptions' => Status::labelMap(),
		]);
	}

	protected function createSettingsModel(): ?Model
	{
		return new Settings();
	}

	private function queueSyncOnProductSaved(ModelEvent $event): void
	{
		if (! $this->getSettings()->syncProducts) {
			return;
		}

		$product = $event->sender;
		if (! $product instanceof Product) {
			return;
		}

		if ($product->propagating || $product->resaving || ElementHelper::isDraftOrRevision($product)) {
			return;
		}

		Craft::$app->getQueue()->push(new SyncProductJob([
			'productId' => $product->id,
		]));
	}

	/**
	 * Queues a push to Veeqo when a shipment reaches the configured auto-push status. Skips changes
	 * sourced from an integration so the inbound poll's status writes do not loop back into a push.
	 *
	 * @throws Throwable
	 */
	private function pushOnStatusReached(ShipmentStatusChangedEvent $event): void
	{
		$triggerStatus = $this->getSettings()->autoPushStatus;
		if ($triggerStatus === null || $triggerStatus === '') {
			return;
		}

		if ($event->toCode->value !== $triggerStatus || $event->sourceIntegration instanceof Integration) {
			return;
		}

		$shipmentId = $event->shipment->id;
		$provider = $this->getVeeqoProvider();
		if ($shipmentId === null || ! $provider instanceof VeeqoProvider) {
			return;
		}

		/** @var ShipmentsPlugin $shipmentsPlugin */
		$shipmentsPlugin = ShipmentsPlugin::getInstance();
		$integration = $shipmentsPlugin->integrations->getIntegrationByHandle((string) $provider->handle);
		if (! $integration instanceof Integration || $integration->id === null) {
			return;
		}

		// A shipment the poll mirrors back reaches this status with no source integration, so the
		// claim is what tells the two apart.
		if (OrderPush::find()->where([
			'orderId' => $event->shipment->orderId,
			'integrationId' => $integration->id,
		])->exists()) {
			return;
		}

		Craft::$app->getQueue()->push(new PushShipmentJob([
			'shipmentId' => $shipmentId,
			'integrationId' => $integration->id,
		]));
	}

	private function noteShipmentDeleted(Event $event): void
	{
		$shipment = $event->sender;
		if (! $shipment instanceof Shipment) {
			return;
		}

		$order = $shipment->getOrder();
		if (! $order instanceof Order) {
			return;
		}

		$this->queueCancellationNote(fn () => $this->getOrderSync()->queueCancellationNote($order, 'shipment deleted'));
	}

	private function noteOrderDeleted(Event $event): void
	{
		$order = $event->sender;
		if (! $order instanceof Order) {
			return;
		}

		$this->queueCancellationNote(fn () => $this->getOrderSync()->queueCancellationNote($order, 'order deleted'));
	}

	private function noteOrderIgnored(AfterSaveEvent $event): void
	{
		$record = $event->sender;
		if (! $record instanceof TrackedOrder) {
			return;
		}

		$reason = $this->cancellationReason($record, $event->changedAttributes);
		if ($reason === null) {
			return;
		}

		$order = Order::find()->id((int) $record->orderId)->one();
		if (! $order instanceof Order) {
			return;
		}

		$this->queueCancellationNote(fn () => $this->getOrderSync()->queueCancellationNote($order, $reason));
	}

	/**
	 * Why Veeqo should be told to cancel, or null when this save is not a reason to tell it.
	 *
	 * `state` and `shippable` are independent columns, so the two causes need separate checks.
	 *
	 * @param array<string, mixed> $changedAttributes
	 */
	private function cancellationReason(TrackedOrder $record, array $changedAttributes): ?string
	{
		if ($record->state === TrackedOrderState::Ignored->value && array_key_exists('state', $changedAttributes)) {
			return 'order ignored in Craft';
		}

		if ($record->shippable === TrackedOrderShippable::No->value && array_key_exists('shippable', $changedAttributes)) {
			return 'order no longer requires shipping';
		}

		return null;
	}

	/**
	 * Runs a cancellation-note queueing call, swallowing failures so a Veeqo hiccup never blocks the
	 * Craft delete or status change that triggered it.
	 */
	private function queueCancellationNote(callable $queue): void
	{
		try {
			$queue();
		} catch (Throwable $throwable) {
			Craft::warning('Veeqo cancellation note could not be queued: ' . $throwable->getMessage(), self::HANDLE);
		}
	}
}
