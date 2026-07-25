<?php

declare(strict_types=1);

namespace fostercommerce\shipments\veeqo\helpers;

use craft\base\FieldInterface;
use craft\commerce\elements\Product;
use craft\elements\Asset;
use craft\elements\db\AssetQuery;

final class ProductImageFields
{
	/**
	 * URL of the first asset in the given field, read from the product when the field lives on the
	 * product layout, otherwise from the first variant carrying it.
	 */
	public static function firstUrl(Product $product, string $handle): ?string
	{
		if ($handle === '') {
			return null;
		}

		if ($product->getFieldLayout()?->getFieldByHandle($handle) instanceof FieldInterface) {
			return self::firstAssetUrl($product->getFieldValue($handle));
		}

		foreach ($product->getVariants() as $variant) {
			if (! $variant->getFieldLayout()?->getFieldByHandle($handle) instanceof FieldInterface) {
				continue;
			}

			$url = self::firstAssetUrl($variant->getFieldValue($handle));
			if ($url !== null) {
				return $url;
			}
		}

		return null;
	}

	private static function firstAssetUrl(mixed $fieldValue): ?string
	{
		if (! $fieldValue instanceof AssetQuery) {
			return null;
		}

		$asset = $fieldValue->one();

		return $asset instanceof Asset ? $asset->getUrl() : null;
	}
}
