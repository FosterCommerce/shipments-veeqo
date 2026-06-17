<?php

declare(strict_types=1);

namespace fostercommerce\shipmentsveeqo\services;

use Craft;
use craft\helpers\DateTimeHelper;
use craft\helpers\Json;
use fostercommerce\shipmentsveeqo\Plugin;
use fostercommerce\shipmentsveeqo\records\SellableMapping;
use yii\base\Component;
use yii\base\Exception;

/**
 * Persists the mapping between Craft Commerce purchasable IDs and Veeqo sellable/product IDs.
 * Populated by ProductSync and read by order-push code at payload-build time.
 */
class SellableMappings extends Component
{
	/**
	 * Returns the mapping for a Commerce purchasable, or null if none is cached.
	 */
	public function findByPurchasableId(int $purchasableId): ?SellableMapping
	{
		return SellableMapping::findOne([
			'purchasableId' => $purchasableId,
		]);
	}

	/**
	 * Returns a mapping matching the given SKU, or null if none exists.
	 */
	public function findBySku(string $sku): ?SellableMapping
	{
		return SellableMapping::findOne([
			'sku' => $sku,
		]);
	}

	/**
	 * Returns the mapping for a Veeqo sellable id, or null if none is cached.
	 */
	public function findByVeeqoSellableId(int $veeqoSellableId): ?SellableMapping
	{
		return SellableMapping::findOne([
			'veeqoSellableId' => $veeqoSellableId,
		]);
	}

	/**
	 * Distinct Veeqo product ids across all mappings, for stock-pull iteration.
	 *
	 * @return list<int>
	 */
	public function getAllVeeqoProductIds(): array
	{
		/** @var list<mixed> $ids */
		$ids = SellableMapping::find()
			->select(['veeqoProductId'])
			->distinct()
			->column();

		return array_map(static fn (mixed $id): int => is_numeric($id) ? (int) $id : 0, $ids);
	}

	/**
	 * Creates or updates the mapping row for a purchasable.
	 *
	 * @throws Exception if the record fails to save
	 */
	public function upsert(int $purchasableId, string $sku, int $veeqoSellableId, int $veeqoProductId): SellableMapping
	{
		$mapping = $this->findByPurchasableId($purchasableId) ?? new SellableMapping();
		$mapping->purchasableId = $purchasableId;
		$mapping->sku = $sku;
		$mapping->veeqoSellableId = $veeqoSellableId;
		$mapping->veeqoProductId = $veeqoProductId;
		$mapping->lastSyncedAt = DateTimeHelper::now()->format('Y-m-d H:i:s');

		if (! $mapping->save()) {
			$message = 'Failed to save SellableMapping for purchasable ' . $purchasableId . ': ' . Json::encode($mapping->getErrors());
			Craft::error($message, Plugin::HANDLE);
			throw new Exception($message);
		}

		return $mapping;
	}

	/**
	 * Removes the mapping for a purchasable, if any.
	 */
	public function deleteByPurchasableId(int $purchasableId): void
	{
		$mapping = $this->findByPurchasableId($purchasableId);
		if ($mapping instanceof SellableMapping) {
			$mapping->delete();
		}
	}
}
