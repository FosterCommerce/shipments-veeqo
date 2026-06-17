<?php

declare(strict_types=1);

namespace fostercommerce\shipmentsveeqo\migrations;

use craft\db\Migration;
use fostercommerce\shipmentsveeqo\db\Table;

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

		return true;
	}

	public function safeDown(): bool
	{
		$this->dropTableIfExists(Table::SELLABLE_MAPPINGS);
		return true;
	}
}
