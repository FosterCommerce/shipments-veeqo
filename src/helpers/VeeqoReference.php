<?php

declare(strict_types=1);

namespace fostercommerce\shipmentsveeqo\helpers;

/**
 * Veeqo identity helpers. A Craft shipment stores one Veeqo reference (its allocation id, prefixed),
 * and the Veeqo order is linked back to the Craft order through the order number rather than a
 * stored reference, since the reference table holds one external id per shipment.
 */
final class VeeqoReference
{
	public const ALLOCATION_PREFIX = 'alloc:';

	public static function allocation(int $allocationId): string
	{
		return self::ALLOCATION_PREFIX . $allocationId;
	}

	public static function parseAllocationId(string $externalId): ?int
	{
		if (! str_starts_with($externalId, self::ALLOCATION_PREFIX)) {
			return null;
		}

		return (int) substr($externalId, strlen(self::ALLOCATION_PREFIX));
	}

	public static function orderNumber(string $prefix, string $reference): string
	{
		return $prefix . $reference;
	}

	/**
	 * Recovers the Craft order reference from a Veeqo order number, or null when the number does not
	 * carry the integration's prefix (a foreign Veeqo order).
	 */
	public static function referenceFromNumber(string $prefix, string $number): ?string
	{
		if ($prefix === '') {
			return $number !== '' ? $number : null;
		}

		if (! str_starts_with($number, $prefix)) {
			return null;
		}

		return substr($number, strlen($prefix));
	}
}
