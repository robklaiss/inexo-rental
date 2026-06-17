# Inexo Rental

Sitio de catalogo y pedidos para Inexo Rental. La aplicacion permite publicar equipos por especializacion, agregar productos al carrito, registrar pedidos sin pago online y gestionarlos desde un administrador.

## Stack

- PHP 8.1+ con SQLite.
- Frontend exportado desde Webflow, complementado por `app.css` y `app.js`.
- Base de datos SQLite inicializada automaticamente por `index.php`.
- Scripts Python para indexar e importar productos publicos de Deados.

## Estructura

- `index.php`: aplicacion principal, rutas publicas, APIs, admin, correo y persistencia.
- `router.php`: router para el servidor embebido de PHP y assets estaticos.
- `app.js`: carrito en `localStorage`, reservas, checkout y UI interactiva.
- `app.css`: estilos adicionales sobre el export de Webflow.
- `inexo-rental---tu-partner-en-cada-obra.webflow/`: assets y HTML base exportados.
- `uploads/`: imagenes publicas de productos y marcas.
- `scripts/`: indexador/importador de productos Deados.
- `deploy/`: plantillas y scripts operativos para publicar en produccion.

## Rutas principales

- `/`: inicio.
- `/productos`: listado y busqueda.
- `/producto/{slug}`: detalle del producto.
- `/especializacion`: listado por especializaciones.
- `/marca`: listado de marcas.
- `/carrito`: carrito local.
- `/checkout`: registro de pedido y cuenta.
- `/contacto`: formulario de contacto.
- `/cuenta`: pedidos del cliente autenticado.
- `/admin`: panel de administracion.

## Configuracion local

```bash
php -S 127.0.0.1:8000 router.php
```

La base `inexo_rental.sqlite3` se crea automaticamente si no existe. En desarrollo se puede conservar localmente, pero no debe subirse al repositorio porque contiene pedidos, usuarios y consultas.

## Variables de entorno

```bash
INEXO_ADMIN_EMAILS=admin@example.com
INEXO_BASE_PATH=

INEXO_SMTP_HOST=mail.inexo.com.do
INEXO_SMTP_PORT=465
INEXO_SMTP_ENCRYPTION=ssl
INEXO_SMTP_USERNAME=info@inexo.com.do
INEXO_SMTP_PASSWORD=change-me
INEXO_SMTP_TIMEOUT=20

INEXO_MAIL_FROM=info@inexo.com.do
INEXO_MAIL_FROM_NAME="Inexo Rental"
INEXO_CONTACT_EMAIL=info@inexo.com.do

INEXO_GOOGLE_MAPS_BROWSER_KEY=
```

El paquete v02 incluido en esta carpeta ya tiene una Google Maps browser key guardada en la base demo para autocompletado de direcciones y calculo de distancia/flete. Si se define `INEXO_GOOGLE_MAPS_BROWSER_KEY` en el servidor, esa variable reemplaza el valor guardado en la base.

En deploy, el sitio tambien lee un archivo `.env` ubicado junto a `index.php`. Para que lleguen las proformas con PDF adjunto, configurar las variables `INEXO_SMTP_*`; si el envio falla, `mail.log` registra una linea `proforma-email ... sent=0 error=...` con el motivo.

## Checkout, flete y proformas

El checkout guarda datos fiscales, datos de entrega, coordenadas/Place ID cuando Google Places esta configurado, subtotales, flete y total en el pedido.

La proforma PDF se descarga desde `/admin/pedidos/{id}/proforma.pdf` y se puede enviar al cliente desde el detalle del pedido en admin.

El calculo de flete usa la configuracion de `/admin/configuracion`: direccion base, cargo base, precio por km, minimo y factor ida/vuelta. La distancia se completa con Google Maps Distance Matrix cuando hay `INEXO_GOOGLE_MAPS_BROWSER_KEY` o una clave guardada en admin; tambien puede ingresarse manualmente.

## Mano de Obra

El producto especial `Mano de Obra` usa tipos de trabajo configurables en `/admin/configuracion`. El checkout recalcula el total en backend y guarda el snapshot del calculo en `order_items.item_details_json`, incluyendo tipo de trabajo, tiempo, unidad, trabajadores, m², componentes y formula usada.

Formula centralizada en `calculate_labor_total()`:

```text
total = precio_base + (costo_trabajador x trabajadores x tiempo) + (costo_tiempo x tiempo) + (costo_m2 x m²)
```

## Importacion Deados

Instalar dependencias:

```bash
python3 -m pip install -r requirements.txt
```

Indexar paginas publicas:

```bash
python3 scripts/index_deados.py
```

Importar productos al catalogo local:

```bash
python3 scripts/import_deados_products.py
```

El indexador usa bajo volumen, respeta `robots.txt` cuando esta disponible y guarda resultados en `data/`.

## Deploy

Ver `deploy/README.md` para Apache, Nginx, backups, permisos y build de release.

```bash
deploy/scripts/build-release.sh
```

No publicar `mail.log`, backups, releases antiguos ni bases SQLite con datos reales.
