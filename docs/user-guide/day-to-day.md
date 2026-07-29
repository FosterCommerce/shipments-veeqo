# Working with Veeqo day to day

What the Veeqo connection does once it is set up, and what to do when something looks wrong. Audience: store admins and CS leads running fulfillment.

This page assumes the integration is already configured. See [Installation](../installation.md) if it is not.

## How an order reaches Veeqo

An order goes to Veeqo as a whole, once. It is sent when a shipment on that order reaches the status set in **Auto-push to Veeqo at status**, or when you press **Push to Veeqo** on a shipment yourself.

Only the first push creates the order. Pressing the button again on that order sends its current split instead, so there is no way to create the same order twice by clicking twice.

The order arrives in Veeqo already marked as paid, so it lands ready to pick rather than waiting for payment.

## How tracking comes back

Veeqo decides how to ship the order. It may fill it from one warehouse in one parcel, or split it across warehouses, or ship part now and the rest when stock arrives. Each parcel Veeqo plans is called an allocation.

Craft shows one shipment per allocation. A scheduled job asks Veeqo what changed and updates the order's Shipments tab to match:

- A new parcel in Veeqo appears as a new shipment in Craft.
- A parcel Veeqo resized changes the quantities on its Craft shipment.
- A parcel Veeqo dropped removes its Craft shipment, unless that shipment has already shipped, in which case it stays as a record of what went out.
- Once a parcel ships, its Craft shipment turns **Shipped** and fills in the carrier, tracking number, and tracking link.

Nothing appears instantly. Updates arrive the next time the scheduled job runs, typically within ten to fifteen minutes.

### Changing the split from Craft

You can also change the split from the order's Shipments tab. Move line items between shipments, change a quantity, add a shipment, or delete one, and Veeqo's parcels are updated to match within a minute or so.

Whichever side changed the split last is the one that stands. A poll leaves your Craft edits alone until they have reached Veeqo, and once they have, later changes made in Veeqo come back to Craft as usual.

Shipments that have already shipped are not restructured from either side.

## Cancelling

Veeqo does not let anything cancel an order through its API, so Craft cannot cancel one for you. Instead, Craft posts a note on the Veeqo order asking a warehouse user to cancel it there.

A note is posted when you delete an order's last remaining shipment, delete an order, ignore an order, or change an order so that it no longer needs shipping. The note says which of those happened. Someone still has to act on it in Veeqo, so treat it as a message, not as a cancellation.

Deleting one shipment while others remain is a change to the split, not a cancellation, so it drops that parcel in Veeqo and posts no note.

An order that was never pushed has nothing in Veeqo to write to, so no note is posted.

## Stock

Veeqo owns stock. When the stock job runs, it copies Veeqo's available quantity onto matching Commerce variants, overwriting whatever Craft had. Variants that do not track inventory are never touched.

This is one way. Changing a stock number in Craft does not change it in Veeqo, and will be overwritten on the next run. Adjust stock in Veeqo.

You can turn this off with **Let Veeqo adjust Commerce inventory** under **Settings -> Plugins -> Shipments Veeqo**.

## When a push fails

A failed push leaves its reason on the shipment, under the **Details** tab. The common ones and what to do:

| What it says | What to do |
|---|---|
| No Veeqo channel ID is set on the integration | Add the channel ID under **Shipments -> Settings -> Integrations** |
| The Veeqo integration hasn't been saved yet | Open the integration and save it |
| This order has no email address | Add an email to the order; Veeqo requires one |
| This order has no line items to send | Check that the order has something to ship |
| An item couldn't be synced to Veeqo, check that its variant has a SKU | Give the product variant a SKU in Craft, then push again |
| Another push for this order is already running | Wait a moment and try again |

Anything else, including a message quoting a Veeqo error, is worth passing to a developer along with the order number.

Some failures retry on their own. If Veeqo was busy or briefly unavailable, the job tries again shortly and the message clears when it succeeds.

## When nothing comes back from Veeqo

If an order shipped in Veeqo but Craft still shows it open, check in this order:

1. **Does Veeqo show the order as shipped?** Craft marks a shipment shipped when its parcel has a tracking number, or when Veeqo reports the whole order as shipped.
2. **Do the Craft shipments still show as open?** Craft only asks Veeqo about orders holding a shipment at **New**, **In progress**, or **On hold**. Once every shipment on an order reaches **Fulfilled**, **Shipped**, or **Cancelled**, that order stops being checked. Setting one of those statuses by hand has the same effect.
3. **Was it raised in Veeqo rather than Craft?** Craft only recognises orders whose Veeqo number matches a Craft order reference.

If all three look right, ask a developer to check the plugin log.
