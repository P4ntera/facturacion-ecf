<?php

namespace App\Filament\Resources;

use App\Enums\EstadoCompra;
use App\Enums\EstadoDevolucion;
use App\Enums\TasaItbis;
use App\Enums\TipoComprobante;
use App\Enums\TipoProducto;
use App\Enums\TipoProveedor;
use App\Filament\Resources\CompraResource\Pages;
use App\Models\Compra;
use App\Models\DetalleCompra;
use App\Models\Producto;
use App\Models\Proveedor;
use App\Services\CompraService;
use App\Services\DgiiRncService;
use Filament\Actions\Action;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Repeater\TableColumn;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\RepeatableEntry\TableColumn as InfolistTableColumn;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\Rules\Unique;
use RuntimeException;

class CompraResource extends Resource
{
    protected static ?string $model = Compra::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-shopping-cart';

    protected static ?string $navigationLabel = 'Compras';

    protected static ?string $modelLabel = 'Compra';

    protected static ?string $pluralModelLabel = 'Compras';

    protected static string|\UnitEnum|null $navigationGroup = 'Compras';

    public static function form(Schema $schema): Schema
    {
        return $schema->columns(1)->components([
            Section::make('Datos de la factura de compra')
                ->columnSpanFull()
                ->columns(3)
                ->schema([
                    Select::make('proveedor_id')
                        ->label('Proveedor')
                        ->relationship('proveedor', 'nombre')
                        ->searchable()
                        ->preload()
                        ->live()
                        ->required()
                        ->createOptionForm([
                            TextInput::make('rnc')
                                ->label('RNC / Cédula')
                                ->required()
                                ->unique(table: 'proveedores', ignoreRecord: true)
                                ->maxLength(11)
                                ->minLength(9)
                                ->numeric()
                                ->suffixAction(
                                    Action::make('buscar_dgii')
                                        ->label('Buscar en DGII')
                                        ->icon('heroicon-o-magnifying-glass')
                                        ->action(function (Get $get, callable $set) {
                                            $rnc = preg_replace('/[^0-9]/', '', $get('rnc') ?? '');

                                            if (! $rnc) {
                                                Notification::make()->title('Ingresa un RNC o Cédula primero.')->warning()->send();

                                                return;
                                            }

                                            $service = app(DgiiRncService::class);

                                            if (! $service->esRncValido($rnc)) {
                                                Notification::make()->title('RNC inválido')->danger()->send();

                                                return;
                                            }

                                            $datos = $service->consultarRnc($rnc);

                                            if (! $datos) {
                                                Notification::make()->title('No encontrado en la DGII')->warning()->send();

                                                return;
                                            }

                                            $set('rnc', $datos['rnc']);
                                            $set('nombre', $datos['nombre']);
                                            $set('estado', $datos['estado']);

                                            Notification::make()->title('¡Datos cargados!')->success()->send();
                                        })
                                ),
                            TextInput::make('nombre')
                                ->label('Nombre / Razón Social')
                                ->required()
                                ->maxLength(255),
                            Select::make('tipo')
                                ->label('Tipo de proveedor')
                                ->options([
                                    TipoProveedor::FORMAL->value   => TipoProveedor::FORMAL->etiqueta(),
                                    TipoProveedor::INFORMAL->value => TipoProveedor::INFORMAL->etiqueta(),
                                ])
                                ->default(TipoProveedor::FORMAL->value)
                                ->required(),
                        ])
                        ->createOptionAction(fn (Action $action) => $action->modalHeading('Crear proveedor')),

                    TextEntry::make('proveedor_rnc_preview')
                        ->label('RNC / Cédula')
                        ->state(function (Get $get) {
                            $proveedor = Proveedor::find($get('proveedor_id'));

                            return $proveedor?->rnc ?? '—';
                        }),

                    DateTimePicker::make('fecha')
                        ->label('Fecha de la factura')
                        ->default(now())
                        ->required(),

                    TextEntry::make('fecha_registro_preview')
                        ->label('Fecha de registro')
                        ->state(now()->format('d/m/Y H:i')),

                    Select::make('tipo_comprobante')
                        ->label('Tipo de comprobante')
                        ->options([
                            TipoComprobante::COMPRAS->value        => TipoComprobante::COMPRAS->etiqueta(),
                            TipoComprobante::GASTOS_MENORES->value => TipoComprobante::GASTOS_MENORES->etiqueta(),
                        ])
                        ->default(TipoComprobante::COMPRAS->value)
                        ->visible(fn (Get $get) => ! self::proveedorEsInformal($get))
                        ->required(fn (Get $get) => ! self::proveedorEsInformal($get)),

                    TextInput::make('ncf')
                        ->label('NCF del proveedor')
                        ->helperText('Comprobante fiscal recibido del proveedor. Dejar vacío si no aplica.')
                        ->maxLength(13)
                        ->regex('/^[A-Z0-9]{11,13}$/')
                        ->unique(
                            table: 'compras',
                            column: 'ncf',
                            ignoreRecord: true,
                            modifyRuleUsing: fn (Unique $rule, Get $get) => $rule->where('proveedor_id', $get('proveedor_id')),
                        )
                        ->validationMessages([
                            'regex' => 'El NCF debe tener entre 11 y 13 caracteres alfanuméricos en mayúsculas.',
                            'unique' => 'Ya existe una compra con este NCF para el proveedor seleccionado.',
                        ])
                        ->visible(fn (Get $get) => ! self::proveedorEsInformal($get)),

                    TextEntry::make('ncf_auto_preview')
                        ->label('NCF')
                        ->visible(fn (Get $get) => self::proveedorEsInformal($get))
                        ->state('Se generará automáticamente al guardar (proveedor informal).'),

                    Toggle::make('itbis_incluido')
                        ->label('Los costos digitados ya incluyen el ITBIS')
                        ->live()
                        ->default(false)
                        ->columnSpanFull(),

                    TextInput::make('monto_total_factura')
                        ->label('Monto total de la factura')
                        ->helperText('Total impreso en la factura física del proveedor, para cuadrar contra el detalle digitado abajo.')
                        ->numeric()
                        ->prefix('RD$')
                        ->minValue(0)
                        ->required()
                        ->live(onBlur: true)
                        ->hintColor('warning')
                        ->hint(function (Get $get) {
                            $montoFactura = $get('monto_total_factura');

                            if (! filled($montoFactura)) {
                                return null;
                            }

                            $lineas = collect($get('lineas') ?? [])
                                ->filter(fn ($l) => filled($l['producto_id'] ?? null) && filled($l['cantidad'] ?? null) && filled($l['costo_unitario'] ?? null))
                                ->values()
                                ->all();

                            if (empty($lineas)) {
                                return null;
                            }

                            $service = app(CompraService::class);
                            $calc    = $service->calcularLineas($lineas, (bool) $get('itbis_incluido'));
                            $totales = $service->calcularTotales($calc);

                            $diferencia = round((float) $montoFactura - $totales['total'], 2);

                            if (abs($diferencia) < 0.01) {
                                return null;
                            }

                            return sprintf(
                                'No coincide con el total calculado (RD$%s). Diferencia: RD$%s',
                                number_format($totales['total'], 2),
                                number_format($diferencia, 2),
                            );
                        })
                        ->columnSpanFull(),
                ]),

            Section::make('Agregar producto')
                ->columnSpanFull()
                ->columns(4)
                ->schema([
                    Select::make('nueva_linea_producto_id')
                        ->label('Producto')
                        ->hiddenLabel()
                        ->placeholder('Selecciona un producto…')
                        ->actionSchemaModel(Producto::class)
                        ->options(fn () => Producto::query()->activos()->pluck('nombre', 'id'))
                        ->searchable()
                        ->preload()
                        ->live()
                        ->afterStateUpdated(function ($state, callable $set) {
                            $producto = $state ? Producto::find($state) : null;
                            $set('nueva_linea_costo_unitario', $producto?->costo ?? 0);
                        })
                        ->createOptionUsing(fn (array $data) => Producto::create($data)->getKey())
                        ->createOptionForm([
                            TextInput::make('codigo')
                                ->label('Código')
                                ->required()
                                ->unique(table: 'productos', ignoreRecord: true)
                                ->maxLength(50),
                            TextInput::make('nombre')
                                ->label('Nombre')
                                ->required()
                                ->maxLength(255),
                            Select::make('tipo')
                                ->label('Tipo')
                                ->options([
                                    TipoProducto::PRODUCTO->value => 'Producto',
                                    TipoProducto::SERVICIO->value => 'Servicio',
                                ])
                                ->default(TipoProducto::PRODUCTO->value)
                                ->required(),
                            TextInput::make('costo')
                                ->label('Costo')
                                ->numeric()
                                ->prefix('RD$')
                                ->minValue(0)
                                ->default(0),
                            TextInput::make('precio')
                                ->label('Precio de venta')
                                ->numeric()
                                ->prefix('RD$')
                                ->required()
                                ->minValue(0)
                                ->default(0),
                            Select::make('tasa_itbis')
                                ->label('Tasa ITBIS')
                                ->options([
                                    TasaItbis::DIECIOCHO->value => '18 %',
                                    TasaItbis::DIECISEIS->value => '16 %',
                                    TasaItbis::CERO->value      => '0 %',
                                ])
                                ->default(TasaItbis::DIECIOCHO->value)
                                ->required(),
                            Toggle::make('controla_stock')
                                ->label('Controla stock')
                                ->default(true),
                        ])
                        ->createOptionAction(fn (Action $action) => $action->modalHeading('Crear producto')),

                    TextInput::make('nueva_linea_cantidad')
                        ->label('Cantidad')
                        ->hiddenLabel()
                        ->placeholder('Cantidad')
                        ->numeric()
                        ->minValue(0.001)
                        ->default(1),

                    TextInput::make('nueva_linea_costo_unitario')
                        ->label('Costo unitario')
                        ->hiddenLabel()
                        ->placeholder('Costo unitario')
                        ->numeric()
                        ->prefix('RD$')
                        ->minValue(0)
                        ->extraInputAttributes(['wire:keydown.enter.prevent' => 'agregarLinea']),

                    Actions::make([
                        Action::make('agregarLinea')
                            ->label('Agregar')
                            ->icon('heroicon-o-plus')
                            ->action(function ($livewire) {
                                $livewire->agregarLinea();
                            }),
                    ]),
                ]),

            Repeater::make('lineas')
                ->label('Detalle de productos')
                ->table([
                    TableColumn::make('Producto')->markAsRequired(),
                    TableColumn::make('Cantidad')->markAsRequired(),
                    TableColumn::make('Costo unitario')->markAsRequired(),
                    TableColumn::make('ITBIS'),
                ])
                ->schema([
                    TextEntry::make('producto_id')
                        ->hiddenLabel()
                        ->formatStateUsing(fn ($state) => Producto::find($state)?->nombre ?? '—'),

                    TextEntry::make('cantidad')
                        ->hiddenLabel()
                        ->numeric(decimalPlaces: 2),

                    TextEntry::make('costo_unitario')
                        ->hiddenLabel()
                        ->money('DOP'),

                    TextEntry::make('tasa_itbis_preview')
                        ->hiddenLabel()
                        ->state(function (Get $get) {
                            $producto = Producto::find($get('producto_id'));

                            return $producto ? $producto->tasa_itbis->porcentaje() . ' %' : '—';
                        }),
                ])
                ->addable(false)
                ->reorderable(false)
                ->minItems(1)
                ->required()
                ->columnSpanFull(),

            Section::make('Totales (estimado)')
                ->columnSpanFull()
                ->schema([
                    TextEntry::make('resumen_totales')
                        ->hiddenLabel()
                        ->state(function (Get $get) {
                            $lineas = collect($get('lineas') ?? [])
                                ->filter(fn ($l) => filled($l['producto_id'] ?? null) && filled($l['cantidad'] ?? null) && filled($l['costo_unitario'] ?? null))
                                ->values()
                                ->all();

                            if (empty($lineas)) {
                                return 'Agrega al menos una línea con producto, cantidad y costo.';
                            }

                            $service  = app(CompraService::class);
                            $calc     = $service->calcularLineas($lineas, (bool) $get('itbis_incluido'));
                            $totales  = $service->calcularTotales($calc);

                            return sprintf(
                                'Subtotal: RD$%s   |   ITBIS: RD$%s   |   Total: RD$%s',
                                number_format($totales['subtotal'], 2),
                                number_format($totales['itbis'], 2),
                                number_format($totales['total'], 2),
                            );
                        }),
                ]),
        ]);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Datos de la compra')
                ->columnSpanFull()
                ->columns(3)
                ->schema([
                    TextEntry::make('proveedor.nombre')->label('Proveedor'),
                    TextEntry::make('ncf')->label('NCF')->placeholder('—'),
                    TextEntry::make('fecha')->label('Fecha')->dateTime('d/m/Y H:i'),
                    TextEntry::make('estado')->label('Estado')->badge()
                        ->formatStateUsing(fn (EstadoCompra $state) => match ($state) {
                            EstadoCompra::REGISTRADA => 'Registrada',
                            EstadoCompra::ANULADA    => 'Anulada',
                        })
                        ->color(fn (EstadoCompra $state) => $state === EstadoCompra::ANULADA ? 'danger' : 'success'),
                    TextEntry::make('subtotal')->label('Subtotal')->money('DOP'),
                    TextEntry::make('itbis')->label('ITBIS')->money('DOP'),
                    TextEntry::make('total')->label('Total')->money('DOP'),
                    TextEntry::make('monto_total_factura')
                        ->label('Monto total de la factura')
                        ->money('DOP')
                        ->placeholder('—')
                        ->color(fn (Compra $record) => $record->monto_total_factura !== null
                            && abs((float) $record->monto_total_factura - (float) $record->total) >= 0.01
                                ? 'warning'
                                : null)
                        ->helperText(fn (Compra $record) => $record->monto_total_factura !== null
                            && abs((float) $record->monto_total_factura - (float) $record->total) >= 0.01
                                ? 'No coincide con el total calculado del sistema.'
                                : null),
                    TextEntry::make('motivo_anulacion')->label('Motivo de anulación')->placeholder('—')
                        ->visible(fn (Compra $record) => $record->estaAnulada()),
                ]),

            RepeatableEntry::make('detalles')
                ->label('Detalle de productos')
                ->table([
                    InfolistTableColumn::make('Producto'),
                    InfolistTableColumn::make('Cantidad'),
                    InfolistTableColumn::make('Costo unitario'),
                    InfolistTableColumn::make('ITBIS'),
                    InfolistTableColumn::make('Subtotal'),
                    InfolistTableColumn::make('Devuelto'),
                ])
                ->schema([
                    TextEntry::make('producto.nombre')->hiddenLabel(),
                    TextEntry::make('cantidad')->hiddenLabel()->numeric(decimalPlaces: 2),
                    TextEntry::make('costo_unitario')->hiddenLabel()->money('DOP'),
                    TextEntry::make('itbis_monto')->hiddenLabel()->money('DOP'),
                    TextEntry::make('subtotal')->hiddenLabel()->money('DOP'),
                    TextEntry::make('devuelto_preview')
                        ->hiddenLabel()
                        ->state(fn (DetalleCompra $record) => $record->cantidadDevuelta() > 0
                            ? number_format($record->cantidadDevuelta(), 2)
                            : '—'),
                ])
                ->columnSpanFull(),

            Section::make('Devoluciones registradas')
                ->columnSpanFull()
                ->visible(fn (Compra $record) => $record->devoluciones()->exists())
                ->schema([
                    RepeatableEntry::make('devoluciones')
                        ->hiddenLabel()
                        ->table([
                            InfolistTableColumn::make('Fecha'),
                            InfolistTableColumn::make('Motivo'),
                            InfolistTableColumn::make('Total'),
                            InfolistTableColumn::make('Estado'),
                        ])
                        ->schema([
                            TextEntry::make('fecha')->hiddenLabel()->dateTime('d/m/Y H:i'),
                            TextEntry::make('motivo')->hiddenLabel(),
                            TextEntry::make('total')->hiddenLabel()->money('DOP'),
                            TextEntry::make('estado')->hiddenLabel()->badge()
                                ->formatStateUsing(fn (EstadoDevolucion $state) => match ($state) {
                                    EstadoDevolucion::REGISTRADA => 'Registrada',
                                    EstadoDevolucion::ANULADA    => 'Anulada',
                                })
                                ->color(fn (EstadoDevolucion $state) => $state === EstadoDevolucion::ANULADA ? 'danger' : 'success'),
                        ]),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('proveedor.nombre')
                    ->label('Proveedor')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('ncf')
                    ->label('NCF')
                    ->placeholder('—')
                    ->searchable(),

                TextColumn::make('tipo_comprobante')
                    ->label('Tipo')
                    ->badge()
                    ->formatStateUsing(fn (TipoComprobante $state) => $state->etiqueta()),

                TextColumn::make('fecha')
                    ->label('Fecha')
                    ->dateTime('d/m/Y')
                    ->sortable(),

                TextColumn::make('total')
                    ->label('Total')
                    ->money('DOP')
                    ->sortable(),

                TextColumn::make('estado')
                    ->label('Estado')
                    ->badge()
                    ->formatStateUsing(fn (EstadoCompra $state) => match ($state) {
                        EstadoCompra::REGISTRADA => 'Registrada',
                        EstadoCompra::ANULADA    => 'Anulada',
                    })
                    ->color(fn (EstadoCompra $state) => $state === EstadoCompra::ANULADA ? 'danger' : 'success'),

                TextColumn::make('user.name')
                    ->label('Registrada por')
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('proveedor_id')
                    ->label('Proveedor')
                    ->relationship('proveedor', 'nombre')
                    ->searchable()
                    ->preload(),

                SelectFilter::make('estado')
                    ->label('Estado')
                    ->options([
                        EstadoCompra::REGISTRADA->value => 'Registrada',
                        EstadoCompra::ANULADA->value    => 'Anulada',
                    ]),

                Filter::make('fecha')
                    ->schema([
                        DatePicker::make('desde')->label('Desde'),
                        DatePicker::make('hasta')->label('Hasta'),
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        return $query
                            ->when($data['desde'] ?? null, fn (Builder $q, $desde) => $q->whereDate('fecha', '>=', $desde))
                            ->when($data['hasta'] ?? null, fn (Builder $q, $hasta) => $q->whereDate('fecha', '<=', $hasta));
                    }),
            ])
            ->recordActions([
                ViewAction::make(),

                Action::make('anular')
                    ->label('Anular')
                    ->icon('heroicon-o-x-circle')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->visible(fn (Compra $record): bool => ! $record->estaAnulada() && (auth()->user()?->can('anular_compras') ?? false))
                    ->schema([
                        Textarea::make('motivo')
                            ->label('Motivo de anulación')
                            ->required()
                            ->rows(2),
                    ])
                    ->action(function (Compra $record, array $data): void {
                        try {
                            app(CompraService::class)->anular($record, $data['motivo'], auth()->id());
                        } catch (RuntimeException $e) {
                            Notification::make()->title($e->getMessage())->danger()->send();

                            return;
                        }

                        Notification::make()->title('Compra anulada')->success()->send();
                    }),
            ])
            ->searchable(false)
            ->defaultSort('fecha', 'desc');
    }

    private static function proveedorEsInformal(Get $get): bool
    {
        $proveedorId = $get('proveedor_id');

        return $proveedorId ? (Proveedor::find($proveedorId)?->esInformal() ?? false) : false;
    }

    public static function getPages(): array
    {
        return [
            'index'  => Pages\CreateCompra::route('/'),
            'create' => Pages\CreateCompra::route('/create'),
            'view'   => Pages\ViewCompra::route('/{record}'),
        ];
    }
}
