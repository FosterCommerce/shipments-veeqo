<?php

declare(strict_types=1);

namespace fostercommerce\shipments\veeqo\helpers;

use Craft;
use craft\commerce\Plugin as Commerce;
use craft\elements\Address;
use craft\fields\Assets;
use craft\fields\PlainText;
use craft\models\FieldLayout;
use fostercommerce\shipments\veeqo\Plugin;

/**
 * Builds the field-picker options for the plugin settings screen.
 */
final class SettingsFieldOptions
{
	/**
	 * Asset fields across every product type's product and variant layouts, labeled by where they
	 * live so a store admin knows which one they are picking. Deduped by handle.
	 *
	 * @return list<array{label: string, value: string}>
	 */
	public static function productImageFields(): array
	{
		/** @var Commerce $commerce */
		$commerce = Commerce::getInstance();

		$options = [];
		$seenHandles = [];
		foreach ($commerce->getProductTypes()->getAllProductTypes() as $productType) {
			$productScope = Craft::t(Plugin::HANDLE, 'settings.imageField.productScope');
			$variantScope = Craft::t(Plugin::HANDLE, 'settings.imageField.variantScope');
			self::collect($productType->getFieldLayout(), Assets::class, $productScope . ': ', $options, $seenHandles);
			self::collect($productType->getVariantFieldLayout(), Assets::class, $variantScope . ': ', $options, $seenHandles);
		}

		return $options;
	}

	/**
	 * Plain text fields on the address layout, as candidates for the phone-number setting.
	 *
	 * @return list<array{label: string, value: string}>
	 */
	public static function addressTextFields(): array
	{
		$fieldLayout = Craft::$app->getFields()->getLayoutByType(Address::class);

		$options = [];
		$seenHandles = [];
		self::collect($fieldLayout, PlainText::class, '', $options, $seenHandles);

		return $options;
	}

	/**
	 * @param class-string $fieldClass
	 * @param list<array{label: string, value: string}> $options
	 * @param array<string, true> $seenHandles
	 */
	private static function collect(?FieldLayout $fieldLayout, string $fieldClass, string $labelPrefix, array &$options, array &$seenHandles): void
	{
		foreach ($fieldLayout?->getCustomFields() ?? [] as $field) {
			if (! $field instanceof $fieldClass) {
				continue;
			}

			if (isset($seenHandles[$field->handle])) {
				continue;
			}

			$seenHandles[$field->handle] = true;
			$options[] = [
				'label' => $labelPrefix . $field->name,
				'value' => $field->handle,
			];
		}
	}
}
