<?php

declare(strict_types=1);

namespace fostercommerce\shipments\veeqo\services;

use Craft;
use craft\helpers\DateTimeHelper;
use craft\helpers\Json;
use fostercommerce\shipments\veeqo\Plugin;
use fostercommerce\shipments\veeqo\records\SellableMapping;
use yii\base\Component;
use yii\base\Exception;

/**
 * Craft purchasable to Veeqo sellable mappings.
 */
class SellableMappings extends Component
{
	public function findByPurchasableId(int $purchasableId): ?SellableMapping
	{
		return SellableMapping::findOne([
			'purchasableId' => $purchasableId,
		]);
	}

	public function findBySku(string $sku): ?SellableMapping
	{
		return SellableMapping::findOne([
			'sku' => $sku,
		]);
	}

	public function findByVeeqoSellableId(int $veeqoSellableId): ?SellableMapping
	{
		return SellableMapping::findOne([
			'veeqoSellableId' => $veeqoSellableId,
		]);
	}

	/**
	 * Veeqo sellable ids for many purchasables in one query, keyed by purchasable id.
	 *
	 * @param list<int> $purchasableIds
	 * @return array<int, int>
	 */
	public function getSellableIdsByPurchasableId(array $purchasableIds): array
	{
		if ($purchasableIds === []) {
			return [];
		}

		/** @var list<array{purchasableId: int|string, veeqoSellableId: int|string}> $rows */
		$rows = SellableMapping::find()
			->select(['purchasableId', 'veeqoSellableId'])
			->where([
				'purchasableId' => $purchasableIds,
			])
			->asArray()
			->all();

		$sellableIdsByPurchasableId = [];
		foreach ($rows as $row) {
			$sellableIdsByPurchasableId[(int) $row['purchasableId']] = (int) $row['veeqoSellableId'];
		}

		return $sellableIdsByPurchasableId;
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
	 * @return int the number of rows deleted
	 */
	public function deleteByVeeqoProductId(int $veeqoProductId): int
	{
		return SellableMapping::deleteAll([
			'veeqoProductId' => $veeqoProductId,
		]);
	}

	public function deleteByPurchasableId(int $purchasableId): void
	{
		$mapping = $this->findByPurchasableId($purchasableId);
		if ($mapping instanceof SellableMapping) {
			$mapping->delete();
		}
	}
}
