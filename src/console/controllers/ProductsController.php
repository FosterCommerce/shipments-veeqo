<?php

declare(strict_types=1);

namespace fostercommerce\shipmentsveeqo\console\controllers;

use Craft;
use craft\commerce\elements\Product;
use craft\console\Controller;
use fostercommerce\shipmentsveeqo\jobs\SyncProductJob;
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
}
