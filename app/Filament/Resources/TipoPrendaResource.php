<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Enums\Modulo;
use App\Filament\Concerns\RestringidoPorModulo;
use App\Filament\Resources\TipoPrendaResource\Pages;
use App\Models\TipoPrenda;
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

class TipoPrendaResource extends Resource
{
    use RestringidoPorModulo;

    protected static ?string $model = TipoPrenda::class;

    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedTag;

    protected static string|\UnitEnum|null $navigationGroup = 'Lavandería';

    protected static ?int $navigationSort = 1;

    protected static ?string $navigationLabel = 'Tipos de Prenda';

    protected static ?string $pluralModelLabel = 'Tipos de Prenda';

    protected static ?string $modelLabel = 'Tipo de Prenda';

    public static function modulo(): Modulo
    {
        return Modulo::LAVANDERIA_TIPOS_PRENDA;
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

            Toggle::make('activo')
                ->label('Activo')
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

                TextColumn::make('descripcion')
                    ->label('Descripción')
                    ->limit(50),

                IconColumn::make('activo')
                    ->label('Activo')
                    ->boolean(),
            ])
            ->defaultSort('nombre')
            ->actions([
                EditAction::make(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ManageTiposPrenda::route('/'),
        ];
    }
}
