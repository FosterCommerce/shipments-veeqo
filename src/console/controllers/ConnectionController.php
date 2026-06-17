<?php

declare(strict_types=1);

namespace fostercommerce\shipmentsveeqo\console\controllers;

use craft\console\Controller;
use fostercommerce\shipmentsveeqo\errors\VeeqoApiException;
use fostercommerce\shipmentsveeqo\Plugin;
use fostercommerce\shipmentsveeqo\providers\VeeqoProvider;
use Throwable;
use yii\console\ExitCode;

/**
 * Console actions for verifying the plugin's connection to Veeqo.
 */
class ConnectionController extends Controller
{
	/**
	 * Tests the active Veeqo integration's API key by calling GET /current_company
	 * and prints the resolved Veeqo company name on success.
	 */
	public function actionTest(): int
	{
		$provider = $this->resolveProvider();
		if (! $provider instanceof VeeqoProvider) {
			$this->stderr("No enabled Veeqo integration is configured.\n");
			return ExitCode::UNSPECIFIED_ERROR;
		}

		try {
			$company = $provider->getClient()->testConnection();
		} catch (VeeqoApiException $veeqoApiException) {
			$this->stderr(sprintf("Veeqo connection test failed (%d): %s\n", $veeqoApiException->getStatusCode(), $veeqoApiException->getMessage()));
			return ExitCode::UNSPECIFIED_ERROR;
		}

		$companyName = isset($company['name']) && is_string($company['name']) ? $company['name'] : '(unknown)';
		$this->stdout(sprintf("Connected to Veeqo company: %s\n", $companyName));
		return ExitCode::OK;
	}

	private function resolveProvider(): ?VeeqoProvider
	{
		try {
			/** @var Plugin $plugin */
			$plugin = Plugin::getInstance();
			return $plugin->getVeeqoProvider();
		} catch (Throwable $throwable) {
			$this->stderr(sprintf("Could not resolve Veeqo integration: %s\n", $throwable->getMessage()));
			return null;
		}
	}
}
