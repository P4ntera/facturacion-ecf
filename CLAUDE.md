# Facturación e-CF

ERP de facturación con NCF (República Dominicana). Laravel 12 + PostgreSQL + Filament v4,
corriendo sobre Laravel Sail (WSL). Todo comando de artisan/npm/composer se ejecuta vía
`./vendor/bin/sail ...`.

## Seguridad

- Autorización: spatie/laravel-permission (roles y permisos) + Policies de Laravel por modelo
  (`app/Policies`), autodescubiertas por convención de nombres (Laravel 12, sin registro manual).
  Filament oculta del menú los Resources cuyo `viewAny()` sea `false`.
- La mayoría de las Policies devuelven `false` fijo en `delete()`: es deliberado (nada se borra
  físicamente, los maestros/transacciones se desactivan o anulan en su lugar), no un permiso
  pendiente — no crear un permiso `*.eliminar` para "arreglarlo". La única excepción real es
  `UserPolicy::delete()`, gateada por `usuarios.gestionar`.

### Permisos

El sistema usa un catálogo granular por pantalla/acción, definido en `App\Support\Permisos`
(única fuente de verdad). Cada permiso sigue el formato `modulo.accion` (ej. `productos.crear`,
`ventas.anular`, `secuencias.administrar`). Reemplazó a un esquema anterior de permisos gruesos
(`gestionar_maestros`, `registrar_ventas`, etc.) que `RolePermissionSeeder` borra activamente de
la base de datos si los encuentra, para que no queden huérfanos.

- Los ROLES son **por empresa** (spatie/laravel-permission con `'teams' => true`, `empresa_id`
  como `team_foreign_key`): no existe un "Vendedor" global, cada empresa tiene el suyo. Se siembran
  vía `App\Services\RolesEmpresaService::sembrarRolesBase()` (llamado al crear una empresa nueva
  desde `EmpresaResource`, y desde `RolePermissionSeeder` para instalaciones/tests con empresas
  preexistentes) — roles base: Administrador (todos los permisos), Vendedor, Almacenista.
- Cualquier consulta/asignación de roles o permisos fuera del ciclo normal de una request de panel
  (seeders, tests, comandos) necesita `setPermissionsTeamId($empresa->id)` primero, o
  `Role::firstOrCreate()`/`hasRole()`/`can()` no encuentran nada.
- Gotcha conocido: `gestionar_arqueo_caja` es un permiso real en uso (Administrador y Vendedor lo
  tienen) que quedó fuera de `Permisos::catalogo()` — al tocar el catálogo, no asumir que
  `Permisos::todos()` es la lista completa de permisos que existen en la base de datos.
- `User::canAccessPanel()` exige tener al menos un rol.
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
