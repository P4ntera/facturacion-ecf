<?php

declare(strict_types=1);

namespace App\Filament\Resources\OrdenCompraResource\RelationManagers;

use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
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
