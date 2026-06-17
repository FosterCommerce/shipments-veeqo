<?php

declare(strict_types=1);

namespace fostercommerce\shipmentsveeqo\tests\unit\helpers;

use fostercommerce\shipmentsveeqo\helpers\VeeqoReference;
use PHPUnit\Framework\TestCase;

final class VeeqoReferenceTest extends TestCase
{
	public function testAllocationRoundTrips(): void
	{
		self::assertSame('alloc:456', VeeqoReference::allocation(456));
		self::assertSame(456, VeeqoReference::parseAllocationId('alloc:456'));
	}

	public function testParseAllocationIdRejectsOtherValues(): void
	{
		self::assertNull(VeeqoReference::parseAllocationId('order:1'));
		self::assertNull(VeeqoReference::parseAllocationId('456'));
		self::assertNull(VeeqoReference::parseAllocationId(''));
	}

	public function testOrderNumberAppliesPrefix(): void
	{
		self::assertSame('LH-1001', VeeqoReference::orderNumber('LH-', '1001'));
		self::assertSame('1001', VeeqoReference::orderNumber('', '1001'));
	}

	public function testReferenceFromNumberStripsPrefix(): void
	{
		self::assertSame('1001', VeeqoReference::referenceFromNumber('LH-', 'LH-1001'));
		self::assertSame('1001', VeeqoReference::referenceFromNumber('', '1001'));
	}

	public function testReferenceFromNumberRejectsForeignNumbers(): void
	{
		self::assertNull(VeeqoReference::referenceFromNumber('LH-', 'AMZ-999'));
		self::assertNull(VeeqoReference::referenceFromNumber('', ''));
	}
}
