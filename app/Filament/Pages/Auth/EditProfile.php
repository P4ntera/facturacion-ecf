<?php

declare(strict_types=1);

namespace App\Filament\Pages\Auth;

use App\Enums\ModuloImpresion;
use Filament\Auth\Pages\EditProfile as BaseEditProfile;
use Filament\Facades\Filament;
use Filament\Forms\Components\Select;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Builder;

/**
 * Extiende la página de perfil nativa de Filament (self-service, sin permiso especial) para
 * que cualquier usuario —incluido un cajero sin `usuarios.gestionar`— pueda fijar su propia
 * impresora de facturación. UserResource expone el mismo campo para que un administrador
 * también pueda asignarla desde la gestión de usuarios.
 */
class EditProfile extends BaseEditProfile
{
    public function form(Schema $schema): Schema
    {
        return $schema->components([
            $this->getNameFormComponent(),
            $this->getEmailFormComponent(),
            $this->getImpresoraFacturacionFormComponent(),
            $this->getPasswordFormComponent(),
            $this->getPasswordConfirmationFormComponent(),
            $this->getCurrentPasswordFormComponent(),
        ]);
    }

    protected function getImpresoraFacturacionFormComponent(): Component
    {
        return Select::make('impresora_facturacion_id')
            ->label('Mi impresora de facturación')
            ->helperText('Se usa al imprimir tickets de venta en vez de la predeterminada del módulo.')
            // Scope manual obligatorio — Filament NO aplica tenant scope dentro de
            // ->relationship(), ni siquiera dentro de su propio Resource.
            ->relationship('impresoraFacturacion', 'nombre', fn (Builder $query) => $query
                ->where('empresa_id', Filament::getTenant()?->id)
                ->activas()
                ->porModulo(ModuloImpresion::FACTURACION))
            ->preload()
            ->native(false);
    }
}
