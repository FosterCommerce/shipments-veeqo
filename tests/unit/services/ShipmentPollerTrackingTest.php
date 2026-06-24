<?php

declare(strict_types=1);

namespace fostercommerce\shipments\veeqo\tests\unit\services;

use fostercommerce\shipments\veeqo\services\ShipmentPoller;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * Parses extractTracking() against the real Veeqo allocation shape captured 2026-06-16: tracking
 * nested under allocation.shipment, carrier as an object with a name.
 */
final class ShipmentPollerTrackingTest extends TestCase
{
	public function testPullsTrackingFromAllocationShipment(): void
	{
		$result = $this->extract([
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
		]);

		self::assertSame([
			'trackingNumber' => 'TRACK123',
			'trackingUrl' => 'https://track.example/abc',
			'carrier' => 'Royal Mail',
			'service' => 'Royal Mail Tracked 24',
		], $result);
	}

	public function testReadsNestedTrackingNumberObjectAndStringCarrier(): void
	{
		$result = $this->extract([
			'id' => 2,
			'shipment' => [
				'tracking_number' => [
					'tracking_number' => 'SECOND',
				],
				'carrier' => 'DHL',
			],
		]);

		self::assertNotNull($result);
		self::assertSame('SECOND', $result['trackingNumber']);
		self::assertSame('DHL', $result['carrier']);
		self::assertNull($result['trackingUrl']);
		self::assertNull($result['service']);
	}

	public function testReturnsNullWhenAllocationHasNoTracking(): void
	{
		self::assertNull($this->extract([
			'id' => 1,
			'shipment' => [
				'tracking_number' => '',
			],
		]));

		self::assertNull($this->extract([
			'id' => 1,
		]));

		self::assertNull($this->extract([]));
	}

	/**
	 * @param array<array-key, mixed> $allocation
	 * @return array<string, mixed>|null
	 */
	private function extract(array $allocation): ?array
	{
		$method = new ReflectionMethod(ShipmentPoller::class, 'extractTracking');
		$method->setAccessible(true);
		/** @var array<string, mixed>|null $result */
		$result = $method->invoke(new ShipmentPoller(), $allocation);
		return $result;
	}
}
