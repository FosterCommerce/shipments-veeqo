# Shipments Veeqo installation and configuration

A Veeqo provider for the Foster Commerce Shipments plugin, plus product sync between Craft Commerce and Veeqo.

## Requirements

- Craft CMS `^5.0`
- Craft Commerce `^5.3`
- Foster Commerce Shipments `dev-main` (installed and enabled first)
- PHP `^8.2`
- A running Craft queue worker (product syncs and shipment pushes run as queued jobs)
- A Veeqo account with API access

## Install

```sh
composer require fostercommerce/shipments-veeqo
./craft plugin/install shipments-veeqo
```

The install migration creates two tables:

| Table | Holds |
|-------|-------|
| `shipmentsveeqo_sellable_mappings` | Commerce-purchasable to Veeqo-sellable id mapping |
| `shipmentsveeqo_order_pushes`      | One row per pushed order, so an order is never pushed to Veeqo twice |

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
- **Channel id**: the Veeqo sales-channel id pushed orders belong to. Allocation follows this channel's default warehouse. Supports environment variables (recommended), since project config is shared across environments but the channel is not.
- **Order reference prefix**: optional prefix applied to the reference sent to Veeqo.
- **Notify customer from Veeqo**: whether Veeqo emails the customer when the order ships. Default: off.

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
./craft shipments-veeqo/products/classify
```

Re-checks which Veeqo products were built for a sales channel, so the sync knows which ones it may rename. Run it when products have been listed on or removed from a channel since the plugin was installed. Safe to re-run; a product already recognised as the plugin's stays that way.

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
   It links every variant it can match and prints the SKUs it could not. Nothing is created in Veeqo. SKUs shorter than 3 characters are always listed as unmatched, since Veeqo's search cannot look them up.
3. Fix any unmatched SKUs in Craft or Veeqo and run reconcile again.
4. Run `./craft shipments-veeqo/products/sync` to create the products that do not exist in Veeqo yet.

`products/sync` also reconciles on its own (it looks a product up by SKU before creating). Reconcile creates nothing, so it lets you preview and confirm the links first. Both paths stay under Veeqo's rate limit: the client paces requests just below 5 per second and retries on a 429.

## Receiving shipped status from Veeqo

Veeqo has no webhooks and the plugin exposes no endpoint for Veeqo to call. Inbound updates are pull only, through `shipments-veeqo/sync/pull`.

Craft pushes a whole order to Veeqo as one Veeqo order. Veeqo then splits it into one or more **allocations**, one per parcel it intends to ship, and each allocation carries its own line items and tracking. Craft keeps one shipment per allocation, and either side can change that split.

Each run asks Veeqo about the orders Craft still counts as unfinished: any order holding a shipment at **New**, **In progress**, or **On hold**. There is no date window, so an order picked up a year after it was raised is still reconciled. An order whose shipments have all reached **Fulfilled**, **Shipped**, or **Cancelled** is no longer queried, including when someone set that status by hand in Craft.

Each order it fetches is reconciled against its allocations:

- An allocation with no matching Craft shipment creates one.
- An allocation whose shipment already exists has its line items resized to match.
- A shipment whose allocation no longer exists is trashed, but only while it is still **New**. A shipment that already shipped is kept and logged.
- Each shipment then takes its own allocation's tracking. A shipment with no tracking number still moves to **Shipped** once Veeqo reports the order as shipped, so a parcel sent without a label is not left open.

Editing an order's shipments in Craft sends the new split to Veeqo, adding, resizing, and removing allocations to match. Whichever side changed the split last is the one that stands: a poll leaves Craft's shipments alone while an edit is still on its way to Veeqo. Allocations Veeqo has already shipped are not restructured from either side.

To test that a Veeqo shipment is captured in Craft:

1. Push an order to Veeqo (the **Push to Veeqo** button on a shipment).
2. In Veeqo, ship that order and enter a **carrier and tracking number**.
3. Run the poll:
   ```sh
   ./craft shipments-veeqo/sync/pull
   ```
4. Open the order's Shipments tab in Craft and confirm there is one shipment per Veeqo allocation, each **Shipped** with its own tracking number, URL, and carrier, and that the **Status history** tab shows the transition with the integration as the source.

If nothing changes, check the usual causes: every Craft shipment on the order already sits at a terminal status, so the order is no longer polled, or its number does not resolve to a Craft order reference (a wrong or missing **Order reference prefix**, or an order raised directly in Veeqo).

## Cancellations

**Veeqo to Craft:** when a pushed Veeqo order is cancelled (in the Veeqo UI), the next `sync/pull` flips the order's open Craft shipments to the `Cancelled` status. Veeqo drops a cancelled order's allocations, so this runs off the order's status rather than its parcels. Shipments already at a terminal status are left alone.

**Craft to Veeqo:** Veeqo's API has **no way to cancel or delete an order** (`status` is not writable, and there is no cancel or delete endpoint), so a Craft-side cancellation cannot set the Veeqo order to cancelled. Instead, the plugin posts an employee note on the Veeqo order prompting a warehouse user to cancel it manually. A note is queued when:

- an order's last remaining shipment is deleted in Craft,
- an order is deleted,
- an order is ignored, either by an admin or by moving into one of the ignored order statuses, or
- an order stops requiring shipping.

Each note names the reason and targets the Veeqo order matching the Craft order's number. An order that was never pushed has no Veeqo order to find, so nothing is posted.

## Logging

All Veeqo communication logs to its own file, `storage/logs/shipments-veeqo-<date>.log` (category `shipments-veeqo`), across web, queue, and console runs. Every non-2xx Veeqo response and transport error is recorded, as are these reconcile outcomes:

| Logged | Level |
|---|---|
| An allocation whose line items match nothing on the Craft order, so no shipment is written for it | Warning |
| An allocation that could not be mirrored (the shipment save failed) | Error |
| A shipment whose allocation is gone but which has already progressed past **New**, so it is kept | Warning |
| A push skipped because the order was already pushed | Info |

A Veeqo order whose number does not resolve to a Craft order is skipped silently, since every store has orders that did not come from Craft.

Push failures are also stored on the shipment itself (the last attempt error on its Details tab) and surface as failed jobs in the queue.

## Known limitations

- The order push and shipped-order poll follow Veeqo's documented model but have not been verified against a live account. Test against a real Veeqo account before relying on them.
- The product payload's nested `sellables_attributes` shape is inferred from Veeqo's Rails nested-attributes pattern.
- Stock quantities are not sent with sellable writes; Veeqo tracks stock in per-warehouse `stock_entries`.
- Product dimensions (length, width, height) are not sent. Veeqo's API exposes `width`, `height`, and `depth` on read but does not accept them on product create or update, so dimensions must be set in Veeqo directly or via its CSV product import. Weight is sent, converted to grams from the store's configured weight unit.
- Weight only applies when a product is first created in Veeqo. Veeqo's update endpoint ignores `weight_grams`, so re-syncing an already-synced product refreshes its title, price, and images but not its weight. To correct the weight of an existing product, set it in Veeqo directly.
- Veeqo accepts duplicate order numbers, so the plugin records a claim in `shipmentsveeqo_order_pushes` before pushing. If Veeqo answers with an error the claim is dropped, so a retry or a manual re-push goes through. If the request times out with no answer at all, the claim stands (Veeqo may have created the order), and that order will not push again until you delete its row.
- Variants without a SKU are skipped by product sync.
- Veeqo's product search needs at least 3 characters, so a variant whose SKU is 1 or 2 characters cannot be linked to an existing Veeqo product. Reconcile lists it as unmatched and the next sync creates it fresh, which duplicates it if Veeqo already holds that SKU.
