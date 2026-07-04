<?php

namespace App\Filament\Pages;

use App\Enums\TasaItbis;
use App\Enums\TipoComprobante;
use App\Settings\FacturacionSettings;
use BackedEnum;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Toggle;
use Filament\Pages\SettingsPage;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use UnitEnum;

class ManageFacturacion extends SettingsPage
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCog6Tooth;

    protected static string|UnitEnum|null $navigationGroup = 'Configuración';

    protected static ?string $navigationLabel = 'Facturación';

    protected static ?string $title = 'Facturación';

    protected static string $settings = FacturacionSettings::class;

    public static function canAccess(): bool
    {
        return auth()->user()?->can('administrar_configuracion') ?? false;
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->columns(2)
            ->components([
                Toggle::make('aplica_itbis')
                    ->label('La empresa cobra ITBIS')
                    ->columnSpanFull(),

                Toggle::make('precio_incluye_itbis')
                    ->label('Los precios ya incluyen ITBIS')
                    ->helperText('Si está activo, el precio del producto se toma como precio final y el ITBIS se calcula por dentro. Si está inactivo, el ITBIS se suma aparte sobre el precio del producto.')
                    ->columnSpanFull(),

                Select::make('tasa_itbis_defecto')
                    ->label('Tasa de ITBIS por defecto')
                    ->options(collect(TasaItbis::cases())->mapWithKeys(
                        fn (TasaItbis $tasa) => [$tasa->value => $tasa === TasaItbis::CERO ? '0 % (Exento)' : "{$tasa->value} %"]
                    ))
                    ->required(),

                Select::make('tipo_comprobante_defecto')
                    ->label('Tipo de comprobante por defecto')
                    ->options(collect(TipoComprobante::cases())->mapWithKeys(
                        fn (TipoComprobante $tipo) => [$tipo->value => "{$tipo->value} — {$tipo->etiqueta()}"]
                    ))
                    ->required(),

                Select::make('moneda')
                    ->label('Moneda')
                    ->options([
                        'DOP' => 'DOP — Peso dominicano',
                        'USD' => 'USD — Dólar estadounidense',
                    ])
                    ->required(),
            ]);
    }
}
