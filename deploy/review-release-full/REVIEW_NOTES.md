# Inexo Rental review release

Date: 2026-05-26

This folder is intended to be copied to a review/staging location on the server so the current changes can be reviewed online with a sanitized SQLite database.

## Included

- `.htaccess`
- `README.md`
- `app.css`
- `app.js`
- `index.php`
- `router.php`
- `inexo_rental.sqlite3` sanitized review database
- `inexo-rental---tu-partner-en-cada-obra.webflow/`
- `uploads/`

## Not included

- `.env` or `.env.*`
- Real SMTP credentials
- Production SQLite/database files
- `mail.log`
- Backups
- `data/deados_raw_pages`
- Raw import data
- Local/generated sensitive files

## Review notes

- The app needs PHP 8.1+ with SQLite support.
- The included database keeps catalog, product, brand, specialization, offer, stock, freight, labor, and commercial configuration data.
- The included database removes users, orders, order items, contact messages, login tokens, and reservations.
- The Google Maps browser key value is cleared in the included database.
- Do not copy a production `.env` or production database into this folder.
- Check SMTP variables on the server before testing email flows.
- Configure `INEXO_GOOGLE_MAPS_BROWSER_KEY` on the server if Google Maps delivery features need to be tested.
