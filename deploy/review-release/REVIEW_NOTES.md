# Inexo Rental review release

Date: 2026-05-26

This folder is intended to be copied to a review/staging location on the server so the current changes can be reviewed online.

## Included

- `.htaccess`
- `README.md`
- `app.css`
- `app.js`
- `index.php`
- `router.php`
- `inexo-rental---tu-partner-en-cada-obra.webflow/`
- `uploads/`

## Not included

- `.env` or `.env.*`
- Real SMTP credentials
- SQLite/database files
- `mail.log`
- Backups
- `data/deados_raw_pages`
- Raw import data
- Local/generated sensitive files

## Review notes

- The app needs PHP 8.1+ with SQLite support.
- If no database is placed on the review server, the app will create a new empty SQLite database on first request.
- To review existing products, offers, stock, checkout history, or admin configuration with real-looking data, use a staging database or a sanitized copy prepared separately on the server.
- Do not copy a production `.env` or production database into this folder unless the review server is intentionally configured for it.
- Check SMTP variables on the server before testing email flows.
- Configure `INEXO_GOOGLE_MAPS_BROWSER_KEY` on the server if Google Maps delivery features need to be tested.
