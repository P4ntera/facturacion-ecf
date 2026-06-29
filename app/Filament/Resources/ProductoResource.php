<?php

namespace App\Filament\Resources;

use App\Enums\TasaItbis;
use App\Enums\TipoProducto;
use App\Filament\Resources\ProductoResource\Pages;
use App\Models\Producto;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Get;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;

class ProductoResource extends Resource
{
    protected static ?string $model = Producto::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-cube';

    protected static ?string $navigationLabel = 'Productos';

    protected static ?string $modelLabel = 'Producto';

    protected static ?string $pluralModelLabel = 'Productos';

    protected static string|\UnitEnum|null $navigationGroup = 'Maestros';

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->columns(2)
            ->components([
                // --- Identificación ---
                TextInput::make('codigo')
                    ->label('Código')
                    ->required()
                    ->unique(ignoreRecord: true)
                    ->maxLength(50),

                TextInput::make('nombre')
                    ->label('Nombre')
                    ->required()
                    ->maxLength(255),

                Select::make('tipo')
                    ->label('Tipo')
                    ->options([
                        TipoProducto::PRODUCTO->value => 'Producto',
                        TipoProducto::SERVICIO->value => 'Servicio',
                    ])
                    ->required()
                    ->default(TipoProducto::PRODUCTO->value),

                Select::make('categoria_id')
                    ->label('Categoría')
                    ->relationship('categoria', 'nombre')
                    ->searchable()
                    ->preload()
                    ->nullable(),

                Textarea::make('descripcion')
                    ->label('Descripción')
                    ->rows(2)
                    ->columnSpanFull(),

                // --- Precios ---
                TextInput::make('precio')
                    ->label('Precio de venta')
                    ->numeric()
                    ->prefix('RD$')
                    ->required()
                    ->minValue(0)
                    ->default(0),

                TextInput::make('costo')
                    ->label('Costo')
                    ->numeric()
                    ->prefix('RD$')
                    ->minValue(0)
                    ->default(0),

                Select::make('tasa_itbis')
                    ->label('Tasa ITBIS')
                    ->options([
                        TasaItbis::DIECIOCHO->value => '18 %',
                        TasaItbis::DIECISEIS->value => '16 %',
                        TasaItbis::CERO->value      => '0 %',
                        TasaItbis::EXENTO->value    => 'Exento',
                    ])
                    ->required()
                    ->default(TasaItbis::DIECIOCHO->value),

                // --- Inventario ---
                Toggle::make('controla_stock')
                    ->label('Controla stock')
                    ->default(false)
                    ->live()
                    ->columnSpanFull(),

                TextInput::make('stock')
                    ->label('Stock actual')
                    ->numeric()
                    ->minValue(0)
                    ->default(0)
                    ->hidden(fn (Get $get): bool => ! $get('controla_stock')),

                TextInput::make('stock_minimo')
                    ->label('Stock mínimo')
                    ->numeric()
                    ->minValue(0)
                    ->default(0)
                    ->hidden(fn (Get $get): bool => ! $get('controla_stock')),

                Toggle::make('activo')
                    ->label('Activo')
                    ->default(true)
                    ->columnSpanFull(),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('codigo')
                    ->label('Código')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('nombre')
                    ->label('Nombre')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('categoria.nombre')
                    ->label('Categoría')
                    ->placeholder('—')
                    ->sortable(),

                TextColumn::make('tipo')
                    ->label('Tipo')
                    ->formatStateUsing(fn ($state) => match ($state) {
                        TipoProducto::PRODUCTO => 'Producto',
                        TipoProducto::SERVICIO => 'Servicio',
                        default                => $state,
                    }),

                TextColumn::make('precio')
                    ->label('Precio')
                    ->money('DOP')
                    ->sortable(),

                TextColumn::make('tasa_itbis')
                    ->label('ITBIS')
                    ->formatStateUsing(fn ($state) => match ($state) {
                        TasaItbis::DIECIOCHO => '18 %',
                        TasaItbis::DIECISEIS => '16 %',
                        TasaItbis::CERO      => '0 %',
                        TasaItbis::EXENTO    => 'Exento',
                        default              => $state,
                    }),

                TextColumn::make('stock')
                    ->label('Stock')
                    ->numeric(decimalPlaces: 2)
                    ->sortable()
                    ->placeholder('—'),

                IconColumn::make('activo')
                    ->label('Activo')
                    ->boolean()
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('tipo')
                    ->label('Tipo')
                    ->options([
                        TipoProducto::PRODUCTO->value => 'Producto',
                        TipoProducto::SERVICIO->value => 'Servicio',
                    ]),
                SelectFilter::make('categoria_id')
                    ->label('Categoría')
                    ->relationship('categoria', 'nombre'),
                TernaryFilter::make('activo')->label('Activo'),
            ])
            ->defaultSort('nombre');
    }

    public static function getPages(): array
    {
        return [
            'index'  => Pages\ListProductos::route('/'),
            'create' => Pages\CreateProducto::route('/create'),
            'edit'   => Pages\EditProducto::route('/{record}/edit'),
        ];
    }
}
