<?php

namespace App\Providers\Filament;

use App\Filament\Pages\Auth\EditProfile;
use App\Http\Middleware\EstablecerEmpresaPermisos;
use App\Models\Empresa;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Pages\Dashboard;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Filament\Support\Enums\Width;
use Filament\Widgets\AccountWidget;
use Filament\Widgets\FilamentInfoWidget;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\View\Middleware\ShareErrorsFromSession;

class AdminPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->default()
            ->id('admin')
            ->path('admin')
            ->viteTheme('resources/css/filament/admin/theme.css')
            ->login()
            ->profile(EditProfile::class)
            // Multi-tenant nativo de Filament: cada empresa es un tenant, identificado en la URL
            // por su slug (/admin/{empresa-slug}/...). ownershipRelationship es explícito aunque
            // coincide con el default (camelCase del modelo) para que quede documentado aquí.
            ->tenant(Empresa::class, slugAttribute: 'slug', ownershipRelationship: 'empresa')
            // No hay auto-registro: las empresas las da de alta el super-admin (EmpresaResource).
            ->tenantRegistration(null)
            // Solo tiene efecto visual real para el super-admin (varias empresas); un usuario
            // normal con una sola empresa entra directo (getDefaultTenant) y no lo necesita, pero
            // no le estorba dejarlo visible.
            ->tenantMenu()
            ->colors([
                'primary' => Color::hex('#5D87FF'), // --primary
                'info' => Color::hex('#49BEFF'), // --secondary (rol "informativo" de Filament)
                'success' => Color::hex('#13DEB9'), // --tertiary / --success
                'warning' => Color::hex('#F59E0B'), // --warning
                'danger' => Color::hex('#EF4444'), // --danger
                'gray' => Color::hex('#7C808D'), // --neutral: gris de marca Stitch, no el gris
                // genérico de Filament — de aquí salen fondos, bordes y superficies del panel.
            ])
            // El fondo/superficies del panel salen del slot 'gray' + el modo de color, no de
            // 'primary'. El design-system del proyecto (resources/design-system/) es enteramente
            // claro: ningún archivo define variantes .dark. Estrategia documentada en
            // docs/estilos.md (sección 6): mantener el panel y el POS solo en claro hasta que
            // exista una necesidad real de modo oscuro (que implicaría escribir esas variantes).
            ->darkMode(false)
            ->databaseNotifications()
            ->maxContentWidth(Width::Full)
            ->sidebarCollapsibleOnDesktop()
            // Cuerpo de texto (--font-body). Filament no permite una segunda familia solo para
            // titulares vía este método: Manrope (--font-headline) se aplica en theme.css sobre
            // las hook classes de heading de Filament (fi-header-heading y similares).
            ->font('Inter')
            ->brandName('Facturación e-CF')
            ->discoverResources(in: app_path('Filament/Resources'), for: 'App\Filament\Resources')
            ->discoverPages(in: app_path('Filament/Pages'), for: 'App\Filament\Pages')
            ->pages([
                Dashboard::class,
            ])
            ->discoverWidgets(in: app_path('Filament/Widgets'), for: 'App\Filament\Widgets')
            ->widgets([
                AccountWidget::class,
                FilamentInfoWidget::class,
            ])
            ->middleware([
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
                AuthenticateSession::class,
                ShareErrorsFromSession::class,
                VerifyCsrfToken::class,
                SubstituteBindings::class,
                DisableBladeIconComponents::class,
                DispatchServingFilamentEvent::class,
            ])
            // ANTES de SubstituteBindings a propósito (ver bootstrap/app.php, que lo ancla antes
            // de AuthenticatesRequests con prioridad): spatie/laravel-permission con
            // 'teams' => true necesita un contexto de empresa activo para CUALQUIER consulta de
            // roles/permisos (incluida canAccessPanel(), que corre más adelante en
            // ->authMiddleware()). Si quedara después, esas consultas se resolverían sin contexto
            // (ven todo vacío) y el binding de rutas fallaría en 404 en vez del 403 que
            // corresponde a un problema de autorización.
            //
            // isPersistent: true es OBLIGATORIO además de la prioridad: sin esto, el middleware
            // solo corre en la carga de página completa. Filament navega DENTRO del panel (tablas,
            // paginación, wire:navigate, cualquier interacción de un componente Livewire ya
            // montado) vía peticiones a /livewire/update, que Livewire enruta por SU PROPIO
            // mecanismo (Livewire\Mechanisms\PersistentMiddleware) — este solo reaplica el
            // middleware de la ruta original que esté en la lista persistente (la de Filament
            // mismo: Authenticate, IdentifyTenant, SetUpPanel... registrada en
            // FilamentServiceProvider::boot()), no la pipeline completa de la request inicial. Sin
            // marcarlo persistente, setPermissionsTeamId() nunca se ejecuta en esas peticiones: el
            // usuario entra bien (la carga inicial sí pasa por la pipeline completa), pero cualquier
            // interacción posterior ve permisos vacíos, como si no tuviera ningún rol.
            ->middleware([
                EstablecerEmpresaPermisos::class,
            ], isPersistent: true)
            ->authMiddleware([
                Authenticate::class,
            ]);
    }
}
