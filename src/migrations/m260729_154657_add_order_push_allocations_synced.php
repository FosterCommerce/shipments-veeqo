<?php

declare(strict_types=1);

namespace fostercommerce\shipments\veeqo\migrations;

use craft\db\Migration;
use fostercommerce\shipments\veeqo\db\Table;
use yii\db\Expression;
use yii\db\Schema;

class m260729_154657_add_order_push_allocations_synced extends Migration
{
	public function safeUp(): bool
	{
		$this->addColumn(Table::ORDER_PUSHES, 'dateAllocationsSynced', Schema::TYPE_DATETIME);

		// Existing rows had no Craft-side split to send, so the push is their last sync point.
		$this->update(Table::ORDER_PUSHES, [
			'dateAllocationsSynced' => new Expression('[[dateUpdated]]'),
		]);

		return true;
	}
}
