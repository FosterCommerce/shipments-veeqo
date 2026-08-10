<?php

declare(strict_types=1);

namespace fostercommerce\shipments\veeqo\services;

use Craft;
use craft\commerce\elements\Product;
use craft\commerce\elements\Variant;
use craft\commerce\models\LineItem;
use craft\commerce\Plugin as Commerce;
use craft\helpers\StringHelper;
use fostercommerce\shipments\errors\PermanentIntegrationException;
use fostercommerce\shipments\veeqo\errors\VeeqoApiException;
use fostercommerce\shipments\veeqo\events\ProductPayloadEvent;
use fostercommerce\shipments\veeqo\helpers\ProductImageFields;
use fostercommerce\shipments\veeqo\helpers\VeeqoPrice;
use fostercommerce\shipments\veeqo\Plugin;
use fostercommerce\shipments\veeqo\providers\VeeqoProvider;
use fostercommerce\shipments\veeqo\records\SellableMapping;
use yii\base\Component;

/**
 * Veeqo product sync service.
 */
class ProductSync extends Component
{
	public const EVENT_BEFORE_SEND_PAYLOAD = 'beforeSendPayload';

	/**
	 * SKU prefix for the synthetic sellable a custom (non-purchasable) line item is pushed under, so
	 * the Craft line item id is recoverable when an allocation is mirrored back.
	 */
	public const CUSTOM_SKU_PREFIX = 'custom-';

	/**
	 * Shortest query Veeqo's product search accepts; anything shorter comes back as a 400.
	 */
	public const MIN_SEARCH_LENGTH = 3;

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

		if (! $existingMapping instanceof SellableMapping) {
			$response = $client->post('/products', $this->buildEventPayload($product, null));
			$this->persistMappingsFromResponse($product, $response);
			return;
		}

		$veeqoProductId = $existingMapping->veeqoProductId;
		$response = $client->put('/products/' . $veeqoProductId, $this->buildEventPayload($product, $veeqoProductId));
		$this->persistMappingsFromResponse($product, $response);
	}

	/**
	 * Link a product's variants to sellables already in Veeqo by exact SKU match, creating nothing.
	 *
	 * Only a retryable failure lands in `failed`; a bulk run finishes either way.
	 *
	 * @return array{linked: list<string>, unmatched: list<string>, failed: list<string>}
	 */
	public function reconcile(Product $product, VeeqoProvider $provider): array
	{
		$sellableMappings = Plugin::instance()->getSellableMappings();

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

			if ($sellableMappings->findByPurchasableId($variant->id) instanceof SellableMapping) {
				continue;
			}

			// Veeqo's product search rejects a query under 3 characters, so a short SKU is unfindable
			// and will be created rather than linked.
			if (StringHelper::length($sku) < self::MIN_SEARCH_LENGTH) {
				$unmatched[] = $sku;
				continue;
			}

			try {
				$match = $this->findSellableBySku($sku, $provider);
			} catch (VeeqoApiException $veeqoApiException) {
				Craft::warning("Veeqo reconcile lookup failed for SKU {$sku}: " . $veeqoApiException->getMessage(), Plugin::HANDLE);

				// A 4xx answers the same way every run, so reporting it as retryable sends the operator
				// back to a command that can never clear it.
				if ($veeqoApiException->isRetryable()) {
					$failed[] = $sku;
				} else {
					$unmatched[] = $sku;
				}

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
	 * Create a standalone Veeqo product for one line item and return the sellable it carries, or
	 * null when the create response holds no matching sellable. Keys on the line item's own SKU,
	 * falling back to a synthetic id so the sellable is stable per line item.
	 *
	 * @return array{sellableId: int, productId: int, sku: string}|null
	 * @throws PermanentIntegrationException
	 * @throws VeeqoApiException
	 */
	public function syncLineItemAsOwnProduct(LineItem $lineItem, VeeqoProvider $provider): ?array
	{
		$sku = trim($lineItem->getSku());
		if ($sku === '') {
			$sku = self::CUSTOM_SKU_PREFIX . $lineItem->id;
		}

		// Veeqo accepts duplicate SKUs, so every order carrying the same custom item would otherwise
		// add another product for it.
		$existing = $this->findSellableBySku($sku, $provider);
		if ($existing !== null) {
			return [
				...$existing,
				'sku' => $sku,
			];
		}

		$currencyCode = (string) $lineItem->getOrder()?->getStore()->getCurrency()?->getCode();

		$response = $provider->getClient()->post('/products', [
			'title' => $lineItem->getDescription(),
			'sellables_attributes' => [
				[
					'sku_code' => $sku,
					'title' => $lineItem->getDescription(),
					'price' => VeeqoPrice::decimal((float) $lineItem->salePrice, $currencyCode),
				],
			],
		]);

		$sellableId = $this->buildSellableIdIndexBySku($response)[$sku] ?? 0;
		$productId = $this->extractVeeqoProductId($response);
		if ($sellableId === 0 || $productId === 0) {
			return null;
		}

		return [
			'sellableId' => $sellableId,
			'productId' => $productId,
			'sku' => $sku,
		];
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

	private function findExistingMapping(Product $product): ?SellableMapping
	{
		$sellableMappings = Plugin::instance()->getSellableMappings();

		foreach ($product->getVariants() as $variant) {
			if ($variant->id === null) {
				continue;
			}

			$sellableMapping = $sellableMappings->findByPurchasableId($variant->id);
			if ($sellableMapping instanceof SellableMapping) {
				return $sellableMapping;
			}

			$sku = trim((string) $variant->sku);
			if ($sku === '') {
				continue;
			}

			$sellableMapping = $sellableMappings->findBySku($sku);
			if ($sellableMapping instanceof SellableMapping) {
				return $sellableMapping;
			}
		}

		return null;
	}

	/**
	 * @return array<string, mixed>
	 */
	private function buildEventPayload(Product $product, ?int $veeqoProductId): array
	{
		$productPayloadEvent = new ProductPayloadEvent([
			'product' => $product,
			'payload' => $this->buildPayload($product, $veeqoProductId),
		]);
		$this->trigger(self::EVENT_BEFORE_SEND_PAYLOAD, $productPayloadEvent);

		return $productPayloadEvent->payload;
	}

	/**
	 * @return array<string, mixed>
	 */
	private function buildPayload(Product $product, ?int $veeqoProductId): array
	{
		$sellablePayloads = [];
		foreach ($product->getVariants() as $variant) {
			if (trim((string) $variant->sku) === '') {
				continue;
			}

			$sellablePayloads[] = $this->buildSellablePayload($variant, $veeqoProductId);
		}

		$payload = [
			'title' => (string) $product->title,
			'sellables_attributes' => $sellablePayloads,
		];

		$imageUrl = ProductImageFields::firstUrl($product, (string) Plugin::instance()->getSettings()->productImagesHandle);
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
	 * Stock is not sent: Veeqo tracks it in per-warehouse `stock_entries`, not on the sellable.
	 *
	 * @return array<string, mixed>
	 */
	private function buildSellablePayload(Variant $variant, ?int $veeqoProductId): array
	{
		$currencyCode = (string) $variant->getStore()->getCurrency()?->getCode();

		$productTitle = (string) $variant->getProduct()?->title;
		$variantTitle = (string) $variant->title;

		$attributes = [
			'sku_code' => trim((string) $variant->sku),
			// Veeqo names a sellable "<product title> <sellable title>", so a variant carrying its
			// product's title reads twice. Blank must be explicit: omitting the key leaves the
			// doubled title in place on an update.
			'title' => $variantTitle === $productTitle ? '' : $variantTitle,
			'price' => VeeqoPrice::decimal((float) $variant->price, $currencyCode),
		];

		// Veeqo discards a sellable in `sellables_attributes` unless it carries the id; matching on
		// sku_code alone silently drops every field on it. An id belonging to a different Veeqo
		// product is an unresolvable foreign key, which Veeqo answers with a 404 on the request.
		$mapping = $variant->id === null ? null : Plugin::instance()->getSellableMappings()->findByPurchasableId($variant->id);
		if ($mapping instanceof SellableMapping && $mapping->veeqoProductId === $veeqoProductId) {
			$attributes['id'] = $mapping->veeqoSellableId;
		}

		$weightGrams = $this->toGrams((float) $variant->weight);
		if ($weightGrams > 0) {
			$attributes['weight_grams'] = $weightGrams;
		}

		return $attributes;
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

		$sellableMappings = Plugin::instance()->getSellableMappings();

		foreach ($product->getVariants() as $variant) {
			$sku = trim((string) $variant->sku);
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
