<?php

declare(strict_types=1);

namespace fostercommerce\shipments\veeqo\migrations;

use Craft;
use craft\db\Migration;
use fostercommerce\shipments\veeqo\db\Table;
use fostercommerce\shipments\veeqo\jobs\ClassifyAdoptedMappingsJob;

class m260813_013940_add_adopted_to_sellable_mappings extends Migration
{
	public function safeUp(): bool
	{
		// Existing rows start adopted so a Veeqo product built elsewhere keeps its name until the
		// job has established which products the plugin created itself. Cast because `addColumn()`
		// is annotated as taking a string, though it accepts a builder.
		$this->addColumn(Table::SELLABLE_MAPPINGS, 'adopted', (string) $this->boolean()->notNull()->defaultValue(true)->after('veeqoProductId'));

		Craft::$app->getQueue()->push(new ClassifyAdoptedMappingsJob());

		return true;
	}
}
