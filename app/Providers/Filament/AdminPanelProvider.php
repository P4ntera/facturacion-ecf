<?php

namespace App\Providers\Filament;

use App\Filament\Pages\Auth\EditProfile;
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
                'primary' => Color::hex('#2563EB'), // --primary
                'success' => Color::hex('#10B981'), // --secondary
                'info' => Color::hex('#06B6D4'), // --info
                'warning' => Color::hex('#F59E0B'), // --warning
                'danger' => Color::hex('#EF4444'), // --danger
                'gray' => Color::Gray,
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
            ->authMiddleware([
                Authenticate::class,
            ]);
    }
}
