<?php

declare(strict_types=1);

namespace fostercommerce\shipments\veeqo\console\controllers;

use Craft;
use craft\commerce\elements\Product;
use craft\console\Controller;
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
			$report = $plugin->productSync->reconcile($product, $provider);
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
