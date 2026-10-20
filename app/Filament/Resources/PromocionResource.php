<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Enums\Modulo;
use App\Enums\TipoPromocion;
use App\Filament\Concerns\RestringidoPorModulo;
use App\Filament\Resources\PromocionResource\Pages;
use App\Models\Producto;
use App\Models\Promocion;
use Filament\Facades\Filament;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Get;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ToggleColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;

class PromocionResource extends Resource
{
    use RestringidoPorModulo;

    protected static ?string $model = Promocion::class;

    public static function modulo(): Modulo
    {
        return Modulo::MAESTROS_PROMOCIONES;
    }

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-gift';

    protected static ?string $navigationLabel = 'Promociones';

    protected static ?string $modelLabel = 'Promoción';

    protected static ?string $pluralModelLabel = 'Promociones';

    protected static string|\UnitEnum|null $navigationGroup = 'Configuración';

    protected static ?int $navigationSort = 7;

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('nombre')
                ->label('Nombre')
                ->helperText('Nombre descriptivo de la promoción (ej. "2×1 en refrescos", "15% en lácteos").')
                ->required()
                ->maxLength(255)
                ->columnSpanFull(),

            Select::make('tipo')
                ->label('Tipo de promoción')
                ->options(collect(TipoPromocion::cases())->mapWithKeys(
                    fn (TipoPromocion $t) => [$t->value => $t->etiqueta()]
                ))
                ->required()
                ->live()
                ->native(false),

            TextInput::make('valor')
                ->label(fn (Get $get) => match ($get('tipo')) {
                    TipoPromocion::PORCENTAJE->value => 'Porcentaje de descuento',
                    TipoPromocion::MONTO_FIJO->value => 'Monto fijo por unidad (RD$)',
                    default => 'Valor',
                })
                ->numeric()
                ->suffix(fn (Get $get) => $get('tipo') === TipoPromocion::PORCENTAJE->value ? '%' : '')
                ->minValue(0.01)
                ->maxValue(fn (Get $get) => $get('tipo') === TipoPromocion::PORCENTAJE->value ? 100 : 999999.99)
                ->required(fn (Get $get) => in_array($get('tipo'), [
                    TipoPromocion::PORCENTAJE->value,
                    TipoPromocion::MONTO_FIJO->value,
                ]))
                ->visible(fn (Get $get) => $get('tipo') !== TipoPromocion::DOS_POR_UNO->value),

            DatePicker::make('fecha_inicio')
                ->label('Vigencia desde')
                ->required()
                ->native(false),

            DatePicker::make('fecha_fin')
                ->label('Vigencia hasta')
                ->required()
                ->native(false)
                ->afterOrEqual('fecha_inicio'),

            Select::make('productos')
                ->label('Productos incluidos')
                ->helperText('Seleccione los productos a los que aplica esta promoción.')
                ->relationship(
                    'productos',
                    'nombre',
                    modifyQueryUsing: fn ($query) => $query->where('empresa_id', Filament::getTenant()->id)->where('activo', true),
                )
                ->multiple()
                ->searchable()
                ->preload()
                ->required()
                ->columnSpanFull()
                ->getOptionLabelFromRecordUsing(fn (Producto $p) => "{$p->codigo} — {$p->nombre}"),

            Toggle::make('activa')
                ->label('Activa')
                ->helperText('Solo las promociones activas se aplican automáticamente en el POS.')
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

                TextColumn::make('tipo')
                    ->label('Tipo')
                    ->formatStateUsing(fn (TipoPromocion $state) => $state->etiqueta())
                    ->sortable(),

                TextColumn::make('valor')
                    ->label('Valor')
                    ->formatStateUsing(function (?string $state, Promocion $record) {
                        if ($record->tipo === TipoPromocion::DOS_POR_UNO) {
                            return '—';
                        }
                        if ($record->tipo === TipoPromocion::PORCENTAJE) {
                            return $state . '%';
                        }

                        return 'RD$ ' . number_format((float) $state, 2);
                    })
                    ->sortable(),

                TextColumn::make('fecha_inicio')
                    ->label('Desde')
                    ->date('d/m/Y')
                    ->sortable(),

                TextColumn::make('fecha_fin')
                    ->label('Hasta')
                    ->date('d/m/Y')
                    ->sortable(),

                IconColumn::make('vigente')
                    ->label('Vigente')
                    ->getStateUsing(fn (Promocion $record) => $record->estaVigente())
                    ->boolean(),

                TextColumn::make('productos_count')
                    ->label('Productos')
                    ->counts('productos')
                    ->sortable(),

                ToggleColumn::make('activa')
                    ->label('Activa')
                    ->disabled(fn (): bool => ! auth()->user()?->can('promociones.desactivar'))
                    ->sortable(),
            ])
            ->filters([
                TernaryFilter::make('activa')->label('Activa')->default(true),

                SelectFilter::make('tipo')
                    ->label('Tipo')
                    ->options(collect(TipoPromocion::cases())->mapWithKeys(
                        fn (TipoPromocion $t) => [$t->value => $t->etiqueta()]
                    )),
            ])
            ->defaultSort('fecha_fin', 'desc');
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListPromociones::route('/'),
            'create' => Pages\CreatePromocion::route('/create'),
            'edit' => Pages\EditPromocion::route('/{record}/edit'),
        ];
    }
}
