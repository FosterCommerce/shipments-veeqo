# Shipments Veeqo installation and configuration

A Veeqo provider for the Foster Commerce Shipments plugin, plus product sync between Craft Commerce and Veeqo.

## Requirements

- Craft CMS `^5.0`
- Craft Commerce `^5.0`
- Foster Commerce Shipments `^1.0` (installed and enabled first)
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

## Known limitations

- The order push and shipped-order poll follow Veeqo's documented model but have not been verified against a live account. Test against a real Veeqo account before relying on them.
- The product payload's nested `sellables_attributes` shape is inferred from Veeqo's Rails nested-attributes pattern.
- Stock quantities are not sent with sellable writes; Veeqo tracks stock in per-warehouse `stock_entries`.
- Veeqo has no idempotency keys. A stored integration reference prevents re-pushing a shipment, but a 504 that partially persists can still leave a duplicate order.
- Variants without a SKU are skipped by product sync.
