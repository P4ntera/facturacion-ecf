<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Enums\Modulo;
use App\Filament\Concerns\RestringidoPorModulo;
use App\Filament\Resources\PrecioLavanderiaResource\Pages;
use App\Models\PrecioLavanderia;
use App\Models\ServicioLavanderia;
use App\Models\TipoPrenda;
use Filament\Facades\Filament;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class PrecioLavanderiaResource extends Resource
{
    use RestringidoPorModulo;

    protected static ?string $model = PrecioLavanderia::class;

    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedCurrencyDollar;

    protected static string|\UnitEnum|null $navigationGroup = 'Lavandería';

    protected static ?int $navigationSort = 3;

    protected static ?string $navigationLabel = 'Precios por Prenda';

    protected static ?string $pluralModelLabel = 'Precios por Prenda';

    protected static ?string $modelLabel = 'Precio por Prenda';

    public static function modulo(): Modulo
    {
        return Modulo::LAVANDERIA_PRECIOS;
    }

    public static function form(Schema $schema): Schema
    {
        $empresaId = Filament::getTenant()?->id;

        return $schema->components([
            Select::make('tipo_prenda_id')
                ->label('Tipo de Prenda')
                ->options(fn () => TipoPrenda::where('empresa_id', $empresaId)
                    ->where('activo', true)
                    ->pluck('nombre', 'id'))
                ->required()
                ->searchable(),

            Select::make('servicio_lavanderia_id')
                ->label('Servicio')
                ->options(fn () => ServicioLavanderia::where('empresa_id', $empresaId)
                    ->where('activo', true)
                    ->pluck('nombre', 'id'))
                ->required()
                ->searchable(),

            TextInput::make('precio')
                ->label('Precio')
                ->required()
                ->numeric()
                ->prefix('RD$')
                ->minValue(0),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('tipoPrenda.nombre')
                    ->label('Tipo de Prenda')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('servicioLavanderia.nombre')
                    ->label('Servicio')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('precio')
                    ->label('Precio')
                    ->money('DOP')
                    ->sortable(),
            ])
            ->defaultSort('tipoPrenda.nombre')
            ->actions([
                EditAction::make(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ManagePreciosLavanderia::route('/'),
        ];
    }
}
