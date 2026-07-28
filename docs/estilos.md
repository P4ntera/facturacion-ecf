# Identidad visual del panel (Stitch): mapa de estilos

Este documento mapea de dónde sale cada parte de la identidad visual del panel/POS y, sobre todo,
**qué archivo tocar para cambiarla**. La regla de fondo de todo este rediseño: la paleta,
tipografía y radios viven en **un solo archivo** (`resources/design-system/variables.css`);
todo lo demás (Filament, POS, botones) lee de ahí, directa o indirectamente.

## 1. Entry point de Vite

Uno solo: `resources/css/filament/admin/theme.css` (`->viteTheme(...)` en
`AdminPanelProvider.php`). Los remanentes de Breeze (`resources/css/app.css`,
`resources/js/app.js`, `tailwind.config.js`, y las versiones sin scope de
`buttons/forms/tables/badges/components.css`) se eliminaron: no los servía ninguna vista.

`theme.css` importa, en orden:

```css
@import url('https://fonts.bunny.net/css?family=manrope:...');   /* Manrope, para titulares */
@import '.../vendor/filament/filament/resources/css/theme.css';   /* base de Filament */

@import '../../../design-system/variables.css';   /* PALETA, RADIOS — fuente única */
@import '../../../design-system/colors.css';       /* alias semánticos (--text, --border...) */
@import '../../../design-system/typography.css';   /* --font-headline, --font-body, --fs-* */
@import '../../../design-system/spacing.css';
@import '../../../design-system/shadows.css';

@import '../../../design-system/pos.css';           /* .card/.btn/.table/... SOLO para el POS */
```

Después de los imports, `theme.css` tiene además unas pocas reglas propias (radios de Filament,
fondo de tarjetas, tipografía de titulares) — ver secciones 3 y 4.

## 2. La paleta: un solo lugar, dos consumidores

`resources/design-system/variables.css` define 6 colores base (`--primary`, `--secondary`,
`--tertiary`, `--neutral`, `--warning`, `--danger`), cada uno con su escala completa `-50` a
`-950`, más los radios (`--radius-sm`, `--radius`, `--radius-lg`).

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
| `primary` | `--primary` (`#5D87FF`) | Acento principal, botones primary |
| `info` | `--secondary` (`#49BEFF`) | Filament no tiene slot "secondary"; el rol informativo de Filament es el que mejor mapea al secondary de Stitch |
| `success` | `--tertiary` / `--success` (`#13DEB9`) | Estados de éxito |
| `warning` | `--warning` (`#F59E0B`) | Estados de alerta |
| `danger` | `--danger` (`#EF4444`) | Estados de error/peligro |
| `gray` | `--neutral` (`#7C808D`) | Fondos, bordes, superficies de TODO el panel (sidebar, topbar, tablas...) |
| `dark` (slot extra, no nativo) | `--neutral-900`/`950` | Variante "Inverted" de botones (ver sección 5) |

## 3. Radios: el truco de la variable compartida

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

## 4. Tipografía

- `--font-body` (Inter) — cuerpo de texto. `AdminPanelProvider.php` usa `->font('Inter')`
  (proveedor Bunny Fonts, el mismo que usa Filament por defecto).
- `--font-headline` (Manrope) — titulares. Filament no permite una segunda familia solo para
  headings vía `->font()`, así que se aplica en `theme.css` sobre las hook classes de heading de
  Filament (`.fi-header-heading`, `.fi-section-header-heading`, `.fi-modal-heading`, etc.) y se
  carga con su propio `@import url(...)` de Bunny Fonts al inicio de `theme.css`.

**Para cambiar de fuente: edita `--font-headline`/`--font-body` en
`resources/design-system/typography.css` y actualiza el `@import` de Bunny Fonts en `theme.css`
(y `->font()` en `AdminPanelProvider.php` si cambia la de cuerpo).**

## 5. Botones: 4 variantes, nativas de Filament

Los 4 estilos de Stitch (Primary/Secondary/Inverted/Outlined) mapean 1:1 a mecanismos nativos de
Filament — no hace falta CSS custom para el panel:

| Variante Stitch | Cómo se logra en una Action de Filament | Clase equivalente en POS (`pos.css`) |
|---|---|---|
| Primary (sólido) | Color por defecto, sin `->outlined()` (Filament ya pinta sólido, shade 600) | `.btn-primary` |
| Secondary (gris suave) | `->color('gray')` (Filament no le pone color: fondo blanco/borde suave) | `.btn-secondary` |
| Inverted (oscuro) | `->color('dark')` — slot custom registrado en `AdminPanelProvider.php` | `.btn-inverted` |
| Outlined (borde) | `->outlined()` | `.btn-outlined` |

El slot `dark` (ver sección 2) usa una escala explícita en vez de `Color::hex()`: Filament pinta
el fondo sólido de un botón con el shade 600 de su color, así que ese shade tiene que ser YA el
oscuro que se quiere (los shades 700-950 son los mismos pasos de `--neutral-900`/`--neutral-950`
de `variables.css` — si esos tokens cambian, hay que actualizar también el array en
`AdminPanelProvider.php`).

## 6. Densidad: tablas y formularios

- **Paginación y formato de moneda**, en un solo lugar para TODO el panel:
  `app/Providers/AppServiceProvider.php::boot()`, vía `Table::configureUsing()` y
  `Schema::configureUsing()` (Filament permite fijar defaults globales así, en vez de repetirlos
  en cada Resource). De ahí sale que `->money('DOP')` se vea como `RD$1,234.50` (se fija
  `defaultNumberLocale('es_DO')` solo para los componentes de Filament, sin tocar
  `config('app.locale')`, que seguiría afectando fechas/traducciones de toda la app) y que las
  tablas paginen en 10/25.
- **Columnas secundarias** (teléfono, email, metadatos de auditoría...): `->toggleable
  (isToggledHiddenByDefault: true)` en cada `TextColumn` del Resource — ocultas por defecto, el
  usuario las reactiva desde el selector de columnas de la tabla.
- **Formularios**: agrupados en `Section::make('Título')` con 2-3 columnas donde aporta (ver
  `ProductoResource`, `ProveedorResource`, `CompraResource`, `EmpresaResource` como referencia).

## 7. El POS: la única pantalla con markup propio

El Punto de Venta (`app/Filament/Pages/PuntoDeVenta.php` +
`resources/views/filament/pages/punto-de-venta.blade.php`) no usa componentes de Filament para su
UI de venta: es HTML propio con clases `class="card"`, `class="btn btn-primary"`, `class="table"`,
`class="badge badge-success"`, etc. `resources/design-system/pos.css` es la ÚNICA fuente de esas
clases (las versiones sin scope que existían en `buttons.css`/`tables.css`/etc. se eliminaron por
huérfanas — ver Paso 6 del rediseño): mismas reglas, mismos tokens, con el selector `.pos-screen`
por delante para no afectar nada fuera de esta página.

**Para cambiar los estilos del POS**: `resources/design-system/pos.css`. Si el cambio es de un
token (color, espaciado, radio), tócalo en `variables.css`/`spacing.css`/etc., no lo hardcodees
en `pos.css`.

## 8. Navegación

Los grupos del menú (`Ventas`, `Maestros`, `Inventario`, `Compras`, `Fiscal`, `Reportes`,
`Configuración`, `Super Admin`) se registran explícitamente en
`AdminPanelProvider.php::navigationGroups([...])`, con icono y `->collapsed()` por grupo — todos
colapsados por defecto salvo `Ventas` (el uso diario). El **orden** de esa lista es el orden en el
sidebar. Cada Resource/Page fija su grupo (`$navigationGroup`) y su posición dentro del grupo
(`$navigationSort`, valores por decenas: 1x Ventas, 1x Maestros con prefijo distinto, etc. — ver
cualquier Resource para el patrón).

## 9. Modo claro/oscuro

Sigue como estaba: **solo claro** (`->darkMode(false)` en `AdminPanelProvider.php`). El
design-system del proyecto (`variables.css` → `pos.css`) es 100% modo-claro — cero `.dark`, cero
media queries. Si en el futuro se necesita modo oscuro real, hay que escribir esas variantes en
cada archivo del design-system antes de activar el switch, o el panel (que sí sabe oscurecerse
solo, vía el slot `gray`) y el POS (que no) quedarían inconsistentes.

## 10. Cheat-sheet: "quiero cambiar X"

- **El color primario/de acento de todo el sistema** → `--primary` (+ su escala) en
  `variables.css` **y** el hex en `->colors(['primary' => ...])` de `AdminPanelProvider.php`
  (sección 2 — dos lugares, uno es CSS y el otro PHP, no se puede evitar).
- **El gris/fondo de TODO el panel** (sidebar, topbar, tablas de Filament) → `--neutral` en
  `variables.css` + el slot `gray` en `AdminPanelProvider.php`.
- **Los radios** (botones, inputs, tarjetas) → `--radius`/`--radius-lg` en `variables.css`
  únicamente (sección 3).
- **La tipografía** → `--font-headline`/`--font-body` en `typography.css` (sección 4).
- **Los estilos del POS** → `resources/design-system/pos.css` (sección 7).
- **Qué se ve en cada grupo del menú y en qué orden** → `navigationGroups([...])` en
  `AdminPanelProvider.php` (orden de grupos) + `$navigationGroup`/`$navigationSort` en cada
  Resource/Page (sección 8).
- **Paginación por defecto o formato de moneda de las tablas** → `AppServiceProvider::boot()`
  (sección 6), no cada Resource.
- Cualquier cambio a `theme.css` o a `resources/design-system/` no se ve en el navegador hasta
  correr `./vendor/bin/sail npm run build` (o `npm run dev` para hot-reload mientras se trabaja).
