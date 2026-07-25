<?php

declare(strict_types=1);

namespace fostercommerce\shipments\veeqo\web\assets\cp;

use craft\web\AssetBundle;
use craft\web\assets\cp\CpAsset;

/**
 * Control-panel asset bundle for the plugin settings screen.
 */
class ShipmentsVeeqoCpAsset extends AssetBundle
{
	public function init(): void
	{
		$this->sourcePath = __DIR__ . '/dist';

		$this->depends = [
			CpAsset::class,
		];

		$this->css[] = 'css/shipments-veeqo-cp.css';

		parent::init();
	}
}
