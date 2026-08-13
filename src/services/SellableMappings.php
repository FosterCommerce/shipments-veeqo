<?php

declare(strict_types=1);

namespace fostercommerce\shipments\veeqo\services;

use Craft;
use craft\commerce\db\Table as CommerceTable;
use craft\db\Query;
use craft\helpers\DateTimeHelper;
use craft\helpers\Json;
use fostercommerce\shipments\veeqo\db\Table;
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
	/**
	 * @param ?bool $adopted true when linking to a sellable Veeqo already had, or null to leave an
	 *   existing row as it is and treat a new one as created by this plugin
	 * @throws Exception if the record fails to save
	 */
	public function upsert(int $purchasableId, string $sku, int $veeqoSellableId, int $veeqoProductId, ?bool $adopted = null): SellableMapping
	{
		$mapping = $this->findByPurchasableId($purchasableId) ?? new SellableMapping();

		if ($adopted !== null) {
			$mapping->adopted = $adopted;
		} elseif ($mapping->getIsNewRecord()) {
			$mapping->adopted = false;
		}

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
	 * Whether any variant mapped into a Veeqo product was linked to a sellable Veeqo already had,
	 * which makes the product one this plugin did not create.
	 */
	public function hasAdoptedForVeeqoProduct(int $veeqoProductId): bool
	{
		return SellableMapping::find()
			->where([
				'veeqoProductId' => $veeqoProductId,
				'adopted' => true,
			])
			->exists();
	}

	/**
	 * Records every mapping into a Veeqo product as created by this plugin.
	 */
	public function markCreatedForVeeqoProduct(int $veeqoProductId): void
	{
		SellableMapping::updateAll([
			'adopted' => false,
		], [
			'veeqoProductId' => $veeqoProductId,
		]);
	}

	/**
	 * How many Craft products have variants mapped into one Veeqo product. More than one means the
	 * Veeqo product is shared, so its own name and image are not ours to write.
	 */
	public function countCraftProductsForVeeqoProduct(int $veeqoProductId): int
	{
		return (int) (new Query())
			->from([
				'mappings' => Table::SELLABLE_MAPPINGS,
			])
			->innerJoin([
				'variants' => CommerceTable::VARIANTS,
			], '[[variants.id]] = [[mappings.purchasableId]]')
			->where([
				'mappings.veeqoProductId' => $veeqoProductId,
			])
			->count('DISTINCT [[variants.primaryOwnerId]]');
	}

	/**
	 * Forgets a mapping whose SKU no longer matches the purchasable, since the sellable it records
	 * still carries the old one.
	 */
	public function deleteIfSkuChanged(int $purchasableId, string $sku): void
	{
		$mapping = $this->findByPurchasableId($purchasableId);
		if ($mapping instanceof SellableMapping && $mapping->sku !== $sku) {
			$mapping->delete();
		}
	}

	public function deleteByPurchasableId(int $purchasableId): void
	{
		$mapping = $this->findByPurchasableId($purchasableId);
		if ($mapping instanceof SellableMapping) {
			$mapping->delete();
		}
	}
}
