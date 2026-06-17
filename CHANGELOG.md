# Changelog

## [Unreleased]

### Added

- Veeqo registers as a provider on the Foster Commerce Shipments plugin via `Integrations::EVENT_REGISTER_INTEGRATIONS`.
- `VeeqoProvider` implements `sendShipment()` (pushes a shipment to Veeqo as an order) and `pull()` (polls Veeqo for shipped orders and writes tracking back through `Shipments::applyUpdate`).
- `OrderSync`, `CustomerResolver`, and `ShipmentPoller` services backing the push and poll paths.
- Console command `shipments-veeqo/sync/pull` for the inbound poll.
- `VeeqoApi` now supports paginated list reads via the `X-Total-Pages-Count` header.
- Pushed orders are created with a recorded payment so they land at Veeqo `awaiting_fulfillment` (ready to ship) instead of `awaiting_payment`.
- Stock sync from Veeqo to Commerce: `StockSync` + `shipments-veeqo/stock/pull` write Veeqo available stock onto inventory-tracked variants (Veeqo is the source of truth). Non-tracked variants are skipped. Gated by the `syncStock` setting (default on).

### Changed

- Renamed the package to `fostercommerce/shipments-veeqo` (handle `shipments-veeqo`, namespace `fostercommerce\shipmentsveeqo`).
- Veeqo credentials and push options (API key, channel id, warehouse id, customer notification, poll lookback) now live on the per-integration `VeeqoProvider`, not on plugin settings.

### Removed

- Plugin-level shipment-lifecycle settings (poll interval, shipped status handle, Matrix-field writeback handles, validation toggle). The Shipments plugin owns shipment status, history, and fulfillment fields.
- The web `SettingsController` connection-test endpoint. Connection checks run from the integration edit page and `shipments-veeqo/connection/test`.
- The provider `warehouseId` setting. Veeqo allocates pushed orders to the sales channel's default warehouse; a fresh order has no allocation to assign at create time.
