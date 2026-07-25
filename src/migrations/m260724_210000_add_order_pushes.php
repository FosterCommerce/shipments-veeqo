<?php

declare(strict_types=1);

namespace fostercommerce\shipments\veeqo\migrations;

use craft\db\Migration;
use fostercommerce\shipments\veeqo\db\Table;

class m260724_210000_add_order_pushes extends Migration
{
	public function safeUp(): bool
	{
		$this->createTable(Table::ORDER_PUSHES, [
			'id' => $this->primaryKey(),
			'orderId' => $this->integer()->notNull(),
			'integrationId' => $this->integer()->notNull(),
			'veeqoOrderNumber' => $this->string()->notNull(),
			'veeqoOrderId' => $this->integer(),
			'dateCreated' => $this->dateTime()->notNull(),
			'dateUpdated' => $this->dateTime()->notNull(),
			'uid' => $this->uid(),
		]);

		$this->createIndex(null, Table::ORDER_PUSHES, ['orderId', 'integrationId'], true);

		$this->addForeignKey(
			null,
			Table::ORDER_PUSHES,
			['orderId'],
			'{{%commerce_orders}}',
			['id'],
			'CASCADE',
		);

		return true;
	}
}
