<?php

declare(strict_types=1);

namespace fostercommerce\shipments\veeqo\migrations;

use craft\db\Migration;
use fostercommerce\shipments\veeqo\db\Table;
use yii\db\Schema;

class m260924_090000_widen_order_push_veeqo_order_id extends Migration
{
	public function safeUp(): bool
	{
		// Veeqo order ids exceed the signed 32-bit range.
		$this->alterColumn(Table::ORDER_PUSHES, 'veeqoOrderId', Schema::TYPE_BIGINT);

		return true;
	}
}
