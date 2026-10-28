<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Enums\Modulo;
use App\Filament\Concerns\RestringidoPorModulo;
use App\Filament\Resources\PrecioLavanderiaResource\Pages;
use App\Models\PrecioLavanderia;
use App\Models\ServicioLavanderia;
use App\Models\TipoPrenda;
use Filament\Facades\Filament;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\HtmlString;

class PrecioLavanderiaResource extends Resource
{
    use RestringidoPorModulo;

    protected static ?string $model = PrecioLavanderia::class;

    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedCurrencyDollar;

    protected static string|\UnitEnum|null $navigationGroup = 'Lavandería';

    protected static ?int $navigationSort = 3;

    protected static ?string $navigationLabel = 'Precios por Prenda';

    protected static ?string $pluralModelLabel = 'Precios por Prenda';

    protected static ?string $modelLabel = 'Precio por Prenda';

    public static function modulo(): Modulo
    {
        return Modulo::LAVANDERIA_PRECIOS;
    }

    public static function form(Schema $schema): Schema
    {
        $empresaId = Filament::getTenant()?->id;

        return $schema->components([
            Section::make('Combinación')
                ->icon(Heroicon::OutlinedTag)
                ->columns(2)
                ->schema([
                    Select::make('tipo_prenda_id')
                        ->label('Tipo de Prenda')
                        ->columnSpanFull()
                        ->searchable()
                        ->required()
                        ->allowHtml()
                        ->live()
                        ->getSearchResultsUsing(function (string $search) use ($empresaId) {
                            return TipoPrenda::where('empresa_id', $empresaId)
                                ->where('activo', true)
                                ->when($search !== '', fn ($q) => $q->where('nombre', 'ilike', "%{$search}%"))
                                ->when($search === '', fn ($q) => $q->inRandomOrder())
                                ->limit(10)
                                ->get()
                                ->mapWithKeys(fn (TipoPrenda $p) => [
                                    $p->id => '<div class="py-0.5">'
                                        .'<div class="font-medium">'.e($p->nombre).'</div>'
                                        .($p->descripcion ? '<div class="text-xs text-gray-400 dark:text-gray-500">'.e($p->descripcion).'</div>' : '')
                                        .'</div>',
                                ]);
                        })
                        ->getOptionLabelUsing(fn ($value) => TipoPrenda::find($value)?->nombre),

                    Select::make('servicio_lavanderia_id')
                        ->label('Servicio')
                        ->columnSpanFull()
                        ->searchable()
                        ->required()
                        ->allowHtml()
                        ->live()
                        ->getSearchResultsUsing(function (string $search) use ($empresaId) {
                            return ServicioLavanderia::where('empresa_id', $empresaId)
                                ->where('activo', true)
                                ->when($search !== '', fn ($q) => $q->where('nombre', 'ilike', "%{$search}%"))
                                ->when($search === '', fn ($q) => $q->inRandomOrder())
                                ->limit(10)
                                ->get()
                                ->mapWithKeys(fn (ServicioLavanderia $s) => [
                                    $s->id => '<div class="py-0.5">'
                                        .'<div class="font-medium">'.e($s->nombre).'</div>'
                                        .($s->descripcion ? '<div class="text-xs text-gray-400 dark:text-gray-500">'.e($s->descripcion).'</div>' : '')
                                        .'<div class="text-xs text-primary-600 dark:text-primary-400">Base: RD$ '.number_format((float) $s->precio_base, 2).'</div>'
                                        .'</div>',
                                ]);
                        })
                        ->getOptionLabelUsing(fn ($value) => ServicioLavanderia::find($value)?->nombre),

                    Placeholder::make('precio_referencia')
                        ->label('')
                        ->hiddenLabel()
                        ->columnSpanFull()
                        ->content(function (Get $get): HtmlString {
                            $servicioId = $get('servicio_lavanderia_id');
                            if (! $servicioId) {
                                return new HtmlString('<span class="text-xs text-gray-400">Seleccione prenda y servicio para ver el precio base de referencia.</span>');
                            }
                            $servicio = ServicioLavanderia::find($servicioId);
                            if (! $servicio) {
                                return new HtmlString('');
                            }

                            return new HtmlString(
                                '<div class="rounded-lg bg-primary-50 dark:bg-primary-950/30 p-3 text-sm">'
                                .'<span class="text-gray-600 dark:text-gray-400">Precio base del servicio:</span> '
                                .'<span class="font-semibold text-primary-600 dark:text-primary-400">RD$ '.number_format((float) $servicio->precio_base, 2).'</span>'
                                .'</div>'
                            );
                        })
                        ->visible(fn (Get $get): bool => (bool) $get('servicio_lavanderia_id')),
                ]),

            Section::make('Precio especial')
                ->icon(Heroicon::OutlinedCurrencyDollar)
                ->description('Este precio se usará en vez del precio base cuando se combine esta prenda con este servicio.')
                ->schema([
                    TextInput::make('precio')
                        ->label('Precio')
                        ->required()
                        ->numeric()
                        ->prefix('RD$')
                        ->minValue(0)
                        ->columnSpanFull(),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('tipoPrenda.nombre')
                    ->label('Tipo de Prenda')
                    ->description(fn (PrecioLavanderia $record) => $record->tipoPrenda?->descripcion)
                    ->searchable()
                    ->sortable(),

                TextColumn::make('servicioLavanderia.nombre')
                    ->label('Servicio')
                    ->description(fn (PrecioLavanderia $record) => $record->servicioLavanderia?->descripcion)
                    ->searchable()
                    ->sortable(),

                TextColumn::make('servicioLavanderia.precio_base')
                    ->label('Precio Base')
                    ->money('DOP')
                    ->sortable()
                    ->color('gray'),

                TextColumn::make('precio')
                    ->label('Precio Especial')
                    ->money('DOP')
                    ->sortable()
                    ->weight('bold')
                    ->color('primary'),
            ])
            ->defaultSort('tipoPrenda.nombre')
            ->actions([
                EditAction::make(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ManagePreciosLavanderia::route('/'),
        ];
    }
}
