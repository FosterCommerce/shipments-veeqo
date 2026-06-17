<?php

declare(strict_types=1);

namespace fostercommerce\shipmentsveeqo\services;

use Craft;
use craft\commerce\elements\Product;
use craft\commerce\elements\Variant;
use fostercommerce\shipmentsveeqo\events\ProductPayloadEvent;
use fostercommerce\shipmentsveeqo\Plugin;
use fostercommerce\shipmentsveeqo\providers\VeeqoProvider;
use fostercommerce\shipmentsveeqo\records\SellableMapping;
use yii\base\Component;

/**
 * Pushes Craft Commerce products (and their variants) up to Veeqo as products + sellables,
 * using the credentials on the active Veeqo provider.
 */
class ProductSync extends Component
{
	public const EVENT_BEFORE_SEND_PAYLOAD = 'beforeSendPayload';

	/**
	 * Creates or updates the given Commerce product in Veeqo, then records the returned
	 * sellable and product IDs in the local mapping table for later order-push use.
	 */
	public function syncProduct(Product $product, VeeqoProvider $provider): void
	{
		$client = $provider->getClient();
		$existingMapping = $this->findExistingMapping($product);

		$payload = $this->buildPayload($product);
		$productPayloadEvent = new ProductPayloadEvent([
			'product' => $product,
			'payload' => $payload,
		]);
		$this->trigger(self::EVENT_BEFORE_SEND_PAYLOAD, $productPayloadEvent);
		$payload = $productPayloadEvent->payload;

		if (! $existingMapping instanceof SellableMapping) {
			$response = $client->post('/products', $payload);
			$this->persistMappingsFromResponse($product, $response);
			return;
		}

		$response = $client->put('/products/' . $existingMapping->veeqoProductId, $payload);
		$this->persistMappingsFromResponse($product, $response);
	}

	private function plugin(): Plugin
	{
		/** @var Plugin $plugin */
		$plugin = Plugin::getInstance();
		return $plugin;
	}

	private function findExistingMapping(Product $product): ?SellableMapping
	{
		$sellableMappings = $this->plugin()->sellableMappings;

		foreach ($product->getVariants() as $variant) {
			if ($variant->id === null) {
				continue;
			}

			$sellableMapping = $sellableMappings->findByPurchasableId($variant->id);
			if ($sellableMapping !== null) {
				return $sellableMapping;
			}

			$sku = trim((string) $variant->sku);
			if ($sku === '') {
				continue;
			}

			$sellableMapping = $sellableMappings->findBySku($sku);
			if ($sellableMapping !== null) {
				return $sellableMapping;
			}
		}

		return null;
	}

	/**
	 * @return array<string, mixed>
	 */
	private function buildPayload(Product $product): array
	{
		$sellablePayloads = [];
		foreach ($product->getVariants() as $variant) {
			if (trim((string) $variant->sku) === '') {
				continue;
			}

			$sellablePayloads[] = $this->buildSellablePayload($variant);
		}

		return [
			'title' => (string) $product->title,
			'sellables_attributes' => $sellablePayloads,
		];
	}

	/**
	 * Builds the per-variant sellable attributes. Stock is intentionally NOT sent here:
	 * Veeqo tracks stock in per-warehouse `stock_entries`, not as a field on sellables.
	 *
	 * @return array<string, mixed>
	 */
	private function buildSellablePayload(Variant $variant): array
	{
		$attributes = [
			'sku_code' => (string) $variant->sku,
			'title' => (string) $variant->title,
			'price' => (float) $variant->price,
		];

		$weightGrams = (float) $variant->weight;
		if ($weightGrams > 0) {
			$attributes['weight_grams'] = (int) round($weightGrams);
		}

		return $attributes;
	}

	/**
	 * @param array<array-key, mixed> $response
	 */
	private function persistMappingsFromResponse(Product $product, array $response): void
	{
		$veeqoProductId = $this->extractVeeqoProductId($response);
		if ($veeqoProductId === 0) {
			Craft::warning('Veeqo product response missing ID for Commerce product ' . ($product->id ?? 0), Plugin::HANDLE);
			return;
		}

		$sellableIdsBySku = $this->buildSellableIdIndexBySku($response);
		if ($sellableIdsBySku === []) {
			return;
		}

		$sellableMappings = $this->plugin()->sellableMappings;

		foreach ($product->getVariants() as $variant) {
			$sku = (string) $variant->sku;
			if ($sku === '') {
				continue;
			}

			if ($variant->id === null) {
				continue;
			}

			if (! isset($sellableIdsBySku[$sku])) {
				continue;
			}

			$sellableMappings->upsert($variant->id, $sku, $sellableIdsBySku[$sku], $veeqoProductId);
		}
	}

	/**
	 * @param array<array-key, mixed> $response
	 */
	private function extractVeeqoProductId(array $response): int
	{
		if (isset($response['id']) && is_numeric($response['id'])) {
			return (int) $response['id'];
		}

		return 0;
	}

	/**
	 * Indexes the `sellables` array of a Veeqo product response by SKU to sellable ID.
	 *
	 * @param array<array-key, mixed> $response
	 * @return array<string, int>
	 */
	private function buildSellableIdIndexBySku(array $response): array
	{
		$sellables = $response['sellables'] ?? [];
		if (! is_array($sellables)) {
			return [];
		}

		$sellableIdsBySku = [];
		foreach ($sellables as $sellable) {
			if (! is_array($sellable)) {
				continue;
			}

			$sku = isset($sellable['sku_code']) ? (string) $sellable['sku_code'] : '';
			$sellableId = isset($sellable['id']) && is_numeric($sellable['id']) ? (int) $sellable['id'] : 0;
			if ($sku !== '' && $sellableId !== 0) {
				$sellableIdsBySku[$sku] = $sellableId;
			}
		}

		return $sellableIdsBySku;
	}
}
