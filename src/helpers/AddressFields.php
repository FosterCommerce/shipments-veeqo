<?php

declare(strict_types=1);

namespace fostercommerce\shipments\veeqo\helpers;

use craft\base\FieldInterface;
use craft\elements\Address;
use fostercommerce\shipments\veeqo\Plugin;

class AddressFields
{
	/**
	 * Phone number from the configured address field, or an empty string when no handle is set
	 * or the field is absent from the address layout. Phone is optional to Veeqo, so a stale or
	 * missing handle must not fail the push.
	 */
	public static function phone(Address $address): string
	{
		/** @var Plugin $plugin */
		$plugin = Plugin::getInstance();
		$handle = (string) $plugin->getSettings()->phoneFieldHandle;
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
