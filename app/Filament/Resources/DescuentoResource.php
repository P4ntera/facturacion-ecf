<?php

namespace App\Filament\Resources;

use App\Enums\Modulo;
use App\Filament\Concerns\RestringidoPorModulo;
use App\Filament\Resources\DescuentoResource\Pages;
use App\Models\Descuento;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ToggleColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;

class DescuentoResource extends Resource
{
    use RestringidoPorModulo;

    protected static ?string $model = Descuento::class;

    public static function modulo(): Modulo
    {
        return Modulo::MAESTROS_DESCUENTOS;
    }

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-receipt-percent';

    protected static ?string $navigationLabel = 'Descuentos';

    protected static ?string $modelLabel = 'Descuento';

    protected static ?string $pluralModelLabel = 'Descuentos';

    protected static string|\UnitEnum|null $navigationGroup = 'Maestros';

    protected static ?int $navigationSort = 13;

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('nombre')
                ->label('Nombre')
                ->helperText('Ej. "Empleado 10%", "Pronto pago 5%" — así lo va a ver el cajero en Caja.')
                ->required()
                ->maxLength(255)
                ->columnSpanFull(),

            TextInput::make('porcentaje')
                ->label('Porcentaje de descuento')
                ->numeric()
                ->suffix('%')
                ->minValue(0.01)
                ->maxValue(100)
                ->required(),

            Toggle::make('activo')
                ->label('Activo')
                ->helperText('Solo los descuentos activos aparecen para elegir en Caja/Facturación.')
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

                TextColumn::make('porcentaje')
                    ->label('Porcentaje')
                    ->suffix('%')
                    ->sortable(),

                ToggleColumn::make('activo')
                    ->label('Activo')
                    ->disabled(fn (): bool => ! auth()->user()?->can('descuentos.desactivar'))
                    ->sortable(),

                TextColumn::make('created_at')
                    ->label('Creado')
                    ->dateTime('d/m/Y')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                TernaryFilter::make('activo')->label('Activo')->default(true),
            ])
            ->defaultSort('nombre');
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListDescuentos::route('/'),
            'create' => Pages\CreateDescuento::route('/create'),
            'edit' => Pages\EditDescuento::route('/{record}/edit'),
        ];
    }
}
