<?php

declare(strict_types=1);

namespace fostercommerce\shipments\veeqo\migrations;

use craft\db\Migration;
use craft\db\Query;
use fostercommerce\shipments\db\Table as ShipmentsTable;
use fostercommerce\shipments\veeqo\db\Table;

class m260725_084338_add_order_push_integration_fk extends Migration
{
	public function safeUp(): bool
	{
		// Claims left behind by a deleted integration would fail the key.
		$this->delete(Table::ORDER_PUSHES, [
			'not in',
			'integrationId',
			(new Query())->select(['[[id]]'])->from(ShipmentsTable::INTEGRATIONS),
		]);

		$this->addForeignKey(
			null,
			Table::ORDER_PUSHES,
			['integrationId'],
			ShipmentsTable::INTEGRATIONS,
			['id'],
			'CASCADE',
		);

		return true;
	}
}
