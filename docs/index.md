# Shipments Veeqo documentation

A Veeqo provider for the Foster Commerce Shipments plugin, plus product sync between Craft Commerce and Veeqo.

## Where to go

**Setting up?** Start with [Installation](./installation.md), which covers requirements, the Veeqo account values you need, plugin settings, and adding the integration.

**Running the plugin day-to-day?**

- [Working with Veeqo day to day](./user-guide/day-to-day.md), how orders reach Veeqo, how tracking comes back, and what to do when a push fails
- [Installation](./installation.md), the console commands and an example crontab for the tracking and stock pulls

**Building on top of the plugin?**

- [Custom product payloads](./dev-guide/custom-product-payload.md), mutate the product data sent to Veeqo before it leaves Craft
- [Allocation reconciliation](./roadmap.md), how Craft shipments and Veeqo allocations are kept in step in both directions
