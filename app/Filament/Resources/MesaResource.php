<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Enums\EstadoMesa;
use App\Enums\Modulo;
use App\Filament\Concerns\RestringidoPorModulo;
use App\Filament\Resources\MesaResource\Pages;
use App\Models\AreaRestaurante;
use App\Models\Mesa;
use Filament\Facades\Filament;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class MesaResource extends Resource
{
    use RestringidoPorModulo;

    protected static ?string $model = Mesa::class;

    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedSquares2x2;

    protected static string|\UnitEnum|null $navigationGroup = 'Restaurante';

    protected static ?int $navigationSort = 2;

    protected static ?string $navigationLabel = 'Mesas';

    protected static ?string $pluralModelLabel = 'Mesas';

    protected static ?string $modelLabel = 'Mesa';

    public static function modulo(): Modulo
    {
        return Modulo::RESTAURANTE_MESAS;
    }

    public static function form(Schema $schema): Schema
    {
        $empresaId = Filament::getTenant()?->id;

        return $schema->components([
            Select::make('area_restaurante_id')
                ->label('Área')
                ->options(fn () => AreaRestaurante::where('empresa_id', $empresaId)
                    ->where('activa', true)
                    ->orderBy('orden')
                    ->pluck('nombre', 'id'))
                ->required()
                ->searchable(),

            TextInput::make('numero')
                ->label('Número / Código')
                ->required()
                ->maxLength(50)
                ->unique(ignoreRecord: true, modifyRuleUsing: fn ($rule) => $rule->where('empresa_id', $empresaId)),

            TextInput::make('capacidad')
                ->label('Capacidad (personas)')
                ->numeric()
                ->default(4)
                ->minValue(1)
                ->required(),

            Toggle::make('activa')
                ->label('Activa')
                ->default(true),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('numero')
                    ->label('Mesa')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('area.nombre')
                    ->label('Área')
                    ->sortable(),

                TextColumn::make('capacidad')
                    ->label('Capacidad')
                    ->sortable(),

                TextColumn::make('estado')
                    ->label('Estado')
                    ->badge()
                    ->formatStateUsing(fn (EstadoMesa $state) => $state->etiqueta())
                    ->color(fn (EstadoMesa $state) => $state->color()),

                IconColumn::make('activa')
                    ->label('Activa')
                    ->boolean(),
            ])
            ->filters([
                SelectFilter::make('area_restaurante_id')
                    ->label('Área')
                    ->options(fn () => AreaRestaurante::where('empresa_id', Filament::getTenant()?->id)
                        ->where('activa', true)
                        ->pluck('nombre', 'id'))
                    ->searchable(),

                SelectFilter::make('estado')
                    ->options(collect(EstadoMesa::cases())
                        ->mapWithKeys(fn (EstadoMesa $e) => [$e->value => $e->etiqueta()])
                        ->all()),
            ])
            ->defaultSort('numero');
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListMesas::route('/'),
            'create' => Pages\CreateMesa::route('/create'),
            'edit' => Pages\EditMesa::route('/{record}/edit'),
        ];
    }
}
