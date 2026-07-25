<?php

declare(strict_types=1);

namespace fostercommerce\shipments\veeqo\helpers;

use craft\helpers\MoneyHelper;
use fostercommerce\shipments\errors\PermanentIntegrationException;

final class VeeqoPrice
{
	/**
	 * Format a price as the decimal string Veeqo expects, routing through Money so float dollar
	 * values do not drift before they leave Craft.
	 *
	 * @throws PermanentIntegrationException
	 */
	public static function decimal(float $amount, string $currencyCode): string
	{
		if ($currencyCode === '') {
			throw new PermanentIntegrationException('Cannot format a Veeqo price without a currency.');
		}

		$money = MoneyHelper::toMoney([
			'value' => (string) $amount,
			'currency' => $currencyCode,
		]);
		$decimal = $money === false ? false : MoneyHelper::toDecimal($money);

		if ($decimal === false) {
			throw new PermanentIntegrationException("Could not format price for currency “{$currencyCode}”.");
		}

		return $decimal;
	}
}
