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

## Regla de tenancy para features nuevas

Toda tabla de negocio nueva (cabecera) DEBE llevar, desde el primer commit que la crea:

- `empresa_id` (FK a `empresas`, con índice; `restrictOnDelete()`, no cascade — no se borra el
  historial de una empresa por accidente al desactivarla).
- Relación `empresa(): BelongsTo` en el modelo, y `empresa_id` en su `$fillable`.
- Scoping manual de cualquier `Select`/`Repeater`/`CheckboxList`/`SelectFilter` que cargue
  registros relacionados (proveedores, productos, usuarios, etc.): el global scope automático de
  Filament (`BelongsToTenant::registerTenancyModelGlobalScope`) **no protege** una consulta directa
  al modelo (`Model::query()`, `Model::find()`, `->options(fn () => Modelo::query()...)`), ni
  siquiera dentro de un `->relationship()` — verificado empíricamente (T5): solo protege lo que el
  propio Resource arma en su `getEloquentQuery()`. Con `->relationship()` usa el parámetro
  `modifyQueryUsing`; con consultas manuales, agrega `->where('empresa_id', Filament::getTenant()->id)`
  a mano.
- Su caso correspondiente en el enum `App\Enums\Modulo` si el feature tiene su propio
  Resource/Page en el menú (ver `App\Filament\Concerns\RestringidoPorModulo`).
- En Services/Jobs: derivar `empresa_id` EXPLÍCITAMENTE de un parámetro `Empresa $empresa` (o de
  una entidad relacionada ya validada), nunca de `Filament::getTenant()` ambiente — falla fuera
  del ciclo de vida de una request de panel (colas, comandos, tests). Si el service recibe ids de
  entidades relacionadas (proveedor, producto...) que vienen de un formulario, son
  client-controllable: revalida ahí mismo que pertenezcan a `$empresa` antes de usarlas.
- `firstOrCreate()`/`updateOrCreate()`: `empresa_id` va en la CLAVE DE BÚSQUEDA (primer array), no
  solo en los valores — de lo contrario se puede reutilizar el registro de otra empresa (bug real
  detectado en T1 con PuntoDeVenta, y de nuevo en T5 con roles de prueba sin `empresa_id`).
- Rutas fuera del panel de Filament (PDF, export, endpoints públicos): el scoping del panel no
  corre ahí. Verificar a mano con `$request->user()->perteneceAEmpresa($registro->empresa_id)`
  (`abort_unless(..., 403)`) antes de servir cualquier dato.

Antes de dar por buena la tenancy de un feature nuevo, escribir (o extender)
`tests/Feature/AislamientoEntreEmpresasTest.php` con al menos: cada empresa solo ve sus propios
registros en el índice, no puede descargar/ver los de otra por URL, y crearlos los asocia
automáticamente a la empresa activa.

## Inventario (Kardex)

`App\Services\InventarioService::registrarMovimiento()` es el único punto que mueve stock. Bloquea
la fila del producto con `lockForUpdate`, y lanza `App\Exceptions\StockInsuficienteException` si el
resultado quedaría negativo. Debe invocarse dentro de una transacción abierta por el llamador.
