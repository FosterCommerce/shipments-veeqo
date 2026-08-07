# Changelog

## [Unreleased]

### Added

- Veeqo provider for the Shipments plugin, with order push, allocation mirroring, product sync, and stock pull.
- The provider's channel id now supports environment variables, so each environment can push to its own Veeqo channel from shared project config.

### Fixed

- Fixed a bug where splitting or editing an order's shipments in Craft never reached Veeqo.
- Fixed a bug where editing a shipment's line items in Craft stopped that order receiving tracking and shipped status from Veeqo.
- Fixed a bug where Veeqo merging an order's parcels made Craft ask for that order to be cancelled.
- Fixed a bug where deleting one of an order's shipments asked Veeqo to cancel the whole order.
- Fixed a bug where “Push to Veeqo” did nothing on an order already sent to Veeqo.
- Fixed a bug where every order containing the same custom line item added another product to Veeqo.
- Fixed an issue where an order could stop syncing to Veeqo after a connection failure during its push.
- Fixed a bug where an order shipped in Veeqo a day or more after it was raised was never picked up by the poll.
- Fixed a bug where an order cancelled in Veeqo left its Craft shipments open.
- Fixed an issue where an order shipped in Veeqo without a tracking number left its Craft shipment at its previous status.
- Fixed a bug where an order that hit a Veeqo rate limit or server error during a push could never be pushed again, and the retry reported success.
- Fixed a bug where a cancellation note posted to Veeqo named the wrong reason for an order taken out of fulfillment.
- Fixed an issue where an order that stopped requiring shipping did not post a cancellation note to Veeqo.
- Fixed an issue where push failures shown on a shipment were not translatable.
- Fixed a bug where a variant whose SKU carried leading or trailing spaces never linked to its Veeqo sellable, blocking every order containing it.
- Fixed a bug where a variant added to a product already in Veeqo blocked its whole order from reaching Veeqo.

### Removed

- Removed the “Poll lookback (hours)” setting. The poll now covers every order still holding an open shipment, whatever its age.
