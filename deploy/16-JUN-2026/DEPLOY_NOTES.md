# Inexo Rental deployment notes

Date: 2026-06-09

## Included changes

- Google Routes API `computeRoutes` integration for delivery orders.
- Server-side validation of origin and destination coordinates.
- Route distance, duration, traffic duration and encoded polyline persistence.
- Direct Google Maps route links for admin and logistics companies.
- Pending-route fallback that preserves the order when Google fails.
- Admin action to recalculate pending routes.
- Delivery/pickup flow, shipment portal and operational configuration updates.
- Order reminder scripts and deployment configuration templates.

## Required configuration

- Configure `INEXO_GOOGLE_ROUTES_API_KEY` with a server-side key restricted to Routes API.
- Configure the real Inexo base latitude and longitude in `/admin/configuracion`.
- Keep `INEXO_GOOGLE_MAPS_BROWSER_KEY` configured for the checkout map.
- Run `php scripts/check_operational_config.php` after deploying.

## Deployment safety

- Back up `inexo_rental.sqlite3` before replacing application files.
- Do not overwrite the production `.env`.
- Do not overwrite the production database or `uploads/`.
- Run the automatic migration once after publishing:

```bash
php -r 'putenv("INEXO_SKIP_DISPATCH=1"); require "index.php"; init_db();'
```

- Verify with:

```bash
php tests/route_helpers_test.php
php scripts/check_operational_config.php
```
