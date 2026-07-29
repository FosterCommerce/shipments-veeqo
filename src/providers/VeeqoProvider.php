<?php

declare(strict_types=1);

namespace fostercommerce\shipments\veeqo\providers;

use Craft;
use craft\commerce\elements\Order;
use craft\web\View;
use fostercommerce\shipments\base\Provider;
use fostercommerce\shipments\elements\Shipment;
use fostercommerce\shipments\errors\IntegrationException;
use fostercommerce\shipments\models\Integration;
use fostercommerce\shipments\veeqo\Plugin;
use fostercommerce\shipments\veeqo\records\OrderPush;
use fostercommerce\shipments\veeqo\services\VeeqoApi;

/**
 * Veeqo fulfillment provider.
 */
class VeeqoProvider extends Provider
{
	public ?string $apiKey = null;

	public ?int $channelId = null;

	public bool $notifyCustomer = false;

	public string $orderIdPrefix = '';

	private ?VeeqoApi $client = null;

	public static function displayName(): string
	{
		return 'Veeqo';
	}

	#[\Override]
	public function supportsPush(): bool
	{
		return true;
	}

	// The plugin queues its own push when a shipment reaches the configured auto-push status.
	#[\Override]
	public function autoPushNewShipments(): bool
	{
		return false;
	}

	#[\Override]
	public function autoPushUpdatedShipments(): bool
	{
		return false;
	}

	/**
	 * @throws IntegrationException
	 */
	public function sendShipment(Shipment $shipment, Order $order): void
	{
		Plugin::instance()->getOrderSync()->settleUnfinishedPush($order, $this);

		// Once the order exists in Veeqo there is nothing left to send but its split, and a second
		// order push would only be refused by the claim.
		if ($this->hasBeenPushed($order)) {
			Plugin::instance()->getAllocationSync()->pushAllocations($order, $this);
			return;
		}

		Plugin::instance()->getOrderSync()->pushShipment($shipment, $order, $this);
	}

	/**
	 * @throws IntegrationException
	 */
	public function pull(): void
	{
		Plugin::instance()->getShipmentPoller()->poll($this);
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
			[['notifyCustomer'], 'boolean'],
		]);
	}

	private function hasBeenPushed(Order $order): bool
	{
		$integration = $this->getSourceIntegration();
		if (! $integration instanceof Integration) {
			return false;
		}

		return OrderPush::find()
			->where([
				'orderId' => $order->id,
				'integrationId' => $integration->id,
			])
			->exists();
	}
}
