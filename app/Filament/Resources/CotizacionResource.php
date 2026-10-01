<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Enums\EstadoCotizacion;
use App\Enums\Modulo;
use App\Filament\Concerns\RestringidoPorModulo;
use App\Filament\Resources\CotizacionResource\Pages;
use App\Models\Cliente;
use App\Models\Cotizacion;
use App\Models\Producto;
use Filament\Actions\ViewAction;
use Filament\Facades\Filament;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

class CotizacionResource extends Resource
{
    use RestringidoPorModulo;

    protected static ?string $model = Cotizacion::class;

    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentDuplicate;

    protected static string|UnitEnum|null $navigationGroup = 'Comercial';

    protected static ?int $navigationSort = 3;

    protected static ?string $navigationLabel = 'Cotizaciones';

    protected static ?string $pluralModelLabel = 'Cotizaciones';

    protected static ?string $modelLabel = 'Cotización';

    public static function modulo(): Modulo
    {
        return Modulo::COTIZACIONES;
    }

    public static function form(Schema $schema): Schema
    {
        $empresaId = Filament::getTenant()?->id;

        return $schema->components([
            Section::make('Datos generales')->schema([
                Select::make('cliente_id')
                    ->label('Cliente')
                    ->options(fn () => Cliente::where('empresa_id', $empresaId)
                        ->where('activo', true)
                        ->pluck('nombre', 'id'))
                    ->searchable()
                    ->placeholder('Sin cliente (cotización genérica)'),

                DatePicker::make('fecha')
                    ->label('Fecha')
                    ->default(fn () => now()->toDateString())
                    ->required(),

                TextInput::make('dias_vigencia')
                    ->label('Días de vigencia')
                    ->numeric()
                    ->default(15)
                    ->minValue(1)
                    ->required(),

                Select::make('condiciones_pago')
                    ->label('Condiciones de pago')
                    ->options([
                        'Contado' => 'Contado',
                        'Crédito 15 días' => 'Crédito 15 días',
                        'Crédito 30 días' => 'Crédito 30 días',
                        'Crédito 60 días' => 'Crédito 60 días',
                    ])
                    ->placeholder('Seleccionar...'),

                Textarea::make('notas')
                    ->label('Notas / Observaciones')
                    ->rows(2)
                    ->columnSpanFull(),
            ])->columns(2),

            Section::make('Productos')->schema([
                Repeater::make('lineas')
                    ->label('')
                    ->schema([
                        Select::make('producto_id')
                            ->label('Producto')
                            ->options(fn () => Producto::where('empresa_id', $empresaId)
                                ->where('activo', true)
                                ->pluck('nombre', 'id'))
                            ->searchable()
                            ->required()
                            ->live()
                            ->afterStateUpdated(function ($state, callable $set, callable $get) use ($empresaId) {
                                if (! $state) {
                                    return;
                                }

                                $producto = Producto::where('empresa_id', $empresaId)->find($state);

                                if (! $producto) {
                                    return;
                                }

                                $precio = $producto->precio;

                                $set('precio_unitario', $precio);
                            }),

                        TextInput::make('cantidad')
                            ->label('Cantidad')
                            ->numeric()
                            ->minValue(0.0001)
                            ->default(1)
                            ->required(),

                        TextInput::make('precio_unitario')
                            ->label('Precio unitario')
                            ->numeric()
                            ->prefix('RD$')
                            ->minValue(0)
                            ->required(),

                        TextInput::make('descuento')
                            ->label('Descuento')
                            ->numeric()
                            ->prefix('RD$')
                            ->default(0),
                    ])
                    ->columns(4)
                    ->defaultItems(1)
                    ->addActionLabel('Agregar producto')
                    ->reorderable(false),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('numero')
                    ->label('Número')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('cliente.nombre')
                    ->label('Cliente')
                    ->searchable()
                    ->placeholder('Sin cliente')
                    ->sortable(),

                TextColumn::make('fecha')
                    ->label('Fecha')
                    ->date('d/m/Y')
                    ->sortable(),

                TextColumn::make('fecha_vencimiento')
                    ->label('Vencimiento')
                    ->date('d/m/Y')
                    ->sortable(),

                TextColumn::make('total')
                    ->label('Total')
                    ->money('DOP')
                    ->sortable(),

                TextColumn::make('estado')
                    ->label('Estado')
                    ->badge()
                    ->formatStateUsing(fn (EstadoCotizacion $state) => $state->etiqueta())
                    ->color(fn (EstadoCotizacion $state) => $state->color())
                    ->sortable(),

                TextColumn::make('user.name')
                    ->label('Creada por')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('estado')
                    ->options(collect(EstadoCotizacion::cases())
                        ->mapWithKeys(fn (EstadoCotizacion $e) => [$e->value => $e->etiqueta()])
                        ->all()),

                SelectFilter::make('cliente_id')
                    ->label('Cliente')
                    ->options(fn () => Cliente::where('empresa_id', Filament::getTenant()?->id)
                        ->where('activo', true)
                        ->pluck('nombre', 'id'))
                    ->searchable(),

                Filter::make('rango')
                    ->schema([
                        DatePicker::make('desde')->label('Desde'),
                        DatePicker::make('hasta')->label('Hasta'),
                    ])
                    ->query(function (Builder $query, array $data) {
                        return $query
                            ->when($data['desde'] ?? null, fn (Builder $q, $d) => $q->whereDate('fecha', '>=', $d))
                            ->when($data['hasta'] ?? null, fn (Builder $q, $d) => $q->whereDate('fecha', '<=', $d));
                    }),
            ])
            ->defaultSort('fecha', 'desc')
            ->actions([
                ViewAction::make(),
            ]);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            \Filament\Infolists\Components\TextEntry::make('numero')->label('Número'),
            \Filament\Infolists\Components\TextEntry::make('cliente.nombre')->label('Cliente')->placeholder('Sin cliente'),
            \Filament\Infolists\Components\TextEntry::make('fecha')->label('Fecha')->date('d/m/Y'),
            \Filament\Infolists\Components\TextEntry::make('fecha_vencimiento')->label('Vencimiento')->date('d/m/Y'),
            \Filament\Infolists\Components\TextEntry::make('condiciones_pago')->label('Condiciones de pago')->placeholder('—'),
            \Filament\Infolists\Components\TextEntry::make('estado')
                ->label('Estado')
                ->badge()
                ->formatStateUsing(fn (EstadoCotizacion $state) => $state->etiqueta())
                ->color(fn (EstadoCotizacion $state) => $state->color()),
            \Filament\Infolists\Components\TextEntry::make('subtotal')->label('Subtotal')->money('DOP'),
            \Filament\Infolists\Components\TextEntry::make('descuento')->label('Descuento')->money('DOP'),
            \Filament\Infolists\Components\TextEntry::make('itbis')->label('ITBIS')->money('DOP'),
            \Filament\Infolists\Components\TextEntry::make('total')->label('Total')->money('DOP'),
            \Filament\Infolists\Components\TextEntry::make('user.name')->label('Creada por'),
            \Filament\Infolists\Components\TextEntry::make('aprobadoPor.name')->label('Aprobada por')->placeholder('—'),
            \Filament\Infolists\Components\TextEntry::make('notas')->label('Notas')->placeholder('—')->columnSpanFull(),

            \Filament\Infolists\Components\RepeatableEntry::make('detalles')
                ->label('Detalle')
                ->schema([
                    \Filament\Infolists\Components\TextEntry::make('producto.nombre')->label('Producto'),
                    \Filament\Infolists\Components\TextEntry::make('cantidad')->label('Cantidad'),
                    \Filament\Infolists\Components\TextEntry::make('precio_unitario')->label('Precio unit.')->money('DOP'),
                    \Filament\Infolists\Components\TextEntry::make('descuento')->label('Descuento')->money('DOP'),
                    \Filament\Infolists\Components\TextEntry::make('itbis')->label('ITBIS')->money('DOP'),
                    \Filament\Infolists\Components\TextEntry::make('subtotal')->label('Subtotal')->money('DOP'),
                ])
                ->columns(6),
        ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListCotizaciones::route('/'),
            'create' => Pages\CreateCotizacion::route('/create'),
            'view' => Pages\ViewCotizacion::route('/{record}'),
            'edit' => Pages\EditCotizacion::route('/{record}/edit'),
        ];
    }
}
