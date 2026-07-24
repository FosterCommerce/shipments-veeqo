<?php

declare(strict_types=1);

namespace fostercommerce\shipments\veeqo\migrations;

use craft\db\Migration;
use fostercommerce\shipments\veeqo\db\Table;

class Install extends Migration
{
	public function safeUp(): bool
	{
		$this->archiveTableIfExists(Table::SELLABLE_MAPPINGS);

		$this->createTable(Table::SELLABLE_MAPPINGS, [
			'id' => $this->primaryKey(),
			'purchasableId' => $this->integer()->notNull(),
			'sku' => $this->string()->notNull(),
			'veeqoSellableId' => $this->integer()->notNull(),
			'veeqoProductId' => $this->integer()->notNull(),
			'lastSyncedAt' => $this->dateTime(),
			'dateCreated' => $this->dateTime()->notNull(),
			'dateUpdated' => $this->dateTime()->notNull(),
			'uid' => $this->uid(),
		]);

		$this->createIndex(null, Table::SELLABLE_MAPPINGS, ['purchasableId'], true);
		$this->createIndex(null, Table::SELLABLE_MAPPINGS, ['sku']);

		$this->addForeignKey(
			null,
			Table::SELLABLE_MAPPINGS,
			['purchasableId'],
			'{{%commerce_purchasables}}',
			['id'],
			'CASCADE',
		);

		$this->archiveTableIfExists(Table::ORDER_PUSHES);

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

	public function safeDown(): bool
	{
		$this->dropTableIfExists(Table::ORDER_PUSHES);
		$this->dropTableIfExists(Table::SELLABLE_MAPPINGS);
		return true;
	}
}
