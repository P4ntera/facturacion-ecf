<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Enums\Modulo;
use App\Filament\Concerns\RestringidoPorModulo;
use App\Filament\Resources\PresentacionHeladoResource\Pages;
use App\Models\PresentacionHelado;
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

class PresentacionHeladoResource extends Resource
{
    use RestringidoPorModulo;

    protected static ?string $model = PresentacionHelado::class;

    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedCake;

    protected static string|\UnitEnum|null $navigationGroup = 'Heladería';

    protected static ?int $navigationSort = 2;

    protected static ?string $navigationLabel = 'Presentaciones';

    protected static ?string $pluralModelLabel = 'Presentaciones';

    protected static ?string $modelLabel = 'Presentación';

    public static function modulo(): Modulo
    {
        return Modulo::HELADERIA_PRESENTACIONES;
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
                ->default('🍦')
                ->maxLength(10),

            TextInput::make('scoops_incluidos')
                ->label('Scoops incluidos')
                ->numeric()
                ->required()
                ->minValue(1)
                ->default(1),

            TextInput::make('toppings_incluidos')
                ->label('Toppings incluidos')
                ->numeric()
                ->minValue(0)
                ->default(0),

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
            'index' => Pages\ManagePresentacionesHelado::route('/'),
        ];
    }
}
