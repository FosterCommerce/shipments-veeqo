<?php

declare(strict_types=1);

namespace fostercommerce\shipmentsveeqo;

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
use fostercommerce\shipments\enums\TrackedOrderState;
use fostercommerce\shipments\events\RegisterIntegrationsEvent;
use fostercommerce\shipments\events\ShipmentStatusChangedEvent;
use fostercommerce\shipments\models\Integration;
use fostercommerce\shipments\Plugin as ShipmentsPlugin;
use fostercommerce\shipments\queue\jobs\PushShipmentJob;
use fostercommerce\shipments\records\TrackedOrder;
use fostercommerce\shipments\services\Integrations;
use fostercommerce\shipments\services\Shipments;
use fostercommerce\shipmentsveeqo\helpers\ProductImageFields;
use fostercommerce\shipmentsveeqo\jobs\SyncProductJob;
use fostercommerce\shipmentsveeqo\models\Settings;
use fostercommerce\shipmentsveeqo\providers\VeeqoProvider;
use fostercommerce\shipmentsveeqo\services\CustomerResolver;
use fostercommerce\shipmentsveeqo\services\OrderSync;
use fostercommerce\shipmentsveeqo\services\ProductSync;
use fostercommerce\shipmentsveeqo\services\SellableMappings;
use fostercommerce\shipmentsveeqo\services\ShipmentPoller;
use fostercommerce\shipmentsveeqo\services\StockSync;
use Psr\Log\LogLevel;
use Throwable;
use yii\base\Event;
use yii\db\AfterSaveEvent;

/**
 * @property-read Settings $settings
 * @property-read ProductSync $productSync
 * @property-read SellableMappings $sellableMappings
 * @property-read OrderSync $orderSync
 * @property-read ShipmentPoller $shipmentPoller
 * @property-read StockSync $stockSync
 * @property-read CustomerResolver $customerResolver
 */
class Plugin extends \craft\base\Plugin
{
	public const HANDLE = 'shipments-veeqo';

	public bool $hasCpSettings = true;

	public string $schemaVersion = '1.0.0';

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
			$this->controllerNamespace = 'fostercommerce\\shipmentsveeqo\\console\\controllers';
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

	public function getSettings(): Settings
	{
		/** @var Settings $settings */
		$settings = parent::getSettings();
		return $settings;
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

			if (! $allIntegration->enabled) {
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
			'imageFieldOptions' => ProductImageFields::options(),
			'statusOptions' => Status::labelMap(),
		]);
	}

	protected function createSettingsModel(): ?Model
	{
		return new Settings();
	}

	/**
	 * Queues a Veeqo product sync when a Commerce product is saved through a normal code path
	 * (not propagation, not resaving, not a draft or revision).
	 */
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

		$this->queueCancellationNote(fn () => $this->orderSync->queueCancellationNoteForShipment($shipment, 'shipment deleted'));
	}

	private function noteOrderDeleted(Event $event): void
	{
		$order = $event->sender;
		if (! $order instanceof Order || $order->id === null) {
			return;
		}

		$this->queueCancellationNote(fn () => $this->orderSync->queueCancellationNotesForOrder($order->id, 'order deleted'));
	}

	/**
	 * Fires when an order's tracked-order record updates; notes Veeqo only on the transition into
	 * the ignored state (covers both an ignored order status and the requires-shipping toggle).
	 */
	private function noteOrderIgnored(AfterSaveEvent $event): void
	{
		$record = $event->sender;
		if (! $record instanceof TrackedOrder) {
			return;
		}

		if ($record->state !== TrackedOrderState::Ignored->value || ! array_key_exists('state', $event->changedAttributes)) {
			return;
		}

		$this->queueCancellationNote(fn () => $this->orderSync->queueCancellationNotesForOrder((int) $record->orderId, 'order no longer requires shipping'));
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
