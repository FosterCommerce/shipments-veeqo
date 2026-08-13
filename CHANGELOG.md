# Changelog

## [Unreleased]

### Added

- Veeqo product names, images and item names are kept up to date for products this plugin created. Products it linked to, or that hold more than one Craft product, are left as Veeqo has them.
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
- Fixed an error that could occur when pushing an order containing a product whose variants were linked to more than one Veeqo product.
- Fixed an issue where a Veeqo error raised while syncing an order's products was missing from the shipment's last push attempt.
- Fixed a bug where syncing a product overwrote the name and image of a Veeqo product it shares with other products, so those showed the wrong name and image in Veeqo and in its shipping emails. A Veeqo product holding one Craft product still has its name and image kept current.
- Fixed a bug where syncing a product replaced each of its items' names in Veeqo with a size, leaving shipping emails with nothing identifying what was bought.
- Fixed a bug where syncing a product overwrote its items' SKUs in Veeqo.
- Fixed a bug where a variant added to a product already in Veeqo never reached Veeqo, so it was created as a separate product the first time it was ordered.
- Fixed a bug where syncing a product added its variants to a Veeqo product shared with other products, growing a grouping it did not own.
- Fixed an issue where a product moved to a different Veeqo product stopped syncing until its mapping was corrected by hand.
- Fixed a bug where a Veeqo product created for a sales channel had its name, image and item names overwritten with the Craft product's.

### Removed

- Removed the “Poll lookback (hours)” setting. The poll now covers every order still holding an open shipment, whatever its age.
