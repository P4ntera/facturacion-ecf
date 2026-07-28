<?php

namespace App\Filament\Pages;

use App\Enums\TasaItbis;
use App\Enums\TipoComprobante;
use App\Models\Empresa;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Components\Form;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use UnitEnum;

class ManageFacturacion extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCog6Tooth;

    protected static string|UnitEnum|null $navigationGroup = 'Configuración';

    protected static ?int $navigationSort = 61;

    protected static ?string $navigationLabel = 'Facturación';

    protected static ?string $title = 'Facturación';

    /** @var array<string, mixed>|null */
    public ?array $data = [];

    public static function canAccess(): bool
    {
        return auth()->user()?->can('facturacion.administrar') ?? false;
    }

    public function mount(): void
    {
        $config = $this->empresa()->config();

        $this->form->fill([
            'aplica_itbis' => $config->aplica_itbis,
            'precio_incluye_itbis' => $config->precio_incluye_itbis,
            'tasa_itbis_defecto' => $config->tasa_itbis_defecto,
            'tipo_comprobante_defecto' => $config->tipo_comprobante_defecto,
            'moneda' => $config->moneda,
        ]);
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->statePath('data')
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

    public function save(): void
    {
        $data = $this->form->getState();

        $this->empresa()->config()->update($data);

        Notification::make()->title('Configuración guardada')->success()->send();
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            Form::make([EmbeddedSchema::make('form')])
                ->id('form')
                ->livewireSubmitHandler('save')
                ->footer([
                    Actions::make([
                        Action::make('save')
                            ->label('Guardar')
                            ->submit('save')
                            ->keyBindings(['mod+s']),
                    ]),
                ]),
        ]);
    }

    private function empresa(): Empresa
    {
        /** @var Empresa */
        return Filament::getTenant();
    }
}
