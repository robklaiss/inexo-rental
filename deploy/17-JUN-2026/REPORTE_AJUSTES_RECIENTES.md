# Reporte de ajustes recientes - Inexo Rental

**Fecha de corte:** 11 de junio de 2026  
**Rama revisada:** `main`  
**Ultimo commit:** `05487fe` del 25 de mayo de 2026  
**Estado general:** existe una actualizacion amplia en el directorio de trabajo que todavia no fue confirmada en Git.

## Resumen ejecutivo

Los ajustes recientes amplian Inexo Rental desde el flujo basico de alquiler y compra hacia una operacion mas completa. Se incorporaron mejoras en checkout, calculo de flete, gestion de rutas, coordinacion con empresas logisticas, proformas, impuestos, recordatorios, documentos de clientes, contenido comercial y despliegue.

La actualizacion local modifica nueve archivos versionados, con aproximadamente 3.572 lineas agregadas y 248 eliminadas, y suma nuevos recursos, scripts operativos, pruebas y paquetes de despliegue.

## Ajustes ya confirmados en Git

El commit `05487fe`, registrado el 25 de mayo de 2026, incluyo:

- configuracion SMTP protegida mediante variables de entorno;
- mejoras en el checkout de alquiler;
- captura de datos fiscales y de entrega;
- integracion inicial con Google Maps;
- calculo y almacenamiento de flete;
- generacion y envio de proformas PDF;
- configuracion y calculo del producto especial Mano de Obra;
- mejoras visuales asociadas a estos flujos.

## Ajustes locales pendientes de commit

### Checkout y pedidos

- El cliente elige entre retiro en Inexo y entrega con logistica.
- Ambos flujos solicitan fecha y hora coordinada.
- Los campos de direccion, mapa, camion y flete aparecen solo para entregas.
- Los periodos diario, semanal y mensual sincronizan automaticamente las fechas de alquiler.
- Se mejoraron el resumen del carrito, subtotales, flete, impuesto y total.
- Se reforzo la validacion de usuarios existentes y contrasenas durante el checkout.
- Los pedidos guardan snapshots de cliente, datos fiscales, modalidad, agenda, entrega, ruta e impuestos.

### Rutas, flete y camiones

- Se agrego integracion de servidor con Google Routes API mediante `computeRoutes`.
- Se validan las coordenadas de origen y destino antes de calcular una ruta.
- Se guardan distancia, duracion, duracion con trafico, polyline y enlace directo a Google Maps.
- El calculo definitivo de flete usa distancia de ruta, costo por kilometro del camion y factor de ida/vuelta.
- Si Google Routes falla, el pedido se conserva con estado de ruta pendiente.
- El administrador puede recalcular posteriormente una ruta pendiente.
- Los productos y camiones incorporan peso, volumen, capacidad y condicion de apilado.
- Se agrego configuracion operativa para capacidades y costos de los camiones.

### Gestion logistica

- Se incorporaron empresas logisticas con contacto, email, telefono, notas, estado y token de acceso.
- Cada empresa dispone de un portal para revisar envios ofrecidos.
- Los envios muestran agenda, camion requerido, ruta, peso, volumen y apilado.
- La aceptacion de un envio se realiza con una actualizacion atomica: solo la primera empresa puede tomarlo.
- El administrador puede consultar y actualizar el estado de cada envio.

### Impuestos y proformas

- El nombre y porcentaje del impuesto son configurables.
- Cada pedido guarda una copia del nombre, tasa y monto del impuesto para preservar documentos historicos.
- La proforma PDF fue redisenada con identidad visual, datos del cliente, detalle de productos, entrega, flete, impuesto y total.
- El cliente puede descargar su proforma desde su cuenta.
- El administrador puede descargarla o enviarla por email.
- Se mejoraron los mensajes y registros de errores de envio.

### Correo y recordatorios

- `send_email()` ahora informa errores, registra intentos y reintenta fallos SMTP recuperables.
- Se agrego un fallback defensivo a `mail()` cuando SMTP falla.
- Se incorporaron recordatorios automaticos para pedidos programados dentro de las siguientes 24 horas.
- `reminder_sent_at` evita envios duplicados.
- El cron propuesto se ejecuta cada cinco minutos y usa bloqueo con `flock`.
- El proceso CLI carga la misma configuracion `.env` y SMTP que la aplicacion.
- Se agrego una utilidad para probar el SMTP configurado.

### Cuenta de cliente y documentos

- La cuenta muestra el estado de envio de la proforma y permite descargarla.
- Los clientes pueden subir documentos legales en PDF, JPG o PNG.
- Los documentos quedan almacenados fuera del acceso web directo.
- La descarga se realiza mediante rutas autenticadas para cliente o administrador.
- Se agregaron bloqueos explícitos para `.env`, base SQLite, logs y documentos privados.

### Contenido y experiencia visual

- Se incorporo una seccion de blog con listado, articulos y administracion.
- Se agrego contenido inicial orientado a alquiler de equipos.
- Se incorporaron ofertas destacadas y un popup comercial.
- Se agregaron mapas para seleccionar origen y destino mediante pin.
- Se mejoraron tarjetas de producto, carrito, checkout, formularios, documentos, blog y panel logistico.
- Se agregaron recursos visuales para camion y logotipo de la proforma.

### Administracion y base de datos

- `init_db()` agrega automaticamente tablas y columnas nuevas sin eliminar los datos existentes.
- Se incorporaron tablas para envios, empresas logisticas, documentos de usuarios y publicaciones del blog.
- El panel administrativo incluye nuevas areas de logistica, blog, documentos, impuestos, rutas y configuracion de camiones.
- Se agregaron scripts transaccionales para aplicar y comprobar la configuracion operativa.

### Despliegue

- Se actualizaron las instrucciones de produccion y las variables de entorno de ejemplo.
- PHP requiere ahora la extension `curl` para Google Routes API.
- El paquete de release incluye `README.md`, recursos, scripts y pruebas.
- Se agrego una plantilla de `/etc/cron.d` para recordatorios.
- Se prepararon paquetes de demo, revision y release actual.
- Las notas del release actual estan fechadas el 9 de junio de 2026.

## Validaciones realizadas

Al 11 de junio de 2026 se comprobaron:

- sintaxis PHP correcta en `index.php`, `router.php`, scripts operativos y prueba de rutas;
- sintaxis JavaScript correcta en `app.js`;
- prueba `tests/route_helpers_test.php` completada correctamente;
- `git diff --check` sin errores de espacios o formato.

## Pendientes antes de produccion

- Confirmar y organizar los cambios locales en uno o mas commits.
- Excluir o limpiar archivos `.DS_Store` y revisar cuales paquetes ZIP deben conservarse en el repositorio.
- Completar `deploy/operations.example.json` en una copia privada con origen, capacidades y costos reales.
- Configurar `INEXO_GOOGLE_ROUTES_API_KEY` e `INEXO_GOOGLE_MAPS_BROWSER_KEY` con las restricciones correctas.
- Ejecutar `php scripts/check_operational_config.php` hasta obtener `"ready": true`.
- Probar SMTP real con `php scripts/test_smtp.php`.
- Instalar y comprobar el cron de recordatorios en el servidor.
- Respaldar SQLite y ejecutar la migracion automatica antes de publicar.
- Hacer pruebas integrales de checkout, rutas, proformas, documentos y portal logistico en staging.

## Conclusion

La actualizacion cubre los principales procesos comerciales y operativos de Inexo Rental: cotizacion, retiro o entrega, calculo de ruta y flete, gestion de transportistas, documentacion del cliente, proforma e interaccion posterior al pedido. El codigo supera las comprobaciones locales basicas, pero la mayor parte de estos cambios sigue pendiente de commit y las integraciones externas deben validarse con la configuracion real del servidor antes de pasar a produccion.
