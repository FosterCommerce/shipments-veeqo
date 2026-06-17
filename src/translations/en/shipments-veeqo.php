<?php

declare(strict_types=1);

return [
	// Plugin settings page
	'settings.connectionNotice' => 'Veeqo credentials and shipment-push options are configured per integration under Shipments → Settings → Integrations. These options are plugin-wide.',
	'settings.syncProductsLabel' => 'Sync products to Veeqo',
	'settings.syncProductsInstructions' => 'When on, saving a Commerce product enqueues a Veeqo sellable sync using the active Veeqo integration.',
	'settings.productImagesLabel' => 'Product images field',
	'settings.productImagesInstructions' => 'Asset field whose images are sent with the product payload. Leave empty to skip images.',
	'settings.syncStockLabel' => 'Let Veeqo adjust Commerce inventory',
	'settings.syncStockInstructions' => 'When on, the Veeqo stock pull overwrites inventory counts for inventory-tracked variants. Non-tracked variants are never changed.',

	// Provider settings (integration edit page)
	'provider.apiKeyLabel' => 'API key',
	'provider.apiKeyInstructions' => 'Your Veeqo API key from Account → API Access. Supports environment variables.',
	'provider.channelIdLabel' => 'Channel ID',
	'provider.channelIdInstructions' => 'The Veeqo sales-channel ID that pushed orders belong to. Veeqo allocates orders to this channel’s default warehouse.',
	'provider.orderIdPrefixLabel' => 'Order reference prefix',
	'provider.orderIdPrefixInstructions' => 'Optional prefix applied to the reference sent to Veeqo.',
	'provider.notifyCustomerLabel' => 'Notify customer from Veeqo',
	'provider.notifyCustomerInstructions' => 'Whether Veeqo emails the customer when the order ships.',
	'provider.pollLookbackLabel' => 'Poll lookback (hours)',
	'provider.pollLookbackInstructions' => 'How far back each poll queries Veeqo for shipped orders.',

	// Queue jobs
	'job.syncProduct' => 'Syncing product {id} to Veeqo',
];
