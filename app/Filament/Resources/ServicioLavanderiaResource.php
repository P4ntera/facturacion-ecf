<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Enums\Modulo;
use App\Filament\Concerns\RestringidoPorModulo;
use App\Filament\Resources\ServicioLavanderiaResource\Pages;
use App\Models\ServicioLavanderia;
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

class ServicioLavanderiaResource extends Resource
{
    use RestringidoPorModulo;

    protected static ?string $model = ServicioLavanderia::class;

    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedSparkles;

    protected static string|\UnitEnum|null $navigationGroup = 'Lavandería';

    protected static ?int $navigationSort = 2;

    protected static ?string $navigationLabel = 'Servicios';

    protected static ?string $pluralModelLabel = 'Servicios de Lavandería';

    protected static ?string $modelLabel = 'Servicio de Lavandería';

    public static function modulo(): Modulo
    {
        return Modulo::LAVANDERIA_SERVICIOS;
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

            TextInput::make('precio_base')
                ->label('Precio Base')
                ->required()
                ->numeric()
                ->prefix('RD$')
                ->minValue(0),

            Toggle::make('aplica_itbis')
                ->label('Aplica ITBIS')
                ->default(true),

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

                TextColumn::make('precio_base')
                    ->label('Precio Base')
                    ->money('DOP')
                    ->sortable(),

                IconColumn::make('aplica_itbis')
                    ->label('ITBIS')
                    ->boolean(),

                IconColumn::make('activo')
                    ->label('Activo')
                    ->boolean(),
            ])
            ->defaultSort('nombre');
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListServiciosLavanderia::route('/'),
            'create' => Pages\CreateServicioLavanderia::route('/create'),
            'edit' => Pages\EditServicioLavanderia::route('/{record}/edit'),
        ];
    }
}
