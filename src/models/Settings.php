<?php

declare(strict_types=1);

namespace fostercommerce\shipmentsveeqo\models;

use craft\base\Model;

/**
 * Plugin-wide settings. Veeqo credentials and shipment-push config live on the
 * VeeqoProvider (per integration); these are the only plugin-global options.
 */
class Settings extends Model
{
	/**
	 * When on, saving a Commerce product enqueues a Veeqo sellable sync.
	 */
	public bool $syncProducts = true;

	/**
	 * Asset field handle whose images are sent with the product payload. Null skips images.
	 */
	public ?string $productImagesHandle = null;

	/**
	 * When on, the Veeqo stock pull adjusts Commerce inventory for inventory-tracked variants.
	 */
	public bool $syncStock = true;

	/**
	 * @return array<array-key, mixed>
	 */
	protected function defineRules(): array
	{
		return [
			[['syncProducts', 'syncStock'], 'boolean'],
			[['productImagesHandle'], 'string'],
		];
	}
}
