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
		self::assertSame([5], $this->match(['sellable_id' => 100], [100 => [5]], []));
	}

	public function testMatchesByNestedSellableId(): void
	{
		self::assertSame([5], $this->match(['sellable' => ['id' => 100]], [100 => [5]], []));
	}

	public function testMatchesBySku(): void
	{
		self::assertSame([7], $this->match(['sku_code' => 'ABC'], [], ['ABC' => [7]]));
	}

	public function testRecoversCustomLineItemIdFromSyntheticSku(): void
	{
		self::assertSame([42], $this->match(['sku_code' => 'custom-42'], [], []));
	}

	public function testSellableIdWinsOverSku(): void
	{
		self::assertSame([5], $this->match(['sellable_id' => 100, 'sku_code' => 'ABC'], [100 => [5]], ['ABC' => [7]]));
	}

	public function testReturnsEveryLineItemSharingASellable(): void
	{
		self::assertSame([5, 6], $this->match(['sellable_id' => 100], [100 => [5, 6]], []));
	}

	public function testReturnsEmptyWhenNothingMatches(): void
	{
		self::assertSame([], $this->match(['sku_code' => 'ZZZ'], [100 => [5]], ['ABC' => [7]]));
		self::assertSame([], $this->match([], [], []));
	}

	public function testSplitsASharedSellableAcrossItsLineItems(): void
	{
		$unassignedQtyByLineItemId = [5 => 1, 6 => 1];
		$allocation = ['line_items' => [['sellable_id' => 100, 'quantity' => 2]]];

		self::assertSame([5 => 1, 6 => 1], $this->resolve($allocation, [100 => [5, 6]], $unassignedQtyByLineItemId));
		self::assertSame([5 => 0, 6 => 0], $unassignedQtyByLineItemId);
	}

	public function testLaterAllocationTakesTheLineItemAnEarlierOneLeftOpen(): void
	{
		$unassignedQtyByLineItemId = [5 => 1, 6 => 1];
		$allocation = ['line_items' => [['sellable_id' => 100, 'quantity' => 1]]];

		self::assertSame([5 => 1], $this->resolve($allocation, [100 => [5, 6]], $unassignedQtyByLineItemId));
		self::assertSame([6 => 1], $this->resolve($allocation, [100 => [5, 6]], $unassignedQtyByLineItemId));
	}

	public function testGivesExcessToTheLastLineItem(): void
	{
		$unassignedQtyByLineItemId = [5 => 1, 6 => 1];
		$allocation = ['line_items' => [['sellable_id' => 100, 'quantity' => 3]]];

		self::assertSame([5 => 1, 6 => 2], $this->resolve($allocation, [100 => [5, 6]], $unassignedQtyByLineItemId));
	}

	public function testRefillsWhatTheShipmentHoldsAgainstLineItemOrder(): void
	{
		$unassignedQtyByLineItemId = [5 => 1, 6 => 1];
		$allocation = ['line_items' => [['sellable_id' => 100, 'quantity' => 1]]];

		self::assertSame([6 => 1], $this->resolve($allocation, [100 => [5, 6]], $unassignedQtyByLineItemId, [6 => 1]));
		self::assertSame([5 => 1], $this->resolve($allocation, [100 => [5, 6]], $unassignedQtyByLineItemId, [5 => 1]));
	}

	public function testRefillsAnUnevenSplit(): void
	{
		$unassignedQtyByLineItemId = [5 => 2, 6 => 1];
		$allocation = ['line_items' => [['sellable_id' => 100, 'quantity' => 2]]];

		self::assertSame([5 => 1, 6 => 1], $this->resolve($allocation, [100 => [5, 6]], $unassignedQtyByLineItemId, [5 => 1, 6 => 1]));
	}

	/**
	 * @param array<array-key, mixed> $line
	 * @param array<int, list<int>> $bySellableId
	 * @param array<string, list<int>> $bySku
	 * @return list<int>
	 */
	private function match(array $line, array $bySellableId, array $bySku): array
	{
		$method = new ReflectionMethod(ShipmentPoller::class, 'matchAllocationLine');
		/** @var list<int> $result */
		$result = $method->invoke(new ShipmentPoller(), $line, $bySellableId, $bySku);
		return $result;
	}

	/**
	 * @param array<array-key, mixed> $allocation
	 * @param array<int, list<int>> $bySellableId
	 * @param array<int, int> $unassignedQtyByLineItemId
	 * @param array<int, int> $heldQtyByLineItemId
	 * @return array<int, int>
	 */
	private function resolve(array $allocation, array $bySellableId, array &$unassignedQtyByLineItemId, array $heldQtyByLineItemId = []): array
	{
		$method = new ReflectionMethod(ShipmentPoller::class, 'resolveAllocationLineItems');
		$lineItemIndex = [
			'bySellableId' => $bySellableId,
			'bySku' => [],
		];
		/** @var array<int, int> $result */
		$result = $method->invokeArgs(new ShipmentPoller(), [$allocation, $lineItemIndex, $heldQtyByLineItemId, &$unassignedQtyByLineItemId]);
		return $result;
	}
}
