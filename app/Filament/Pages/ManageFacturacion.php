<?php

namespace App\Filament\Pages;

use App\Enums\FormaReembolso;
use App\Enums\MetodoCosto;
use App\Enums\RedondeoPrecio;
use App\Enums\TasaItbis;
use App\Enums\TipoComprobante;
use App\Models\Empresa;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Components\Form;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use UnitEnum;

class ManageFacturacion extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCog6Tooth;

    protected static string|UnitEnum|null $navigationGroup = 'Configuración';

    protected static ?int $navigationSort = 4;

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
            'permite_ventas_sin_comprobante' => $config->permite_ventas_sin_comprobante,
            'moneda' => $config->moneda,
            'metodo_costo' => $config->metodo_costo->value,
            'redondeo_precio' => $config->redondeo_precio->value,
            'permite_stock_negativo' => $config->permite_stock_negativo,
            'acepta_devoluciones' => $config->acepta_devoluciones,
            'devolucion_plazo_dias' => $config->devolucion_plazo_dias,
            'devolucion_reembolsos' => collect($config->reembolsosPermitidos())->map(fn (FormaReembolso $f) => $f->value)->all(),
            'devolucion_monto_supervisor' => $config->devolucion_monto_supervisor,
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
                    ->helperText('Solo tipos de venta. Los electrónicos (e-CF) solo aparecen si la empresa tiene e-CF habilitado.')
                    ->options(fn () => collect(TipoComprobante::cases())
                        ->filter(fn (TipoComprobante $tipo) => $tipo->esDeVenta())
                        ->filter(fn (TipoComprobante $tipo) => $tipo->esFisico() || $this->empresa()->usaEcf())
                        ->mapWithKeys(fn (TipoComprobante $tipo) => [$tipo->value => "{$tipo->value} — {$tipo->etiqueta()}"]))
                    ->required(),

                Toggle::make('permite_ventas_sin_comprobante')
                    ->label('Permitir ventas sin comprobante fiscal')
                    ->helperText('Agrega "Sin comprobante" al POS (y lo usa por defecto en el POS táctil): la venta se registra sin NCF y no aparece en el 607. Solo para negocios NO obligados a emitir NCF — un contribuyente de ITBIS debe emitir comprobante en cada venta (Decreto 254-06).')
                    ->columnSpanFull(),

                Select::make('moneda')
                    ->label('Moneda')
                    ->options([
                        'DOP' => 'DOP — Peso dominicano',
                        'USD' => 'USD — Dólar estadounidense',
                    ])
                    ->required(),

                Section::make('Costo y precios')
                    ->description('Cómo se calcula el costo de tus productos y cómo se redondea el precio sugerido por el porcentaje de ganancia.')
                    ->columnSpanFull()
                    ->columns(2)
                    ->schema([
                        Select::make('metodo_costo')
                            ->label('Método de costo')
                            ->options(collect(MetodoCosto::cases())->mapWithKeys(fn (MetodoCosto $metodo) => [$metodo->value => $metodo->etiqueta()]))
                            ->live()
                            ->helperText(fn (Get $get): string => (MetodoCosto::tryFrom((string) $get('metodo_costo'))?->descripcion() ?? '')
                                .' Al cambiarlo, el costo actual de cada producto es el punto de partida; las compras anteriores no se recalculan.')
                            ->required(),

                        Select::make('redondeo_precio')
                            ->label('Redondeo del precio sugerido')
                            ->options(collect(RedondeoPrecio::cases())->mapWithKeys(fn (RedondeoPrecio $redondeo) => [$redondeo->value => $redondeo->etiqueta()]))
                            ->helperText('Siempre hacia arriba, para no perder margen. El precio sugerido nunca se aplica solo: lo revisas y apruebas al registrar la compra.')
                            ->required(),
                    ]),

                Section::make('Inventario')
                    ->columnSpanFull()
                    ->schema([
                        Toggle::make('permite_stock_negativo')
                            ->label('Permitir vender sin stock')
                            ->helperText('Para cuando la mercancía está en la tienda pero la compra todavía no se registró. Solo al vender: el producto queda en negativo, la venta queda marcada en el Kardex y sale una alerta hasta que entre la compra o se ajuste tras contar. Ajustes, anular compras y devolver al proveedor siguen sin poder dejar el stock en negativo.'),
                    ]),

                Section::make('Devoluciones de clientes')
                    ->description('Las reglas de tu negocio. La devolución siempre se registra en la caja de hoy; una caja cerrada no se toca.')
                    ->columnSpanFull()
                    ->columns(2)
                    ->schema([
                        Toggle::make('acepta_devoluciones')
                            ->label('Aceptar devoluciones')
                            ->live()
                            ->columnSpanFull(),

                        TextInput::make('devolucion_plazo_dias')
                            ->label('Plazo máximo (días desde la venta)')
                            ->numeric()
                            ->minValue(1)
                            ->maxValue(3650)
                            ->placeholder('Sin límite')
                            ->visible(fn (Get $get): bool => (bool) $get('acepta_devoluciones')),

                        TextInput::make('devolucion_monto_supervisor')
                            ->label('Necesita supervisor a partir de')
                            ->numeric()
                            ->minValue(0)
                            ->prefix('RD$')
                            ->placeholder('Nunca')
                            ->helperText('Por encima de este monto, solo un usuario con el permiso "Autorizar devoluciones" puede registrarla.')
                            ->visible(fn (Get $get): bool => (bool) $get('acepta_devoluciones')),

                        CheckboxList::make('devolucion_reembolsos')
                            ->label('Cómo se le puede devolver el dinero al cliente')
                            ->options(collect(FormaReembolso::cases())->mapWithKeys(fn (FormaReembolso $f) => [$f->value => $f->etiqueta()]))
                            ->helperText('Si la venta fue a crédito, primero se rebaja lo que el cliente debe; solo el resto se reembolsa.')
                            ->required(fn (Get $get): bool => (bool) $get('acepta_devoluciones'))
                            ->visible(fn (Get $get): bool => (bool) $get('acepta_devoluciones'))
                            ->columnSpanFull(),
                    ]),
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
