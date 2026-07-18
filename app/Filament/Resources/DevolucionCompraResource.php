<?php

namespace App\Filament\Resources;

use App\Enums\EstadoCompra;
use App\Enums\EstadoDevolucion;
use App\Filament\Resources\DevolucionCompraResource\Pages;
use App\Models\Compra;
use App\Models\DetalleCompra;
use App\Models\DevolucionCompra;
use App\Services\DevolucionCompraService;
use Filament\Actions\Action;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Repeater\TableColumn;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
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
use RuntimeException;

class DevolucionCompraResource extends Resource
{
    protected static ?string $model = DevolucionCompra::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-arrow-uturn-left';

    protected static ?string $navigationLabel = 'Devoluciones';

    protected static ?string $modelLabel = 'Devolución';

    protected static ?string $pluralModelLabel = 'Devoluciones';

    protected static ?string $slug = 'devoluciones-compra';

    protected static string|\UnitEnum|null $navigationGroup = 'Compras';

    public static function form(Schema $schema): Schema
    {
        return $schema->columns(1)->components([
            Section::make('Datos de la devolución')
                ->columnSpanFull()
                ->columns(3)
                ->schema([
                    Select::make('compra_id')
                        ->label('Compra')
                        ->relationship(
                            name: 'compra',
                            titleAttribute: 'ncf',
                            modifyQueryUsing: fn (Builder $query) => $query->where('estado', EstadoCompra::REGISTRADA)->with('proveedor'),
                        )
                        ->getOptionLabelFromRecordUsing(fn (Compra $record) => sprintf(
                            '%s — %s — %s',
                            $record->proveedor->nombre,
                            $record->ncf ?? 'sin NCF',
                            $record->fecha->format('d/m/Y'),
                        ))
                        ->searchable()
                        ->preload()
                        ->live()
                        ->required()
                        ->afterStateUpdated(fn (callable $set) => $set('lineas', [])),

                    DateTimePicker::make('fecha')
                        ->label('Fecha de la devolución')
                        ->default(now())
                        ->required(),

                    Textarea::make('motivo')
                        ->label('Motivo')
                        ->placeholder('Ej. faltante en la entrega, mercancía dañada…')
                        ->required()
                        ->rows(1)
                        ->columnSpanFull(),
                ]),

            Section::make('Agregar línea a devolver')
                ->columnSpanFull()
                ->columns(3)
                ->visible(fn (Get $get) => filled($get('compra_id')))
                ->schema([
                    Select::make('nueva_linea_detalle_compra_id')
                        ->label('Producto comprado')
                        ->hiddenLabel()
                        ->placeholder('Selecciona un producto de la compra…')
                        ->options(function (Get $get) {
                            $compraId = $get('compra_id');

                            if (! $compraId) {
                                return [];
                            }

                            return DetalleCompra::where('compra_id', $compraId)
                                ->get()
                                ->filter(fn (DetalleCompra $d) => $d->cantidadDisponibleParaDevolver() > 0)
                                ->mapWithKeys(fn (DetalleCompra $d) => [
                                    $d->id => "{$d->producto->nombre} (disponible: {$d->cantidadDisponibleParaDevolver()})",
                                ]);
                        })
                        ->live()
                        ->afterStateUpdated(function ($state, callable $set) {
                            $detalle = $state ? DetalleCompra::find($state) : null;
                            $set('nueva_linea_cantidad', $detalle?->cantidadDisponibleParaDevolver());
                        }),

                    TextEntry::make('nueva_linea_costo_preview')
                        ->hiddenLabel()
                        ->state(function (Get $get) {
                            $detalle = DetalleCompra::find($get('nueva_linea_detalle_compra_id'));

                            return $detalle ? 'Costo unitario: RD$'.number_format((float) $detalle->costo_unitario, 2) : '—';
                        }),

                    TextInput::make('nueva_linea_cantidad')
                        ->label('Cantidad a devolver')
                        ->numeric()
                        ->minValue(0.001),

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
                ->label('Líneas a devolver')
                ->table([
                    TableColumn::make('Producto')->markAsRequired(),
                    TableColumn::make('Cantidad a devolver')->markAsRequired(),
                ])
                ->schema([
                    // Hidden porque los TextEntry de abajo son solo de vista (Entry::isDehydrated()
                    // siempre es false): sin estos campos el valor real no viaja al $data del submit.
                    Hidden::make('detalle_compra_id'),
                    Hidden::make('cantidad'),

                    TextEntry::make('detalle_compra_id')
                        ->hiddenLabel()
                        ->formatStateUsing(fn ($state) => DetalleCompra::find($state)?->producto?->nombre ?? '—'),

                    TextEntry::make('cantidad')
                        ->hiddenLabel()
                        ->numeric(decimalPlaces: 2),
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
                                ->filter(fn ($l) => filled($l['detalle_compra_id'] ?? null) && filled($l['cantidad'] ?? null))
                                ->values();

                            if ($lineas->isEmpty()) {
                                return 'Agrega al menos una línea con producto y cantidad.';
                            }

                            $calc = $lineas->map(function ($l) {
                                $detalle = DetalleCompra::find($l['detalle_compra_id']);
                                $cantidad = (float) $l['cantidad'];
                                $subtotal = round((float) $detalle->costo_unitario * $cantidad, 2);
                                $porcentaje = $detalle->tasa_itbis->porcentaje();

                                return [
                                    'tasa_itbis' => $detalle->tasa_itbis,
                                    'subtotal' => $subtotal,
                                    'itbis_monto' => round($subtotal * $porcentaje / 100, 2),
                                ];
                            })->all();

                            $totales = app(DevolucionCompraService::class)->calcularTotales($calc);

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
            Section::make('Datos de la devolución')
                ->columnSpanFull()
                ->columns(3)
                ->schema([
                    TextEntry::make('compra.proveedor.nombre')->label('Proveedor'),
                    TextEntry::make('compra.ncf')->label('NCF de la compra')->placeholder('—'),
                    TextEntry::make('fecha')->label('Fecha')->dateTime('d/m/Y H:i'),
                    TextEntry::make('estado')->label('Estado')->badge()
                        ->formatStateUsing(fn (EstadoDevolucion $state) => match ($state) {
                            EstadoDevolucion::REGISTRADA => 'Registrada',
                            EstadoDevolucion::ANULADA => 'Anulada',
                        })
                        ->color(fn (EstadoDevolucion $state) => $state === EstadoDevolucion::ANULADA ? 'danger' : 'success'),
                    TextEntry::make('motivo')->label('Motivo')->columnSpanFull(),
                    TextEntry::make('subtotal')->label('Subtotal')->money('DOP'),
                    TextEntry::make('itbis')->label('ITBIS')->money('DOP'),
                    TextEntry::make('total')->label('Total')->money('DOP'),
                    TextEntry::make('motivo_anulacion')->label('Motivo de anulación')->placeholder('—')
                        ->visible(fn (DevolucionCompra $record) => $record->estaAnulada()),
                ]),

            RepeatableEntry::make('detalles')
                ->label('Detalle de productos devueltos')
                ->table([
                    InfolistTableColumn::make('Producto'),
                    InfolistTableColumn::make('Cantidad'),
                    InfolistTableColumn::make('Costo unitario'),
                    InfolistTableColumn::make('ITBIS'),
                    InfolistTableColumn::make('Subtotal'),
                ])
                ->schema([
                    TextEntry::make('producto.nombre')->hiddenLabel(),
                    TextEntry::make('cantidad')->hiddenLabel()->numeric(decimalPlaces: 2),
                    TextEntry::make('costo_unitario')->hiddenLabel()->money('DOP'),
                    TextEntry::make('itbis_monto')->hiddenLabel()->money('DOP'),
                    TextEntry::make('subtotal')->hiddenLabel()->money('DOP'),
                ])
                ->columnSpanFull(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('compra.proveedor.nombre')
                    ->label('Proveedor')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('compra.ncf')
                    ->label('NCF de la compra')
                    ->placeholder('—'),

                TextColumn::make('motivo')
                    ->label('Motivo')
                    ->limit(40),

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
                    ->formatStateUsing(fn (EstadoDevolucion $state) => match ($state) {
                        EstadoDevolucion::REGISTRADA => 'Registrada',
                        EstadoDevolucion::ANULADA => 'Anulada',
                    })
                    ->color(fn (EstadoDevolucion $state) => $state === EstadoDevolucion::ANULADA ? 'danger' : 'success'),

                TextColumn::make('user.name')
                    ->label('Registrada por')
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('estado')
                    ->label('Estado')
                    ->options([
                        EstadoDevolucion::REGISTRADA->value => 'Registrada',
                        EstadoDevolucion::ANULADA->value => 'Anulada',
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
                    ->visible(fn (DevolucionCompra $record): bool => ! $record->estaAnulada() && (auth()->user()?->can('compras.anular') ?? false))
                    ->schema([
                        Textarea::make('motivo')
                            ->label('Motivo de anulación')
                            ->required()
                            ->rows(2),
                    ])
                    ->action(function (DevolucionCompra $record, array $data): void {
                        try {
                            app(DevolucionCompraService::class)->anular($record, $data['motivo'], auth()->id());
                        } catch (RuntimeException $e) {
                            Notification::make()->title($e->getMessage())->danger()->send();

                            return;
                        }

                        Notification::make()->title('Devolución anulada')->success()->send();
                    }),
            ])
            ->defaultSort('fecha', 'desc');
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListDevolucionesCompra::route('/'),
            'create' => Pages\CreateDevolucionCompra::route('/create'),
            'view' => Pages\ViewDevolucionCompra::route('/{record}'),
        ];
    }
}
