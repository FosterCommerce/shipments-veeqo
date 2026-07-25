<?php

declare(strict_types=1);

namespace fostercommerce\shipments\veeqo\models;

use craft\base\Model;
use fostercommerce\shipments\enums\Status;

/**
 * Plugin-wide settings. Credentials and push config live on the provider, per integration.
 */
class Settings extends Model
{
	public bool $syncProducts = true;

	public ?string $productImagesHandle = null;

	public bool $syncStock = true;

	public ?string $phoneFieldHandle = null;

	public ?string $autoPushStatus = null;

	/**
	 * @return array<array-key, mixed>
	 */
	protected function defineRules(): array
	{
		return [
			[['syncProducts', 'syncStock'], 'boolean'],
			[['productImagesHandle', 'phoneFieldHandle', 'autoPushStatus'], 'string'],
			[
				['autoPushStatus'],
				'in',
				'range' => array_map(
					static fn (Status $case): string => $case->value,
					Status::cases()
				),
			],
		];
	}
}
