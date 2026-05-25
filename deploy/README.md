# Deploy de Inexo Rental

Esta carpeta contiene los archivos operativos para publicar el sitio en produccion.

La aplicacion es PHP + SQLite y puede publicarse en la raiz del dominio o bajo un subdirectorio como `/demo`. Si el hosting no informa correctamente el subdirectorio en `SCRIPT_NAME`, configurar `INEXO_BASE_PATH=/demo`.

## Requisitos del servidor

- Linux con Apache 2.4 o Nginx + PHP-FPM.
- PHP 8.1 o superior recomendado.
- Extensiones PHP: `pdo_sqlite`, `sqlite3`, `json`, `mbstring`, `iconv`, `fileinfo`, `openssl`, `session`.
- Escritura para el usuario web en:
  - `inexo_rental.sqlite3`
  - `uploads/`
  - `mail.log` si se quiere conservar el log local de correo
- SMTP real configurado por variables de entorno para contactos y actualizaciones de pedidos. No guardar claves SMTP dentro del repo.

## Archivos runtime que deben subir

Subir estos archivos y carpetas al document root:

- `.htaccess`
- `index.php`
- `router.php`
- `app.css`
- `app.js`
- `inexo-rental---tu-partner-en-cada-obra.webflow/`
- `uploads/`
- `inexo_rental.sqlite3`

Opcionales para mantenimiento/importacion:

- `requirements.txt`
- `scripts/`
- `data/`

No subir backups viejos de SQLite ni `mail.log` si contienen datos sensibles.

## Variables de entorno

Usar `production.env.example` como referencia y configurar esos valores en el panel del hosting, systemd, PHP-FPM, Apache o Nginx segun corresponda.

Variables importantes:

- `INEXO_ADMIN_EMAILS`: lista separada por coma o espacio de emails con acceso admin.
- `INEXO_BASE_PATH`: prefijo publico cuando la aplicacion vive bajo un subdirectorio, por ejemplo `/demo`. Dejar vacio para dominio raiz.
- `INEXO_SMTP_PASSWORD`: clave SMTP. Es necesaria para enviar contactos y actualizaciones de pedidos.
- `INEXO_CONTACT_EMAIL`: destino de formularios de contacto.

## Apache

Para hosting compartido, subir el `.htaccess` de la raiz del proyecto. Ya contiene:

- Rewrites a `index.php`.
- `DirectoryIndex index.php` para evitar 403 cuando se entra al directorio de la aplicacion.
- Bloqueo de listados de directorio.
- Bloqueo de SQLite, logs, backups, `.env`, `data/` y `deploy/`.

Para VPS con Apache, usar `apache-vhost.conf` como plantilla y ajustar:

- `ServerName`
- `DocumentRoot`
- version/socket de PHP-FPM si aplica
- rutas de logs

## Nginx

Usar `nginx-site.conf` como plantilla. Ajustar:

- `server_name`
- `root`
- socket o host de PHP-FPM
- rutas TLS si se agrega HTTPS directamente en Nginx

Nginx no lee `.htaccess`, por eso las reglas de bloqueo estan repetidas en `nginx-site.conf`.

## Build de release

Desde la raiz del repo:

```bash
deploy/scripts/build-release.sh
```

Genera un tarball en `deploy/releases/` con los archivos runtime. Por defecto no incluye `data/`; para incluirla:

```bash
INCLUDE_DATA=1 deploy/scripts/build-release.sh
```

## Backup y restauracion

Backup local:

```bash
deploy/scripts/backup-sqlite.sh
```

Restaurar un backup:

```bash
deploy/scripts/restore-sqlite.sh deploy/backups/inexo_rental-YYYYmmdd-HHMMSS.sqlite3
```

En produccion, programar `backup-sqlite.sh` con cron y copiar los backups fuera del servidor.

## Permisos recomendados

Ejemplo para VPS, ajustando usuario/grupo segun el hosting:

```bash
chown -R www-data:www-data uploads inexo_rental.sqlite3 mail.log
find uploads -type d -exec chmod 775 {} \;
find uploads -type f -exec chmod 664 {} \;
chmod 664 inexo_rental.sqlite3 mail.log
```

Si SQLite queda en modo solo lectura, el admin, pedidos, contactos y uploads van a fallar.

## Checklist antes de DNS

- `INEXO_ADMIN_EMAILS` tiene al menos un email real.
- SMTP envia correos de contacto y actualizaciones de pedidos.
- `/admin` redirige a login y solo entra un admin.
- `/contacto` registra y envia.
- `/api/checkout` crea pedido desde el carrito y registra al cliente con email y contrasena.
- `/uploads/products`, `/uploads/brands` y `/uploads/specializations` existen o son creables.
- `https://dominio/inexo_rental.sqlite3`, `/mail.log`, `/data/` y `/deploy/` devuelven 403/404.
- Hay backup probado y descargado fuera del servidor.

## Rollback

Antes de reemplazar una version:

1. Ejecutar `deploy/scripts/backup-sqlite.sh`.
2. Guardar copia de `uploads/`.
3. Conservar el tarball anterior.

Para rollback, restaurar el tarball anterior y, si hubo cambios de datos no deseados, restaurar el backup SQLite correspondiente.
