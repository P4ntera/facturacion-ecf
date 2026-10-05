<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Enums\EstadoComanda;
use App\Enums\EstadoPreparacion;
use App\Enums\Modulo;
use App\Filament\Concerns\RestringidoPorModulo;
use App\Filament\Resources\ComandaResource\Pages;
use App\Models\Comanda;
use App\Models\User;
use Filament\Facades\Filament;
use Filament\Forms\Components\DatePicker;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class ComandaResource extends Resource
{
    use RestringidoPorModulo;

    protected static ?string $model = Comanda::class;

    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedClipboardDocumentList;

    protected static string|\UnitEnum|null $navigationGroup = 'Restaurante';

    protected static ?int $navigationSort = 4;

    protected static ?string $navigationLabel = 'Comandas';

    protected static ?string $pluralModelLabel = 'Comandas';

    protected static ?string $modelLabel = 'Comanda';

    public static function modulo(): Modulo
    {
        return Modulo::RESTAURANTE_COMANDAS;
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('numero')
                    ->label('Número')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('mesa.numero')
                    ->label('Mesa')
                    ->sortable(),

                TextColumn::make('mesero.name')
                    ->label('Mesero')
                    ->sortable(),

                TextColumn::make('comensales')
                    ->label('Comensales')
                    ->sortable(),

                TextColumn::make('estado')
                    ->label('Estado')
                    ->badge()
                    ->formatStateUsing(fn (EstadoComanda $state) => $state->etiqueta())
                    ->color(fn (EstadoComanda $state) => $state->color()),

                TextColumn::make('created_at')
                    ->label('Abierta')
                    ->dateTime('d/m/Y H:i')
                    ->sortable(),

                TextColumn::make('cerrada_en')
                    ->label('Cerrada')
                    ->dateTime('d/m/Y H:i')
                    ->placeholder('—')
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('estado')
                    ->options(collect(EstadoComanda::cases())
                        ->mapWithKeys(fn (EstadoComanda $e) => [$e->value => $e->etiqueta()])
                        ->all()),

                SelectFilter::make('mesero_id')
                    ->label('Mesero')
                    ->options(fn () => User::where('empresa_id', Filament::getTenant()?->id)
                        ->pluck('name', 'id'))
                    ->searchable(),

                Filter::make('rango')
                    ->schema([
                        DatePicker::make('desde')->label('Desde'),
                        DatePicker::make('hasta')->label('Hasta'),
                    ])
                    ->query(function (Builder $query, array $data) {
                        return $query
                            ->when($data['desde'] ?? null, fn (Builder $q, $d) => $q->whereDate('created_at', '>=', $d))
                            ->when($data['hasta'] ?? null, fn (Builder $q, $d) => $q->whereDate('created_at', '<=', $d));
                    }),
            ])
            ->defaultSort('created_at', 'desc');
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            \Filament\Infolists\Components\TextEntry::make('numero')->label('Número'),
            \Filament\Infolists\Components\TextEntry::make('mesa.numero')->label('Mesa'),
            \Filament\Infolists\Components\TextEntry::make('mesero.name')->label('Mesero'),
            \Filament\Infolists\Components\TextEntry::make('comensales')->label('Comensales'),
            \Filament\Infolists\Components\TextEntry::make('estado')
                ->label('Estado')
                ->badge()
                ->formatStateUsing(fn (EstadoComanda $state) => $state->etiqueta())
                ->color(fn (EstadoComanda $state) => $state->color()),
            \Filament\Infolists\Components\TextEntry::make('notas')->label('Notas')->placeholder('—')->columnSpanFull(),
            \Filament\Infolists\Components\TextEntry::make('created_at')->label('Abierta')->dateTime('d/m/Y H:i'),
            \Filament\Infolists\Components\TextEntry::make('cerrada_en')->label('Cerrada')->dateTime('d/m/Y H:i')->placeholder('—'),
            \Filament\Infolists\Components\TextEntry::make('venta.numero_comprobante')->label('Comprobante')->placeholder('—'),

            \Filament\Infolists\Components\RepeatableEntry::make('detalles')
                ->label('Productos')
                ->schema([
                    \Filament\Infolists\Components\TextEntry::make('producto.nombre')->label('Producto'),
                    \Filament\Infolists\Components\TextEntry::make('cantidad')->label('Cantidad'),
                    \Filament\Infolists\Components\TextEntry::make('precio_unitario')->label('Precio unit.')->money('DOP'),
                    \Filament\Infolists\Components\TextEntry::make('notas')->label('Notas')->placeholder('—'),
                    \Filament\Infolists\Components\TextEntry::make('estado_preparacion')
                        ->label('Preparación')
                        ->badge()
                        ->formatStateUsing(fn (EstadoPreparacion $state) => $state->etiqueta())
                        ->color(fn (EstadoPreparacion $state) => $state->color()),
                ])
                ->columns(5),
        ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListComandas::route('/'),
            'view' => Pages\ViewComanda::route('/{record}'),
        ];
    }
}
