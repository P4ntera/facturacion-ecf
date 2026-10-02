<?php

declare(strict_types=1);

namespace App\Filament\Resources\OrdenCompraResource\RelationManagers;

use App\Filament\Resources\CompraResource;
use App\Models\RecepcionCompra;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class RecepcionesRelationManager extends RelationManager
{
    protected static string $relationship = 'recepciones';

    protected static ?string $title = 'Historial de Recepciones';

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('fecha')
                    ->label('Fecha')
                    ->date('d/m/Y')
                    ->sortable(),

                // Cada recepción es una compra registrada (con su factura).
                TextColumn::make('compra.ncf')
                    ->label('Compra')
                    ->state(fn (RecepcionCompra $record): ?string => $record->compra_id === null
                        ? null
                        : "Compra #{$record->compra_id}".($record->compra?->ncf ? " · {$record->compra->ncf}" : ''))
                    ->url(fn (RecepcionCompra $record): ?string => $record->compra_id ? CompraResource::getUrl('view', ['record' => $record->compra_id]) : null)
                    ->placeholder('—'),

                TextColumn::make('estado')
                    ->label('Estado')
                    ->badge()
                    ->state(fn (RecepcionCompra $record): string => $record->estaAnulada() ? 'Anulada' : 'Recibida')
                    ->color(fn (RecepcionCompra $record): string => $record->estaAnulada() ? 'danger' : 'success'),

                TextColumn::make('user.name')
                    ->label('Recibido por')
                    ->sortable(),

                TextColumn::make('detalles_count')
                    ->label('Líneas')
                    ->counts('detalles'),

                TextColumn::make('notas')
                    ->label('Observaciones')
                    ->limit(50)
                    ->placeholder('—'),

                TextColumn::make('created_at')
                    ->label('Registrada')
                    ->dateTime('d/m/Y H:i')
                    ->sortable(),
            ])
            ->defaultSort('fecha', 'desc');
    }
}
