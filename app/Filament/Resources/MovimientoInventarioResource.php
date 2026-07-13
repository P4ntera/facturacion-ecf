<?php

namespace App\Filament\Resources;

use App\Enums\OrigenMovimiento;
use App\Enums\TipoMovimiento;
use App\Filament\Resources\MovimientoInventarioResource\Pages;
use App\Models\MovimientoInventario;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DatePicker;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class MovimientoInventarioResource extends Resource
{
    protected static ?string $model = MovimientoInventario::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-archive-box';

    protected static ?string $navigationLabel = 'Kardex';

    protected static ?string $modelLabel = 'Movimiento de inventario';

    protected static ?string $pluralModelLabel = 'Kardex';

    protected static ?string $slug = 'kardex';

    protected static string|\UnitEnum|null $navigationGroup = 'Inventario';

    // Kardex: solo lectura, no se crea ni edita a mano.
    public static function canCreate(): bool
    {
        return false;
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            TextEntry::make('producto.nombre')->label('Producto'),
            TextEntry::make('tipo')->label('Tipo')->formatStateUsing(fn (TipoMovimiento $state) => match ($state) {
                TipoMovimiento::ENTRADA => 'Entrada',
                TipoMovimiento::SALIDA  => 'Salida',
                TipoMovimiento::AJUSTE  => 'Ajuste',
            }),
            TextEntry::make('origen')->label('Origen')->formatStateUsing(fn (OrigenMovimiento $state) => match ($state) {
                OrigenMovimiento::VENTA             => 'Venta',
                OrigenMovimiento::COMPRA            => 'Compra',
                OrigenMovimiento::AJUSTE            => 'Ajuste',
                OrigenMovimiento::ANULACION         => 'Anulación',
                OrigenMovimiento::DEVOLUCION_COMPRA => 'Devolución a proveedor',
            }),
            TextEntry::make('cantidad')->label('Cantidad')->numeric(decimalPlaces: 2),
            TextEntry::make('stock_anterior')->label('Stock anterior')->numeric(decimalPlaces: 2),
            TextEntry::make('stock_nuevo')->label('Stock nuevo')->numeric(decimalPlaces: 2),
            TextEntry::make('user.name')->label('Usuario')->placeholder('Sistema'),
            TextEntry::make('observacion')->label('Observación')->placeholder('—')->columnSpanFull(),
            TextEntry::make('created_at')->label('Fecha')->dateTime('d/m/Y H:i'),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('producto.nombre')
                    ->label('Producto')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('tipo')
                    ->label('Tipo')
                    ->badge()
                    ->formatStateUsing(fn ($state) => match ($state) {
                        TipoMovimiento::ENTRADA => 'Entrada',
                        TipoMovimiento::SALIDA  => 'Salida',
                        TipoMovimiento::AJUSTE  => 'Ajuste',
                        default                 => $state,
                    })
                    ->color(fn ($state) => match ($state) {
                        TipoMovimiento::ENTRADA => 'success',
                        TipoMovimiento::SALIDA  => 'danger',
                        TipoMovimiento::AJUSTE  => 'warning',
                        default                 => 'gray',
                    }),

                TextColumn::make('origen')
                    ->label('Origen')
                    ->badge()
                    ->formatStateUsing(fn ($state) => match ($state) {
                        OrigenMovimiento::VENTA             => 'Venta',
                        OrigenMovimiento::COMPRA            => 'Compra',
                        OrigenMovimiento::AJUSTE            => 'Ajuste',
                        OrigenMovimiento::ANULACION         => 'Anulación',
                        OrigenMovimiento::DEVOLUCION_COMPRA => 'Devolución a proveedor',
                        default                              => $state,
                    }),

                TextColumn::make('cantidad')
                    ->label('Cantidad')
                    ->numeric(decimalPlaces: 2)
                    ->sortable(),

                TextColumn::make('stock_anterior')
                    ->label('Stock anterior')
                    ->numeric(decimalPlaces: 2),

                TextColumn::make('stock_nuevo')
                    ->label('Stock nuevo')
                    ->numeric(decimalPlaces: 2),

                TextColumn::make('user.name')
                    ->label('Usuario')
                    ->placeholder('Sistema')
                    ->sortable(),

                TextColumn::make('created_at')
                    ->label('Fecha')
                    ->dateTime('d/m/Y H:i')
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('producto_id')
                    ->label('Producto')
                    ->relationship('producto', 'nombre')
                    ->searchable()
                    ->preload(),

                Filter::make('fecha')
                    ->schema([
                        DatePicker::make('desde')->label('Desde'),
                        DatePicker::make('hasta')->label('Hasta'),
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        return $query
                            ->when($data['desde'] ?? null, fn (Builder $q, $desde) => $q->whereDate('created_at', '>=', $desde))
                            ->when($data['hasta'] ?? null, fn (Builder $q, $hasta) => $q->whereDate('created_at', '<=', $hasta));
                    }),
            ])
            ->recordActions([
                ViewAction::make(),
            ])
            ->defaultSort('created_at', 'desc');
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListMovimientoInventarios::route('/'),
            'view'  => Pages\ViewMovimientoInventario::route('/{record}'),
        ];
    }
}
