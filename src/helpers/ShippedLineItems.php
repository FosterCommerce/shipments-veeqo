<?php

declare(strict_types=1);

namespace fostercommerce\shipments\veeqo\helpers;

use craft\commerce\elements\Order;
use craft\commerce\models\LineItem;
use fostercommerce\shipments\Plugin as ShipmentsPlugin;

final class ShippedLineItems
{
	/**
	 * Line items the order ships: shipping work in the Shipments plugin, with a shippable unit or more.
	 *
	 * Match Veeqo lines against this list only, so the push and the allocation mirror agree.
	 *
	 * @return list<LineItem>
	 */
	public static function forOrder(Order $order): array
	{
		/** @var ShipmentsPlugin $shipments */
		$shipments = ShipmentsPlugin::getInstance();
		$shippableUnits = $shipments->shipmentLineItems->shippableUnitsFor($order);

		$lineItems = [];
		foreach ($order->getLineItems() as $lineItem) {
			if (! $shipments->shipmentLineItems->isShippingWork($lineItem)) {
				continue;
			}

			if (($shippableUnits[$lineItem->id] ?? 0) === 0) {
				continue;
			}

			$lineItems[] = $lineItem;
		}

		return $lineItems;
	}
}
