<?php

declare(strict_types=1);

namespace fostercommerce\shipments\veeqo\services;

use Craft;
use craft\commerce\base\Purchasable;
use craft\commerce\elements\Variant;
use craft\commerce\Plugin as Commerce;
use fostercommerce\shipments\errors\IntegrationException;
use fostercommerce\shipments\errors\PermanentIntegrationException;
use fostercommerce\shipments\veeqo\errors\VeeqoApiException;
use fostercommerce\shipments\veeqo\Plugin;
use fostercommerce\shipments\veeqo\providers\VeeqoProvider;
use fostercommerce\shipments\veeqo\records\SellableMapping;
use yii\base\Component;

/**
 * Veeqo stock sync service. One-way: Veeqo is the source of truth and is never written back to.
 */
class StockSync extends Component
{
	/**
	 * @throws IntegrationException
	 * @throws PermanentIntegrationException
	 */
	public function pull(VeeqoProvider $provider): void
	{
		$client = $provider->getClient();

		foreach (Plugin::instance()->getSellableMappings()->getAllVeeqoProductIds() as $veeqoProductId) {
			try {
				$product = $client->get('/products/' . $veeqoProductId);
			} catch (VeeqoApiException $veeqoApiException) {
				// Retryable errors abort so cron retries the whole run; a gone product just logs and continues.
				if ($veeqoApiException->isRetryable()) {
					throw new IntegrationException($veeqoApiException->getMessage(), 0, $veeqoApiException);
				}

				Craft::warning("Veeqo stock pull skipped product {$veeqoProductId}: " . $veeqoApiException->getMessage(), Plugin::HANDLE);
				continue;
			}

			$this->applyProductStock($product);
		}
	}

	/**
	 * @param array<array-key, mixed> $product
	 */
	private function applyProductStock(array $product): void
	{
		$sellables = $product['sellables'] ?? [];
		if (! is_array($sellables)) {
			return;
		}

		$sellableMappings = Plugin::instance()->getSellableMappings();

		foreach ($sellables as $sellable) {
			if (! is_array($sellable)) {
				continue;
			}

			if (! isset($sellable['id'])) {
				continue;
			}

			if (! is_numeric($sellable['id'])) {
				continue;
			}

			$mapping = $sellableMappings->findByVeeqoSellableId((int) $sellable['id']);
			if (! $mapping instanceof SellableMapping) {
				continue;
			}

			$available = isset($sellable['available_stock_level_at_all_warehouses']) && is_numeric($sellable['available_stock_level_at_all_warehouses'])
				? (int) $sellable['available_stock_level_at_all_warehouses']
				: 0;

			$this->writeLevel($mapping->purchasableId, max(0, $available));
		}
	}

	private function writeLevel(int $purchasableId, int $available): void
	{
		// Commerce treats an untracked purchasable as unlimited, so a level would mean nothing.
		$variant = Variant::find()->id($purchasableId)->one();
		if (! $variant instanceof Purchasable || ! $variant->inventoryTracked) {
			return;
		}

		/** @var Commerce $commerce */
		$commerce = Commerce::getInstance();
		$commerce->getInventory()->updatePurchasableInventoryLevel($variant, $available);
	}
}
