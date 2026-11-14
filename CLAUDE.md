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
- `gestionar_arqueo_caja` ya está incluido en `Permisos::catalogo()` (sección Ventas). Al editar
  un rol, `EditRole::handleRecordUpdate()` preserva permisos que existan en la BD pero no estén
  en el catálogo, para evitar pérdida accidental de datos al hacer `syncPermissions()`.
- `User::canAccessPanel()` exige tener al menos un rol.
- El login de Filament trae rate limiting nativo (5 intentos antes de bloqueo temporal).

### Hardening para producción

- `APP_DEBUG=false` y `APP_ENV=production` en el `.env` de producción — nunca exponer trazas de
  excepciones ni información de configuración a usuarios finales.
- No commitear `.env` (ya está en `.gitignore`); mantener secretos fuera del repo.
- `SUPER_ADMIN_PASSWORD` (mínimo 12 caracteres) es obligatoria en producción: sin ella
  `FilamentAdminSeeder` aborta sin crear nada. La contraseña demo solo vale en desarrollo/tests.
  El seeder nunca pisa una contraseña existente; si en producción encuentra un super-admin que
  sigue con la demo, lo avisa en consola para cambiarla a mano.

### Licencias (modo solo lectura)

Con `LICENSE_ENABLED=true`, una empresa sin licencia vigente queda en solo lectura. Dos capas:

- `VerificarLicencia` (middleware del panel) corta con 403 las acciones de Livewire que claramente
  escriben (`create`, `save`, `delete`, `callMountedAction`...).
- Todo lo demás pasa con el candado `App\Support\LicenciaSoloLectura` activado: cualquier
  INSERT/UPDATE/DELETE sobre una tabla de negocio lanza `LicenciaSoloLecturaException` antes de
  ejecutarse. Esta es la garantía real — no depende del nombre del método (`cobrar`, `facturar`...).
  Una tabla nueva de infraestructura que deba escribirse siempre (no de negocio) se agrega a
  `LicenciaSoloLectura::TABLAS_PERMITIDAS`.
- "Datos de la empresa" (`ManageEmpresa`) siempre puede guardar: ahí se registra la clave, y al
  cambiarla se valida en el momento.
- La firma RS256 del token se verifica con `App\Support\JwtRs256` (solo `ext-openssl`); no hay que
  instalar `firebase/php-jwt`.
- Sin internet, una licencia ya validada sigue vigente hasta `license_expires_at`.

### Instalación, uso sin internet y respaldos

Guía completa: `docs/instalacion-local.md`.

- Nada de CDN: fuentes, estilos y scripts se sirven desde la propia instalación. Inter y Manrope
  viven en `public/fonts/erp` (`fuentes.css`, enlazado con `LocalFontProvider`).
  `FuentesLocalesTest` falla si una vista o un CSS carga un recurso externo.
- PostgreSQL 18 o superior (`config/instalacion.php`): la búsqueda sin acentos no funciona antes.
- `ext-bcmath`, `ext-openssl` y `ext-pdo_pgsql` están en `composer.json`; `imagick` (QR de e-CF) y
  `zip` (respaldo de archivos) son recomendadas.
- `php artisan erp:verificar-instalacion` (solo lectura) revisa requisitos, seguridad y respaldos;
  correrlo al instalar y después de cada actualización.
- `php artisan erp:respaldo` (programado a diario) = `pg_dump` + zip de `storage/app` y `.env`, con
  retención y copia opcional a `RESPALDO_COPIA_DIRECTORIO`. Es global a la instalación, no por
  empresa.

### Errores que ve el usuario

- Al usuario solo le llegan mensajes escritos para él. Todo `catch` que muestre el mensaje de una
  excepción en una notificación usa `App\Support\MensajeError::para($e)`: las de negocio
  (`App\Exceptions\*`, o `RuntimeException`/`InvalidArgumentException` lanzadas tal cual por un
  service) muestran su mensaje; cualquier otra se registra en el log con una referencia corta y se
  muestra un texto genérico con esa referencia. Nunca `->title($e->getMessage())` directo:
  `catch (RuntimeException)` también atrapa `QueryException` y mostraría SQL.
- Cobrar (POS general, táctil y heladería) atrapa `Throwable`: un fallo inesperado sale como aviso
  y el carrito se conserva para reintentar.
- Fallos de una acción de Livewire (500, 403, sin red, sesión expirada): aviso en español dibujado
  en el navegador por `resources/views/filament/hooks/errores-livewire.blade.php`, sin depender del
  servidor. Páginas completas de error en `resources/views/errors` (plantilla `erp.blade.php`).

### El carrito del POS viene del navegador

Las propiedades públicas de Livewire (`$carrito`, `$descuentoId`...) se pueden reescribir desde el
navegador. De ellas solo se cree QUÉ se pidió y la cantidad; precio, factor y descripción se
vuelven a leer de la base antes de calcular y de cobrar (`PuntoDeVenta::sanearCarrito()`,
`HeladeriaService::revalidarCarrito()`). Los descuentos (global y por línea) exigen
`ventas.aplicar_descuento` en el servidor, no solo ocultar el campo. Un POS nuevo debe seguir la
misma regla y cubrirla con un test de "carrito manipulado".

Precio que vale en el POS, en este orden: lista de precio del cliente seleccionado (solo unidad
base, lista activa y de la misma empresa) → precio de la presentación → precio del producto. En
lavandería, el precio escrito a mano en el ticket solo cuenta con `lavanderia.precio_manual`; sin
él manda la matriz prenda×servicio. En heladería salen gratis los toppings más baratos de los
elegidos (`HeladeriaService::toppingsCobrados()` es la única fuente de esa regla).

### Rendimiento, logs y límites de uso

- N+1: toda relación que una columna, acción o `visible()` de una tabla lea por fila va en el
  `with()` de `getEloquentQuery()`. Lo que es igual para todas las filas (p. ej. la impresora del
  usuario) se resuelve una vez por petición con `once()`. `VentaResourceTest` comprueba que el
  listado hace las mismas consultas con 2 ventas que con 7.
- Consultas agregadas que se hidratan como un modelo (reportes con `fromSub`): usar
  `Modelo::withoutGlobalScopes()` en la consulta externa (el scope de empresa del panel agregaría
  `tabla.empresa_id` sobre un resultado que no tiene esa columna) y no nombrar una columna
  calculada igual que un atributo con cast del modelo (`tipo`, `tipo_venta` en Producto).
  Probar estos reportes CON datos y dentro del panel: vacíos no fallan.
- Índices: PostgreSQL no indexa las claves foráneas. Una tabla hija nueva (detalles, pagos) lleva
  índice por su padre desde su migración.
- Servicios de terceros (RNC, cédula): siempre por `App\Support\ConsultaExterna` (caché de un
  día + máximo de consultas nuevas por minuto por usuario).
- Lentitud: `App\Support\RegistroDeLentitud` anota en `storage/logs/lento-*.log` toda consulta de
  más de 500 ms y toda petición de más de 2 s (sin valores, solo el SQL). Resumen:
  `php artisan erp:lentitud`. Umbrales y límites en `config/instalacion.php`.
- Límites por minuto (por usuario, o IP sin sesión): `throttle:livewire` (900) en toda acción de
  Livewire, `throttle:pdf` (30) en comprobantes y tickets, `throttle:reportes-pdf` (10) en
  reportes, login de Filament (5 intentos). Una ruta nueva fuera del panel lleva su `throttle`.
- Sin sesión, las rutas con `auth` redirigen al login del panel (`redirectGuestsTo` en
  `bootstrap/app.php`); la app no tiene ruta `login`.

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

## e-CF (Comprobante Fiscal Electrónico)

Flujo: `VentaService` → `VentaObserver` → `EnviarEcfJob` (cola `ecf`) →
`EnvioEcfService` → `EcfBuilder` (JSON) → `DgiiGatewayInterface` (PAC).

- `EcfBuilder` solo **mapea** montos ya calculados por `VentaService`/`ImpuestoStrategy` al formato
  JSON del e-CF DGII; nunca recalcula. Solo soporta tipos 31, 32, 33, 34
  (`TipoComprobante::soportadoPorEcfBuilder()`); los demás electrónicos (44, 45, 46) se rechazan
  con `EcfInvalidoException`.
- `DgiiGatewayFactory::make($empresa)` resuelve el gateway por empresa: `FakeGateway` en tests
  (`DGII_FAKE_GATEWAY=true` en `phpunit.xml`), `EcfPlatformGateway` en producción.
  `DGII_FORZAR_GATEWAY_REAL=true` permite usar el gateway real en local, pero SOLO para
  `TesteCF` (nunca producción).
- `EnvioEcfService` valida que `dgii_base_url` y `dgii_api_key` estén configuradas antes de
  enviar; si falta alguna, rechaza con motivo claro sin intentar la conexión. En el formulario de
  empresa son "las dos o ninguna" (`requiredWith`), no obligatorias siempre.
- `SecuenciaNcfService::tiposDisponibles()` no ofrece al POS electrónicos de venta sin soporte en
  el builder (44, 45, 46), aunque tengan secuencia cargada.
- `TasaItbis::EXENTO` (`IndicadorFacturacion` = 4) es distinto de `TasaItbis::CERO`
  (`IndicadorFacturacion` = 3): exento no graba ITBIS y va a `MontoExento`, no a `MontoGravadoI3`.
- `IndicadorMontoGravado` (no `IndicadorServicioTodoIncluido`) se usa cuando
  `precio_incluye_itbis` es `true`.
- `CodigoModificacion` en NC: 1 = anulación total, 3 = corrección de montos (parcial o manual).
- `ecf_fecha_firma` (datetime, nullable) se extrae de la respuesta del PAC; si no viene y el
  e-CF quedó aceptado, se lee `FechaHoraFirma` del XML firmado. Se muestra en PDF y ticket.
- Impresos: el QR solo sale con el e-CF aceptado; mientras no lo esté, PDF y ticket llevan el
  aviso "pendiente de validación". El monto exento se muestra en su propia línea.
- Módulos verticales (lavandería, heladería): NO asumen "sin comprobante". Ofrecen lo que da
  `App\Support\ComprobanteVenta::opciones($empresa)` (sin comprobante si está habilitado, tipos B
  con secuencia, e-CF solo con `usa_ecf`) y sus totales salen de `calcularTotales()` de cada
  servicio, que usa la misma `ImpuestoStrategy` que `VentaService` (`aplica_itbis`,
  `precio_incluye_itbis`). El vuelto se calcula en el servicio, no en el formulario.
- `EcfBuilder` recorta `NombreItem` a 80 caracteres y nunca declara en efectivo más que el total.
- Comando de diagnóstico: `php artisan ecf:diagnostico {venta_id}` (solo lectura, no envía nada).

## Datos demo (seeders)

`DatabaseSeeder` = `FilamentAdminSeeder` + `RolePermissionSeeder` + `LavanderiaDemoSeeder` +
`HeladeriaSeeder` + `VeterinariaDemoSeeder`. Cada vertical crea TRES empresas (slugs
`demo-{vertical}-informal|tipo-b|ecf`) con la base común `Database\Seeders\Concerns\SiembraEmpresaDemo`:
informal (sin ITBIS, solo sin comprobante), tipo B (ITBIS incluido, B01/B02/B04) y e-CF (ITBIS
aparte, E31–E34, sin "sin comprobante"). Son idempotentes y abortan en producción.
`tests/Feature/SeedersDemoTest.php` los corre dos veces y valida totales y aislamiento. Prefijo
de secuencia = serie + tipo (`E31`, `B02`): el servicio agrega el secuencial.

## Inventario (Kardex)

`App\Services\InventarioService::registrarMovimiento()` es el único punto que mueve stock. Bloquea
la fila del producto con `lockForUpdate`, y lanza `App\Exceptions\StockInsuficienteException` si el
resultado quedaría negativo. Debe invocarse dentro de una transacción abierta por el llamador.
