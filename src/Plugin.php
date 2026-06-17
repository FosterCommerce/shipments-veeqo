<?php

declare(strict_types=1);

namespace fostercommerce\shipmentsveeqo;

use Craft;
use craft\base\Model;
use craft\commerce\elements\Product;
use craft\console\Application as ConsoleApplication;
use craft\events\ModelEvent;
use craft\helpers\ElementHelper;
use fostercommerce\shipments\events\RegisterIntegrationsEvent;
use fostercommerce\shipments\models\Integration;
use fostercommerce\shipments\Plugin as ShipmentsPlugin;
use fostercommerce\shipments\services\Integrations;
use fostercommerce\shipmentsveeqo\jobs\SyncProductJob;
use fostercommerce\shipmentsveeqo\models\Settings;
use fostercommerce\shipmentsveeqo\providers\VeeqoProvider;
use fostercommerce\shipmentsveeqo\services\CustomerResolver;
use fostercommerce\shipmentsveeqo\services\OrderSync;
use fostercommerce\shipmentsveeqo\services\ProductSync;
use fostercommerce\shipmentsveeqo\services\SellableMappings;
use fostercommerce\shipmentsveeqo\services\ShipmentPoller;
use fostercommerce\shipmentsveeqo\services\StockSync;
use Throwable;
use yii\base\Event;

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
}
