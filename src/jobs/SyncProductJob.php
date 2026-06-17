<?php

declare(strict_types=1);

namespace fostercommerce\shipmentsveeqo\jobs;

use Craft;
use craft\commerce\elements\Product;
use craft\queue\BaseJob;
use fostercommerce\shipmentsveeqo\errors\VeeqoApiException;
use fostercommerce\shipmentsveeqo\Plugin;
use fostercommerce\shipmentsveeqo\providers\VeeqoProvider;

class SyncProductJob extends BaseJob
{
	public ?int $productId = null;

	public function execute($queue): void
	{
		if ($this->productId === null) {
			return;
		}

		$product = Product::find()->id($this->productId)->one();
		if (! $product instanceof Product) {
			Craft::warning('Veeqo sync skipped: Commerce product ' . $this->productId . ' not found', Plugin::HANDLE);
			return;
		}

		/** @var Plugin $plugin */
		$plugin = Plugin::getInstance();

		$provider = $plugin->getVeeqoProvider();
		if (! $provider instanceof VeeqoProvider) {
			Craft::warning('Veeqo product sync skipped: no enabled Veeqo integration is configured.', Plugin::HANDLE);
			return;
		}

		try {
			$plugin->productSync->syncProduct($product, $provider);
		} catch (VeeqoApiException $veeqoApiException) {
			if ($veeqoApiException->isRetryable()) {
				throw $veeqoApiException;
			}

			Craft::error('Veeqo product sync failed for product ' . $this->productId . ': ' . $veeqoApiException->getMessage(), Plugin::HANDLE);
		}
	}

	protected function defaultDescription(): ?string
	{
		return Craft::t(Plugin::HANDLE, 'job.syncProduct', [
			'id' => $this->productId,
		]);
	}
}
