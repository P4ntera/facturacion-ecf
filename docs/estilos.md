# Identidad visual del panel (Stitch): mapa de estilos

Este documento mapea de dónde sale cada parte de la identidad visual del panel/POS y, sobre todo,
**qué archivo tocar para cambiarla**. La regla de fondo de todo este rediseño: la paleta,
tipografía y radios viven en **un solo archivo** (`resources/design-system/variables.css`);
todo lo demás (Filament, POS, botones) lee de ahí, directa o indirectamente.

## Guía rápida: "cómo cambio…"

Todos los tokens de esta guía viven en `resources/design-system/variables.css`, sección
`SUPERFICIES / FONDOS DEL PANEL` (buscar ese título dentro del archivo). Después de editar
cualquiera, siempre: `./vendor/bin/sail npm run build`.

- **Cambiar el FONDO DE LA PÁGINA** (el gris detrás de todo): edita `--fondo-pagina` en
  `resources/design-system/variables.css` y corre `npm run build`. No hay que tocar el slot
  `gray` de `AdminPanelProvider.php` — el fondo de página lee directo de este token, no del slot
  (ver la sección "Capas de superficie" más abajo para el detalle de por qué).
- **Cambiar el FONDO DE LAS TARJETAS** (las cajas blancas: Sections, stat cards, modales, tablas,
  tarjetas del POS): edita `--fondo-tarjeta` en `resources/design-system/variables.css` y
  `npm run build`.
- **Cambiar el FONDO DEL MENÚ lateral**: edita `--fondo-sidebar` en
  `resources/design-system/variables.css` y `npm run build`.
- **Cambiar el COLOR PRIMARIO** (botones/acentos/ítem activo del sidebar): dos lugares —
  `--primary` en `resources/design-system/variables.css` **y** el slot `primary` en
  `->colors([...])` de `app/Providers/Filament/AdminPanelProvider.php` (mismo hex en los dos; ver
  sección 4 más abajo del porqué).
- **Cambiar el BORDE de las tarjetas**: edita `--borde-tarjeta` en
  `resources/design-system/variables.css` y `npm run build`. (Este mismo token también pinta el
  borde del sidebar y de los paneles flotantes como el menú de usuario — mismo valor en todos.)
- **Cambiar la SOMBRA de las tarjetas**: edita `--sombra-tarjeta` en
  `resources/design-system/variables.css` y `npm run build`.

## 1. Entry point de Vite

Uno solo: `resources/css/filament/admin/theme.css` (`->viteTheme(...)` en
`AdminPanelProvider.php`). Los remanentes de Breeze (`resources/css/app.css`,
`resources/js/app.js`, `tailwind.config.js`, y las versiones sin scope de
`buttons/forms/tables/badges/components.css`) se eliminaron: no los servía ninguna vista.

`theme.css` importa, en orden:

```css
@import url('https://fonts.bunny.net/css?family=manrope:...');   /* Manrope, para titulares */
@import '.../vendor/filament/filament/resources/css/theme.css';   /* base de Filament */

@import '../../../design-system/variables.css';   /* PALETA, RADIOS, SUPERFICIES — fuente única */
@import '../../../design-system/colors.css';       /* alias semánticos (--text, --link...) */
@import '../../../design-system/typography.css';   /* --font-headline, --font-body, --fs-* */
@import '../../../design-system/spacing.css';
@import '../../../design-system/shadows.css';

@import '../../../design-system/pos.css';           /* .card/.btn/.table/... SOLO para el POS */
```

Después de los imports, `theme.css` tiene además varias reglas propias (capas de superficie,
radios de Filament, stat cards, tipografía, sidebar) — ver el resto de este documento.

## 2. Capas de superficie: fondo de app vs. tarjeta vs. borde/sombra

Esto es lo que corrige el bug de "fondo blanco y cuadros raritos": tres capas con tokens propios,
cada uno con un único trabajo. Los 5 tokens viven TODOS juntos en
`resources/design-system/variables.css`, sección "SUPERFICIES / FONDOS DEL PANEL" (antes estaban
repartidos entre `variables.css`, `colors.css` y `shadows.css` bajo nombres que no decían qué
eran — `--surface-app`, `--surface`, `--border`, `--shadow-card` — de ahí que no quedara claro
dónde cambiar cada fondo; ahora el nombre del token ES la explicación).

| Capa | Token | Valor | Dónde se aplica |
|---|---|---|---|
| Fondo de la página (la más atrás, detrás de todo) | `--fondo-pagina` | `#F4F6FA` (gris azulado muy suave) | `.fi-body` en `theme.css` |
| Fondo de tarjeta | `--fondo-tarjeta` | `#FFFFFF` (blanco puro) | `.fi-section`, stat cards, modales, `.fi-ta-ctn`, `.pos-screen .card` |
| Fondo del sidebar | `--fondo-sidebar` | `#F7F7F8` (casi blanco, distinto del blanco de tarjeta) | `.fi-sidebar` |
| Borde de la tarjeta | `--borde-tarjeta` | `#E5E7EB` | Junto con `--fondo-tarjeta`; también el borde del sidebar y de los paneles flotantes (`.fi-dropdown-panel`) |
| Sombra de la tarjeta | `--sombra-tarjeta` | `0 1px 3px rgb(16 24 40 / .06), 0 1px 2px rgb(16 24 40 / .04)` | Junto con `--fondo-tarjeta`; también los paneles flotantes |

**El error que esto reemplaza:** una versión anterior pintaba de gris
`.fi-section-content-ctn` — que no es la tarjeta (`.fi-section`) sino su contenedor INTERNO de
contenido — mientras la tarjeta en sí seguía blanca por default de Filament. El resultado era un
rectángulo gris flotando dentro de una tarjeta blanca ("cuadro rarito"). La regla ahora vive en
`.fi-section`, `.fi-wi-stats-overview-stat`, `.fi-modal-window` y `.fi-ta-ctn` directamente (los
selectores que Filament usa de verdad para pintar cada tarjeta — confirmado leyendo
`vendor/filament/support/resources/css/components/section.css`,
`vendor/filament/widgets/resources/css/stats-overview-widget.css` y
`vendor/filament/tables/resources/css/container.css`), no en su contenedor interno.

**Excepción que hay que tener presente**: el fondo de página NO pasa por el slot `gray` de
Filament (aunque ese slot también existe y controla otras cosas — texto secundario, bordes finos
de tabla). `.fi-body` lee `--fondo-pagina` directo por una regla explícita en `theme.css`, así que
cambiar el token alcanza; no hace falta tocar `AdminPanelProvider.php` para este caso.

**Para cambiar el contraste de capas**: los 5 tokens en
`resources/design-system/variables.css` (ver la guía rápida al principio de este documento). El
bloque que los aplica está en `theme.css`, sección "CAPAS DE SUPERFICIE" — no hace falta tocarlo
salvo que cambie qué elementos de Filament deban considerarse "tarjeta".

## 3. Stat cards (KPIs): ícono con fondo de color

`app/Filament/Widgets/ReporteStatsOverviewWidget.php` (el widget de KPIs del dashboard) usa
`Stat::make(...)->icon(...)->color(...)->descriptionIcon(...)`. Filament NO colorea el ícono de
un Stat por defecto — solo la descripción y el mini-gráfico reaccionan a `->color()`. La única
forma de lograr el ícono con fondo de color suave es un override de la vista de Filament:

- `resources/views/vendor/filament-widgets/stats-overview-widget/stat.blade.php` — copia del
  original de `vendor/filament/widgets/resources/views/stats-overview-widget/stat.blade.php` que
  envuelve el ícono en `<span class="stat-icon-chip stat-icon-chip-{color}">`. Si Filament cambia
  esta vista en una actualización del paquete, hay que revisar el diff a mano (está documentado en
  un comentario al principio del archivo).
- `theme.css`, sección "STAT CARDS" — define `.stat-icon-chip-primary` (etc.) con los tokens del
  proyecto (`--primary-100` de fondo, `--primary-600` de ícono), no con la paleta OKLCH interna de
  Filament.

Formato de moneda: `ReporteStatsOverviewWidget` arma sus valores con `Number::currency(...)` de
Laravel directo en PHP, NO con un componente `->money()` de Filament — así que no lo cubre el
`Table::configureUsing()`/`Schema::configureUsing()` de la sección 9. Se fija por separado con
`Number::useLocale('es_DO')` en `AppServiceProvider::boot()`, mismo motivo y mismo locale.

**Para agregar/cambiar un KPI**: `ReporteStatsOverviewWidget::getStats()`. Para cambiar el color
del chip de ícono: los tokens en `variables.css` (el chip ya lee `--{color}-100`/`-600` según el
`->color()` del Stat, no hace falta tocar el override de la vista).

## 4. La paleta: un solo lugar, dos consumidores

`resources/design-system/variables.css` define 6 colores base (`--primary`, `--secondary`,
`--tertiary`, `--neutral`, `--warning`, `--danger`), cada uno con su escala completa `-50` a
`-950`, más los radios (`--radius-sm`, `--radius`, `--radius-lg`) y los tokens de superficie de la
sección 2.

**Para cambiar un color de identidad (p. ej. `--primary`) hay que tocarlo en DOS lugares:**

1. `resources/design-system/variables.css` — el token y su escala. Esto repinta el POS
   (`pos.css` lee estos tokens) y cualquier CSS del panel que los use directamente.
2. `app/Providers/Filament/AdminPanelProvider.php`, dentro de `->colors([...])` — el mismo hex,
   porque Filament genera su propia paleta OKLCH en PHP y no puede leer variables CSS. Cada línea
   de `->colors([...])` está comentada con el token del que copia el valor (`// --primary`, etc.)
   para que sea fácil mantenerlos sincronizados.

Mapeo de slots de Filament ↔ tokens:

| Slot de Filament | Token | Uso |
|---|---|---|
| `primary` | `--primary` (`#5D87FF`) | Acento principal, botones primary, ítem activo del sidebar |
| `info` | `--secondary` (`#49BEFF`) | Filament no tiene slot "secondary"; el rol informativo de Filament es el que mejor mapea al secondary de Stitch |
| `success` | `--tertiary` / `--success` (`#13DEB9`) | Estados de éxito |
| `warning` | `--warning` (`#F59E0B`) | Estados de alerta |
| `danger` | `--danger` (`#EF4444`) | Estados de error/peligro |
| `gray` | `--neutral` (`#7C808D`) | Grises puntuales de Filament (bordes de tabla, texto secundario por defecto). NO controla el fondo del panel — eso lo hace `--fondo-pagina` (sección 2), directo, sin pasar por este slot |
| `dark` (slot extra, no nativo) | `--neutral-900`/`950` | Variante "Inverted" de botones (ver sección 7) |

## 5. Radios: el truco de la variable compartida

Tailwind v4 expone su escala de radios como variables CSS propias (`--radius-lg`, `--radius-xl`,
que las utilidades compiladas de Filament usan directo: `rounded-lg` en botones/inputs,
`rounded-xl` en el contenedor de cada Section/tarjeta). `variables.css` define **su propio**
`--radius-lg` con ese mismo nombre a propósito: como se importa después del `theme.css` base de
Filament, gana en la cascada y agranda botones e inputs sin tocar ni un selector de Filament.
`theme.css` solo necesita una línea más para igualar `--radius-xl` (borde exterior de la
tarjeta) al mismo valor, para que no quede más chico que su contenido:

```css
:root{ --radius-xl:var(--radius-lg); }
```

**Para cambiar el radio de TODO el panel + POS: edita `--radius`/`--radius-lg` en
`variables.css`.** No hay que tocar `theme.css` ni ningún Resource.

## 6. Tipografía

- `--font-body` (Inter) — cuerpo de texto. `AdminPanelProvider.php` usa `->font('Inter')`
  (proveedor Bunny Fonts, el mismo que usa Filament por defecto).
- `--font-headline` (Manrope) — titulares. Filament no permite una segunda familia solo para
  headings vía `->font()`, así que se aplica en `theme.css` sobre las hook classes de heading de
  Filament (`.fi-header-heading`, `.fi-section-header-heading`, `.fi-modal-heading`, etc.) y se
  carga con su propio `@import url(...)` de Bunny Fonts al inicio de `theme.css`.
- El título de página (`.fi-header-heading`) además tiene un tamaño fijo (1.875rem) en vez del
  `text-2xl`/`text-3xl` responsive de Filament, para que pese lo mismo en cualquier pantalla. El
  texto secundario (`.fi-header-subheading`, descripciones de Section, labels de los stat cards)
  usa `--text-muted` explícitamente, para no depender de qué gris le toque a Filament por default.

**Para cambiar de fuente: edita `--font-headline`/`--font-body` en
`resources/design-system/typography.css` y actualiza el `@import` de Bunny Fonts en `theme.css`
(y `->font()` en `AdminPanelProvider.php` si cambia la de cuerpo).**

## 7. Botones: 4 variantes, nativas de Filament

Los 4 estilos de Stitch (Primary/Secondary/Inverted/Outlined) mapean 1:1 a mecanismos nativos de
Filament — no hace falta CSS custom para el panel:

| Variante Stitch | Cómo se logra en una Action de Filament | Clase equivalente en POS (`pos.css`) |
|---|---|---|
| Primary (sólido) | Color por defecto, sin `->outlined()` (Filament ya pinta sólido, shade 600) | `.btn-primary` |
| Secondary (gris suave) | `->color('gray')` (Filament no le pone color: fondo blanco/borde suave) | `.btn-secondary` |
| Inverted (oscuro) | `->color('dark')` — slot custom registrado en `AdminPanelProvider.php` | `.btn-inverted` |
| Outlined (borde) | `->outlined()` | `.btn-outlined` |

El slot `dark` (ver sección 4) usa una escala explícita en vez de `Color::hex()`: Filament pinta
el fondo sólido de un botón con el shade 600 de su color, así que ese shade tiene que ser YA el
oscuro que se quiere (los shades 700-950 son los mismos pasos de `--neutral-900`/`--neutral-950`
de `variables.css` — si esos tokens cambian, hay que actualizar también el array en
`AdminPanelProvider.php`).

## 8. Sidebar y remate

- **Fondo**: Filament deja el sidebar transparente en escritorio (`lg:bg-transparent`), así que
  por default hereda el fondo de `.fi-body`. Se fuerza `background-color:var(--fondo-sidebar)`
  (casi blanco, un pelín distinto del blanco puro de las tarjetas) + un borde derecho
  (`border-inline-end:1px solid var(--borde-tarjeta)`), para que quede claramente separado del
  contenido.
- **Ítem activo**: Filament ya lo pinta con texto/ícono `primary-700` por defecto; solo hacía
  falta que el fondo detrás fuera un tinte de `primary` (`--primary-100`) en vez del gris genérico
  que trae Filament (`bg-gray-100`).
- **Paneles flotantes** (menú de usuario, notificaciones, cualquier `.fi-dropdown-panel`): mismo
  radio/borde/sombra que el resto de las tarjetas (`--radius`/`--borde-tarjeta`/`--sombra-tarjeta`),
  para que se sientan del mismo sistema visual.
- **Widget de bienvenida de Filament** (el bloque de Documentación/GitHub que trae por defecto):
  quitado de `AdminPanelProvider.php::widgets([...])` — es material promocional del framework, no
  algo que un usuario del ERP necesite ver en su dashboard.

## 9. Densidad: tablas y formularios

- **Paginación y formato de moneda**, en un solo lugar para TODO el panel:
  `app/Providers/AppServiceProvider.php::boot()`, vía `Table::configureUsing()` y
  `Schema::configureUsing()` (Filament permite fijar defaults globales así, en vez de repetirlos
  en cada Resource). De ahí sale que `->money('DOP')` se vea como `RD$1,234.50` (se fija
  `defaultNumberLocale('es_DO')` solo para los componentes de Filament, sin tocar
  `config('app.locale')`, que seguiría afectando fechas/traducciones de toda la app) y que las
  tablas paginen en 10/25. Los KPIs del dashboard usan un mecanismo aparte —
  `Number::useLocale('es_DO')`, mismo archivo — porque arman su valor con `Number::currency()` de
  Laravel en vez de un componente de Filament (ver sección 3).
- **Columnas secundarias** (teléfono, email, metadatos de auditoría...): `->toggleable
  (isToggledHiddenByDefault: true)` en cada `TextColumn` del Resource — ocultas por defecto, el
  usuario las reactiva desde el selector de columnas de la tabla.
- **Formularios**: agrupados en `Section::make('Título')` con 2-3 columnas donde aporta (ver
  `ProductoResource`, `ProveedorResource`, `CompraResource`, `EmpresaResource` como referencia).

## 10. El POS: la única pantalla con markup propio

El Punto de Venta (`app/Filament/Pages/PuntoDeVenta.php` +
`resources/views/filament/pages/punto-de-venta.blade.php`) no usa componentes de Filament para su
UI de venta: es HTML propio con clases `class="card"`, `class="btn btn-primary"`, `class="table"`,
`class="badge badge-success"`, etc. `resources/design-system/pos.css` es la ÚNICA fuente de esas
clases (las versiones sin scope que existían en `buttons.css`/`tables.css`/etc. se eliminaron por
huérfanas): mismas reglas, mismos tokens (incluidas las capas de superficie de la sección 2:
`.pos-screen .card` usa `--fondo-tarjeta`/`--borde-tarjeta`/`--sombra-tarjeta`, igual que una
`.fi-section` del panel), con el selector `.pos-screen` por delante para no afectar nada fuera de
esta página. El fondo detrás de las tarjetas del POS no necesita una regla aparte: la página vive
dentro de `.fi-body`, así que ya hereda `--fondo-pagina` de la sección 2.

**Para cambiar los estilos del POS**: `resources/design-system/pos.css`. Si el cambio es de un
token (color, espaciado, radio), tócalo en `variables.css`/`spacing.css`/etc., no lo hardcodees
en `pos.css`.

## 11. Navegación

Los grupos del menú (`Ventas`, `Maestros`, `Inventario`, `Compras`, `Fiscal`, `Reportes`,
`Configuración`, `Super Admin`) se registran explícitamente en
`AdminPanelProvider.php::navigationGroups([...])`, con icono y `->collapsed()` por grupo — todos
colapsados por defecto salvo `Ventas` (el uso diario). El **orden** de esa lista es el orden en el
sidebar. Cada Resource/Page fija su grupo (`$navigationGroup`) y su posición dentro del grupo
(`$navigationSort`, valores por decenas: 1x Ventas, 1x Maestros con prefijo distinto, etc. — ver
cualquier Resource para el patrón).

## 12. Modo claro/oscuro

Sigue como estaba: **solo claro** (`->darkMode(false)` en `AdminPanelProvider.php`). El
design-system del proyecto (`variables.css` → `pos.css`) es 100% modo-claro — cero `.dark`, cero
media queries. Si en el futuro se necesita modo oscuro real, hay que escribir esas variantes en
cada archivo del design-system antes de activar el switch, o el panel (que sí sabe oscurecerse
solo, vía el slot `gray`) y el POS (que no) quedarían inconsistentes.

## 13. Cheat-sheet: "quiero cambiar X"

- **El contraste de capas** (fondo de página, tarjetas, sidebar, borde, sombra) → los 5 tokens en
  `variables.css`, sección "SUPERFICIES / FONDOS DEL PANEL" (sección 2 — y la guía rápida al
  principio de este documento).
- **El color primario/de acento de todo el sistema** → `--primary` (+ su escala) en
  `variables.css` **y** el hex en `->colors(['primary' => ...])` de `AdminPanelProvider.php`
  (sección 4 — dos lugares, uno es CSS y el otro PHP, no se puede evitar).
- **El gris de detalle del panel** (bordes de tabla, texto secundario de Filament) → `--neutral`
  en `variables.css` + el slot `gray` en `AdminPanelProvider.php`. El fondo general de la página NO
  sale de aquí: sale de `--fondo-pagina` (sección 2).
- **Los radios** (botones, inputs, tarjetas) → `--radius`/`--radius-lg` en `variables.css`
  únicamente (sección 5).
- **La tipografía** → `--font-headline`/`--font-body` en `typography.css` (sección 6).
- **El color del ícono de un stat card (KPI)** → el `->color()` del `Stat` en
  `ReporteStatsOverviewWidget`; el tinte sale solo de `--{color}-100`/`-600` en `variables.css`
  (sección 3).
- **Los estilos del POS** → `resources/design-system/pos.css` (sección 10).
- **Qué se ve en cada grupo del menú y en qué orden** → `navigationGroups([...])` en
  `AdminPanelProvider.php` (orden de grupos) + `$navigationGroup`/`$navigationSort` en cada
  Resource/Page (sección 11).
- **Paginación por defecto o formato de moneda de las tablas** → `AppServiceProvider::boot()`
  (sección 9), no cada Resource.
- Cualquier cambio a `theme.css` o a `resources/design-system/` no se ve en el navegador hasta
  correr `./vendor/bin/sail npm run build` (o `npm run dev` para hot-reload mientras se trabaja).
