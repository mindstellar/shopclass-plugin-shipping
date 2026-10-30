# Changelog

## 0.2.0

### New
- Shipping class charges per zone, such as bulky = 200 for each bulky item. Items in the class "free" ship free.

## 0.1.1

### Changed
- Free-over, address problems and tracking links use core's typed cart events.

## 0.1.0

### New
- Delivery zones by country, state or PIN code start; the most exact zone wins.
- Standard and Express delivery per zone, with a flat price plus a price per extra kilo.
- Free delivery over an amount, per zone, shown in the cart's free-delivery meter.
- Delivery dates at checkout, such as "Tomorrow" or a range of dates.
- An address no zone serves is refused with "We do not deliver to this PIN code yet.", or falls back to the standard rate.
- A zone for the cart before the shopper gives an address.
- An address still missing the PIN or state its country's zones need is not refused yet.
- Courier tracking links for the tracking number an admin enters on a shipped order.
- Zones screen under Settings, and a Shipping settings page.
