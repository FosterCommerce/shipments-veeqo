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
use fostercommerce\shipments\Plugin as ShipmentsPlugin;
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
		if ($this->isIgnoredProductType($product)) {
			return;
		}

		$client = $provider->getClient();

		$this->forgetRenamedVariants($product);

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

		$response = $this->putProduct($product, $existingMapping->veeqoProductId, $provider);
		if ($response !== null) {
			$this->persistMappingsFromResponse($product, $response);
		}
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
		if ($this->isIgnoredProductType($product)) {
			return [
				'linked' => [],
				'unmatched' => [],
				'failed' => [],
			];
		}

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

			$sellableMappings->upsert($variant->id, $sku, $match['sellableId'], $match['productId'], true);
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
	 * @return array{sellableId: int, productId: int, sku: string, adopted: bool}|null
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
				'adopted' => true,
			];
		}

		$currencyCode = (string) $lineItem->getOrder()?->getStore()->getCurrency()?->getCode();

		$response = $provider->getClient()->post('/products', [
			'title' => $lineItem->getDescription(),
			'product_variants_attributes' => [
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
			'adopted' => false,
		];
	}

	/**
	 * Updates the Veeqo product, re-reading the mappings and retrying once when it answers 404.
	 *
	 * Veeqo answers any id it cannot resolve with a 404, which is how a variant regrouped under a
	 * different product presents.
	 *
	 * @return array<array-key, mixed>|null null when no variant maps anywhere after the refresh
	 * @throws VeeqoApiException
	 */
	private function putProduct(Product $product, int $veeqoProductId, VeeqoProvider $provider): ?array
	{
		$client = $provider->getClient();

		try {
			return $client->put('/products/' . $veeqoProductId, $this->buildEventPayload($product, $veeqoProductId));
		} catch (VeeqoApiException $veeqoApiException) {
			if ($veeqoApiException->getStatusCode() !== 404) {
				throw $veeqoApiException;
			}
		}

		$this->refreshMappedProductIds($product, $provider);

		$mapping = $this->findExistingMapping($product);
		if (! $mapping instanceof SellableMapping) {
			return null;
		}

		return $client->put('/products/' . $mapping->veeqoProductId, $this->buildEventPayload($product, $mapping->veeqoProductId));
	}

	/**
	 * Records the Veeqo product each mapped sellable currently belongs to, dropping mappings for
	 * sellables Veeqo has since deleted.
	 */
	private function refreshMappedProductIds(Product $product, VeeqoProvider $provider): void
	{
		$sellableMappings = Plugin::instance()->getSellableMappings();

		foreach ($product->getVariants() as $variant) {
			$mapping = $variant->id === null ? null : $sellableMappings->findByPurchasableId($variant->id);
			if (! $mapping instanceof SellableMapping) {
				continue;
			}

			try {
				$sellable = $provider->getClient()->get('/sellables/' . $mapping->veeqoSellableId);
			} catch (VeeqoApiException $veeqoApiException) {
				Craft::warning("Veeqo sellable {$mapping->veeqoSellableId} could not be re-read: " . $veeqoApiException->getMessage(), Plugin::HANDLE);
				continue;
			}

			if (($sellable['deleted_at'] ?? null) !== null) {
				$sellableMappings->deleteByPurchasableId((int) $variant->id);
				continue;
			}

			$sellableProduct = $sellable['product'] ?? null;
			$currentProductId = is_array($sellableProduct) && isset($sellableProduct['id']) && is_numeric($sellableProduct['id'])
				? (int) $sellableProduct['id']
				: 0;

			if ($currentProductId !== 0 && $currentProductId !== $mapping->veeqoProductId) {
				$sellableMappings->upsert((int) $variant->id, $mapping->sku, $mapping->veeqoSellableId, $currentProductId);
			}
		}
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

	/**
	 * Whether a Veeqo product is one this plugin created and still holds alone. Anything else was
	 * built for another product or another system, so its name, image and contents stand.
	 */
	private function isVeeqoProductOurs(int $veeqoProductId): bool
	{
		$sellableMappings = Plugin::instance()->getSellableMappings();

		return $sellableMappings->countCraftProductsForVeeqoProduct($veeqoProductId) <= 1
			&& ! $sellableMappings->hasAdoptedForVeeqoProduct($veeqoProductId);
	}

	/**
	 * Product types the Shipments plugin skips never reach a shipment, so they have nothing to do in
	 * a warehouse system.
	 */
	private function isIgnoredProductType(Product $product): bool
	{
		/** @var ShipmentsPlugin $shipments */
		$shipments = ShipmentsPlugin::getInstance();
		$ignoredProductTypes = $shipments->getSettings()->productTypesToIgnore;

		return in_array($product->getType()->handle, $ignoredProductTypes, true);
	}

	/**
	 * Drops mappings for variants whose SKU has changed, so each links to the sellable carrying its
	 * current SKU, or is created, rather than renaming the sellable it used to point at.
	 */
	private function forgetRenamedVariants(Product $product): void
	{
		$sellableMappings = Plugin::instance()->getSellableMappings();

		foreach ($product->getVariants() as $variant) {
			if ($variant->id !== null) {
				$sellableMappings->deleteIfSkuChanged($variant->id, trim((string) $variant->sku));
			}
		}
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
	 * @param ?int $veeqoProductId the Veeqo product being updated, or null when creating one
	 * @return array<string, mixed>
	 */
	private function buildPayload(Product $product, ?int $veeqoProductId): array
	{
		$isOurs = $veeqoProductId === null || $this->isVeeqoProductOurs($veeqoProductId);

		$variantPayloads = [];
		foreach ($product->getVariants() as $variant) {
			$variantPayload = $this->buildVariantPayload($variant, $veeqoProductId, $isOurs);
			if ($variantPayload !== []) {
				$variantPayloads[] = $variantPayload;
			}
		}

		$payload = [
			'product_variants_attributes' => $variantPayloads,
		];

		if (! $isOurs) {
			return $payload;
		}

		$payload['title'] = (string) $product->title;

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
	 * Stock is not sent: Veeqo tracks it in per-warehouse `stock_entries`, not on the variant.
	 *
	 * @return array<string, mixed> empty when the variant has no SKU, lives on a different Veeqo
	 *   product, or would be created inside one shared with other Craft products
	 */
	private function buildVariantPayload(Variant $variant, ?int $veeqoProductId, bool $isOurs): array
	{
		$sku = trim((string) $variant->sku);
		if ($sku === '') {
			return [];
		}

		$mapping = $variant->id === null ? null : Plugin::instance()->getSellableMappings()->findByPurchasableId($variant->id);

		// Sending it here would add a second copy alongside the one it already has elsewhere.
		if ($mapping instanceof SellableMapping && $mapping->veeqoProductId !== $veeqoProductId) {
			return [];
		}

		// Creating it here would add to a Veeqo product built for something other than this one.
		if (! $isOurs && ! $mapping instanceof SellableMapping) {
			return [];
		}

		$currencyCode = (string) $variant->getStore()->getCurrency()?->getCode();

		$attributes = [
			'price' => VeeqoPrice::decimal((float) $variant->price, $currencyCode),
		];

		if ($mapping instanceof SellableMapping) {
			$attributes['id'] = $mapping->veeqoSellableId;
		} else {
			$attributes['sku_code'] = $sku;
		}

		// On a Veeqo product this plugin did not create, the variant's name is the only text saying
		// which Craft product the line is, so it is left as Veeqo has it.
		if ($isOurs && ! ($mapping instanceof SellableMapping && $mapping->adopted)) {
			$productTitle = (string) $variant->getProduct()?->title;
			$variantTitle = (string) $variant->title;

			// Veeqo names a variant "<product title> <variant title>", so one carrying its product's
			// title reads twice.
			$attributes['title'] = $variantTitle === $productTitle ? '' : $variantTitle;
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
