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
INEXO_GOOGLE_ROUTES_API_KEY=
```

## Checkout, flete y proformas

El checkout pregunta primero si el pedido se retira en Inexo o se entrega con logistica. Solo la entrega solicita direccion, pin, camion y flete. Ambos flujos requieren fecha y hora coordinada.

Para entregas, el backend vuelve a validar el pin, llama a Google Routes API con `computeRoutes` y crea un envio con origen, destino, distancia, duracion, duracion con trafico, polyline, enlace de Google Maps, peso, volumen y condicion de apilado. Las empresas activas reciben la oferta en su portal con token; la asignacion usa una actualizacion atomica para que solo la primera aceptacion sea valida.

`INEXO_GOOGLE_ROUTES_API_KEY` debe ser una clave apta para llamadas de servidor y restringida a Routes API. Si no esta definida, se usa la clave de Maps existente como compatibilidad. Si Routes API falla, el pedido y el envio quedan como `Ruta pendiente de recalcular`; no se envia una proforma provisional y el admin puede reintentar desde el detalle del pedido.

La proforma PDF se descarga desde `/admin/pedidos/{id}/proforma.pdf` y se puede enviar al cliente desde el detalle del pedido en admin.

El nombre y porcentaje del impuesto se configuran en `/admin/configuracion`. Cada pedido guarda un snapshot de nombre, tasa y monto para que futuras modificaciones no alteren proformas anteriores.

El calculo definitivo de flete usa la distancia devuelta por Routes API y la configuracion de `/admin/configuracion`: coordenadas de la base, precio por km del camion y factor ida/vuelta. La distancia que muestra el navegador durante el checkout es solo una referencia; el backend siempre intenta reemplazarla con el resultado autoritativo.

## Recordatorios 24 horas antes

El recordatorio usa `send_email()` y la misma configuracion SMTP de la aplicacion en produccion. El proceso CLI carga automaticamente el `.env` ubicado junto a `index.php`; no se configura un segundo SMTP para cron.

Ejecutar cada 5 minutos mediante el wrapper con bloqueo:

```bash
cd /var/www/inexo-rental/current
PHP_BIN=/usr/bin/php ./scripts/run_order_reminders.sh
```

El proceso selecciona pedidos cuya fecha programada ocurre dentro de las siguientes 24 horas. `orders.reminder_sent_at` evita duplicados; si el envio falla, queda pendiente para el siguiente intento.

La plantilla lista para `/etc/cron.d` esta en `deploy/cron.d/inexo-rental`. Antes de activarla, verificar toda la configuracion operativa:

```bash
php scripts/check_operational_config.php
php scripts/test_smtp.php info@inexo.com.do
```

La prueba SMTP no cambia credenciales: verifica las que ya estan configuradas en produccion.

## Configuracion operativa de logistica

Completar una copia privada de `deploy/operations.example.json` con la direccion o coordenadas reales de Inexo y, para cada camion, capacidad en kg, capacidad en m3 y costo por km. Luego aplicar:

```bash
php scripts/configure_operations.php /ruta/privada/operations.json
php scripts/check_operational_config.php
```

El configurador rechaza capacidades y costos iguales o menores a cero y hace todos los cambios dentro de una transaccion.

## Migraciones automaticas

`init_db()` mantiene compatibilidad con bases existentes mediante `CREATE TABLE IF NOT EXISTS` y `ensure_column()`. Antes de publicar:

```bash
deploy/scripts/backup-sqlite.sh
php -r 'putenv("INEXO_SKIP_DISPATCH=1"); require "index.php"; init_db();'
```

Se agregan campos logisticos a productos, camiones y envios; snapshots de impuesto, modalidad, agenda, ruta y recordatorio a pedidos; y las tablas `shipments`, `logistics_companies`, `user_documents` y `blog_posts`.

Los documentos legales se guardan en `data/private_documents/`. Nginx y `.htaccess` bloquean acceso directo; se descargan mediante rutas autenticadas de cuenta o admin.

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
