#!/usr/bin/env python3
"""Respectful public product indexer for deados.com.py."""

from __future__ import annotations

import argparse
import csv
import hashlib
import json
import re
import time
from dataclasses import dataclass
from datetime import datetime, timezone
from pathlib import Path
from typing import Dict, Iterable, List, Optional, Set, Tuple
from urllib.parse import urldefrag, urljoin, urlparse, urlunparse
from urllib.robotparser import RobotFileParser

import requests
from bs4 import BeautifulSoup, Tag, UnicodeDammit


SOURCE_SITE = "deados.com.py"
ROOT_DIR = Path(__file__).resolve().parents[1]
DATA_DIR = ROOT_DIR / "data"
RAW_DIR = DATA_DIR / "deados_raw_pages"
CSV_PATH = DATA_DIR / "deados_products.csv"
JSON_PATH = DATA_DIR / "deados_products.json"

USER_AGENT = (
    "inexo-rental-public-indexer/1.0 "
    "(respectful research crawler; contact: contacto@inexo.com.py)"
)

SEED_URLS = [
    "https://deados.com.py/alquileres.html",
    "https://deados.com.py/venta-usados.html",
    "https://deados.com.py/pluma-montacarga.html",
    "https://www.deados.com.py/balancin-electrico.html",
    "https://www.deados.com.py/balancin-manual.html",
    "https://www.deados.com.py/torre-montacaga.html",
]

ALQUILER_INDEX = "alquileres.html"
VENTA_USADOS_INDEX = "venta-usados.html"

FIELDNAMES = [
    "id",
    "source_site",
    "source_url",
    "operation_type",
    "product_name",
    "product_slug",
    "category",
    "description",
    "specs_json",
    "image_urls_json",
    "contact_phone",
    "contact_email",
    "captured_at",
    "crawl_status",
    "notes",
]


@dataclass
class QueueItem:
    url: str
    operation_type: str
    discovered_from: str
    is_index_page: bool = False


@dataclass
class FetchResult:
    requested_url: str
    final_url: str
    status_code: Optional[int]
    content_type: str
    text: str
    error: str = ""


class RateLimiter:
    def __init__(self, delay_seconds: float) -> None:
        self.delay_seconds = delay_seconds
        self.last_request_at = 0.0

    def wait(self) -> None:
        elapsed = time.monotonic() - self.last_request_at
        remaining = self.delay_seconds - elapsed
        if remaining > 0:
            time.sleep(remaining)
        self.last_request_at = time.monotonic()


class RespectfulFetcher:
    def __init__(self, delay_seconds: float, timeout_seconds: float) -> None:
        self.rate_limiter = RateLimiter(delay_seconds)
        self.timeout_seconds = timeout_seconds
        self.session = requests.Session()
        self.session.headers.update(
            {
                "User-Agent": USER_AGENT,
                "Accept": "text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8",
                "Accept-Language": "es,en;q=0.8",
            }
        )
        self.robots_by_host: Dict[str, Optional[RobotFileParser]] = {}
        self.robots_notes: Dict[str, str] = {}

    def can_fetch(self, url: str) -> Tuple[bool, str]:
        parsed = urlparse(url)
        host_key = parsed.netloc.lower()
        if host_key not in self.robots_by_host:
            self._load_robots(parsed)

        parser = self.robots_by_host.get(host_key)
        if parser is None:
            return True, self.robots_notes.get(host_key, "robots.txt no encontrado o no legible")

        allowed = parser.can_fetch(USER_AGENT, url)
        if allowed:
            return True, self.robots_notes.get(host_key, "robots.txt permite la URL")
        return False, f"bloqueado por robots.txt en {host_key}"

    def fetch(self, url: str) -> FetchResult:
        self.rate_limiter.wait()
        try:
            response = self.session.get(
                url,
                timeout=(min(10.0, self.timeout_seconds), self.timeout_seconds),
                allow_redirects=True,
            )
            decoded = UnicodeDammit(response.content, is_html=True).unicode_markup
            return FetchResult(
                requested_url=url,
                final_url=normalize_url(response.url),
                status_code=response.status_code,
                content_type=response.headers.get("content-type", ""),
                text=decoded or response.text or "",
            )
        except requests.RequestException as exc:
            return FetchResult(
                requested_url=url,
                final_url=url,
                status_code=None,
                content_type="",
                text="",
                error=str(exc),
            )

    def _load_robots(self, parsed_url) -> None:
        host_key = parsed_url.netloc.lower()
        robots_url = urlunparse((parsed_url.scheme, parsed_url.netloc, "/robots.txt", "", "", ""))
        result = self.fetch(robots_url)

        if result.status_code == 200 and result.text.strip():
            parser = RobotFileParser()
            parser.set_url(robots_url)
            parser.parse(result.text.splitlines())
            self.robots_by_host[host_key] = parser
            self.robots_notes[host_key] = f"robots.txt leído: {robots_url}"
            return

        if result.status_code == 404:
            self.robots_by_host[host_key] = None
            self.robots_notes[host_key] = f"robots.txt no existe: {robots_url}"
            return

        self.robots_by_host[host_key] = None
        detail = result.error or f"HTTP {result.status_code}"
        self.robots_notes[host_key] = f"robots.txt no legible ({detail}): {robots_url}"


def normalize_url(url: str) -> str:
    url = urldefrag(url)[0]
    parsed = urlparse(url)
    netloc = parsed.netloc.lower()
    if netloc == "www.deados.com.py":
        netloc = "deados.com.py"
    path = parsed.path or "/"
    if path != "/" and path.endswith("/"):
        path = path[:-1]
    return urlunparse((parsed.scheme.lower(), netloc, path, "", parsed.query, ""))


def path_basename(url: str) -> str:
    path = urlparse(url).path.rstrip("/")
    return path.rsplit("/", 1)[-1]


def is_deados_public_html(url: str) -> bool:
    parsed = urlparse(url)
    host = parsed.netloc.lower()
    if host not in {"deados.com.py", "www.deados.com.py"}:
        return False
    path = parsed.path.lower()
    return path in {"", "/"} or path.endswith(".html")


def classify_seed(url: str) -> Tuple[str, bool]:
    basename = path_basename(url)
    if basename == VENTA_USADOS_INDEX:
        return "venta_usados", True
    if basename == ALQUILER_INDEX:
        return "alquiler", True
    return "alquiler", False


def slugify(value: str, fallback_url: str = "") -> str:
    value = value.strip().lower()
    replacements = {
        "á": "a",
        "é": "e",
        "í": "i",
        "ó": "o",
        "ú": "u",
        "ü": "u",
        "ñ": "n",
    }
    for source, target in replacements.items():
        value = value.replace(source, target)
    value = re.sub(r"[^a-z0-9]+", "-", value).strip("-")
    if value:
        return value
    stem = path_basename(fallback_url).replace(".html", "")
    return stem or hashlib.sha1(fallback_url.encode("utf-8")).hexdigest()[:12]


def stable_id(operation_type: str, source_url: str, product_slug: str) -> str:
    digest = hashlib.sha1(source_url.encode("utf-8")).hexdigest()[:10]
    return f"deados-{operation_type}-{product_slug}-{digest}"


def clean_text(value: str) -> str:
    return re.sub(r"\s+", " ", value or "").strip()


def visible_texts(nodes: Iterable[Tag]) -> List[str]:
    values: List[str] = []
    for node in nodes:
        text = clean_text(node.get_text(" ", strip=True))
        if text:
            values.append(text)
    return values


def first_heading(soup: BeautifulSoup) -> str:
    for tag_name in ("h1", "h2", "h3"):
        tag = soup.find(tag_name)
        if tag:
            text = clean_text(tag.get_text(" ", strip=True))
            if text:
                return text
    title = soup.find("title")
    return clean_text(title.get_text(" ", strip=True)) if title else ""


def extract_category(soup: BeautifulSoup, product_name: str) -> str:
    heading_texts = visible_texts(soup.find_all(["h1", "h2", "h3"]))
    for text in heading_texts:
        if text and text != product_name:
            return text
    return ""


def find_description_block(soup: BeautifulSoup) -> str:
    label = soup.find(string=re.compile(r"\bdescripci[oó]n\b", re.IGNORECASE))
    if not label:
        meta = soup.find("meta", attrs={"name": re.compile("^description$", re.IGNORECASE)})
        return clean_text(meta.get("content", "")) if meta else ""

    parent = label.parent if isinstance(label.parent, Tag) else None
    if parent:
        container = parent.find_parent(["section", "article", "div"]) or parent
        parts: List[str] = []

        collect = False
        for node in container.descendants:
            if not isinstance(node, Tag):
                continue
            if node == parent or node.get_text(" ", strip=True) == clean_text(str(label)):
                collect = True
                continue
            if collect and node.name in {"p", "li", "span", "div"}:
                text = clean_text(node.get_text(" ", strip=True))
                if text and not re.fullmatch(r"descripci[oó]n", text, re.IGNORECASE):
                    parts.append(text)
            if len(parts) >= 8:
                break

        if parts:
            return clean_text(" ".join(dict.fromkeys(parts)))

    next_texts: List[str] = []
    current = parent
    while current and len(next_texts) < 6:
        current = current.find_next_sibling() if isinstance(current, Tag) else None
        if not current:
            break
        if current.name in {"h1", "h2", "h3"}:
            break
        text = clean_text(current.get_text(" ", strip=True))
        if text:
            next_texts.append(text)
    return clean_text(" ".join(next_texts))


def normalize_spec_key(value: str) -> str:
    value = clean_text(value).strip(":-").lower()
    value = value.replace("á", "a").replace("é", "e").replace("í", "i")
    value = value.replace("ó", "o").replace("ú", "u").replace("ñ", "n")
    value = re.sub(r"[^a-z0-9]+", "_", value).strip("_")
    return value or "spec"


def parse_spec_line(line: str) -> Optional[Tuple[str, str]]:
    text = clean_text(line).lstrip("-•* ").strip()
    if not text:
        return None

    if ":" in text:
        key, value = text.split(":", 1)
        return normalize_spec_key(key), clean_text(value)

    match = re.match(r"^([A-ZÁÉÍÓÚÑ0-9 /().,-]{4,}?)(?:\s{2,}|\s+-\s+)(.+)$", text)
    if match:
        return normalize_spec_key(match.group(1)), clean_text(match.group(2))

    parts = text.split()
    unit_tokens = {
        "KG",
        "KGS",
        "M",
        "MT",
        "MTS",
        "CM",
        "MM",
        "HP",
        "V",
        "KW",
        "W",
        "TON",
        "T",
        "RPM",
    }
    for idx, token in enumerate(parts):
        if re.search(r"\d", token) or token.upper() in unit_tokens:
            if idx > 0:
                key = " ".join(parts[:idx])
                value = " ".join(parts[idx:])
                return normalize_spec_key(key), clean_text(value)

    if text.isupper() and len(parts) > 1:
        return normalize_spec_key(text), ""

    return None


def extract_specs(soup: BeautifulSoup, description: str) -> Dict[str, str]:
    specs: Dict[str, str] = {}
    candidates: List[str] = []

    for li in soup.find_all("li"):
        candidates.append(li.get_text(" ", strip=True))

    page_text = soup.get_text("\n", strip=True)
    candidates.extend(expand_bullet_segments(page_text))
    candidates.extend(expand_bullet_segments(description))

    for line in candidates:
        parsed = parse_spec_line(line)
        if parsed:
            key, value = parsed
            if is_valid_spec_key(key) and key not in specs:
                specs[key] = value

    return specs


def is_valid_spec_key(key: str) -> bool:
    if not key or len(key) > 80:
        return False
    generic_prefixes = (
        "alquiler_de_maquinas",
        "alquiler_y_venta_de_maquinas",
        "servicios_de_mantenimiento",
    )
    return not key.startswith(generic_prefixes)


def expand_bullet_segments(text: str) -> List[str]:
    segments: List[str] = []
    for line in text.splitlines() or [text]:
        if not re.search(r"(^|\s)[\-•*]\s+", line):
            continue
        parts = re.split(r"(?:^|\s)[\-•*]\s+", line)
        for part in parts:
            part = clean_text(part)
            if part:
                segments.append(part)
    return segments


def extract_images(soup: BeautifulSoup, base_url: str) -> List[str]:
    urls: List[str] = []
    for image in soup.find_all("img"):
        candidates = []
        for attr in ("src", "data-src", "data-original"):
            value = image.get(attr)
            if value:
                candidates.append(value)
        srcset = image.get("srcset")
        if srcset:
            candidates.extend(item.strip().split(" ")[0] for item in srcset.split(",") if item.strip())

        for candidate in candidates:
            if candidate.startswith("data:"):
                continue
            absolute = normalize_url(urljoin(base_url, candidate))
            if absolute and absolute not in urls:
                urls.append(absolute)
    return urls


def extract_contacts(soup: BeautifulSoup) -> Tuple[str, str]:
    phone = ""
    email = ""

    for link in soup.find_all("a", href=True):
        href = str(link["href"])
        if not phone and href.startswith("tel:"):
            phone = clean_text(href[4:])
        if not email and href.startswith("mailto:"):
            email = clean_text(href[7:].split("?")[0])

    text = soup.get_text(" ", strip=True)
    phone_matches = re.findall(r"(?:\+?595|0)\s?[\d\s().-]{7,}", text)
    email_matches = re.findall(r"[\w.+-]+@[\w.-]+\.[A-Za-z]{2,}", text)

    if not phone and phone_matches:
        phone = re.sub(r"[^\d+]+$", "", clean_text(phone_matches[0]))
    if not email and email_matches:
        email = email_matches[0]

    return phone, email


def extract_links(soup: BeautifulSoup, base_url: str) -> List[str]:
    links: List[str] = []
    excluded_pages = {
        "",
        "index.html",
        "contacto.html",
        ALQUILER_INDEX,
        VENTA_USADOS_INDEX,
    }
    for anchor in soup.find_all("a", href=True):
        absolute = normalize_url(urljoin(base_url, str(anchor["href"])))
        basename = path_basename(absolute)
        if basename in excluded_pages:
            continue
        if is_deados_public_html(absolute) and absolute not in links:
            links.append(absolute)
    return links


def extract_external_product_cards(soup: BeautifulSoup, base_url: str) -> List[Dict[str, str]]:
    cards: List[Dict[str, str]] = []
    seen_names: Set[str] = set()

    for anchor in soup.find_all("a", href=True):
        href = str(anchor["href"])
        absolute = normalize_url(urljoin(base_url, href))
        parsed = urlparse(absolute)
        if parsed.netloc in {"", "deados.com.py", "www.deados.com.py"}:
            continue

        name = clean_text(anchor.get_text(" ", strip=True))
        if not name or len(name) > 40:
            continue

        slug = slugify(name, absolute)
        if slug in seen_names:
            continue

        image_urls = extract_images(BeautifulSoup(str(anchor), "lxml"), base_url)
        cards.append(
            {
                "name": name,
                "slug": slug,
                "external_url": absolute,
                "image_urls_json": json.dumps(image_urls, ensure_ascii=False),
            }
        )
        seen_names.add(slug)

    return cards


def raw_filename(url: str) -> str:
    parsed = urlparse(url)
    basename = path_basename(url) or "index"
    safe_host = parsed.netloc.replace("www.", "").replace(".", "_")
    safe_name = re.sub(r"[^a-zA-Z0-9_.-]+", "_", basename)
    digest = hashlib.sha1(url.encode("utf-8")).hexdigest()[:8]
    return f"{safe_host}__{safe_name}__{digest}.html"


def save_raw_page(url: str, html: str) -> None:
    RAW_DIR.mkdir(parents=True, exist_ok=True)
    (RAW_DIR / raw_filename(url)).write_text(html, encoding="utf-8")


def make_error_record(
    item: QueueItem,
    captured_at: str,
    status: str,
    notes: str,
) -> Dict[str, str]:
    product_slug = slugify("", item.url)
    return {
        "id": stable_id(item.operation_type, item.url, product_slug),
        "source_site": SOURCE_SITE,
        "source_url": item.url,
        "operation_type": item.operation_type,
        "product_name": "",
        "product_slug": product_slug,
        "category": "",
        "description": "",
        "specs_json": "{}",
        "image_urls_json": "[]",
        "contact_phone": "",
        "contact_email": "",
        "captured_at": captured_at,
        "crawl_status": status,
        "notes": notes,
    }


def parse_product_record(
    item: QueueItem,
    result: FetchResult,
    captured_at: str,
    robots_note: str,
) -> Dict[str, str]:
    soup = BeautifulSoup(result.text, "lxml")
    product_name = first_heading(soup)
    product_slug = slugify(product_name, result.final_url)
    description = find_description_block(soup)
    specs = extract_specs(soup, description)
    image_urls = extract_images(soup, result.final_url)
    phone, email = extract_contacts(soup)
    category = extract_category(soup, product_name)

    notes = [robots_note]
    if item.is_index_page:
        notes.append("pagina indice incluida para confirmar contenido")
    if item.discovered_from and item.discovered_from != item.url:
        notes.append(f"descubierto desde {item.discovered_from}")
    if not product_name:
        notes.append("no se detectó h1/h2/h3")
    if not description:
        notes.append("no se detectó bloque después de Descripción")

    return {
        "id": stable_id(item.operation_type, result.final_url, product_slug),
        "source_site": SOURCE_SITE,
        "source_url": result.final_url,
        "operation_type": item.operation_type,
        "product_name": product_name,
        "product_slug": product_slug,
        "category": category,
        "description": description,
        "specs_json": json.dumps(specs, ensure_ascii=False, sort_keys=True),
        "image_urls_json": json.dumps(image_urls, ensure_ascii=False),
        "contact_phone": phone,
        "contact_email": email,
        "captured_at": captured_at,
        "crawl_status": "ok",
        "notes": "; ".join(note for note in notes if note),
    }


def make_linked_product_record(
    item: QueueItem,
    result: FetchResult,
    card: Dict[str, str],
    captured_at: str,
    robots_note: str,
) -> Dict[str, str]:
    source_url = result.final_url
    product_slug = card["slug"]
    return {
        "id": stable_id(item.operation_type, f"{source_url}#{product_slug}", product_slug),
        "source_site": SOURCE_SITE,
        "source_url": source_url,
        "operation_type": item.operation_type,
        "product_name": card["name"],
        "product_slug": product_slug,
        "category": "",
        "description": "",
        "specs_json": "{}",
        "image_urls_json": card["image_urls_json"],
        "contact_phone": "",
        "contact_email": "",
        "captured_at": captured_at,
        "crawl_status": "ok",
        "notes": (
            f"{robots_note}; producto listado en página índice; "
            f"enlace externo no recorrido: {card['external_url']}"
        ),
    }


def should_include_index_page(url: str, product_links: List[str]) -> bool:
    basename = path_basename(url)
    if basename == VENTA_USADOS_INDEX:
        return not product_links
    return False


def write_outputs(records: List[Dict[str, str]]) -> None:
    DATA_DIR.mkdir(parents=True, exist_ok=True)
    RAW_DIR.mkdir(parents=True, exist_ok=True)

    with CSV_PATH.open("w", newline="", encoding="utf-8") as csv_file:
        writer = csv.DictWriter(csv_file, fieldnames=FIELDNAMES)
        writer.writeheader()
        writer.writerows(records)

    JSON_PATH.write_text(
        json.dumps(records, ensure_ascii=False, indent=2) + "\n",
        encoding="utf-8",
    )


def prepare_output_dirs() -> None:
    DATA_DIR.mkdir(parents=True, exist_ok=True)
    RAW_DIR.mkdir(parents=True, exist_ok=True)
    for html_file in RAW_DIR.glob("*.html"):
        html_file.unlink()


def crawl(args: argparse.Namespace) -> List[Dict[str, str]]:
    captured_at = datetime.now(timezone.utc).isoformat()
    fetcher = RespectfulFetcher(args.delay, args.timeout)
    queue: List[QueueItem] = []
    seen: Set[str] = set()
    records_by_url: Dict[str, Dict[str, str]] = {}

    for seed in SEED_URLS:
        normalized = normalize_url(seed)
        operation_type, is_index_page = classify_seed(normalized)
        queue.append(
            QueueItem(
                url=normalized,
                operation_type=operation_type,
                discovered_from="seed",
                is_index_page=is_index_page,
            )
        )

    while queue and len(seen) < args.max_pages:
        item = queue.pop(0)
        item.url = normalize_url(item.url)
        if item.url in seen:
            continue
        seen.add(item.url)

        allowed, robots_note = fetcher.can_fetch(item.url)
        if not allowed:
            records_by_url[item.url] = make_error_record(
                item,
                captured_at,
                "blocked_by_robots",
                robots_note,
            )
            continue

        result = fetcher.fetch(item.url)
        status_code = result.status_code
        if result.text:
            save_raw_page(result.final_url, result.text)

        if result.error or status_code is None or status_code >= 400:
            detail = result.error or f"HTTP {status_code}"
            records_by_url[item.url] = make_error_record(
                item,
                captured_at,
                "error",
                f"{robots_note}; {detail}",
            )
            continue

        if "html" not in result.content_type.lower() and result.text:
            records_by_url[item.url] = make_error_record(
                item,
                captured_at,
                "error",
                f"{robots_note}; respuesta no HTML: {result.content_type}",
            )
            continue

        soup = BeautifulSoup(result.text, "lxml")
        links = extract_links(soup, result.final_url)
        product_links = [link for link in links if path_basename(link) not in {ALQUILER_INDEX, VENTA_USADOS_INDEX}]

        if item.is_index_page:
            for card in extract_external_product_cards(soup, result.final_url):
                record_key = f"{result.final_url}#{card['slug']}"
                records_by_url[record_key] = make_linked_product_record(
                    item,
                    result,
                    card,
                    captured_at,
                    robots_note,
                )

            for link in product_links[: args.max_links_per_index]:
                if link not in seen:
                    queue.append(
                        QueueItem(
                            url=link,
                            operation_type=item.operation_type,
                            discovered_from=result.final_url,
                            is_index_page=False,
                        )
                    )

        if item.is_index_page and not should_include_index_page(result.final_url, product_links):
            continue

        records_by_url[result.final_url] = parse_product_record(
            item,
            result,
            captured_at,
            robots_note,
        )

    return list(records_by_url.values())


def main() -> int:
    parser = argparse.ArgumentParser(
        description="Indexa productos públicos de deados.com.py respetando robots.txt y rate limit bajo."
    )
    parser.add_argument(
        "--delay",
        type=float,
        default=2.5,
        help="Segundos mínimos entre requests HTTP. Debe mantenerse entre 2 y 3 para uso normal.",
    )
    parser.add_argument(
        "--timeout",
        type=float,
        default=30.0,
        help="Timeout de lectura por request en segundos.",
    )
    parser.add_argument(
        "--max-pages",
        type=int,
        default=30,
        help="Límite duro de páginas a visitar.",
    )
    parser.add_argument(
        "--max-links-per-index",
        type=int,
        default=20,
        help="Máximo de enlaces de producto a seguir desde cada índice.",
    )
    args = parser.parse_args()

    if args.delay < 2.0:
        raise SystemExit("El delay mínimo permitido por este indexador es 2.0 segundos.")

    prepare_output_dirs()
    records = crawl(args)
    write_outputs(records)

    print(f"Registros escritos: {len(records)}")
    print(f"CSV: {CSV_PATH}")
    print(f"JSON: {JSON_PATH}")
    print(f"HTML crudo: {RAW_DIR}")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
