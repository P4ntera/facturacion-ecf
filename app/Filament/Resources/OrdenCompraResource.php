<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Enums\EstadoOrdenCompra;
use App\Enums\Modulo;
use App\Filament\Concerns\RestringidoPorModulo;
use App\Filament\Resources\OrdenCompraResource\Pages;
use App\Filament\Resources\OrdenCompraResource\RelationManagers\RecepcionesRelationManager;
use App\Models\OrdenCompra;
use App\Models\Producto;
use App\Models\Proveedor;
use App\Services\OrdenCompraService;
use Filament\Actions\Action;
use Filament\Actions\ViewAction;
use Filament\Facades\Filament;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use RuntimeException;

class OrdenCompraResource extends Resource
{
    use RestringidoPorModulo;

    protected static ?string $model = OrdenCompra::class;

    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedClipboardDocumentCheck;

    protected static string|\UnitEnum|null $navigationGroup = 'Inventario';

    protected static ?int $navigationSort = 5;

    protected static ?string $navigationLabel = 'Órdenes de Compra';

    protected static ?string $pluralModelLabel = 'Órdenes de Compra';

    protected static ?string $modelLabel = 'Orden de Compra';

    public static function modulo(): Modulo
    {
        return Modulo::COMPRAS_PEDIDOS;
    }

    public static function form(Schema $schema): Schema
    {
        $empresaId = Filament::getTenant()?->id;

        return $schema->components([
            Section::make('Datos generales')->schema([
                Select::make('oc_base_id')
                    ->label('Crear desde OC anterior')
                    ->placeholder('Buscar por número, proveedor o fecha...')
                    ->searchable()
                    ->getSearchResultsUsing(function (string $search) use ($empresaId) {
                        return OrdenCompra::where('empresa_id', $empresaId)
                            ->whereIn('estado', [
                                EstadoOrdenCompra::ENVIADA,
                                EstadoOrdenCompra::RECEPCION_PARCIAL,
                                EstadoOrdenCompra::COMPLETADA,
                            ])
                            ->where(function ($q) use ($search) {
                                $q->where('numero', 'like', "%{$search}%")
                                  ->orWhereHas('proveedor', fn ($q) => $q->where('nombre', 'like', "%{$search}%"))
                                  ->orWhere('fecha', 'like', "%{$search}%");
                            })
                            ->orderByDesc('fecha')
                            ->limit(50)
                            ->get()
                            ->mapWithKeys(fn (OrdenCompra $oc) => [
                                $oc->id => "{$oc->numero} — {$oc->proveedor->nombre} ({$oc->fecha->format('d/m/Y')}) — RD$ ".number_format((float) $oc->total, 2),
                            ]);
                    })
                    ->live()
                    ->afterStateUpdated(function ($state, callable $set) use ($empresaId) {
                        if (! $state) {
                            return;
                        }

                        $oc = OrdenCompra::where('empresa_id', $empresaId)
                            ->with('detalles')
                            ->find($state);

                        if (! $oc) {
                            return;
                        }

                        $set('proveedor_id', $oc->proveedor_id);
                        $set('lineas', $oc->detalles->map(fn ($d) => [
                            'producto_id' => $d->producto_id,
                            'cantidad_solicitada' => $d->cantidad_solicitada,
                            'precio_unitario' => $d->precio_unitario,
                        ])->toArray());
                    })
                    ->visible(fn ($livewire) => $livewire instanceof CreateRecord)
                    ->dehydrated(false),

                Select::make('proveedor_id')
                    ->label('Proveedor')
                    ->options(fn () => Proveedor::where('empresa_id', $empresaId)
                        ->where('activo', true)
                        ->pluck('nombre', 'id'))
                    ->searchable()
                    ->required(),

                DatePicker::make('fecha')
                    ->label('Fecha')
                    ->default(fn () => now()->toDateString())
                    ->required(),

                DatePicker::make('fecha_esperada')
                    ->label('Fecha esperada de entrega'),

                Textarea::make('notas')
                    ->label('Notas / Observaciones')
                    ->rows(2),
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
                            ->afterStateUpdated(function ($state, callable $set) use ($empresaId) {
                                if ($state) {
                                    $producto = Producto::where('empresa_id', $empresaId)->find($state);
                                    if ($producto) {
                                        $set('precio_unitario', $producto->costo);
                                    }
                                }
                            }),

                        TextInput::make('cantidad_solicitada')
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
                    ])
                    ->columns(3)
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

                TextColumn::make('proveedor.nombre')
                    ->label('Proveedor')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('fecha')
                    ->label('Fecha')
                    ->date('d/m/Y')
                    ->sortable(),

                TextColumn::make('fecha_esperada')
                    ->label('Fecha esperada')
                    ->date('d/m/Y')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('total')
                    ->label('Total')
                    ->money('DOP')
                    ->sortable(),

                TextColumn::make('estado')
                    ->label('Estado')
                    ->badge()
                    ->formatStateUsing(fn (EstadoOrdenCompra $state) => $state->etiqueta())
                    ->color(fn (EstadoOrdenCompra $state) => $state->color())
                    ->sortable(),

                TextColumn::make('porcentaje_recibido')
                    ->label('% Recibido')
                    ->getStateUsing(fn (OrdenCompra $record) => $record->porcentajeRecibido().'%')
                    ->sortable(false),

                TextColumn::make('user.name')
                    ->label('Creada por')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('estado')
                    ->options(collect(EstadoOrdenCompra::cases())
                        ->mapWithKeys(fn (EstadoOrdenCompra $e) => [$e->value => $e->etiqueta()])
                        ->all()),

                SelectFilter::make('proveedor_id')
                    ->label('Proveedor')
                    ->options(fn () => Proveedor::where('empresa_id', Filament::getTenant()?->id)
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
            \Filament\Infolists\Components\TextEntry::make('proveedor.nombre')->label('Proveedor'),
            \Filament\Infolists\Components\TextEntry::make('fecha')->label('Fecha')->date('d/m/Y'),
            \Filament\Infolists\Components\TextEntry::make('fecha_esperada')->label('Fecha esperada')->date('d/m/Y')->placeholder('—'),
            \Filament\Infolists\Components\TextEntry::make('estado')
                ->label('Estado')
                ->badge()
                ->formatStateUsing(fn (EstadoOrdenCompra $state) => $state->etiqueta())
                ->color(fn (EstadoOrdenCompra $state) => $state->color()),
            \Filament\Infolists\Components\TextEntry::make('subtotal')->label('Subtotal')->money('DOP'),
            \Filament\Infolists\Components\TextEntry::make('itbis')->label('ITBIS')->money('DOP'),
            \Filament\Infolists\Components\TextEntry::make('total')->label('Total')->money('DOP'),
            \Filament\Infolists\Components\TextEntry::make('user.name')->label('Creada por'),
            \Filament\Infolists\Components\TextEntry::make('aprobadoPor.name')->label('Aprobada por')->placeholder('—'),
            \Filament\Infolists\Components\TextEntry::make('aprobado_en')->label('Fecha aprobación')->dateTime('d/m/Y H:i')->placeholder('—'),
            \Filament\Infolists\Components\TextEntry::make('notas')->label('Notas')->placeholder('—')->columnSpanFull(),

            \Filament\Infolists\Components\RepeatableEntry::make('detalles')
                ->label('Detalle')
                ->schema([
                    \Filament\Infolists\Components\TextEntry::make('producto.nombre')->label('Producto'),
                    \Filament\Infolists\Components\TextEntry::make('cantidad_solicitada')->label('Solicitada'),
                    \Filament\Infolists\Components\TextEntry::make('cantidad_recibida')->label('Recibida'),
                    \Filament\Infolists\Components\TextEntry::make('precio_unitario')->label('Precio unit.')->money('DOP'),
                    \Filament\Infolists\Components\TextEntry::make('itbis')->label('ITBIS')->money('DOP'),
                    \Filament\Infolists\Components\TextEntry::make('subtotal')->label('Subtotal')->money('DOP'),
                ])
                ->columns(6),
        ]);
    }

    public static function getRelations(): array
    {
        return [
            RecepcionesRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListOrdenesCompra::route('/'),
            'create' => Pages\CreateOrdenCompra::route('/create'),
            'view' => Pages\ViewOrdenCompra::route('/{record}'),
            'edit' => Pages\EditOrdenCompra::route('/{record}/edit'),
        ];
    }
}
