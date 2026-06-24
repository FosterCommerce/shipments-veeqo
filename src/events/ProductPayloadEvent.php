<?php

declare(strict_types=1);

namespace fostercommerce\shipments\veeqo\events;

use craft\commerce\elements\Product;
use yii\base\Event;

/**
 * Fired by ProductSync before the payload is sent to Veeqo, so integrators can
 * mutate the array (e.g. add custom fields, images, tags).
 */
class ProductPayloadEvent extends Event
{
	public Product $product;

	/**
	 * @var array<string, mixed>
	 */
	public array $payload = [];
}
