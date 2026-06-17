<?php

declare(strict_types=1);

namespace fostercommerce\shipmentsveeqo\services;

use Craft;
use craft\commerce\elements\Product;
use craft\commerce\elements\Variant;
use craft\commerce\models\LineItem;
use craft\commerce\Plugin as Commerce;
use craft\helpers\MoneyHelper;
use fostercommerce\shipments\errors\PermanentIntegrationException;
use fostercommerce\shipmentsveeqo\errors\VeeqoApiException;
use fostercommerce\shipmentsveeqo\events\ProductPayloadEvent;
use fostercommerce\shipmentsveeqo\helpers\ProductImageFields;
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
	 *
	 * @throws PermanentIntegrationException
	 * @throws VeeqoApiException
	 */
	public function syncProduct(Product $product, VeeqoProvider $provider): void
	{
		$client = $provider->getClient();

		$existingMapping = $this->findExistingMapping($product);
		if (! $existingMapping instanceof SellableMapping) {
			// Link to a product already in Veeqo (matched by SKU) so a first sync against a
			// populated account updates it rather than creating a duplicate.
			$this->reconcile($product, $provider);
			$existingMapping = $this->findExistingMapping($product);
		}

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

	/**
	 * Link a product's variants to sellables already in Veeqo by exact SKU match, recording the
	 * mapping without creating anything in Veeqo. A lookup that errors (timeout, rate limit) is
	 * collected as failed rather than aborting the run, so a bulk reconcile finishes.
	 *
	 * @return array{linked: list<string>, unmatched: list<string>, failed: list<string>}
	 */
	public function reconcile(Product $product, VeeqoProvider $provider): array
	{
		$sellableMappings = $this->plugin()->sellableMappings;

		$linked = [];
		$unmatched = [];
		$failed = [];
		foreach ($product->getVariants() as $variant) {
			$sku = trim((string) $variant->sku);
			if ($sku === '') {
				continue;
			}

			if ($variant->id === null) {
				continue;
			}

			if ($sellableMappings->findByPurchasableId($variant->id) !== null) {
				continue;
			}

			try {
				$match = $this->findSellableBySku($sku, $provider);
			} catch (VeeqoApiException $veeqoApiException) {
				Craft::warning("Veeqo reconcile lookup failed for SKU {$sku}: " . $veeqoApiException->getMessage(), Plugin::HANDLE);
				$failed[] = $sku;
				continue;
			}

			if ($match === null) {
				$unmatched[] = $sku;
				continue;
			}

			$sellableMappings->upsert($variant->id, $sku, $match['sellableId'], $match['productId']);
			$linked[] = $sku;
		}

		return [
			'linked' => $linked,
			'unmatched' => $unmatched,
			'failed' => $failed,
		];
	}

	/**
	 * Create a Veeqo sellable for a custom (non-purchasable) line item and return its sellable id,
	 * or 0 when the create response carries no matching sellable. Keys on the line item's own SKU,
	 * falling back to a synthetic id so the sellable is stable per line item.
	 *
	 * @throws PermanentIntegrationException
	 * @throws VeeqoApiException
	 */
	public function syncCustomLineItem(LineItem $lineItem, VeeqoProvider $provider): int
	{
		$sku = trim($lineItem->getSku());
		if ($sku === '') {
			$sku = 'custom-' . $lineItem->id;
		}

		$currencyCode = (string) $lineItem->getOrder()?->getStore()->getCurrency()?->getCode();

		$response = $provider->getClient()->post('/products', [
			'title' => $lineItem->getDescription(),
			'sellables_attributes' => [
				[
					'sku_code' => $sku,
					'title' => $lineItem->getDescription(),
					'price' => $this->priceString((float) $lineItem->salePrice, $currencyCode),
				],
			],
		]);

		return $this->buildSellableIdIndexBySku($response)[$sku] ?? 0;
	}

	/**
	 * Veeqo sellable and parent product ids for an exact SKU match, or null when none is found.
	 * Veeqo's product search is free text over name and SKU, so results are filtered to an exact
	 * (case-sensitive) sku_code match, mirroring how Veeqo treats SKUs.
	 *
	 * @return array{sellableId: int, productId: int}|null
	 */
	private function findSellableBySku(string $sku, VeeqoProvider $provider): ?array
	{
		$veeqoProducts = $provider->getClient()->get('/products', [
			'query' => $sku,
			'page_size' => 100,
		]);

		foreach ($veeqoProducts as $veeqoProduct) {
			if (! is_array($veeqoProduct)) {
				continue;
			}

			$productId = isset($veeqoProduct['id']) && is_numeric($veeqoProduct['id']) ? (int) $veeqoProduct['id'] : 0;
			$sellables = $veeqoProduct['sellables'] ?? [];
			if ($productId === 0) {
				continue;
			}

			if (! is_array($sellables)) {
				continue;
			}

			foreach ($sellables as $sellable) {
				if (! is_array($sellable)) {
					continue;
				}

				if (($sellable['sku_code'] ?? null) !== $sku) {
					continue;
				}

				$sellableId = isset($sellable['id']) && is_numeric($sellable['id']) ? (int) $sellable['id'] : 0;
				if ($sellableId !== 0) {
					return [
						'sellableId' => $sellableId,
						'productId' => $productId,
					];
				}
			}
		}

		return null;
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

		$payload = [
			'title' => (string) $product->title,
			'sellables_attributes' => $sellablePayloads,
		];

		$imageUrl = ProductImageFields::firstUrl($product);
		if ($imageUrl !== null) {
			$payload['images_attributes'] = [
				[
					'src' => $imageUrl,
					'display_position' => '1',
				],
			];
		}

		return $payload;
	}

	/**
	 * Builds the per-variant sellable attributes. Stock is intentionally NOT sent here:
	 * Veeqo tracks stock in per-warehouse `stock_entries`, not as a field on sellables.
	 *
	 * @return array<string, mixed>
	 */
	private function buildSellablePayload(Variant $variant): array
	{
		$currencyCode = (string) $variant->getStore()->getCurrency()?->getCode();

		$attributes = [
			'sku_code' => (string) $variant->sku,
			'title' => (string) $variant->title,
			'price' => $this->priceString((float) $variant->price, $currencyCode),
		];

		$weightGrams = $this->toGrams((float) $variant->weight);
		if ($weightGrams > 0) {
			$attributes['weight_grams'] = $weightGrams;
		}

		return $attributes;
	}

	/**
	 * Format a price as the decimal string Veeqo expects, routing through Money so float dollar
	 * values do not drift before they leave Craft.
	 *
	 * @throws PermanentIntegrationException
	 */
	private function priceString(float $amount, string $currencyCode): string
	{
		if ($currencyCode === '') {
			throw new PermanentIntegrationException('Cannot format a Veeqo price without a store currency.');
		}

		$decimal = MoneyHelper::toDecimal([
			'value' => (string) $amount,
			'currency' => $currencyCode,
		]);

		if ($decimal === false) {
			throw new PermanentIntegrationException("Could not format price for currency “{$currencyCode}”.");
		}

		return $decimal;
	}

	/**
	 * Convert a Commerce weight to grams using the store's configured weight unit; Veeqo only
	 * accepts weight_grams, so sending the raw value would mis-scale lb/kg weights.
	 */
	private function toGrams(float $weight): int
	{
		/** @var Commerce $commerce */
		$commerce = Commerce::getInstance();
		$gramsPerUnit = match ($commerce->getSettings()->weightUnits) {
			'kg' => 1000.0,
			'lb' => 453.59237,
			default => 1.0,
		};

		return (int) round($weight * $gramsPerUnit);
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
