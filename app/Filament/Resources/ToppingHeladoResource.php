<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Enums\Modulo;
use App\Filament\Concerns\RestringidoPorModulo;
use App\Filament\Resources\ToppingHeladoResource\Pages;
use App\Models\ToppingHelado;
use Filament\Facades\Filament;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class ToppingHeladoResource extends Resource
{
    use RestringidoPorModulo;

    protected static ?string $model = ToppingHelado::class;

    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedStar;

    protected static string|\UnitEnum|null $navigationGroup = 'Heladería';

    protected static ?int $navigationSort = 3;

    protected static ?string $navigationLabel = 'Toppings';

    protected static ?string $pluralModelLabel = 'Toppings';

    protected static ?string $modelLabel = 'Topping';

    public static function modulo(): Modulo
    {
        return Modulo::HELADERIA_TOPPINGS;
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('nombre')
                ->label('Nombre')
                ->required()
                ->maxLength(255)
                ->unique(ignoreRecord: true, modifyRuleUsing: fn ($rule) => $rule->where('empresa_id', Filament::getTenant()?->id)),

            TextInput::make('icono')
                ->label('Icono (emoji)')
                ->default('🍫')
                ->maxLength(10),

            TextInput::make('precio')
                ->label('Precio')
                ->numeric()
                ->required()
                ->prefix('RD$')
                ->minValue(0),

            Toggle::make('activo')
                ->label('Activo')
                ->default(true),

            TextInput::make('orden')
                ->label('Orden')
                ->numeric()
                ->default(0),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('icono')
                    ->label(''),

                TextColumn::make('nombre')
                    ->label('Nombre')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('precio')
                    ->label('Precio')
                    ->money('DOP')
                    ->sortable(),

                IconColumn::make('activo')
                    ->label('Activo')
                    ->boolean(),
            ])
            ->defaultSort('orden')
            ->actions([
                EditAction::make(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ManageToppingsHelado::route('/'),
        ];
    }
}
