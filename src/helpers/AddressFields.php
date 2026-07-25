<?php

declare(strict_types=1);

namespace fostercommerce\shipments\veeqo\helpers;

use craft\base\FieldInterface;
use craft\elements\Address;

final class AddressFields
{
	/**
	 * Phone number from the given field. Phone is optional to Veeqo, so a handle that no longer
	 * exists on the address layout yields an empty string rather than failing the push.
	 */
	public static function phone(Address $address, string $handle): string
	{
		if ($handle === '') {
			return '';
		}

		if (! $address->getFieldLayout()?->getFieldByHandle($handle) instanceof FieldInterface) {
			return '';
		}

		$value = $address->getFieldValue($handle);

		return is_string($value) ? $value : '';
	}

	/**
	 * First name for the address, falling back to the full name when the structured first name is empty.
	 */
	public static function firstName(Address $address): string
	{
		$firstName = (string) $address->firstName;
		if ($firstName === '') {
			return (string) $address->fullName;
		}

		return $firstName;
	}
}
