<?php

declare(strict_types=1);

namespace fostercommerce\shipments\veeqo\console\controllers;

use Craft;
use craft\commerce\elements\Product;
use craft\console\Controller;
use fostercommerce\shipments\Plugin as ShipmentsPlugin;
use fostercommerce\shipments\veeqo\errors\VeeqoApiException;
use fostercommerce\shipments\veeqo\jobs\ClassifyAdoptedMappingsJob;
use fostercommerce\shipments\veeqo\jobs\SyncProductJob;
use fostercommerce\shipments\veeqo\Plugin;
use yii\console\ExitCode;

/**
 * Console actions for managing the Veeqo mirror of Craft Commerce products.
 */
class ProductsController extends Controller
{
	/**
	 * Enqueues every Craft Commerce product for a Veeqo sync.
	 * Safe to re-run; existing mappings are updated rather than duplicated.
	 */
	public function actionSync(): int
	{
		$productIds = Product::find()->ids();
		$queue = Craft::$app->getQueue();

		foreach ($productIds as $productId) {
			$queue->push(new SyncProductJob([
				'productId' => $productId,
			]));
		}

		$this->stdout(sprintf("Queued %d product(s) for Veeqo sync.\n", count($productIds)));
		return ExitCode::OK;
	}

	/**
	 * Re-checks which mapped Veeqo products were built for a sales channel, so the sync knows which
	 * ones it may rename. Safe to re-run; a mapping already marked as ours stays that way.
	 */
	public function actionClassify(): int
	{
		Craft::$app->getQueue()->push(new ClassifyAdoptedMappingsJob());

		$this->stdout("Queued the mapping classification job.\n");
		return ExitCode::OK;
	}

	/**
	 * Deletes Veeqo products built from Craft product types the Shipments plugin now ignores, for a
	 * store that synced them before the setting existed. Veeqo products holding an adopted sellable,
	 * or variants from more than one Craft product, are left alone.
	 */
	public function actionPruneIgnored(): int
	{
		/** @var Plugin $plugin */
		$plugin = Plugin::getInstance();
		$provider = $plugin->getVeeqoProvider();
		if ($provider === null) {
			$this->stderr("No enabled Veeqo integration found.\n");
			return ExitCode::CONFIG;
		}

		/** @var ShipmentsPlugin $shipments */
		$shipments = ShipmentsPlugin::getInstance();
		$ignoredProductTypes = $shipments->getSettings()->productTypesToIgnore;
		if ($ignoredProductTypes === []) {
			$this->stdout("No product types are ignored, so there is nothing to prune.\n");
			return ExitCode::OK;
		}

		$sellableMappings = $plugin->getSellableMappings();

		// Group the mappings by Veeqo product, since that is what gets deleted.
		$purchasableIdsByVeeqoProduct = [];
		foreach ($sellableMappings->findByProductTypes($ignoredProductTypes) as $mapping) {
			$purchasableIdsByVeeqoProduct[$mapping['veeqoProductId']][] = $mapping['purchasableId'];
		}

		$prunable = [];
		$skipped = [];
		foreach ($purchasableIdsByVeeqoProduct as $veeqoProductId => $purchasableIds) {
			if ($sellableMappings->hasAdoptedForVeeqoProduct($veeqoProductId)
				|| $sellableMappings->countCraftProductsForVeeqoProduct($veeqoProductId) > 1) {
				$skipped[] = $veeqoProductId;
				continue;
			}

			$prunable[$veeqoProductId] = $purchasableIds;
		}

		if ($skipped !== []) {
			$this->stdout(sprintf("Leaving %d Veeqo product(s) alone (adopted, or shared with another Craft product): %s\n", count($skipped), implode(', ', $skipped)));
		}

		if ($prunable === []) {
			$this->stdout("Nothing to prune.\n");
			return ExitCode::OK;
		}

		$this->stdout(sprintf("About to delete %d Veeqo product(s): %s\n", count($prunable), implode(', ', array_keys($prunable))));
		if (! $this->confirm('Delete them in Veeqo and drop their mappings?')) {
			return ExitCode::OK;
		}

		$client = $provider->getClient();
		$deletedCount = 0;
		foreach ($prunable as $veeqoProductId => $purchasableIds) {
			try {
				$client->delete('/products/' . $veeqoProductId);
			} catch (VeeqoApiException $veeqoApiException) {
				$this->stderr("Veeqo product {$veeqoProductId} could not be deleted: " . $veeqoApiException->getMessage() . "\n");
				continue;
			}

			foreach ($purchasableIds as $purchasableId) {
				$sellableMappings->deleteByPurchasableId($purchasableId);
			}

			++$deletedCount;
		}

		$this->stdout(sprintf("Deleted %d Veeqo product(s).\n", $deletedCount));
		return ExitCode::OK;
	}

	/**
	 * Links Craft variants to products already in Veeqo by exact SKU match, without creating
	 * anything. Run before the first sync against a populated Veeqo account to avoid duplicates.
	 */
	public function actionReconcile(): int
	{
		/** @var Plugin $plugin */
		$plugin = Plugin::getInstance();
		$provider = $plugin->getVeeqoProvider();
		if ($provider === null) {
			$this->stderr("No enabled Veeqo integration found.\n");
			return ExitCode::CONFIG;
		}

		$linkedCount = 0;
		$unmatched = [];
		$failed = [];
		foreach (Product::find()->all() as $product) {
			$report = $plugin->getProductSync()->reconcile($product, $provider);
			$linkedCount += count($report['linked']);
			$unmatched = [...$unmatched, ...$report['unmatched']];
			$failed = [...$failed, ...$report['failed']];
		}

		$this->stdout(sprintf("Linked %d sellable(s) to existing Veeqo products by SKU.\n", $linkedCount));
		if ($unmatched !== []) {
			$this->stdout(sprintf("%d SKU(s) had no Veeqo match (will be created on sync):\n", count($unmatched)));
			foreach ($unmatched as $sku) {
				$this->stdout("  {$sku}\n");
			}
		}

		if ($failed !== []) {
			$this->stdout(sprintf("%d SKU(s) failed to look up (re-run to retry):\n", count($failed)));
			foreach ($failed as $sku) {
				$this->stdout("  {$sku}\n");
			}
		}

		return ExitCode::OK;
	}
}
