<?php

declare(strict_types=1);

namespace fostercommerce\shipmentsveeqo\records;

use craft\db\ActiveRecord;
use fostercommerce\shipmentsveeqo\db\Table;

/**
 * Persisted mapping between a Craft Commerce purchasable and a Veeqo sellable.
 *
 * @property int $id
 * @property int $purchasableId
 * @property string $sku
 * @property int $veeqoSellableId
 * @property int $veeqoProductId
 * @property ?string $lastSyncedAt
 * @property string $dateCreated
 * @property string $dateUpdated
 * @property string $uid
 */
class SellableMapping extends ActiveRecord
{
	public static function tableName(): string
	{
		return Table::SELLABLE_MAPPINGS;
	}
}
