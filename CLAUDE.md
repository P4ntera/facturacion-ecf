# Facturación e-CF

ERP de facturación con NCF (República Dominicana). Laravel 12 + PostgreSQL + Filament v4,
corriendo sobre Laravel Sail (WSL). Todo comando de artisan/npm/composer se ejecuta vía
`./vendor/bin/sail ...`.

## Seguridad

- Autorización: spatie/laravel-permission (roles y permisos) + Policies de Laravel por modelo
  (`app/Policies`), autodescubiertas por convención de nombres (Laravel 12, sin registro manual).
  Filament oculta del menú los Resources cuyo `viewAny()` sea `false`.
- Roles seed en `database/seeders/RolePermissionSeeder.php`: Administrador (todos los permisos),
  Vendedor (`registrar_ventas`, `gestionar_maestros`, `ver_reportes`), Almacenista
  (`gestionar_inventario`, `gestionar_compras`, `gestionar_maestros`).
- `User::canAccessPanel()` exige tener uno de esos roles.
- El login de Filament trae rate limiting nativo (5 intentos antes de bloqueo temporal).

### Hardening para producción

- `APP_DEBUG=false` y `APP_ENV=production` en el `.env` de producción — nunca exponer trazas de
  excepciones ni información de configuración a usuarios finales.
- No commitear `.env` (ya está en `.gitignore`); mantener secretos fuera del repo.

## Inventario (Kardex)

`App\Services\InventarioService::registrarMovimiento()` es el único punto que mueve stock. Bloquea
la fila del producto con `lockForUpdate`, y lanza `App\Exceptions\StockInsuficienteException` si el
resultado quedaría negativo. Debe invocarse dentro de una transacción abierta por el llamador.
