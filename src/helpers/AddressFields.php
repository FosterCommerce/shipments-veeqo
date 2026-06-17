<?php

declare(strict_types=1);

namespace fostercommerce\shipmentsveeqo\helpers;

use craft\elements\Address;
use fostercommerce\shipmentsveeqo\Plugin;

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

		if ($address->getFieldLayout()?->getFieldByHandle($handle) === null) {
			return '';
		}

		return (string) $address->getFieldValue($handle);
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
