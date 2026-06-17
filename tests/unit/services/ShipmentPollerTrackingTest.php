<?php

declare(strict_types=1);

namespace fostercommerce\shipmentsveeqo\tests\unit\services;

use fostercommerce\shipmentsveeqo\services\ShipmentPoller;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * Parses extractTracking() against the real Veeqo /orders response shape captured 2026-06-16:
 * tracking nested under allocations[].shipment, carrier as an object with a name.
 */
final class ShipmentPollerTrackingTest extends TestCase
{
	public function testPullsTrackingFromAllocationShipment(): void
	{
		$result = $this->extract([
			'id' => 123,
			'status' => 'shipped',
			'allocations' => [
				[
					'id' => 1,
					'shipment' => [
						'tracking_number' => 'TRACK123',
						'tracking_url' => 'https://track.example/abc',
						'service_carrier_name' => 'Royal Mail Tracked 24',
						'carrier' => [
							'id' => 1,
							'name' => 'Royal Mail',
						],
					],
				],
			],
		]);

		self::assertSame([
			'trackingNumber' => 'TRACK123',
			'trackingUrl' => 'https://track.example/abc',
			'carrier' => 'Royal Mail',
			'service' => 'Royal Mail Tracked 24',
		], $result);
	}

	public function testSkipsAllocationsWithoutTrackingAndPicksTheTrackedOne(): void
	{
		$result = $this->extract([
			'allocations' => [
				[
					'id' => 1,
					'shipment' => [
						'tracking_number' => null,
					],
				],
				[
					'id' => 2,
					'shipment' => [
						'tracking_number' => 'SECOND',
						'carrier' => 'DHL',
					],
				],
			],
		]);

		self::assertNotNull($result);
		self::assertSame('SECOND', $result['trackingNumber']);
		self::assertSame('DHL', $result['carrier']);
		self::assertNull($result['trackingUrl']);
		self::assertNull($result['service']);
	}

	public function testReturnsNullWhenNoAllocationHasTracking(): void
	{
		self::assertNull($this->extract([
			'allocations' => [
				[
					'id' => 1,
					'shipment' => [
						'tracking_number' => '',
					],
				],
			],
		]));

		self::assertNull($this->extract([
			'allocations' => [],
		]));

		self::assertNull($this->extract([]));
	}

	/**
	 * @param array<array-key, mixed> $order
	 * @return array<string, mixed>|null
	 */
	private function extract(array $order): ?array
	{
		$method = new ReflectionMethod(ShipmentPoller::class, 'extractTracking');
		$method->setAccessible(true);
		/** @var array<string, mixed>|null $result */
		$result = $method->invoke(new ShipmentPoller(), $order);
		return $result;
	}
}
