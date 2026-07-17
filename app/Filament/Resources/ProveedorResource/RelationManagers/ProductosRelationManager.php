<?php

namespace App\Filament\Resources\ProveedorResource\RelationManagers;

use Filament\Actions\AttachAction;
use Filament\Actions\DetachAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class ProductosRelationManager extends RelationManager
{
    protected static string $relationship = 'productos';

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('nombre')
            ->columns([
                TextColumn::make('codigo')->label('Código'),
                TextColumn::make('nombre')->label('Producto')->searchable(),
                TextColumn::make('pivot.costo_referencia')->label('Costo referencia')->money('DOP')->placeholder('—'),
                TextColumn::make('pivot.codigo_proveedor')->label('Código del proveedor')->placeholder('—'),
                IconColumn::make('pivot.es_principal')->label('Principal')->boolean(),
            ])
            ->headerActions([
                AttachAction::make()
                    ->recordSelectSearchColumns(['nombre', 'codigo'])
                    ->schema(fn (AttachAction $action): array => [
                        $action->getRecordSelect(),
                        TextInput::make('costo_referencia')->label('Costo de referencia')->numeric()->prefix('RD$'),
                        TextInput::make('codigo_proveedor')->label('Código del proveedor'),
                        Toggle::make('es_principal')->label('Proveedor principal para este producto'),
                    ]),
            ])
            ->recordActions([
                EditAction::make()
                    ->schema([
                        TextInput::make('costo_referencia')->label('Costo de referencia')->numeric()->prefix('RD$'),
                        TextInput::make('codigo_proveedor')->label('Código del proveedor'),
                        Toggle::make('es_principal')->label('Proveedor principal para este producto'),
                    ]),
                DetachAction::make(),
            ]);
    }
}
