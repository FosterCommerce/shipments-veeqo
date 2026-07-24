<?php

declare(strict_types=1);

namespace fostercommerce\shipments\veeqo\records;

use craft\db\ActiveRecord;
use fostercommerce\shipments\veeqo\db\Table;

/**
 * Claim on pushing a Commerce order to Veeqo. Veeqo accepts duplicate order numbers and offers no
 * idempotency key, and its number lookup reads a search index that lags a create by seconds, so the
 * unique (orderId, integrationId) index is what stops a second push.
 *
 * A null `veeqoOrderId` means the claim was written but the create never reported back: either it is
 * in flight, or the process died mid-push. Either way the order will not push again until the row is
 * cleared.
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
