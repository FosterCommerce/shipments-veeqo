<?php

declare(strict_types=1);

namespace fostercommerce\shipments\veeqo\jobs;

use Craft;
use craft\commerce\elements\Order;
use craft\queue\BaseJob;
use fostercommerce\shipments\errors\PermanentIntegrationException;
use fostercommerce\shipments\veeqo\Plugin;
use fostercommerce\shipments\veeqo\providers\VeeqoProvider;
use RuntimeException;

/**
 * Pushes one Craft order's shipment split onto its Veeqo order's allocations.
 *
 * Queued because the line-item change that triggers it fires inside the Craft write transaction.
 */
class PushAllocationsJob extends BaseJob
{
	public ?int $orderId = null;

	public function execute($queue): void
	{
		if ($this->orderId === null) {
			return;
		}

		$provider = Plugin::instance()->getVeeqoProvider();
		if (! $provider instanceof VeeqoProvider) {
			return;
		}

		$order = Order::find()->id($this->orderId)->one();
		if (! $order instanceof Order) {
			Craft::warning("PushAllocationsJob: order {$this->orderId} not found.", Plugin::HANDLE);
			return;
		}

		try {
			Plugin::instance()->getAllocationSync()->pushAllocations($order, $provider);
		} catch (PermanentIntegrationException $permanentIntegrationException) {
			// A plain exception fails the job outright; an IntegrationException would be retried.
			throw new RuntimeException($permanentIntegrationException->getMessage(), 0, $permanentIntegrationException);
		}
	}

	protected function defaultDescription(): ?string
	{
		return Craft::t(Plugin::HANDLE, 'queue.pushingAllocations', [
			'id' => (string) ($this->orderId ?? '?'),
		]);
	}
}
