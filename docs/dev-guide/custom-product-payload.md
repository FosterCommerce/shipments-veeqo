# Customize the Veeqo product payload

How to change the product and sellable data sent to Veeqo before it leaves Craft. Audience: developers working from a site module or companion plugin.

## The contract

`ProductSync` triggers `ProductSync::EVENT_BEFORE_SEND_PAYLOAD` after it builds the payload and before it calls the Veeqo API. The event is a `fostercommerce\shipments\veeqo\events\ProductPayloadEvent`:

| Property  | Type                    | Notes                                             |
|-----------|-------------------------|---------------------------------------------------|
| `product` | `craft\commerce\elements\Product` | The Commerce product being synced. Read it; do not reassign it. |
| `payload` | `array<string, mixed>`  | The outgoing payload. Reassign it to change what is sent. |

The payload holds `sellables_attributes`, one entry per SKU-bearing variant. Whatever `payload` contains when the listener returns is what gets POSTed or PUT to Veeqo.

`title`, and `images_attributes` when the **Product images field** setting is set and the product has an image, are sent only when the product is being created in Veeqo. One Veeqo product can hold sellables belonging to several Craft products, so sending either on an update applies it to all of them.

A sellable entry carries an `id` only when that variant is already mapped to the Veeqo product being updated. Adding an `id` from any other Veeqo product makes Veeqo reject the whole request with a 404.

## Minimal example

```php
<?php

declare(strict_types=1);

namespace modules\veeqo;

use craft\base\Module;
use fostercommerce\shipments\veeqo\events\ProductPayloadEvent;
use fostercommerce\shipments\veeqo\services\ProductSync;
use yii\base\Event;

class VeeqoModule extends Module
{
    public function init(): void
    {
        parent::init();

        Event::on(
            ProductSync::class,
            ProductSync::EVENT_BEFORE_SEND_PAYLOAD,
            static function (ProductPayloadEvent $event): void {
                $event->payload['tags'] = ['craft'];
            }
        );
    }
}
```

## Register it

The listener lives in your module's `init()`, as shown above. Load the module from `config/app.php` so it boots on every request and queue job:

```php
<?php

return [
    'modules' => [
        'veeqo' => \modules\veeqo\VeeqoModule::class,
    ],
    'bootstrap' => ['veeqo'],
];
```

## Testing your change

1. Save a Commerce product with at least one SKU-bearing variant.
2. Run the queue (or `./craft queue/run`) so the sync job executes.
3. Confirm the product in Veeqo carries your change.
