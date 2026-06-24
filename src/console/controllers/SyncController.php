<?php

declare(strict_types=1);

namespace fostercommerce\shipments\veeqo\console\controllers;

use craft\console\Controller;
use fostercommerce\shipments\veeqo\Plugin;
use fostercommerce\shipments\veeqo\providers\VeeqoProvider;
use Throwable;
use yii\console\ExitCode;

/**
 * Console actions for the inbound Veeqo poll. Point cron at `shipments-veeqo/sync/pull`.
 */
class SyncController extends Controller
{
	/**
	 * Polls Veeqo for shipped orders and writes tracking back onto the matching shipments.
	 */
	public function actionPull(): int
	{
		/** @var Plugin $plugin */
		$plugin = Plugin::getInstance();
		$provider = $plugin->getVeeqoProvider();
		if (! $provider instanceof VeeqoProvider) {
			$this->stderr("No enabled Veeqo integration is configured.\n");
			return ExitCode::UNSPECIFIED_ERROR;
		}

		try {
			$provider->pull();
		} catch (Throwable $throwable) {
			$this->stderr(sprintf("Veeqo poll failed: %s\n", $throwable->getMessage()));
			return ExitCode::UNSPECIFIED_ERROR;
		}

		$this->stdout("Veeqo poll complete.\n");
		return ExitCode::OK;
	}
}
