<?php

declare(strict_types=1);

namespace fostercommerce\shipments\veeqo\tests\unit\helpers;

use fostercommerce\shipments\errors\PermanentIntegrationException;
use fostercommerce\shipments\veeqo\helpers\VeeqoPrice;
use PHPUnit\Framework\TestCase;

/**
 * Guards decimal() against the toMoney/toDecimal contract: toDecimal only accepts a Money object,
 * so the value must round-trip through toMoney first.
 */
final class VeeqoPriceTest extends TestCase
{
	public function testFormatsFloatAsCurrencyDecimalString(): void
	{
		self::assertSame('19.99', VeeqoPrice::decimal(19.99, 'USD'));
		self::assertSame('0.00', VeeqoPrice::decimal(0.0, 'USD'));
		self::assertSame('1234.50', VeeqoPrice::decimal(1234.5, 'USD'));
	}

	public function testThrowsWhenCurrencyIsMissing(): void
	{
		$this->expectException(PermanentIntegrationException::class);
		VeeqoPrice::decimal(19.99, '');
	}
}
