# Customize the Veeqo product payload

How to change the product and sellable data sent to Veeqo before it leaves Craft. Audience: developers working from a site module or companion plugin.

## The contract

`ProductSync` triggers `ProductSync::EVENT_BEFORE_SEND_PAYLOAD` after it builds the payload and before it calls the Veeqo API. The event is a `fostercommerce\shipments\veeqo\events\ProductPayloadEvent`:

| Property  | Type                    | Notes                                             |
|-----------|-------------------------|---------------------------------------------------|
| `product` | `craft\commerce\elements\Product` | The Commerce product being synced. Read it; do not reassign it. |
| `payload` | `array<string, mixed>`  | The outgoing payload. Reassign it to change what is sent. |

The payload holds `product_variants_attributes`, one entry per SKU-bearing variant. Whatever `payload` contains when the listener returns is what gets POSTed or PUT to Veeqo.

## Veeqo products this plugin did not create

Names, images and contents are only written to a Veeqo product the plugin created and still holds alone. Two things disqualify one:

- **It holds variants from more than one Craft product.** Counted from the mapping table at build time.
- **A variant in it was adopted**, meaning the plugin linked to a sellable Veeqo already had rather than creating it. Recorded on the mapping when the link is made.

Either way the product was built for something other than this Craft product, and its name describes whatever that was.

| Sent | On a product the plugin created | On any other |
|------|---------------------------------|--------------|
| Product `title` and `images_attributes` | yes | no |
| Variant `title` | yes | no |
| Variant `price` and `weight_grams` | yes | yes |
| Variant `sku_code` | on create only | on create only |
| Variants not yet in Veeqo | added | not added |

`images_attributes` is only present when the **Product images field** setting is set and the product has an image.

Existing mappings from before this was recorded start adopted, and a queued job clears the flag for products with no sales channel listing. A product listed on a channel keeps it, since the channel shows that product's own name.

## Variant entries

A variant already mapped to this Veeqo product carries its `id`. One that isn't carries `sku_code` instead, and Veeqo creates it.

A variant mapped to a *different* Veeqo product is left out entirely, since sending it would add a second copy alongside the one it already has. Sending an `id` from another Veeqo product makes Veeqo reject the whole request with a 404.

A variant whose SKU changes in Craft has its mapping dropped on the next sync, so it links to the variant carrying its new SKU, or is added as a new one. An existing Veeqo variant's `sku_code` is never rewritten, because SKU is what links the two systems when an id is not available, and Veeqo's own sales channels match on it too.

## When Veeqo regroups

If a variant is moved to another product inside Veeqo, its sellable id stays the same but the stored parent goes stale, and the update comes back 404. The sync then re-reads each mapped variant from Veeqo, records the product it now belongs to, and retries once. Variants Veeqo reports as deleted have their mappings dropped.

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
