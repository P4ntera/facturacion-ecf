<?php

namespace App\Filament\Pages;

use App\Settings\EmpresaSettings;
use BackedEnum;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\TextInput;
use Filament\Pages\SettingsPage;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use UnitEnum;

class ManageEmpresa extends SettingsPage
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCog6Tooth;

    protected static string|UnitEnum|null $navigationGroup = 'Configuración';

    protected static ?string $navigationLabel = 'Datos de la Empresa';

    protected static ?string $title = 'Datos de la Empresa';

    protected static string $settings = EmpresaSettings::class;

    public static function canAccess(): bool
    {
        return auth()->user()?->can('administrar_configuracion') ?? false;
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->columns(2)
            ->components([
                TextInput::make('razon_social')
                    ->label('Razón social')
                    ->required()
                    ->maxLength(255),

                TextInput::make('nombre_comercial')
                    ->label('Nombre comercial')
                    ->required()
                    ->maxLength(255),

                TextInput::make('rnc')
                    ->label('RNC')
                    ->required()
                    ->regex('/^\d{9}(\d{2})?$/')
                    ->validationMessages(['regex' => 'El RNC debe tener 9 u 11 dígitos.'])
                    ->maxLength(11),

                TextInput::make('telefono')
                    ->label('Teléfono')
                    ->tel()
                    ->maxLength(50),

                TextInput::make('direccion')
                    ->label('Dirección')
                    ->maxLength(255)
                    ->columnSpanFull(),

                TextInput::make('email')
                    ->label('Correo electrónico')
                    ->email()
                    ->maxLength(255),

                FileUpload::make('logo')
                    ->label('Logo')
                    ->image()
                    ->disk('public')
                    ->directory('logos')
                    ->columnSpanFull(),
            ]);
    }
}
