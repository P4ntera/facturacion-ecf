<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Enums\Modulo;
use App\Filament\Concerns\RestringidoPorModulo;
use App\Filament\Resources\AreaRestauranteResource\Pages;
use App\Models\AreaRestaurante;
use Filament\Facades\Filament;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class AreaRestauranteResource extends Resource
{
    use RestringidoPorModulo;

    protected static ?string $model = AreaRestaurante::class;

    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedBuildingStorefront;

    protected static string|\UnitEnum|null $navigationGroup = 'Restaurante';

    protected static ?int $navigationSort = 1;

    protected static ?string $navigationLabel = 'Áreas';

    protected static ?string $pluralModelLabel = 'Áreas';

    protected static ?string $modelLabel = 'Área';

    public static function modulo(): Modulo
    {
        return Modulo::RESTAURANTE_MESAS;
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('nombre')
                ->label('Nombre')
                ->required()
                ->maxLength(255)
                ->unique(ignoreRecord: true, modifyRuleUsing: fn ($rule) => $rule->where('empresa_id', Filament::getTenant()?->id)),

            Textarea::make('descripcion')
                ->label('Descripción')
                ->rows(2),

            TextInput::make('orden')
                ->label('Orden')
                ->numeric()
                ->default(0),

            Toggle::make('activa')
                ->label('Activa')
                ->default(true),
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

                TextColumn::make('mesas_count')
                    ->label('Mesas')
                    ->counts('mesas')
                    ->sortable(),

                TextColumn::make('orden')
                    ->label('Orden')
                    ->sortable(),

                IconColumn::make('activa')
                    ->label('Activa')
                    ->boolean(),
            ])
            ->defaultSort('orden');
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListAreasRestaurante::route('/'),
            'create' => Pages\CreateAreaRestaurante::route('/create'),
            'edit' => Pages\EditAreaRestaurante::route('/{record}/edit'),
        ];
    }
}
