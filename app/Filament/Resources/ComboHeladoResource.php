<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Enums\Modulo;
use App\Filament\Concerns\RestringidoPorModulo;
use App\Filament\Resources\ComboHeladoResource\Pages;
use App\Models\ComboHelado;
use Filament\Facades\Filament;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class ComboHeladoResource extends Resource
{
    use RestringidoPorModulo;

    protected static ?string $model = ComboHelado::class;

    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedGift;

    protected static string|\UnitEnum|null $navigationGroup = 'Heladería';

    protected static ?int $navigationSort = 4;

    protected static ?string $navigationLabel = 'Combos';

    protected static ?string $pluralModelLabel = 'Combos';

    protected static ?string $modelLabel = 'Combo';

    public static function modulo(): Modulo
    {
        return Modulo::HELADERIA_COMBOS;
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
                ->default('🍨')
                ->maxLength(10),

            Textarea::make('descripcion')
                ->label('Descripción')
                ->rows(2),

            TextInput::make('scoops_incluidos')
                ->label('Scoops incluidos')
                ->numeric()
                ->required()
                ->minValue(1)
                ->default(2),

            TextInput::make('toppings_incluidos')
                ->label('Toppings incluidos')
                ->numeric()
                ->minValue(0)
                ->default(1),

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

                TextColumn::make('descripcion')
                    ->label('Descripción')
                    ->limit(40),

                TextColumn::make('scoops_incluidos')
                    ->label('Scoops')
                    ->alignCenter(),

                TextColumn::make('toppings_incluidos')
                    ->label('Toppings')
                    ->alignCenter(),

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
            'index' => Pages\ManageCombosHelado::route('/'),
        ];
    }
}
