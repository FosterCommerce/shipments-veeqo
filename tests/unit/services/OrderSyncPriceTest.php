<?php

declare(strict_types=1);

namespace fostercommerce\shipmentsveeqo\tests\unit\services;

use fostercommerce\shipments\errors\PermanentIntegrationException;
use fostercommerce\shipmentsveeqo\services\OrderSync;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * Guards priceString() against the toMoney/toDecimal contract: toDecimal only accepts a Money
 * object, so the value must round-trip through toMoney first.
 */
final class OrderSyncPriceTest extends TestCase
{
	public function testFormatsFloatAsCurrencyDecimalString(): void
	{
		self::assertSame('19.99', $this->priceString(19.99, 'USD'));
		self::assertSame('0.00', $this->priceString(0.0, 'USD'));
		self::assertSame('1234.50', $this->priceString(1234.5, 'USD'));
	}

	public function testThrowsWhenCurrencyIsMissing(): void
	{
		$this->expectException(PermanentIntegrationException::class);
		$this->priceString(19.99, '');
	}

	private function priceString(float $amount, string $currencyCode): string
	{
		$method = new ReflectionMethod(OrderSync::class, 'priceString');
		$method->setAccessible(true);
		/** @var string $result */
		$result = $method->invoke(new OrderSync(), $amount, $currencyCode);
		return $result;
	}
}
