# Inexo Rental v02 demo package

Date: 2026-05-26

This folder is a complete v02 demo copy intended to be uploaded as a separate online demo folder.

## Upload This Whole Folder

Upload the full `inexo-rental-v02` folder to the server.

## Included

- `.htaccess`
- `README.md`
- `app.css`
- `app.js`
- `index.php`
- `router.php`
- `inexo_rental.sqlite3`
- `inexo-rental---tu-partner-en-cada-obra.webflow/`
- `uploads/`

## Database

The included SQLite database is sanitized for demo use.

It keeps:

- Products
- Brands
- Specializations
- Stock and offer fields
- Freight settings
- Labor/work settings
- Commercial configuration

It removes:

- Users
- Orders
- Order items
- Contact messages
- Login tokens
- Reservations

The Google Maps browser key value is configured in the included demo database.

## Not Included

- `.env`
- `.env.*`
- Real SMTP credentials
- Production database
- `mail.log`
- Backups
- Raw import data
- `data/deados_raw_pages`

## Server Notes

- Keep using the server's existing SMTP/environment configuration.
- If this is published under a subfolder such as `/v02`, configure `INEXO_BASE_PATH=/v02` if the server does not detect the subfolder correctly.
- The included database has a Google Maps browser key configured for Maps, Places, and Distance Matrix behavior. A server-level `INEXO_GOOGLE_MAPS_BROWSER_KEY` still overrides the database value when present.
- Make sure PHP can write to `inexo_rental.sqlite3` and `uploads/`.
