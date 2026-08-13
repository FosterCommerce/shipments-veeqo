<?php

declare(strict_types=1);

namespace fostercommerce\shipments\veeqo\jobs;

use Craft;
use craft\db\Query;
use craft\queue\BaseJob;
use fostercommerce\shipments\veeqo\db\Table;
use fostercommerce\shipments\veeqo\errors\VeeqoApiException;
use fostercommerce\shipments\veeqo\Plugin;
use fostercommerce\shipments\veeqo\providers\VeeqoProvider;
use yii\queue\RetryableJobInterface;

/**
 * Marks mappings as created by this plugin rather than adopted, so their Veeqo names and images can
 * be kept current. A Veeqo product listed on a sales channel was built for that channel, and its own
 * name is what the channel shows.
 */
class ClassifyAdoptedMappingsJob extends BaseJob implements RetryableJobInterface
{
	public function execute($queue): void
	{
		$provider = Plugin::instance()->getVeeqoProvider();
		if (! $provider instanceof VeeqoProvider) {
			return;
		}

		$client = $provider->getClient();

		/** @var list<int> $veeqoProductIds */
		$veeqoProductIds = (new Query())
			->select(['veeqoProductId'])
			->distinct()
			->from(Table::SELLABLE_MAPPINGS)
			->column();

		$total = count($veeqoProductIds);

		foreach ($veeqoProductIds as $index => $veeqoProductId) {
			$this->setProgress($queue, $total === 0 ? 1 : $index / $total);

			try {
				$veeqoProduct = $client->get('/products/' . $veeqoProductId);
			} catch (VeeqoApiException $veeqoApiException) {
				Craft::warning("Veeqo product {$veeqoProductId} could not be read while classifying mappings: " . $veeqoApiException->getMessage(), Plugin::HANDLE);
				continue;
			}

			if ($this->isListedOnAChannel($veeqoProduct)) {
				continue;
			}

			Plugin::instance()->getSellableMappings()->markCreatedForVeeqoProduct((int) $veeqoProductId);
		}
	}

	/**
	 * Craft kills the job process at the TTR, and this reads every mapped Veeqo product one at a
	 * time. A partial run leaves unread mappings adopted, which reads as "protected" and is
	 * indistinguishable from a real one.
	 */
	public function getTtr(): int
	{
		return 1800;
	}

	public function canRetry($attempt, $error): bool
	{
		return false;
	}

	protected function defaultDescription(): ?string
	{
		return Craft::t(Plugin::HANDLE, 'queue.classifyingMappings');
	}

	/**
	 * Placing an order against a channel does not list a product on it, so this stays false for
	 * products the plugin pushes orders for.
	 *
	 * @param array<array-key, mixed> $veeqoProduct
	 */
	private function isListedOnAChannel(array $veeqoProduct): bool
	{
		$activeChannels = $veeqoProduct['active_channels'] ?? null;

		return is_array($activeChannels) && $activeChannels !== [];
	}
}
