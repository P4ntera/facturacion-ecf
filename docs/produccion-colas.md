# Colas y tareas programadas en producción

El envío de e-CF a la DGII no se hace mientras el cajero cobra: la venta se guarda y el envío
sale por una cola. Si nada atiende esa cola, **ningún e-CF llega a la DGII**. Por eso en el
servidor de producción tienen que estar corriendo siempre dos cosas:

1. **El worker de colas**, que envía los e-CF (cola `ecf`) y los demás trabajos (cola `default`).
2. **El programador**, que cada 5 minutos reintenta los e-CF que quedaron pendientes y consulta
   el estado de los que están "en proceso" (`ecf:procesar-pendientes`).

En desarrollo con Sail esto ya está resuelto: los servicios `queue` y `scheduler` de
`compose.yaml` arrancan solos con `sail up`.

## 1. Worker con Supervisor

Supervisor reinicia el worker si se cae. Crear `/etc/supervisor/conf.d/facturacion-ecf-worker.conf`
(cambiar la ruta y el usuario por los del servidor):

```ini
[program:facturacion-ecf-worker]
process_name=%(program_name)s_%(process_num)02d
command=php /var/www/facturacion-ecf/artisan queue:work --queue=ecf,default --sleep=3 --max-time=3600
user=www-data
numprocs=1
autostart=true
autorestart=true
stopasgroup=true
killasgroup=true
stopwaitsecs=3600
redirect_stderr=true
stdout_logfile=/var/www/facturacion-ecf/storage/logs/worker.log
```

Y cargarlo:

```bash
sudo supervisorctl reread
sudo supervisorctl update
sudo supervisorctl start "facturacion-ecf-worker:*"
```

`--max-time=3600` hace que el worker se reinicie solo cada hora (Supervisor lo vuelve a
levantar), para que no acumule memoria.

## 2. Programador con cron

Una sola línea en el crontab del mismo usuario (`crontab -e -u www-data`):

```cron
* * * * * cd /var/www/facturacion-ecf && php artisan schedule:run >> /dev/null 2>&1
```

## 3. En cada despliegue

Después de subir código nuevo y correr las migraciones:

```bash
php artisan queue:restart
```

El worker termina el trabajo que tenga en curso y Supervisor lo levanta con el código nuevo. Sin
esto, el worker sigue corriendo el código viejo.

## Cómo saber si está funcionando

- `sudo supervisorctl status` debe mostrar el worker en `RUNNING`.
- `php artisan schedule:list` debe mostrar `ecf:procesar-pendientes` cada 5 minutos.
- En el panel, una venta electrónica nueva debe pasar de **Pendiente** a **Aceptado** (o
  **Rechazado**) en uno o dos minutos. Si se queda en Pendiente, el worker no está corriendo.
- Se puede forzar una corrida a mano: `php artisan ecf:procesar-pendientes`.
