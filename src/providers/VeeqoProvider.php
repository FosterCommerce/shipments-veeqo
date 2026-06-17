<?php

declare(strict_types=1);

namespace fostercommerce\shipmentsveeqo\providers;

use Craft;
use craft\commerce\elements\Order;
use craft\web\View;
use fostercommerce\shipments\base\Provider;
use fostercommerce\shipments\elements\Shipment;
use fostercommerce\shipments\errors\IntegrationException;
use fostercommerce\shipmentsveeqo\Plugin;
use fostercommerce\shipmentsveeqo\services\VeeqoApi;

/**
 * Veeqo fulfillment provider for the Shipments plugin.
 *
 * Pushes shipments to Veeqo as orders, polls Veeqo for tracking, and validates the API key.
 * Credentials live on this provider (per integration); product sync reads them via the active provider.
 */
class VeeqoProvider extends Provider
{
	public ?string $apiKey = null;

	public ?int $channelId = null;

	public bool $notifyCustomer = false;

	public string $orderIdPrefix = '';

	/**
	 * How far back (hours) `pull()` queries Veeqo for shipped orders on each run.
	 */
	public int $pollLookbackHours = 24;

	private ?VeeqoApi $client = null;

	public static function displayName(): string
	{
		return 'Veeqo';
	}

	/**
	 * @throws IntegrationException
	 */
	public function sendShipment(Shipment $shipment, Order $order): void
	{
		$this->plugin()->orderSync->pushShipment($shipment, $order, $this);
	}

	/**
	 * @throws IntegrationException
	 */
	public function pull(): void
	{
		$this->plugin()->shipmentPoller->poll($this);
	}

	public function getClient(): VeeqoApi
	{
		if (! $this->client instanceof VeeqoApi) {
			$this->client = new VeeqoApi([
				'apiKey' => (string) $this->apiKey,
			]);
		}

		return $this->client;
	}

	public function getSettingsHtml(): ?string
	{
		return Craft::$app->getView()->renderTemplate(
			Plugin::HANDLE . '/providers/settings',
			[
				'provider' => $this,
			],
			View::TEMPLATE_MODE_CP,
		);
	}

	protected function fetchConnection(): bool
	{
		$this->getClient()->testConnection();
		return true;
	}

	/**
	 * @return array<array-key, mixed>
	 */
	protected function defineRules(): array
	{
		return array_merge(parent::defineRules(), [
			[['apiKey', 'orderIdPrefix'], 'string'],
			[['channelId'], 'integer'],
			[['pollLookbackHours'],
				'integer',
				'min' => 1],
			[['notifyCustomer'], 'boolean'],
			[['apiKey', 'channelId', 'notifyCustomer', 'orderIdPrefix', 'pollLookbackHours'], 'safe'],
		]);
	}

	private function plugin(): Plugin
	{
		/** @var Plugin $plugin */
		$plugin = Plugin::getInstance();
		return $plugin;
	}
}
