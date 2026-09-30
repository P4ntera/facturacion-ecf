<?php

namespace App\Filament\Resources;

use App\Enums\Modulo;
use App\Filament\Concerns\RestringidoPorModulo;
use App\Filament\Resources\ListaPrecioResource\Pages;
use App\Models\ListaPrecio;
use App\Models\Producto;
use Filament\Facades\Filament;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ToggleColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;

class ListaPrecioResource extends Resource
{
    use RestringidoPorModulo;

    protected static ?string $model = ListaPrecio::class;

    public static function modulo(): Modulo
    {
        return Modulo::MAESTROS_LISTAS_PRECIO;
    }

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-currency-dollar';

    protected static ?string $navigationLabel = 'Listas de Precio';

    protected static ?string $modelLabel = 'Lista de Precio';

    protected static ?string $pluralModelLabel = 'Listas de Precio';

    protected static string|\UnitEnum|null $navigationGroup = 'Inventario';

    protected static ?int $navigationSort = 4;

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('nombre')
                ->label('Nombre')
                ->required()
                ->unique(ignoreRecord: true, modifyRuleUsing: fn ($rule) => $rule->where('empresa_id', Filament::getTenant()->id))
                ->maxLength(100)
                ->columnSpan(1),

            Toggle::make('activa')
                ->label('Activa')
                ->default(true)
                ->columnSpan(1),

            Textarea::make('descripcion')
                ->label('Descripción')
                ->rows(2)
                ->maxLength(255)
                ->columnSpanFull(),

            Repeater::make('productos')
                ->relationship()
                ->label('Precios por producto')
                ->schema([
                    Select::make('producto_id')
                        ->label('Producto')
                        ->options(fn () => Producto::where('empresa_id', Filament::getTenant()->id)
                            ->where('activo', true)
                            ->orderBy('nombre')
                            ->pluck('nombre', 'id'))
                        ->searchable()
                        ->required()
                        ->disableOptionsWhenSelectedInSiblingRepeaterItems(),

                    TextInput::make('precio')
                        ->label('Precio especial')
                        ->numeric()
                        ->required()
                        ->minValue(0)
                        ->prefix('RD$'),
                ])
                ->columns(2)
                ->columnSpanFull()
                ->defaultItems(0)
                ->addActionLabel('Agregar producto')
                ->reorderable(false),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('nombre')
                    ->label('Nombre')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('descripcion')
                    ->label('Descripción')
                    ->limit(40)
                    ->placeholder('—'),

                TextColumn::make('productos_count')
                    ->label('Productos')
                    ->counts('productos')
                    ->sortable(),

                TextColumn::make('clientes_count')
                    ->label('Clientes')
                    ->counts('clientes')
                    ->sortable(),

                ToggleColumn::make('activa')
                    ->label('Activa')
                    ->disabled(fn (): bool => ! auth()->user()?->can('listas_precio.desactivar'))
                    ->sortable(),

                TextColumn::make('created_at')
                    ->label('Creado')
                    ->dateTime('d/m/Y')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                TernaryFilter::make('activa')->label('Activa')->default(true),
            ])
            ->defaultSort('nombre');
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListListaPrecios::route('/'),
            'create' => Pages\CreateListaPrecio::route('/create'),
            'edit' => Pages\EditListaPrecio::route('/{record}/edit'),
        ];
    }
}
