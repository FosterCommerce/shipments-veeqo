# Allocation reconciliation (model C)

Internal design notes. Status: spec for sign-off, not built.

The current build pushes the whole Commerce order to Veeqo and lets Veeqo auto-allocate it into one allocation, which mirrors the order's single shipment. This document covers the unbuilt case: when Veeqo splits a pushed order into multiple allocations (multi-warehouse routing or partial fulfilment), and Craft needs to reflect that as multiple shipments.

## Model

- Commerce Order maps to one Veeqo Order. Pushed once; the Veeqo order id is stored on the order's shipment at push time.
- Commerce Shipment maps to one Veeqo Allocation. Each allocation is one parcel with its own line items and tracking.
- Source of truth for the split, after push, is Veeqo. The poll reconciles Craft shipments to mirror Veeqo's allocations.

## Identity and references

Two separate things, do not conflate them:

- The Veeqo order `number` (the human label in Veeqo) is `orderIdPrefix . order.reference`. Already set at push.
- The internal match keys are integration references, namespaced in `externalId` so the two kinds do not collide in `findByIntegrationReference`:
  - `order:{veeqoOrderId}` on the order anchor, mapping a Veeqo order to a Craft order.
  - `alloc:{allocationId}` per shipment, mapping an allocation to its Craft shipment.

No migration: the plugin is unreleased and only a test Veeqo account is in play, so the namespaced format is adopted from the start.

Parent service: `IntegrationReferences::setIntegrationReference`, `findByIntegrationReference`, `getReferencesForShipmentId`, `saveReferencesForShipment`, `deleteReferenceById`.

## Reverse line item map

Veeqo `allocation.line_items[]` resolves to a Craft order line item by two paths:

- Purchasable items: `sellable_id` -> `SellableMappings::findByVeeqoSellableId` -> `purchasableId` -> the order's `LineItem` carrying that purchasable -> `lineItemId` + qty.
- Custom (non-purchasable) items: by SKU. The Veeqo sellable `sku_code` matches the custom line item's SKU. Where the line item has no SKU, the push uses a synthetic `custom-{lineItemId}` code (see `ProductSync::syncCustomLineItem`), so the Craft `lineItemId` is recoverable from the code.

Verify the allocation line item exposes `sku_code` (or the nested sellable does) so the custom path has something to match on.

## Reconcile algorithm (per Veeqo order, each poll)

1. Resolve the Craft order from the `order:{id}` reference. Skip the Veeqo order if none matches (it did not originate here).
2. Load the order's Craft shipments via `Shipments::findByOrderId`.
3. Build the desired set: each Veeqo allocation becomes a `[lineItemId => qty]` group via the reverse map, keyed by allocation id.
4. Diff desired (allocations) against actual (shipments keyed by their `alloc:` reference):
   - Matched (allocation has a shipment): if the line items differ, `saveLineItems` the shipment to match.
   - New (allocation with no shipment): `createFromAllocations(order, [group])`, then tag the new shipment with `alloc:{id}`.
   - Orphan (shipment has an `alloc:` reference whose allocation is gone): delete the Craft shipment. Veeqo is the source of truth, so a merged-away allocation removes its mirror.
   - First reconcile: the pre-existing whole-order shipment has no `alloc:` reference. It adopts allocation 1 (resize to that allocation's items, tag it); the remaining allocations are created fresh.
5. For each allocation that has shipped, `applyUpdate` its tracking, carrier, and status onto its shipment. `partially_shipped` marks that one shipment Shipped and leaves the others open.

## Invariants

- Coverage: once a reconcile pass completes, the sum of shipment line items equals the order's line items. Transient under-allocation is allowed mid-pass (`saveLineItems` permits it; the overflow guard prevents over-allocation).
- Idempotent: re-running against an unchanged allocation set is a no-op.
- One Veeqo allocation maps to one Craft shipment, keyed by allocation id.
- Veeqo is authoritative after push: a reconcile pass overwrites the Craft split to match Veeqo, including a manually edited shipment. A merged-away allocation deletes its Craft shipment.

## Edge cases and risks

- Allocation merge (Veeqo collapses two allocations into one): the orphaned Craft shipment is deleted.
- Custom line items: reverse mapped by SKU (see Reverse line item map), so they are handled, contingent on the allocation line exposing `sku_code`.
- Human edits a Craft shipment after push: a reconcile pass overwrites it. Veeqo wins.
- Concurrency: the reconcile pass must take the order's allocation lock (the same one `saveLineItems` and `createFromStagingPost` use) to avoid racing a CP edit.
- Resizing a shipment already in a terminal status (Shipped): reconcile runs while shipments are still Open (Veeqo allocates before shipping), so this only arises if a poll lags behind a ship. Confirm the parent plugin's behaviour for the lag case.
- Status mapping: `partially_shipped` now applies per allocation, so the order-level mapping in `ShipmentPoller::mapStatus` needs revisiting.

## Parent plugin APIs relied on

- `Shipments::createFromAllocations(Order, list<array<int,int>>)`: one shipment per allocation.
- `Shipments::saveLineItems(Shipment, array<int,int>)`: resize a shipment's line items in place.
- `Shipments::applyUpdate(...)`: tracking plus status transition (already used by the poller).
- `Shipments::findByOrderId(int)`.
- `IntegrationReferences::{setIntegrationReference, findByIntegrationReference, getReferencesForShipmentId, saveReferencesForShipment, deleteReferenceById}`.
- `SellableMappings::findByVeeqoSellableId(int)`.

## Decisions (settled)

1. Orphaned shipment on allocation merge: delete it. Veeqo is the source of truth.
2. Custom line items: reverse map by SKU (synthetic `custom-{lineItemId}` recovers the id).
3. Human-edited shipment conflict: Veeqo wins; reconcile overwrites.
4. References: namespaced from the start, no migration (unreleased, test account only).
5. Resizing: changing a shipment's line items via `saveLineItems`; reconcile runs pre-ship against Open shipments.

## Phasing

- P1: reference namespacing and the reverse map. No behaviour change; foundation only.
- P2: the poll reconcile (create, resize, per-allocation tracking). The engine.
- P3: orphan and merge handling, plus the conflict rules.

## Verify before P2

- Whether `saveLineItems` is blocked on a shipment with a non-open status.
- How `createFromAllocations` behaves under `enforceCoverage` during the transient under-allocated window.
- Whether Veeqo allocation ids are stable across polls (not regenerated when an allocation is edited).
