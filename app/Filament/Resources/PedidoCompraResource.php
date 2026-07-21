<?php

namespace App\Filament\Resources;

use App\Enums\EstadoPedidoCompra;
use App\Filament\Resources\PedidoCompraResource\Pages;
use App\Mail\PedidoCompraEnviado;
use App\Models\PedidoCompra;
use App\Models\Producto;
use App\Services\PedidoCompraService;
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
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Mail;
use RuntimeException;

class PedidoCompraResource extends Resource
{
    protected static ?string $model = PedidoCompra::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-clipboard-document-list';

    protected static ?string $navigationLabel = 'Pedidos de Compra';

    protected static ?string $modelLabel = 'Pedido de Compra';

    protected static ?string $pluralModelLabel = 'Pedidos de Compra';

    protected static ?string $slug = 'pedidos-compra';

    protected static string|\UnitEnum|null $navigationGroup = 'Compras';

    public static function form(Schema $schema): Schema
    {
        return $schema->columns(1)->components([
            Section::make('Datos del pedido de compra')
                ->columnSpanFull()
                ->columns(3)
                ->schema([
                    Select::make('proveedor_id')
                        ->label('Proveedor')
                        ->relationship('proveedor', 'nombre')
                        ->searchable()
                        ->preload()
                        ->live()
                        ->required(),

                    DateTimePicker::make('fecha')
                        ->label('Fecha del pedido')
                        ->default(now())
                        ->required(),

                    Textarea::make('notas')
                        ->label('Notas')
                        ->rows(1)
                        ->columnSpanFull(),
                ]),

            Section::make('Agregar producto')
                ->columnSpanFull()
                ->columns(4)
                ->visible(fn (Get $get) => filled($get('proveedor_id')))
                ->schema([
                    Select::make('nueva_linea_producto_id')
                        ->label('Producto')
                        ->hiddenLabel()
                        ->placeholder('Selecciona un producto…')
                        ->options(function (Get $get) {
                            $proveedorId = $get('proveedor_id');

                            $query = Producto::query()->activos();

                            if ($proveedorId) {
                                $query->whereHas('proveedores', fn (Builder $q) => $q->where('proveedores.id', $proveedorId));
                            }

                            return $query->pluck('nombre', 'id');
                        })
                        ->searchable()
                        ->preload()
                        ->live()
                        ->afterStateUpdated(function ($state, callable $set, Get $get) {
                            $producto    = $state ? Producto::find($state) : null;
                            $proveedorId = $get('proveedor_id');

                            $costoReferencia = ($producto && $proveedorId)
                                ? $producto->proveedores()->where('proveedores.id', $proveedorId)->first()?->pivot?->costo_referencia
                                : null;

                            $set('nueva_linea_costo_unitario', $costoReferencia ?? $producto?->costo ?? 0);
                        }),

                    TextInput::make('nueva_linea_cantidad')
                        ->label('Cantidad')
                        ->hiddenLabel()
                        ->placeholder('Cantidad')
                        ->numeric()
                        ->minValue(0.001)
                        ->default(1),

                    TextInput::make('nueva_linea_costo_unitario')
                        ->label('Costo estimado')
                        ->hiddenLabel()
                        ->placeholder('Costo estimado')
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
                    TableColumn::make('Costo estimado')->markAsRequired(),
                    TableColumn::make('ITBIS'),
                ])
                ->schema([
                    // Hidden porque los TextEntry de abajo son solo de vista (Entry::isDehydrated()
                    // siempre es false): sin estos campos el valor real no viaja al $data del submit.
                    Hidden::make('producto_id'),
                    Hidden::make('cantidad'),
                    Hidden::make('costo_unitario'),

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
                                ->filter(fn ($l) => Producto::whereKey($l['producto_id'])->exists())
                                ->values()
                                ->all();

                            if (empty($lineas)) {
                                return 'Agrega al menos una línea con producto, cantidad y costo.';
                            }

                            $service = app(PedidoCompraService::class);
                            $calc    = $service->calcularLineas($lineas);
                            $totales = $service->calcularTotales($calc);

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
            Section::make('Datos del pedido')
                ->columnSpanFull()
                ->columns(3)
                ->schema([
                    TextEntry::make('proveedor.nombre')->label('Proveedor'),
                    TextEntry::make('fecha')->label('Fecha')->dateTime('d/m/Y H:i'),
                    TextEntry::make('estado')->label('Estado')->badge()
                        ->formatStateUsing(fn (EstadoPedidoCompra $state) => match ($state) {
                            EstadoPedidoCompra::PENDIENTE => 'Pendiente',
                            EstadoPedidoCompra::CANCELADO => 'Cancelado',
                        })
                        ->color(fn (EstadoPedidoCompra $state) => $state === EstadoPedidoCompra::CANCELADO ? 'danger' : 'gray'),
                    TextEntry::make('notas')->label('Notas')->placeholder('—')->columnSpanFull(),
                    TextEntry::make('enviado_en')->label('Enviado el')->dateTime('d/m/Y H:i')
                        ->visible(fn (PedidoCompra $record) => $record->fueEnviado()),
                    TextEntry::make('enviado_a')->label('Enviado a')
                        ->visible(fn (PedidoCompra $record) => $record->fueEnviado()),
                    TextEntry::make('subtotal')->label('Subtotal')->money('DOP'),
                    TextEntry::make('itbis')->label('ITBIS')->money('DOP'),
                    TextEntry::make('total')->label('Total')->money('DOP'),
                    TextEntry::make('motivo_cancelacion')->label('Motivo de cancelación')->placeholder('—')
                        ->visible(fn (PedidoCompra $record) => $record->estaCancelado()),
                ]),

            RepeatableEntry::make('detalles')
                ->label('Detalle de productos')
                ->table([
                    InfolistTableColumn::make('Producto'),
                    InfolistTableColumn::make('Cantidad'),
                    InfolistTableColumn::make('Costo estimado'),
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
                TextColumn::make('proveedor.nombre')
                    ->label('Proveedor')
                    ->searchable()
                    ->sortable(),

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
                    ->formatStateUsing(fn (EstadoPedidoCompra $state) => match ($state) {
                        EstadoPedidoCompra::PENDIENTE => 'Pendiente',
                        EstadoPedidoCompra::CANCELADO => 'Cancelado',
                    })
                    ->color(fn (EstadoPedidoCompra $state) => $state === EstadoPedidoCompra::CANCELADO ? 'danger' : 'gray'),

                IconColumn::make('enviado')
                    ->label('Enviado')
                    ->boolean()
                    ->state(fn (PedidoCompra $record) => $record->fueEnviado()),

                TextColumn::make('user.name')
                    ->label('Registrado por')
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
                        EstadoPedidoCompra::PENDIENTE->value => 'Pendiente',
                        EstadoPedidoCompra::CANCELADO->value => 'Cancelado',
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

                Action::make('descargarPdf')
                    ->label('Descargar PDF')
                    ->icon('heroicon-o-document-arrow-down')
                    ->color('gray')
                    ->url(fn (PedidoCompra $record) => route('pedidos-compra.pdf', $record), shouldOpenInNewTab: true),

                Action::make('enviarPorCorreo')
                    ->label('Enviar por correo')
                    ->icon('heroicon-o-envelope')
                    ->color('gray')
                    ->visible(fn (PedidoCompra $record): bool => ! $record->estaCancelado() && (auth()->user()?->can('gestionar_compras') ?? false))
                    ->schema([
                        TextInput::make('email')
                            ->label('Correo del proveedor')
                            ->email()
                            ->required()
                            ->default(fn (PedidoCompra $record) => $record->proveedor->email),
                    ])
                    ->action(function (PedidoCompra $record, array $data): void {
                        try {
                            Mail::to($data['email'])->send(new PedidoCompraEnviado($record));
                            app(PedidoCompraService::class)->marcarEnviado($record, $data['email'], auth()->id());
                        } catch (RuntimeException $e) {
                            Notification::make()->title($e->getMessage())->danger()->send();

                            return;
                        }

                        Notification::make()->title('Pedido enviado por correo')->success()->send();
                    }),

                Action::make('cancelar')
                    ->label('Cancelar')
                    ->icon('heroicon-o-x-circle')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->visible(fn (PedidoCompra $record): bool => ! $record->estaCancelado() && (auth()->user()?->can('anular_compras') ?? false))
                    ->schema([
                        Textarea::make('motivo')
                            ->label('Motivo de cancelación')
                            ->required()
                            ->rows(2),
                    ])
                    ->action(function (PedidoCompra $record, array $data): void {
                        try {
                            app(PedidoCompraService::class)->cancelar($record, $data['motivo'], auth()->id());
                        } catch (RuntimeException $e) {
                            Notification::make()->title($e->getMessage())->danger()->send();

                            return;
                        }

                        Notification::make()->title('Pedido cancelado')->success()->send();
                    }),
            ])
            ->defaultSort('fecha', 'desc');
    }

    public static function getPages(): array
    {
        return [
            'index'  => Pages\ListPedidosCompra::route('/'),
            'create' => Pages\CreatePedidoCompra::route('/create'),
            'view'   => Pages\ViewPedidoCompra::route('/{record}'),
        ];
    }
}
