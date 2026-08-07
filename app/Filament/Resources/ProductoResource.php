<?php

namespace App\Filament\Resources;

use App\Enums\Modulo;
use App\Enums\OrigenMovimiento;
use App\Enums\TasaItbis;
use App\Enums\TipoMovimiento;
use App\Enums\TipoProducto;
use App\Enums\TipoVenta;
use App\Exceptions\StockInsuficienteException;
use App\Filament\Concerns\RestringidoPorModulo;
use App\Filament\Resources\ProductoResource\Pages;
use App\Models\Producto;
use App\Services\InventarioService;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ToggleColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

class ProductoResource extends Resource
{
    use RestringidoPorModulo;

    protected static ?string $model = Producto::class;

    public static function modulo(): Modulo
    {
        return Modulo::MAESTROS_PRODUCTOS;
    }

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-cube';

    protected static ?string $navigationLabel = 'Productos';

    protected static ?string $modelLabel = 'Producto';

    protected static ?string $pluralModelLabel = 'Productos';

    protected static string|\UnitEnum|null $navigationGroup = 'Maestros';

    protected static ?int $navigationSort = 10;

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Identificación')
                    ->columns(2)
                    ->components([
                        TextInput::make('codigo')
                            ->label('Código')
                            ->required()
                            ->unique(ignoreRecord: true)
                            ->validationMessages(['unique' => 'Ya existe un producto con este código.'])
                            ->maxLength(50),

                        TextInput::make('codigo_barra')
                            ->label('Código de barras')
                            ->helperText('Escanea o escribe el código de barras.')
                            ->unique(ignoreRecord: true)
                            ->validationMessages(['unique' => 'Ya existe un producto con este código de barras.'])
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
                            ->required()
                            ->default(TipoProducto::PRODUCTO->value),

                        Select::make('tipo_venta')
                            ->label('Tipo de venta')
                            ->options([
                                TipoVenta::CONTABLE->value => TipoVenta::CONTABLE->etiqueta(),
                                TipoVenta::PESADO->value => TipoVenta::PESADO->etiqueta(),
                            ])
                            ->required()
                            ->default(TipoVenta::CONTABLE->value)
                            ->live()
                            ->afterStateUpdated(function (Get $get, callable $set, ?string $state): void {
                                if ($state === TipoVenta::PESADO->value) {
                                    $set('unidad_base', $get('unidad_base') === 'unidad' ? 'libra' : $get('unidad_base'));

                                    return;
                                }

                                $set('unidad_base', 'unidad');
                                $set('precio_por_peso', null);
                            }),

                        Select::make('unidad_base')
                            ->label('Unidad de peso')
                            ->options([
                                'libra' => 'Libra (lb)',
                                'kilogramo' => 'Kilogramo (kg)',
                            ])
                            ->required()
                            ->default('libra')
                            ->visible(fn (Get $get): bool => $get('tipo_venta') === TipoVenta::PESADO->value),

                        Select::make('categoria_id')
                            ->label('Categoría')
                            ->relationship('categoria', 'nombre')
                            ->searchable()
                            ->preload()
                            ->nullable(),

                        Textarea::make('descripcion')
                            ->label('Descripción')
                            ->rows(2)
                            ->columnSpanFull(),
                    ]),

                Section::make('Precios')
                    ->columns(3)
                    ->components([
                        TextInput::make('precio')
                            ->label('Precio de venta')
                            ->numeric()
                            ->prefix('RD$')
                            ->minValue(0)
                            ->default(0)
                            ->live()
                            ->required(fn (Get $get): bool => $get('tipo_venta') !== TipoVenta::PESADO->value)
                            ->hidden(fn (Get $get): bool => $get('tipo_venta') === TipoVenta::PESADO->value)
                            ->helperText('Precio de la presentación base (factor 1); las demás presentaciones tienen su propio precio.'),

                        TextInput::make('precio_por_peso')
                            ->label('Precio por unidad de peso')
                            ->numeric()
                            ->prefix('RD$')
                            ->minValue(0)
                            ->required(fn (Get $get): bool => $get('tipo_venta') === TipoVenta::PESADO->value)
                            ->visible(fn (Get $get): bool => $get('tipo_venta') === TipoVenta::PESADO->value),

                        TextInput::make('costo')
                            ->label('Costo')
                            ->numeric()
                            ->prefix('RD$')
                            ->minValue(0)
                            ->default(0),

                        Select::make('tasa_itbis')
                            ->label('Tasa ITBIS')
                            ->options([
                                TasaItbis::DIECIOCHO->value => '18 %',
                                TasaItbis::DIECISEIS->value => '16 %',
                                TasaItbis::CERO->value => '0 % (Exento)',
                            ])
                            ->required()
                            ->default(TasaItbis::DIECIOCHO->value),
                    ]),

                Section::make('Presentaciones')
                    ->description('Unidad, six-pack, caja... cada una con su propio código de barras y precio. El stock siempre se lleva en la unidad base.')
                    ->visible(fn (Get $get): bool => $get('tipo_venta') !== TipoVenta::PESADO->value)
                    ->components([
                        Repeater::make('presentaciones')
                            ->relationship()
                            ->hiddenLabel()
                            ->schema([
                                TextInput::make('nombre')
                                    ->label('Nombre')
                                    ->placeholder('Unidad, Six-pack, Caja…')
                                    ->required()
                                    ->maxLength(50),

                                TextInput::make('factor')
                                    ->label('Factor')
                                    ->helperText('Unidades base que representa (la base tiene factor 1).')
                                    ->numeric()
                                    ->required()
                                    ->minValue(0.001)
                                    ->default(1)
                                    ->live(onBlur: true),

                                TextInput::make('codigo_barra')
                                    ->label('Código de barras')
                                    ->maxLength(50)
                                    ->distinct()
                                    ->unique(table: 'producto_presentaciones', column: 'codigo_barra', ignoreRecord: true)
                                    ->validationMessages([
                                        'distinct' => 'Este código de barras ya se usó en otra presentación de este producto.',
                                        'unique' => 'Ya existe otra presentación (de cualquier producto) con este código de barras.',
                                    ]),

                                TextInput::make('precio')
                                    ->label('Precio')
                                    ->numeric()
                                    ->prefix('RD$')
                                    ->required()
                                    ->minValue(0)
                                    ->helperText(function (Get $get): string {
                                        $sugerido = (float) ($get('../../precio') ?? 0) * (float) ($get('factor') ?? 0);

                                        return 'Sugerido: RD$ '.number_format($sugerido, 2).' (base × factor; el precio real puede ser menor por volumen).';
                                    }),

                                Toggle::make('es_base')
                                    ->label('Es la presentación base')
                                    ->default(false)
                                    ->live(),

                                Toggle::make('activa')
                                    ->label('Activa')
                                    ->default(true),
                            ])
                            ->columns(3)
                            ->defaultItems(1)
                            ->default([
                                ['nombre' => 'Unidad', 'factor' => 1, 'precio' => 0, 'es_base' => true, 'activa' => true],
                            ])
                            ->minItems(1)
                            ->addActionLabel('Agregar presentación')
                            ->itemLabel(fn (array $state): ?string => $state['nombre'] ?? null)
                            ->mutateRelationshipDataBeforeCreateUsing(
                                fn (array $data): array => [...$data, 'empresa_id' => Filament::getTenant()->id],
                            )
                            ->rule(static function () {
                                return function (string $attribute, mixed $value, \Closure $fail): void {
                                    $items = collect($value ?? []);
                                    $bases = $items->filter(fn ($item) => (bool) ($item['es_base'] ?? false));

                                    if ($bases->count() !== 1) {
                                        $fail('Debe haber exactamente una presentación marcada como base.');

                                        return;
                                    }

                                    if ((float) ($bases->first()['factor'] ?? 0) !== 1.0) {
                                        $fail('La presentación marcada como base debe tener factor 1.');
                                    }
                                };
                            })
                            ->columnSpanFull(),
                    ]),

                Section::make('Inventario')
                    ->columns(2)
                    ->components([
                        Toggle::make('controla_stock')
                            ->label('Controla stock')
                            ->default(false)
                            ->live()
                            ->columnSpanFull(),

                        // Solo lectura: el stock se mueve exclusivamente vía InventarioService
                        // (acción "Ajustar stock", ventas, compras), nunca editando este campo a mano.
                        TextInput::make('stock')
                            ->label('Stock actual')
                            ->numeric()
                            ->default(0)
                            ->disabled()
                            ->dehydrated(false)
                            ->hidden(fn (Get $get): bool => ! $get('controla_stock')),

                        TextInput::make('stock_minimo')
                            ->label('Stock mínimo')
                            ->numeric()
                            ->minValue(0)
                            ->default(0)
                            ->hidden(fn (Get $get): bool => ! $get('controla_stock')),

                        Toggle::make('activo')
                            ->label('Activo')
                            ->default(true)
                            ->columnSpanFull(),
                    ]),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('codigo')
                    ->label('Código')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('codigo_barra')
                    ->label('Código de barras')
                    ->searchable(query: function (Builder $query, string $search): Builder {
                        return $query->where(
                            fn (Builder $q) => $q->where('codigo_barra', 'ilike', "%{$search}%")
                                ->orWhereHas('presentaciones', fn (Builder $p) => $p->where('codigo_barra', 'ilike', "%{$search}%")),
                        );
                    })
                    ->toggleable(isToggledHiddenByDefault: true)
                    ->placeholder('—'),

                TextColumn::make('nombre')
                    ->label('Nombre')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('categoria.nombre')
                    ->label('Categoría')
                    ->placeholder('—')
                    ->sortable(),

                TextColumn::make('tipo')
                    ->label('Tipo')
                    ->formatStateUsing(fn ($state) => match ($state) {
                        TipoProducto::PRODUCTO => 'Producto',
                        TipoProducto::SERVICIO => 'Servicio',
                        default => $state,
                    })
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('tipo_venta')
                    ->label('Venta')
                    ->formatStateUsing(fn (?TipoVenta $state): string => $state?->etiqueta() ?? '—')
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('precio')
                    ->label('Precio')
                    ->formatStateUsing(fn (Producto $record): string => $record->tipo_venta === TipoVenta::PESADO
                        ? ($record->precio_por_peso !== null ? 'RD$ '.number_format((float) $record->precio_por_peso, 2).' / '.$record->unidad_base : '—')
                        : 'RD$ '.number_format((float) $record->precio, 2))
                    ->alignEnd()
                    ->sortable(),

                TextColumn::make('tasa_itbis')
                    ->label('ITBIS')
                    ->formatStateUsing(fn ($state) => match ($state) {
                        TasaItbis::DIECIOCHO => '18 %',
                        TasaItbis::DIECISEIS => '16 %',
                        TasaItbis::CERO => '0 % (Exento)',
                        default => $state,
                    })
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('stock')
                    ->label('Stock')
                    ->numeric(decimalPlaces: 2)
                    ->alignEnd()
                    ->sortable()
                    ->placeholder('—'),

                ToggleColumn::make('activo')
                    ->label('Activo')
                    ->disabled(fn (): bool => ! auth()->user()?->can('productos.desactivar'))
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('tipo')
                    ->label('Tipo')
                    ->options([
                        TipoProducto::PRODUCTO->value => 'Producto',
                        TipoProducto::SERVICIO->value => 'Servicio',
                    ]),
                SelectFilter::make('categoria_id')
                    ->label('Categoría')
                    ->relationship('categoria', 'nombre'),
                TernaryFilter::make('activo')->label('Activo')->default(true),
            ])
            ->recordActions([
                Action::make('ajustarStock')
                    ->label('Ajustar stock')
                    ->icon('heroicon-o-adjustments-horizontal')
                    ->visible(fn (): bool => auth()->user()?->can('inventario.ajustar') ?? false)
                    ->schema([
                        Select::make('tipo')
                            ->label('Tipo')
                            ->options([
                                TipoMovimiento::ENTRADA->value => 'Entrada',
                                TipoMovimiento::SALIDA->value => 'Salida',
                                TipoMovimiento::AJUSTE->value => 'Ajuste',
                            ])
                            ->required(),

                        TextInput::make('cantidad')
                            ->label('Cantidad')
                            ->numeric()
                            ->required()
                            ->minValue(0.001),

                        Textarea::make('observacion')
                            ->label('Observación')
                            ->rows(2),
                    ])
                    ->action(function (Producto $record, array $data): void {
                        try {
                            DB::transaction(fn () => app(InventarioService::class)->registrarMovimiento(
                                $record,
                                TipoMovimiento::from($data['tipo']),
                                OrigenMovimiento::AJUSTE,
                                (float) $data['cantidad'],
                                null,
                                auth()->id(),
                                $data['observacion'] ?? null,
                            ));
                        } catch (StockInsuficienteException $e) {
                            Notification::make()->title($e->getMessage())->danger()->send();

                            return;
                        }

                        Notification::make()->title('Stock ajustado')->success()->send();
                    }),
            ])
            ->defaultSort('nombre');
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListProductos::route('/'),
            'create' => Pages\CreateProducto::route('/create'),
            'edit' => Pages\EditProducto::route('/{record}/edit'),
        ];
    }
}
