# Instalación local (negocio con o sin internet)

Guía corta para una instalación en la PC del negocio (Ubuntu o Windows 11 nativos). El sistema
vende, imprime y reporta sin internet; la conexión solo hace falta para lo que se indica al final.

## 1. Requisitos

| Requisito | Detalle |
|---|---|
| PHP 8.2+ | Extensiones `pdo_pgsql`, `bcmath`, `openssl`, `mbstring`, `intl` (obligatorias: `composer install` se niega sin `bcmath`/`openssl`/`pdo_pgsql`). |
| `imagick` | Para el código QR del PDF y del ticket de los e-CF. Sin ella esos impresos dan error. |
| `zip` | Para que el respaldo incluya logos, certificados y `.env`. |
| PostgreSQL **18 o superior** | La búsqueda sin acentos no funciona en versiones anteriores (falla el buscador del POS). |
| `pg_dump` | El del propio PostgreSQL 18. En Windows, poner la ruta completa en `RESPALDO_PG_DUMP`. |

## 2. Primer arranque

```bash
composer install --no-dev --optimize-autoloader
npm ci && npm run build
php artisan migrate --force
php artisan storage:link
# En el .env: APP_ENV=production, APP_DEBUG=false y SUPER_ADMIN_PASSWORD (mínimo 12 caracteres)
php artisan db:seed --class=FilamentAdminSeeder --force
php artisan db:seed --class=RolePermissionSeeder --force
php artisan config:cache
```

Después, siempre:

```bash
php artisan erp:verificar-instalacion
```

Revisa requisitos, seguridad y respaldos sin cambiar nada. No entregar una instalación con algún
punto en **CRÍTICO**.

## 3. Tareas programadas (obligatorio)

El respaldo diario, el reintento de e-CF pendientes y la revalidación de licencias dependen del
programador de Laravel. Sin esto **no hay respaldos automáticos**.

- **Ubuntu** (crontab del usuario de la app):
  `* * * * * cd /ruta/al/erp && php artisan schedule:run >> /dev/null 2>&1`
- **Windows**: una tarea del Programador de tareas que corra cada minuto
  `php.exe artisan schedule:run` con la carpeta del ERP como directorio de inicio.

Las colas se documentan en `docs/produccion-colas.md`.

## 4. Respaldos

`php artisan erp:respaldo` genera en `storage/app/respaldos`:

- `erp-AAAAMMDD-HHMMSS.dump`: la base completa (todas las empresas), formato `pg_dump` custom.
- `erp-AAAAMMDD-HHMMSS-archivos.zip`: logos, fotos, certificados `.p12` y el `.env`.

Corre solo todos los días a la hora de `RESPALDO_HORA` (12:30 por defecto: de madrugada la PC de
un negocio suele estar apagada) y conserva `RESPALDO_RETENCION_DIAS` días (14).

**Configure siempre `RESPALDO_COPIA_DIRECTORIO`** (otro disco, una USB fija o una carpeta
sincronizada con la nube). Un respaldo que solo vive en el mismo disco que la base no sirve si
ese disco muere. Si la carpeta no está disponible, el respaldo local se hace igual y queda el
aviso.

El zip contiene la `APP_KEY` y el certificado de firma: trátelo como un secreto.

### Restaurar

```bash
createdb -U erp erp_restaurada
pg_restore --no-owner -U erp -d erp_restaurada erp-AAAAMMDD-HHMMSS.dump
```

Luego descomprimir el zip sobre la carpeta del ERP (recupera `storage/app` y el `.env`) y apuntar
`DB_DATABASE` a la base restaurada. Sin la `APP_KEY` original, las claves cifradas (API key del
PAC, contraseña del certificado, token de licencia) no se pueden leer.

Pruebe una restauración al menos una vez por instalación: un respaldo que nunca se restauró no
está comprobado.

## 5. Actualizar

```bash
php artisan erp:respaldo          # siempre antes
git pull
composer install --no-dev --optimize-autoloader
npm ci && npm run build
php artisan migrate --force
php artisan config:cache
php artisan erp:verificar-instalacion
```

## 6. Logs y rendimiento

- En producción use `LOG_STACK=daily` y `LOG_LEVEL=warning`: un archivo por día que se borra solo
  a los 14 días. Con `single` el archivo crece sin límite.
- `storage/logs/lento-AAAA-MM-DD.log` anota cada consulta de más de 500 ms y cada petición de más
  de 2 s. Revíselo de vez en cuando con:

  ```bash
  php artisan erp:lentitud          # últimos 7 días
  php artisan erp:lentitud --dias=30
  ```

  Si una pantalla aparece muchas veces ahí, ese es el problema a atacar.
- Un error inesperado que vio un usuario trae una referencia (`ref. ABC123`): búsquela en
  `storage/logs/laravel*.log` para ver el detalle.
- Límites de uso por minuto (`LIMITE_LIVEWIRE`, `LIMITE_PDF`, `LIMITE_REPORTES_PDF`): los valores
  por defecto están muy por encima del uso normal. Si un negocio comparte un mismo usuario entre
  muchas cajas y ve el aviso "Demasiados intentos", suba `LIMITE_LIVEWIRE`.

## 7. Qué necesita internet

| Función | Sin internet |
|---|---|
| Vender, cobrar, imprimir, reportes, inventario | Funciona normal. |
| e-CF | La venta se registra y queda pendiente; se envía sola al volver la conexión (reintento cada 5 minutos). El QR sale cuando la DGII lo acepta. |
| Consulta de RNC | No disponible: se escribe el nombre a mano. |
| Licencia | Una licencia ya validada sigue vigente hasta su fecha de vencimiento. |
| Actualizaciones (`git pull`, `composer`, `npm`) | Requieren conexión. |
