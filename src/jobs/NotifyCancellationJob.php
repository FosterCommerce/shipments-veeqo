<?php

declare(strict_types=1);

namespace fostercommerce\shipmentsveeqo\jobs;

use Craft;
use craft\queue\BaseJob;
use fostercommerce\shipmentsveeqo\errors\VeeqoApiException;
use fostercommerce\shipmentsveeqo\Plugin;
use Throwable;

/**
 * Posts a note on a Veeqo order flagging that it was cancelled in Craft. Veeqo's API cannot set an
 * order to cancelled, so this note prompts a warehouse user to cancel it manually.
 */
class NotifyCancellationJob extends BaseJob
{
	public ?int $veeqoOrderId = null;

	public string $message = '';

	public function execute($queue): void
	{
		if ($this->veeqoOrderId === null) {
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

		try {
			$provider->getClient()->post("/orders/{$this->veeqoOrderId}/notes", [
				'text' => $this->message,
			]);
		} catch (VeeqoApiException $veeqoApiException) {
			Craft::warning("Veeqo cancellation note failed for order {$this->veeqoOrderId}: " . $veeqoApiException->getMessage(), Plugin::HANDLE);
		}
	}

	protected function defaultDescription(): ?string
	{
		return Craft::t(Plugin::HANDLE, 'queue.notifyCancellation');
	}
}
