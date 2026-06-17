# Shipments Veeqo installation and configuration

A Veeqo provider for the Foster Commerce Shipments plugin, plus product sync between Craft Commerce and Veeqo.

## Requirements

- Craft CMS `^5.0`
- Craft Commerce `^5.0`
- Foster Commerce Shipments `dev-main` (installed and enabled first)
- PHP `^8.2`
- A running Craft queue worker (product syncs and shipment pushes run as queued jobs)
- A Veeqo account with API access

## Install

```sh
composer require fostercommerce/shipments-veeqo
./craft plugin/install shipments-veeqo
```

The install migration creates one table, `shipmentsveeqo_sellable_mappings`, caching the Commerce-purchasable to Veeqo-sellable id mapping.

## Veeqo account setup

You need two values from Veeqo before configuring the integration.

1. **API key.** Veeqo: **Account -> API Access -> Generate API Key**. Copy it immediately; Veeqo shows it once.
2. **Channel id.** Veeqo: **Settings -> Sales Channels**, open the channel Craft orders should land in. The numeric id is in the URL. Veeqo allocates pushed orders to this channel's default warehouse, so make sure the channel has one set under **Settings -> Sales Channels**.

Smoke-test the key from your terminal:

```sh
curl -i \
  -H "x-api-key: <YOUR_KEY>" \
  -H "x-api-request: true" \
  https://api.veeqo.com/current_company
```

A `200 OK` confirms the key is live.

## Plugin settings

**Settings -> Plugins -> Shipments Veeqo.** These are the only plugin-wide options; Veeqo credentials live on the integration (next section).

- **Sync products to Veeqo**: when on, saving a Commerce product queues a Veeqo sellable sync. Default: on.
- **Product images field**: asset field whose images are sent with the product payload. Default: none.
- **Let Veeqo adjust Commerce inventory**: when on, the stock pull overwrites inventory counts for inventory-tracked variants from Veeqo. Non-tracked variants are never changed. Default: on.
- **Address phone field**: plain-text address field holding the customer phone, sent with orders and customers. Default: none.
- **Auto-push to Veeqo at status**: when a shipment reaches this status, push it to Veeqo automatically. Default: none (push only with the manual button).

## Add the Veeqo integration

Veeqo is a provider on the Shipments plugin, configured per integration.

**Shipments -> Settings -> Integrations -> New.** Choose **Veeqo** as the provider, then fill in:

- **API key**: the key from Veeqo. Supports environment variables (recommended).
- **Channel id**: the Veeqo sales-channel id pushed orders belong to. Allocation follows this channel's default warehouse.
- **Order reference prefix**: optional prefix applied to the reference sent to Veeqo.
- **Notify customer from Veeqo**: whether Veeqo emails the customer when the order ships. Default: off.
- **Poll lookback (hours)**: how far back each poll queries Veeqo for shipped orders. Default: 24.

Save. The integration's connection check validates the API key against `/current_company`.

From here the Shipments plugin drives the lifecycle: shipments push to Veeqo from the **Push to Veeqo** button on a shipment, or from a queue job you trigger on status change. Each pushed order is created with a recorded payment, so it lands at Veeqo's `awaiting_fulfillment` status (ready to ship) rather than `awaiting_payment`.

## Console commands

```sh
./craft shipments-veeqo/connection/test
```

Validates the active Veeqo integration's API key and prints the company name.

```sh
./craft shipments-veeqo/products/sync
```

Queues every Commerce product for a Veeqo sellable sync. Safe to re-run.

```sh
./craft shipments-veeqo/products/reconcile
```

Links Craft variants to products already in Veeqo by exact SKU match, without creating anything. Run this before the first sync against a Veeqo account that already has products. See [Connecting to an existing Veeqo catalog](#connecting-to-an-existing-veeqo-catalog).

```sh
./craft shipments-veeqo/sync/pull
```

Polls Veeqo for shipped orders and writes tracking back onto the matching shipments.

```sh
./craft shipments-veeqo/stock/pull
```

Pulls available stock from Veeqo and writes it onto inventory-tracked Commerce variants. Veeqo is the source of truth; this never writes stock back to Veeqo. No-op when "Let Veeqo adjust Commerce inventory" is off.

## Polling

Veeqo has no webhooks, so inbound tracking arrives only by polling. Point cron at the pull command on whatever interval suits your volume:

```
*/10 * * * * php /path/to/craft shipments-veeqo/sync/pull >> /var/log/veeqo-pull.log 2>&1
*/15 * * * * php /path/to/craft shipments-veeqo/stock/pull >> /var/log/veeqo-stock.log 2>&1
```

The second line keeps Commerce inventory in step with Veeqo (Veeqo is the inventory source of truth, so it dictates stock to Commerce the same way it does to other sales channels).

## Connecting to an existing Veeqo catalog

When Veeqo already holds products, link them to Craft by SKU before syncing so the first sync updates them instead of creating duplicates. Matching is by exact, case-sensitive `sku_code`, the same way Veeqo links listings.

1. Make sure each Craft variant's SKU matches its Veeqo `sku_code` exactly, including case.
2. Run the reconcile command:
   ```sh
   ./craft shipments-veeqo/products/reconcile
   ```
   It links every variant it can match and prints the SKUs it could not. Nothing is created in Veeqo.
3. Fix any unmatched SKUs in Craft or Veeqo and run reconcile again.
4. Run `./craft shipments-veeqo/products/sync` to create the products that genuinely do not exist in Veeqo yet.

`products/sync` also reconciles on its own (it looks a product up by SKU before creating), so this command is the safe, no-create way to preview and confirm the links first. Both paths stay under Veeqo's rate limit: the client paces requests just below 5 per second and retries on a 429.

## Receiving shipped status from Veeqo

Veeqo has no webhooks and the plugin exposes no endpoint for Veeqo to call. Inbound updates are pull only: `shipments-veeqo/sync/pull` asks Veeqo for shipped orders and writes their tracking and status back onto the matching Craft shipment. The match is by the Veeqo order id recorded as the shipment's integration reference when it was pushed.

To test that a Veeqo shipment is captured in Craft:

1. Push a shipment to Veeqo so its Veeqo order id is recorded (the **Push to Veeqo** button on the shipment).
2. In Veeqo, ship that order and enter a **carrier and tracking number**. Without a tracking number the poll skips the order.
3. Run the poll:
   ```sh
   ./craft shipments-veeqo/sync/pull
   ```
4. Open the shipment in Craft and confirm its status is **Shipped**, the tracking number, URL, and carrier are filled in, and the **Status history** tab shows the transition with the integration as the source.

If nothing changes, check the three usual causes: the Veeqo shipment has no tracking number, the Veeqo order falls outside the integration's **Poll lookback (hours)** window, or the shipment was never pushed so it has no Veeqo order id to match on.

## Cancellations

**Veeqo to Craft:** the inbound poll also queries `cancelled` orders. When a pushed Veeqo order is cancelled (in the Veeqo UI), the next `sync/pull` flips the matching Craft shipment to the `Cancelled` status. No tracking is required for this transition.

**Craft to Veeqo:** Veeqo's API has **no way to cancel or delete an order** (`status` is not writable, and there is no cancel or delete endpoint), so a Craft-side cancellation cannot set the Veeqo order to cancelled. Instead, the plugin posts an employee note on the Veeqo order prompting a warehouse user to cancel it manually. A note is queued when:

- a shipment is deleted in Craft,
- an order is deleted,
- an order moves into one of the ignored order statuses, or
- an order is switched to not requiring shipping.

Each note targets the Veeqo order behind the affected shipment; shipments that were never pushed are skipped.

## Logging

All Veeqo communication logs to its own file, `storage/logs/shipments-veeqo-<date>.log` (category `shipments-veeqo`), across web, queue, and console runs. Every non-2xx Veeqo response, transport error, and timeout is recorded, along with poll skips: an order with no matching Craft shipment logs at info level, and an order matched to a shipment but missing a tracking number logs at warning level. Push failures are also stored on the shipment itself (the last attempt error on its Details tab) and surface as failed jobs in the queue.

## Known limitations

- The order push and shipped-order poll follow Veeqo's documented model but have not been verified against a live account. Test against a real Veeqo account before relying on them.
- The product payload's nested `sellables_attributes` shape is inferred from Veeqo's Rails nested-attributes pattern.
- Stock quantities are not sent with sellable writes; Veeqo tracks stock in per-warehouse `stock_entries`.
- Product dimensions (length, width, height) are not sent. Veeqo's API exposes `width`, `height`, and `depth` on read but does not accept them on product create or update, so dimensions must be set in Veeqo directly or via its CSV product import. Weight is sent, converted to grams from the store's configured weight unit.
- Weight only applies when a product is first created in Veeqo. Veeqo's update endpoint ignores `weight_grams`, so re-syncing an already-synced product refreshes its title, price, and images but not its weight. To correct the weight of an existing product, set it in Veeqo directly.
- Veeqo has no idempotency keys. A stored integration reference prevents re-pushing a shipment, but a 504 that partially persists can still leave a duplicate order.
- Variants without a SKU are skipped by product sync.
