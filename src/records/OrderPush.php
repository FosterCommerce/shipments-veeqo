<?php

declare(strict_types=1);

namespace fostercommerce\shipments\veeqo\records;

use craft\db\ActiveRecord;
use fostercommerce\shipments\veeqo\db\Table;

/**
 * Claim on pushing a Commerce order to Veeqo.
 *
 * @property int $id
 * @property int $orderId
 * @property int $integrationId
 * @property string $veeqoOrderNumber
 * @property ?int $veeqoOrderId
 * @property string $dateCreated
 * @property string $dateUpdated
 * @property string $uid
 */
class OrderPush extends ActiveRecord
{
	public static function tableName(): string
	{
		return Table::ORDER_PUSHES;
	}
}
