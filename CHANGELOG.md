# Changelog

## [Unreleased]

### Added

- Veeqo provider for the Shipments plugin, with order push, allocation mirroring, product sync, and stock pull.

### Fixed

- Fixed a bug where an order shipped in Veeqo a day or more after it was raised was never picked up by the poll.
- Fixed a bug where an order cancelled in Veeqo left its Craft shipments open.
- Fixed an issue where an order shipped in Veeqo without a tracking number left its Craft shipment at its previous status.
- Fixed a bug where an order that hit a Veeqo rate limit or server error during a push could never be pushed again, and the retry reported success.
- Fixed a bug where a cancellation note posted to Veeqo named the wrong reason for an order taken out of fulfillment.
- Fixed an issue where an order that stopped requiring shipping did not post a cancellation note to Veeqo.
- Fixed an issue where push failures shown on a shipment were not translatable.

### Removed

- Removed the “Poll lookback (hours)” setting. The poll now covers every order still holding an open shipment, whatever its age.
