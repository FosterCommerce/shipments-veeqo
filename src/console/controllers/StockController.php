<?php

declare(strict_types=1);

namespace fostercommerce\shipmentsveeqo\console\controllers;

use craft\console\Controller;
use fostercommerce\shipmentsveeqo\Plugin;
use fostercommerce\shipmentsveeqo\providers\VeeqoProvider;
use Throwable;
use yii\console\ExitCode;

/**
 * Console actions for pulling stock from Veeqo. Point cron at `shipments-veeqo/stock/pull`.
 */
class StockController extends Controller
{
	/**
	 * Pulls available stock from Veeqo and writes it onto the matching Commerce purchasables.
	 */
	public function actionPull(): int
	{
		/** @var Plugin $plugin */
		$plugin = Plugin::getInstance();

		if (! $plugin->getSettings()->syncStock) {
			$this->stdout("Veeqo stock sync is disabled (“Let Veeqo adjust Commerce inventory” is off).\n");
			return ExitCode::OK;
		}

		$provider = $plugin->getVeeqoProvider();
		if (! $provider instanceof VeeqoProvider) {
			$this->stderr("No enabled Veeqo integration is configured.\n");
			return ExitCode::UNSPECIFIED_ERROR;
		}

		try {
			$plugin->stockSync->pull($provider);
		} catch (Throwable $throwable) {
			$this->stderr(sprintf("Veeqo stock pull failed: %s\n", $throwable->getMessage()));
			return ExitCode::UNSPECIFIED_ERROR;
		}

		$this->stdout("Veeqo stock pull complete.\n");
		return ExitCode::OK;
	}
}
