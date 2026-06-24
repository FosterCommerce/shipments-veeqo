<?php

declare(strict_types=1);

namespace fostercommerce\shipments\veeqo\jobs;

use Craft;
use craft\queue\BaseJob;
use fostercommerce\shipments\veeqo\errors\VeeqoApiException;
use fostercommerce\shipments\veeqo\Plugin;
use Throwable;

/**
 * Posts a note on a Veeqo order flagging that it was cancelled in Craft. Veeqo's API cannot set an
 * order to cancelled, so this note prompts a warehouse user to cancel it manually.
 */
class NotifyCancellationJob extends BaseJob
{
	public string $orderNumber = '';

	public string $message = '';

	public function execute($queue): void
	{
		if ($this->orderNumber === '') {
			return;
		}

		/** @var Plugin $plugin */
		$plugin = Plugin::getInstance();

		try {
			$provider = $plugin->getVeeqoProvider();
		} catch (Throwable) {
			return;
		}

		if ($provider === null) {
			return;
		}

		$client = $provider->getClient();

		try {
			$veeqoOrderId = $client->getOrderIdByNumber($this->orderNumber);
			if ($veeqoOrderId === null) {
				return;
			}

			$client->post("/orders/{$veeqoOrderId}/notes", [
				'text' => $this->message,
			]);
		} catch (VeeqoApiException $veeqoApiException) {
			Craft::warning("Veeqo cancellation note failed for order {$this->orderNumber}: " . $veeqoApiException->getMessage(), Plugin::HANDLE);
		}
	}

	protected function defaultDescription(): ?string
	{
		return Craft::t(Plugin::HANDLE, 'queue.notifyCancellation');
	}
}
