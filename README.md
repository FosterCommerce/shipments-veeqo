# Shipments Veeqo

A **Veeqo provider** for the Foster Commerce Shipments plugin, plus product sync between Craft Commerce and Veeqo.

## What it does

- Sends completed orders to Veeqo for your warehouse to pick and pack, from a button in the control panel or automatically at a status you choose.
- Brings carrier and tracking details back into Craft on a schedule, so customers and staff see them without anyone retyping them.
- Follows Veeqo when it splits an order across warehouses or ships it in stages, showing one Craft shipment per parcel with its own tracking.
- Keeps your Veeqo catalogue in step with Commerce, creating or updating a Veeqo product whenever you save one in Craft.
- Lets Veeqo drive stock levels in Commerce, so the two never disagree about what is available to sell.

## Requirements

- Craft CMS `^5.0`
- Craft Commerce `^5.3`
- [Foster Commerce Shipments](https://github.com/fostercommerce/shipments) `dev-main`
- PHP `^8.2`
- A Veeqo account and API key

## Install

```sh
composer require fostercommerce/shipments-veeqo
./craft plugin/install shipments-veeqo
```

See [`docs/installation.md`](./docs/installation.md) for the full guide, including Veeqo account setup and how to add the integration.

## Veeqo integration

Veeqo registers as a provider on the Shipments plugin. You add it under **Shipments -> Settings -> Integrations -> New**, choosing Veeqo as the provider and entering your API key and channel id. From there the Shipments plugin owns the shipment lifecycle (status, history, emails); this plugin only talks to the Veeqo API.

## Product sync

When a Commerce product is saved, the plugin queues a job that creates or updates the matching Veeqo product and its sellables, keyed by SKU. Variants without a SKU are skipped. You can mutate the outgoing payload from your own code before it is sent. See [custom product payloads](./docs/dev-guide/custom-product-payload.md).

## Polling

Veeqo does not offer webhooks, so inbound tracking arrives by polling. You run the pull command on a cron schedule. See [`docs/installation.md`](./docs/installation.md) for the commands and example crontab, and [working with Veeqo day to day](./docs/user-guide/day-to-day.md) for what a store admin sees.

## Stock sync

Veeqo is built to own inventory and dictate stock to its sales channels. This plugin treats Commerce as one of those channels: `shipments-veeqo/stock/pull` reads Veeqo's available stock and writes it onto inventory-tracked Commerce variants. Non-tracked variants are left untouched. Turn it off with the "Let Veeqo adjust Commerce inventory" setting.

## License

Proprietary.
