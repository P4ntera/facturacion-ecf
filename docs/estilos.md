# Pipeline de estilos: panel, POS y design-system

Este documento mapea de dónde toma sus estilos cada parte del sistema y documenta la estrategia
de modo claro/oscuro. Léelo antes de tocar cualquier CSS o el color de algo.

## 1. Los 3 entry points de Vite

`vite.config.js` compila tres entradas independientes:

| Entry point | Para qué |
|---|---|
| `resources/css/filament/admin/theme.css` | El panel de Filament (incluyendo el POS, que vive dentro del panel). **Este es el que importa.** |
| `resources/css/app.css` | Legado de Breeze. **Huérfano**: ninguna vista lo carga hoy (`grep @vite( resources/views/` no devuelve nada — los layouts de Breeze que lo referenciaban se eliminaron). Sigue compilando porque nadie borró la entrada, pero no llega al navegador. |
| `resources/js/app.js` | Igual de huérfano que `app.css`, por la misma razón. |

## 2. De dónde saca sus colores/superficies/tipografía el panel Filament

Todo se configura en `app/Providers/Filament/AdminPanelProvider.php`:

- **Colores de acento** (`->colors([...])`): mapea los slots `primary`, `success`, `info`,
  `warning`, `danger` a los mismos hexadecimales que `--primary`, `--secondary`, `--info`,
  `--warning`, `--danger` del design-system (están anotados con el nombre del token en
  comentario). Es una copia manual del valor — si cambias el token en `variables.css`, tienes
  que cambiar también el hex aquí; no hay una sola fuente de verdad para esto.
- **Superficies/fondo** (`gray` slot): usa `Color::Gray`, la escala de grises **propia** de
  Filament (`Filament\Support\Colors\Color`). No está atada a `--gray-*` del design-system. Es
  Filament quien decide el fondo de sidebar, topbar, tablas, cards de Filament, etc.
- **Tipografía**: `->font('Inter')`, igual que `--font-family:'Inter',sans-serif` del
  design-system.
- **Tema custom**: `->viteTheme('resources/css/filament/admin/theme.css')` es el punto de entrada
  que Filament compila junto a su propio `theme.css` base. Ahí es donde se inyecta el
  design-system del proyecto (ver sección 3).
- **Modo claro/oscuro**: `->darkMode(false)`. El switch de Filament no aparece; el panel siempre
  se renderiza en modo claro. Ver sección 5.

## 3. Cómo llega el design-system al panel y al POS

`resources/css/filament/admin/theme.css`:

```css
@import '.../vendor/filament/filament/resources/css/theme.css';   /* base de Filament */

@import '../../../design-system/variables.css';   /* tokens: paleta */
@import '../../../design-system/colors.css';       /* tokens: alias semánticos */
@import '../../../design-system/typography.css';
@import '../../../design-system/spacing.css';
@import '../../../design-system/shadows.css';

@import '../../../design-system/pos.css';           /* clases .card/.btn/.table/... SOLO para el POS */
```

Nota lo que **no** importa: `buttons.css`, `forms.css`, `tables.css`, `badges.css`,
`components.css` (las versiones de clases *sin scope*). Es deliberado — si esos archivos
entraran al tema de Filament, sus selectores (`.btn`, `.table`, `.card`...) sobrescribirían los
componentes propios de Filament (`.fi-btn`, `.fi-ta-table`...) en todo el panel, no solo en el
POS.

En cambio, el **POS** (`app/Filament/Pages/PuntoDeVenta.php` + su Blade
`resources/views/filament/pages/punto-de-venta.blade.php`) es una página de trabajo con markup
propio (no generado por Filament) que sí usa `class="card"`, `class="btn btn-primary"`,
`class="table"`, `class="badge badge-success"`, etc. Para que esas clases tengan estilo sin tocar
el resto del panel, `pos.css` **reimplementa** cada una de esas reglas con el prefijo `.pos-screen`
delante (p. ej. `.pos-screen .btn-primary`), y la vista Blade envuelve todo su contenido en
`<div class="pos-screen">`. Mismos tokens, mismos valores que `buttons.css`/`tables.css`/etc.,
pero con un selector distinto para quedar aislado.

**Resumen de la pregunta "¿cómo llegan las clases del design-system al POS?"**: ni vía `app.css`
+ `@vite` en un layout Blade (ese archivo está huérfano), ni vía `FilamentAsset::register()`
(no se usa en ningún lugar del código — verificado por grep), sino vía el propio `->viteTheme()`
del panel, que importa `pos.css`.

## 4. Qué archivo define qué

| Archivo | Define | Se usa en |
|---|---|---|
| `resources/design-system/variables.css` | Tokens crudos: `--primary`, `--gray-*`, `--background`, `--radius-*` | Todo lo demás depende de este |
| `resources/design-system/colors.css` | Alias semánticos: `--text`, `--border`, `--surface`, `--link` (sobre los tokens crudos) + `.bg-app` | `.bg-app` está sin uso hoy (ver hallazgo abajo) |
| `typography.css`, `spacing.css`, `shadows.css` | Tokens de tipografía/espaciado/sombra (`--fs-*`, `--space-*`, `--shadow-card`) | Panel (vía theme.css) y POS |
| `buttons.css`, `forms.css`, `tables.css`, `badges.css`, `components.css` | Clases de componentes **sin scope** (`.btn`, `.form-input`, `.table`, `.badge`, `.card`) | Solo en `app.css`, que está huérfano — **no llegan a ningún navegador hoy** |
| `resources/design-system/pos.css` | Las mismas clases de arriba, pero con scope `.pos-screen` delante | El POS, vía `theme.css` |

## 5. Hallazgo: `--background` no coincide con lo documentado

`variables.css` tiene hoy:

```css
--background:#032e5a;   /* azul marino oscuro */
```

Tanto el comentario en `AdminPanelProvider.php` como el resto del design-system (que es
enteramente claro: `--white`, `--gray-100`, `--surface: var(--white)`...) asumen
`--background:#F9FAFB` (gris muy claro). Se rastreó el historial con `git log -p`:

1. El token nació como `#F9FAFB` (commit `529c99d`).
2. Un commit posterior y aislado, `6f52e93` ("Cambios en lso colores"), lo cambió a `#032e5a`
   sin tocar ningún otro archivo ni actualizar el comentario en `AdminPanelProvider.php`.

**Impacto real hoy: ninguno.** El único consumidor de `--background` es `.bg-app` en
`colors.css`, y `.bg-app` no aparece en ninguna vista (`grep -rln bg-app resources/views app`
no devuelve nada). Es CSS muerto. Pero es una inconsistencia real entre el token, el comentario
del código y la intención del design-system — se corrige en la sección 6.

## 6. Estrategia de modo claro/oscuro

Pendiente de decisión — ver PASO 3. Dos opciones evaluadas:

- **(a) Solo claro**: formalizar `->darkMode(false)` (ya activo hoy) como decisión permanente,
  y corregir el `--background` inconsistente de la sección 5 para que el design-system quede
  100% coherente en claro.
- **(b) Claro + oscuro**: reactivar el switch y escribir variantes `.dark` para los 10 archivos
  del design-system (hoy ninguno tiene una sola línea de modo oscuro). Filament marca el modo
  oscuro agregando la clase `.dark` al `<html>`
  (`vendor/filament/filament/resources/js/dark-mode.js`, `document.documentElement.classList.add('dark')`) —
  ese sería el selector a usar.

Esta sección se completa en el commit de PASO 3 una vez tomada la decisión.
