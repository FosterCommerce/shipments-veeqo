# Allocation reconciliation (model C)

Internal design notes. Status: built and validated end to end on the test account.

The push sends the whole Commerce order to Veeqo as one Veeqo order. The poll reconciles each order's Craft shipments to its Veeqo allocations: when Veeqo splits an order into several allocations (multi-warehouse routing or partial fulfilment), Craft mirrors them as several shipments, each with its own tracking.

## Model

- Commerce Order maps to one Veeqo Order. Pushed once.
- Commerce Shipment maps to one Veeqo Allocation. Each allocation is one parcel with its own line items and tracking.
- Source of truth for the split, after push, is Veeqo. The poll reconciles Craft shipments to mirror Veeqo's allocations.

## Identity and references

The reference table holds one external id per (shipment, integration), so a shipment cannot carry both an order id and an allocation id. The model that fits the storage:

- Each Craft shipment stores `alloc:{allocationId}` (its Veeqo allocation). See `helpers/VeeqoReference`.
- The Veeqo order links to the Craft order through the Veeqo order `number` (`orderIdPrefix . order.reference`), not a stored reference. The poll parses the number back to the Craft order reference (`Order::find()->reference()`).
- Push dedup reads `shipmentsveeqo_order_pushes`. The row is written before the create; its unique `(orderId, integrationId)` index rejects the second push.
- Cancellation resolves the Veeqo order by `GET /orders?query={number}` (`VeeqoApi::getOrderIdByNumber`).

The claim table ships in `Install.php` and in `m260724_210000_add_order_pushes`; its integration foreign key was added in `m260725_084338_add_order_push_integration_fk`.

## Reverse line item map

Veeqo `allocation.line_items[]` resolves to a Craft order line item by two paths. Confirmed against the live payload: the top-level `sellable_id` is null and the SKU lives at `line_items[].sellable.sku_code`, so the SKU path carries the real work.

- Purchasable items: `sellable.id` or `sellable_id` -> `SellableMappings::findByVeeqoSellableId` -> `purchasableId` -> the order's `LineItem` -> `lineItemId` + qty.
- Items by SKU: the Veeqo sellable `sku_code` matches the Craft line item's SKU. Where a custom line item has no SKU, the push uses a synthetic `custom-{lineItemId}` code (`ProductSync::CUSTOM_SKU_PREFIX`), so the `lineItemId` is recoverable from the code.

## Poll

The poll set comes from Craft, not from a window over Veeqo's dates: the distinct `veeqoOrderId`s in `shipmentsveeqo_order_pushes` whose order still holds a shipment at `new`, `in_progress`, or `on_hold`. Those ids are fetched in chunks of 100 through `order_ids[]`, which unlike an unfiltered list also returns cancelled orders. Foreign orders are skipped cheaply when the number does not resolve to a Craft order.

The order's rollup status sits at its least-progressed allocation, so a shipped allocation hides under any pre-shipped status (`awaiting_stock`, `awaiting_fulfillment`, and so on). Tracking on the allocation is the per-allocation shipped signal, since allocations carry no status of their own; a `shipped` rollup covers the case where a parcel went out without a label.

This also means pre-ship mirroring: Craft reflects Veeqo's allocation split as soon as it is allocated, before anything ships.

## Reconcile algorithm (per Veeqo order, each poll)

1. Resolve the Craft order by parsing the Veeqo order `number`. Skip if it does not resolve (a foreign order).
2. Skip if the order has zero allocations: never reconcile off an empty set, since a momentary zero-allocation read would orphan every shipment.
3. Load the order's Craft shipments via `Shipments::findByOrderId`. Index them by their `alloc:` reference; ones with no allocation ref yet (a fresh push) are adoptable.
4. For each Veeqo allocation, build a `[lineItemId => qty]` group via the reverse map (skip and log if nothing maps), then:
   - Matched (allocation already has a shipment): `saveLineItems` to match.
   - Adoptable (a fresh shipment with no alloc ref): resize it to this allocation and tag it.
   - New (no shipment, none adoptable): `createFromAllocations(order, [group])`, then tag it `alloc:{id}`.
5. Apply tracking per allocation: an allocation is shipped when it carries a `shipment.tracking_number`, independent of the order rollup status. Cancelled order: mark the shipment Cancelled. Otherwise, if the allocation has tracking, mark Shipped with it; if not, leave the shipment open.
6. Delete orphans: a shipment whose `alloc:` id is no longer in the allocation set, only when it is still `New`. A Shipped (or otherwise progressed) orphan is kept and logged, never destroyed. Deletion is a soft delete (trash).

## Invariants

- Coverage can be legitimately partial. A backordered order ships what it has and leaves the rest unallocated, so the order's shipments need not cover every line item. `enforceCoverage` must be off for the store (it blocks the status write otherwise; see Decisions).
- Idempotent: re-running against an unchanged allocation set is a no-op.
- One Veeqo allocation maps to one Craft shipment, keyed by allocation id.
- Last writer wins: a Craft edit pushes its split to Veeqo, and a poll mirrors Veeqo's allocations back, each skipping the orders the other wrote more recently. `shipmentsveeqo_order_pushes.dateAllocationsSynced` records when the two last agreed. A merged-away allocation deletes its `New` Craft shipment.
- Veeqo ignores line-item edits on an existing allocation, so a changed allocation is deleted and recreated. Allocation ids are therefore stable only while contents are unchanged.

## Parent plugin APIs relied on

- `Shipments::createFromAllocations(Order, list<array<int,int>>)`: one shipment per allocation.
- `Shipments::saveLineItems(Shipment, array<int,int>)`: resize a shipment's line items in place (no coverage assertion).
- `Shipments::applyUpdate(...)`: tracking plus status transition.
- `Shipments::findByOrderId(int)`.
- `IntegrationReferences::{setIntegrationReference, findByIntegrationReference, getReferencesForShipmentId}`.
- `SellableMappings::findByVeeqoSellableId(int)`.

## Decisions (settled)

1. Orphaned shipment on allocation removal: delete it, but only when still `New`. A Shipped orphan is kept (real fulfilment record).
2. Custom line items: reverse map by SKU (synthetic `custom-{lineItemId}` recovers the id).
3. Human-edited shipment conflict: last writer wins, compared against `dateAllocationsSynced`.
4. References: `alloc:` per shipment; order linked by number; cancellation by number lookup. Push dedup is a local claim row written before the create: Veeqo's number lookup lags a create by seconds and `number` is not unique.
5. Poll: no status filter (all recent orders plus a cancelled pass), since a shipped allocation can hide under any rollup status.
6. `enforceCoverage` is turned off for the store, because the Veeqo mirror legitimately produces partially covered orders (backorders). Alternative not taken: make the parent plugin skip coverage for integration-sourced updates.

## Validated on the test account

Order with two allocations (one shipped, one backordered then removed) exercised the whole path:

- Push, number-anchored dedup (`already exists; skipping push`). Superseded by the claim row.
- Poll picks the order up under `awaiting_stock` and `awaiting_fulfillment`.
- Reverse map via nested `sellable.sku_code`.
- Adopt + resize the original shipment; create a second shipment for the second allocation.
- Per-allocation Shipped + tracking from the allocation's own shipment.
- Orphan delete when an allocation is removed (`New` shipment trashed; the Shipped shipment preserved with its tracking and alloc ref).

## Open

- Soft delete (trash) leaves orphaned mirror shipments recoverable but accumulating; hard delete is the alternative.
- `saveLineItems` on a Shipped shipment (poll lags a ship) is unconfirmed; reconcile normally runs while shipments are open.
- Whether Veeqo allocation ids are stable across polls (not regenerated when an allocation is edited).
