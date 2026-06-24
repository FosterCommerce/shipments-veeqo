<?php

declare(strict_types=1);

namespace fostercommerce\shipments\veeqo\tests\unit\services;

use fostercommerce\shipments\veeqo\services\ShipmentPoller;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * Covers the allocation-line to Craft-line-item reverse map: sellable id, nested sellable, SKU, and
 * the synthetic custom-{id} code that recovers a custom line item id with no mapping row.
 */
final class ShipmentPollerMatchTest extends TestCase
{
	public function testMatchesByFlatSellableId(): void
	{
		self::assertSame(5, $this->match(['sellable_id' => 100], [100 => 5], []));
	}

	public function testMatchesByNestedSellableId(): void
	{
		self::assertSame(5, $this->match(['sellable' => ['id' => 100]], [100 => 5], []));
	}

	public function testMatchesBySku(): void
	{
		self::assertSame(7, $this->match(['sku_code' => 'ABC'], [], ['ABC' => 7]));
	}

	public function testRecoversCustomLineItemIdFromSyntheticSku(): void
	{
		self::assertSame(42, $this->match(['sku_code' => 'custom-42'], [], []));
	}

	public function testSellableIdWinsOverSku(): void
	{
		self::assertSame(5, $this->match(['sellable_id' => 100, 'sku_code' => 'ABC'], [100 => 5], ['ABC' => 7]));
	}

	public function testReturnsNullWhenNothingMatches(): void
	{
		self::assertNull($this->match(['sku_code' => 'ZZZ'], [100 => 5], ['ABC' => 7]));
		self::assertNull($this->match([], [], []));
	}

	/**
	 * @param array<array-key, mixed> $line
	 * @param array<int, int> $bySellableId
	 * @param array<string, int> $bySku
	 */
	private function match(array $line, array $bySellableId, array $bySku): ?int
	{
		$method = new ReflectionMethod(ShipmentPoller::class, 'matchAllocationLine');
		$method->setAccessible(true);
		/** @var int|null $result */
		$result = $method->invoke(new ShipmentPoller(), $line, $bySellableId, $bySku);
		return $result;
	}
}
