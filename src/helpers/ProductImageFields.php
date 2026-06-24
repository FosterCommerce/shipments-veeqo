<?php

declare(strict_types=1);

namespace fostercommerce\shipments\veeqo\helpers;

use Craft;
use craft\base\FieldInterface;
use craft\commerce\elements\Product;
use craft\commerce\Plugin as Commerce;
use craft\elements\Asset;
use craft\elements\db\AssetQuery;
use craft\fields\Assets;
use craft\models\FieldLayout;
use fostercommerce\shipments\veeqo\Plugin;

class ProductImageFields
{
	/**
	 * Asset fields across every product type's product and variant layouts, labeled by where they
	 * live so a store admin knows which one they are picking. Deduped by handle.
	 *
	 * @return list<array{label: string, value: string}>
	 */
	public static function options(): array
	{
		/** @var Commerce $commerce */
		$commerce = Commerce::getInstance();

		$options = [];
		$seenHandles = [];
		foreach ($commerce->getProductTypes()->getAllProductTypes() as $productType) {
			self::collect($productType->getFieldLayout(), 'settings.imageField.productScope', $options, $seenHandles);
			self::collect($productType->getVariantFieldLayout(), 'settings.imageField.variantScope', $options, $seenHandles);
		}

		return $options;
	}

	/**
	 * URL of the first asset in the configured image field, read from the product when the field
	 * lives on the product layout, otherwise from the first variant that has it. Null when no
	 * handle is set, the field is absent, or the asset has no URL.
	 */
	public static function firstUrl(Product $product): ?string
	{
		/** @var Plugin $plugin */
		$plugin = Plugin::getInstance();
		$handle = (string) $plugin->getSettings()->productImagesHandle;
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

	/**
	 * @param list<array{label: string, value: string}> $options
	 * @param array<string, true> $seenHandles
	 */
	private static function collect(FieldLayout $fieldLayout, string $scopeKey, array &$options, array &$seenHandles): void
	{
		foreach ($fieldLayout->getCustomFields() as $field) {
			if (! $field instanceof Assets) {
				continue;
			}

			if (isset($seenHandles[$field->handle])) {
				continue;
			}

			$seenHandles[$field->handle] = true;
			$options[] = [
				'label' => Craft::t(Plugin::HANDLE, $scopeKey) . ': ' . $field->name,
				'value' => $field->handle,
			];
		}
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
