#!/usr/bin/env python3
"""Import indexed Deados products into the local SQLite catalog."""

from __future__ import annotations

import argparse
import json
import mimetypes
import shutil
import sqlite3
import time
from datetime import datetime
from pathlib import Path
from typing import Dict, List
from urllib.parse import urlparse

import requests

from index_deados import DATA_DIR, ROOT_DIR, slugify


DB_PATH = ROOT_DIR / "inexo_rental.sqlite3"
SOURCE_JSON = DATA_DIR / "deados_products.json"
UPLOAD_DIR = ROOT_DIR / "uploads" / "products"
UPLOAD_BASE = "/uploads/products"
PLACEHOLDER_IMAGE = (
    "/inexo-rental---tu-partner-en-cada-obra.webflow/images/imagen-producto-generico.avif"
)
USER_AGENT = "inexo-rental-deados-importer/1.0 (image downloader for local product catalog)"

GENERIC_IMAGE_PARTS = (
    "deados-logotipo",
    "llamenos-ahora",
    "volver-catalogo",
    "cuente-con-nosotros",
)

SPECIALIZATION_BY_SLUG = {
    "atex": "casetones",
    "pluma-montacarga": "Acceso y altura",
    "balancin-electrico": "Acceso y altura",
    "balancin-manual": "Acceso y altura",
    "torre-montacarga": "Acceso y altura",
    "allanadoras": "Compactacion",
    "cortadoras-de-junta": "Corte y preparacion",
    "cortadora-de-varillas": "Corte y preparacion",
    "doblador-de-hierro": "Corte y preparacion",
    "martillete-electrico": "Corte y preparacion",
    "apisonador": "Compactacion",
    "unidades-hidraulicas": "Energia",
}


def clean_text(value: str) -> str:
    return " ".join((value or "").split())


def title_from_spec_key(value: str) -> str:
    replacements = {
        "kg": "KG",
        "rpm": "RPM",
        "hp": "HP",
        "kw": "kW",
        "mm": "mm",
    }
    words = value.replace("_", " ").split()
    titled = []
    for word in words:
        lower = word.lower()
        titled.append(replacements.get(lower, lower.capitalize()))
    return " ".join(titled)


def operation_label(operation_type: str) -> str:
    return "Venta usados" if operation_type == "venta_usados" else "Alquiler"


def short_description(description: str, fallback: str) -> str:
    text = clean_text(description)
    if not text:
        return fallback
    return text[:157].rstrip() + "..." if len(text) > 160 else text


def product_code(row: Dict[str, str]) -> str:
    prefix = "DEA-VENTA" if row["operation_type"] == "venta_usados" else "DEA-ALQ"
    return f"{prefix}-{row['product_slug'].upper()[:28]}"


def description_for(row: Dict[str, str]) -> str:
    description = clean_text(row.get("description", ""))
    return description


def specs_for(row: Dict[str, str]) -> str:
    specs = json.loads(row.get("specs_json") or "{}")
    pairs = [[title_from_spec_key(key), str(value)] for key, value in specs.items()]
    return json.dumps(pairs, ensure_ascii=False)


def filtered_image_urls(row: Dict[str, str]) -> List[str]:
    urls = json.loads(row.get("image_urls_json") or "[]")
    filtered = []
    for url in urls:
        lower = url.lower()
        if any(part in lower for part in GENERIC_IMAGE_PARTS):
            continue
        if url not in filtered:
            filtered.append(url)
    return filtered


def extension_from_response(url: str, content_type: str) -> str:
    parsed_ext = Path(urlparse(url).path).suffix.lower()
    if parsed_ext in {".jpg", ".jpeg", ".png", ".webp", ".avif", ".gif"}:
        return parsed_ext
    guessed = mimetypes.guess_extension((content_type or "").split(";")[0].strip())
    if guessed in {".jpe"}:
        return ".jpg"
    return guessed if guessed in {".jpg", ".jpeg", ".png", ".webp", ".avif", ".gif"} else ".jpg"


def download_images(row: Dict[str, str], delay: float, session: requests.Session) -> List[str]:
    UPLOAD_DIR.mkdir(parents=True, exist_ok=True)
    local_paths: List[str] = []

    for index, url in enumerate(filtered_image_urls(row), start=1):
        time.sleep(delay)
        response = session.get(url, timeout=(10, 45), allow_redirects=True)
        if response.status_code >= 400:
            continue
        content_type = response.headers.get("content-type", "")
        if not content_type.lower().startswith("image/"):
            continue
        ext = extension_from_response(url, content_type)
        filename = f"deados-{row['product_slug']}-{index}{ext}"
        destination = UPLOAD_DIR / filename
        destination.write_bytes(response.content)
        local_paths.append(f"{UPLOAD_BASE}/{filename}")

    return local_paths or [PLACEHOLDER_IMAGE]


def ensure_lookup(con: sqlite3.Connection, table: str, name: str) -> None:
    existing = con.execute(f"SELECT id FROM {table} WHERE name = ?", (name,)).fetchone()
    if existing:
        return
    columns = {row[1] for row in con.execute(f"PRAGMA table_info({table})")}
    slug = slugify(name)
    if table == "brands":
        if {"logo", "description"}.issubset(columns):
            con.execute(
                "INSERT INTO brands (name, slug, logo, description) VALUES (?, ?, '', '')",
                (name, slug),
            )
        else:
            con.execute("INSERT INTO brands (name, slug) VALUES (?, ?)", (name, slug))
        return
    if table == "specializations":
        if "icon" in columns:
            con.execute(
                "INSERT INTO specializations (name, slug, icon) VALUES (?, ?, '')",
                (name, slug),
            )
        else:
            con.execute("INSERT INTO specializations (name, slug) VALUES (?, ?)", (name, slug))


def import_row(con: sqlite3.Connection, row: Dict[str, str], image_paths: List[str]) -> str:
    brand = "Deados"
    category = operation_label(row["operation_type"])
    specialization = SPECIALIZATION_BY_SLUG.get(row["product_slug"], "Productos")
    status = "En stock" if row["operation_type"] == "alquiler" else "Usado disponible"
    is_featured = 1 if row["operation_type"] == "alquiler" else 0
    is_new = 1 if row["operation_type"] == "venta_usados" else 0

    ensure_lookup(con, "brands", brand)
    ensure_lookup(con, "specializations", specialization)

    values = {
        "slug": row["product_slug"],
        "name": row["product_name"],
        "code": product_code(row),
        "brand": brand,
        "category": category,
        "specialization": specialization,
        "short_description": short_description(row.get("description", ""), category),
        "description": description_for(row),
        "status": status,
        "price_sale_used": 0.0,
        "price_sale_new": 0.0,
        "rental_daily": 0.0,
        "rental_weekly": 0.0,
        "rental_monthly": 0.0,
        "images": json.dumps(image_paths, ensure_ascii=False, separators=(",", ":")),
        "specs": specs_for(row),
        "is_featured": is_featured,
        "is_new": is_new,
    }

    existing = con.execute("SELECT id FROM products WHERE slug = ?", (values["slug"],)).fetchone()
    if existing:
        values["id"] = existing["id"]
        con.execute(
            """
            UPDATE products SET
                name = :name, code = :code, brand = :brand, category = :category,
                specialization = :specialization, short_description = :short_description,
                description = :description, status = :status,
                price_sale_used = :price_sale_used, price_sale_new = :price_sale_new,
                rental_daily = :rental_daily, rental_weekly = :rental_weekly,
                rental_monthly = :rental_monthly, images = :images, specs = :specs,
                is_featured = :is_featured, is_new = :is_new
            WHERE id = :id
            """,
            values,
        )
        return "updated"

    con.execute(
        """
        INSERT INTO products (
            slug, name, code, brand, category, specialization, short_description,
            description, status, price_sale_used, price_sale_new, rental_daily,
            rental_weekly, rental_monthly, images, specs, is_featured, is_new
        ) VALUES (
            :slug, :name, :code, :brand, :category, :specialization,
            :short_description, :description, :status, :price_sale_used,
            :price_sale_new, :rental_daily, :rental_weekly, :rental_monthly,
            :images, :specs, :is_featured, :is_new
        )
        """,
        values,
    )
    return "inserted"


def backup_database() -> Path:
    backup_path = DB_PATH.with_suffix(f".sqlite3.backup-{datetime.now().strftime('%Y%m%d%H%M%S')}")
    shutil.copy2(DB_PATH, backup_path)
    return backup_path


def main() -> int:
    parser = argparse.ArgumentParser(description="Importa productos indexados de Deados al sistema local.")
    parser.add_argument("--source", type=Path, default=SOURCE_JSON)
    parser.add_argument("--db", type=Path, default=DB_PATH)
    parser.add_argument("--image-delay", type=float, default=1.5)
    parser.add_argument("--no-backup", action="store_true")
    args = parser.parse_args()

    rows = json.loads(args.source.read_text(encoding="utf-8"))
    rows = [row for row in rows if row.get("crawl_status") == "ok" and row.get("product_name")]

    backup_path = None if args.no_backup else backup_database()

    session = requests.Session()
    session.headers.update({"User-Agent": USER_AGENT, "Accept": "image/*,*/*;q=0.8"})

    con = sqlite3.connect(args.db)
    con.row_factory = sqlite3.Row
    try:
        inserted = 0
        updated = 0
        for row in rows:
            image_paths = download_images(row, args.image_delay, session)
            action = import_row(con, row, image_paths)
            inserted += action == "inserted"
            updated += action == "updated"
        con.commit()
    except Exception:
        con.rollback()
        raise
    finally:
        con.close()

    if backup_path:
        print(f"Backup DB: {backup_path}")
    print(f"Productos insertados: {inserted}")
    print(f"Productos actualizados: {updated}")
    print(f"Imagenes locales en: {UPLOAD_DIR}")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
