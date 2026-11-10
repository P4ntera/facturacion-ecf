<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Enums\CategoriaSabor;
use App\Enums\Modulo;
use App\Filament\Concerns\RestringidoPorModulo;
use App\Filament\Resources\SaborHeladoResource\Pages;
use App\Models\SaborHelado;
use Filament\Facades\Filament;
use Filament\Forms\Components\ColorPicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\ColorColumn;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class SaborHeladoResource extends Resource
{
    use RestringidoPorModulo;

    protected static ?string $model = SaborHelado::class;

    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedSparkles;

    protected static string|\UnitEnum|null $navigationGroup = 'Heladería';

    protected static ?int $navigationSort = 1;

    protected static ?string $navigationLabel = 'Sabores';

    protected static ?string $pluralModelLabel = 'Sabores';

    protected static ?string $modelLabel = 'Sabor';

    public static function modulo(): Modulo
    {
        return Modulo::HELADERIA_SABORES;
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('nombre')
                ->label('Nombre')
                ->required()
                ->maxLength(255)
                ->unique(ignoreRecord: true, modifyRuleUsing: fn ($rule) => $rule->where('empresa_id', Filament::getTenant()?->id)),

            Select::make('categoria')
                ->label('Categoría')
                ->options(collect(CategoriaSabor::cases())->mapWithKeys(fn ($c) => [$c->value => $c->etiqueta()]))
                ->required(),

            ColorPicker::make('color_hex')
                ->label('Color representativo')
                ->default('#A0522D'),

            Toggle::make('premium')
                ->label('Premium')
                ->helperText('Los sabores premium pueden tener precio diferenciado')
                ->default(false),

            Toggle::make('disponible')
                ->label('Disponible')
                ->helperText('Desmarcar si se agotó temporalmente')
                ->default(true),

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
                ColorColumn::make('color_hex')
                    ->label('Color'),

                TextColumn::make('nombre')
                    ->label('Nombre')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('categoria')
                    ->label('Categoría')
                    ->formatStateUsing(fn (CategoriaSabor $state) => $state->etiqueta())
                    ->sortable(),

                IconColumn::make('premium')
                    ->label('Premium')
                    ->boolean(),

                IconColumn::make('disponible')
                    ->label('Disponible')
                    ->boolean(),

                IconColumn::make('activo')
                    ->label('Activo')
                    ->boolean(),
            ])
            ->defaultSort('orden')
            ->filters([
                SelectFilter::make('categoria')
                    ->label('Categoría')
                    ->options(collect(CategoriaSabor::cases())->mapWithKeys(fn ($c) => [$c->value => $c->etiqueta()])),
            ])
            ->actions([
                EditAction::make(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ManageSaboresHelado::route('/'),
        ];
    }
}
