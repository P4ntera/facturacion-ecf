<?php

declare(strict_types=1);

namespace App\Filament\Concerns;

use App\Enums\Modulo;
use Filament\Facades\Filament;

/**
 * Combina el control de acceso que ya tuviera el Resource/Page (permisos, vía
 * puedeAccederPorPermiso()) con el módulo de la empresa activa: visible en el menú y accesible
 * por URL solo si AMBOS lo permiten. Filament consulta canAccess() tanto para construir la
 * navegación como para autorizar la ruta, así que un solo método cubre los dos casos.
 */
trait RestringidoPorModulo
{
    /** Qué módulo del catálogo gobierna este Resource/Page. */
    abstract public static function modulo(): Modulo;

    public static function canAccess(): bool
    {
        return static::puedeAccederPorPermiso()
            && (Filament::getTenant()?->tieneModulo(static::modulo()) ?? false);
    }

    /**
     * Por defecto delega en el canAccess() que la clase tendría sin este trait (la política de
     * Filament vía canViewAny(), para Resources). Los Pages con su propia regla de permiso
     * (p. ej. un permission gate puntual) sobreescriben este método en vez de canAccess().
     */
    public static function puedeAccederPorPermiso(): bool
    {
        return parent::canAccess();
    }
}
