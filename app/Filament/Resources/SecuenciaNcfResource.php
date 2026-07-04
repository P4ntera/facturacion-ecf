<?php

namespace App\Filament\Resources;

use App\Enums\TipoComprobante;
use App\Filament\Resources\SecuenciaNcfResource\Pages;
use App\Models\SecuenciaNcf;
use App\Services\SecuenciaNcfService;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

class SecuenciaNcfResource extends Resource
{
    protected static ?string $model = SecuenciaNcf::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-hashtag';

    protected static ?string $navigationLabel = 'Secuencias NCF';

    protected static ?string $modelLabel = 'Secuencia NCF';

    protected static ?string $pluralModelLabel = 'Secuencias NCF';

    protected static string|\UnitEnum|null $navigationGroup = 'Configuración';

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->columns(2)
            ->components([
                Select::make('tipo_comprobante')
                    ->label('Tipo de comprobante')
                    ->options(collect(TipoComprobante::cases())->mapWithKeys(
                        fn (TipoComprobante $tipo) => [$tipo->value => "{$tipo->value} — {$tipo->etiqueta()}"]
                    ))
                    ->required()
                    ->live()
                    ->afterStateUpdated(function (?string $state, Set $set, Get $get): void {
                        if ($state !== null && blank($get('prefijo'))) {
                            $set('prefijo', 'E'.$state);
                        }
                    }),

                TextInput::make('prefijo')
                    ->label('Prefijo')
                    ->required()
                    ->maxLength(3)
                    ->regex('/^E\d{2}$/')
                    ->validationMessages(['regex' => 'El prefijo debe tener el formato "E" seguido de 2 dígitos (ej. E31).'])
                    ->afterStateUpdated(fn (?string $state, Set $set) => $set('prefijo', strtoupper((string) $state)))
                    ->live(onBlur: true),

                TextInput::make('secuencia_desde')
                    ->label('Secuencia desde')
                    ->numeric()
                    ->integer()
                    ->required()
                    ->minValue(1)
                    ->live(onBlur: true)
                    ->rule(function (?SecuenciaNcf $record) {
                        return function (string $attribute, $value, \Closure $fail) use ($record): void {
                            if ($record !== null && (int) $value > (int) $record->secuencia_actual) {
                                $fail('El "desde" no puede ser mayor que la secuencia ya consumida.');
                            }
                        };
                    }),

                TextInput::make('secuencia_hasta')
                    ->label('Secuencia hasta')
                    ->numeric()
                    ->integer()
                    ->required()
                    ->rule(function (Get $get, ?SecuenciaNcf $record) {
                        return function (string $attribute, $value, \Closure $fail) use ($get, $record): void {
                            $desde = (int) $get('secuencia_desde');

                            if ((int) $value < $desde) {
                                $fail('El "hasta" debe ser mayor o igual que el "desde".');

                                return;
                            }

                            if ($record !== null && (int) $value < (int) $record->secuencia_actual - 1) {
                                $fail('El "hasta" no puede ser menor que la secuencia ya consumida.');
                            }
                        };
                    }),

                // Solo lectura: el consumo lo gestiona SecuenciaNcfService (lockForUpdate),
                // nunca se edita a mano para no romper la atomicidad del contador.
                TextInput::make('secuencia_actual')
                    ->label('Secuencia actual')
                    ->numeric()
                    ->disabled()
                    ->dehydrated(false)
                    ->visibleOn('edit')
                    ->helperText('Gestionada automáticamente al emitir comprobantes.'),

                DatePicker::make('vencimiento')
                    ->label('Vencimiento')
                    ->native(false)
                    ->helperText('Recomendado: fecha límite autorizada por la DGII para este rango.')
                    ->rule(function (string $operation) {
                        return function (string $attribute, $value, \Closure $fail) use ($operation): void {
                            if ($operation === 'create' && $value !== null
                                && Carbon::parse($value)->startOfDay()->lt(today())) {
                                $fail('La fecha de vencimiento debe ser futura.');
                            }
                        };
                    }),

                Toggle::make('activa')
                    ->label('Activa')
                    ->default(true)
                    ->live()
                    ->rule(function (Get $get, ?SecuenciaNcf $record) {
                        return function (string $attribute, $value, \Closure $fail) use ($get, $record): void {
                            if (! $value) {
                                return;
                            }

                            $tipo = $get('tipo_comprobante');

                            if ($tipo === null) {
                                return;
                            }

                            $existeOtraActiva = SecuenciaNcf::query()
                                ->where('tipo_comprobante', $tipo)
                                ->where('activa', true)
                                ->when($record, fn (Builder $query) => $query->whereKeyNot($record->getKey()))
                                ->exists();

                            if ($existeOtraActiva) {
                                $fail('Ya hay una secuencia activa para este comprobante; desactiva la anterior primero.');
                            }
                        };
                    })
                    ->columnSpanFull(),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('tipo_comprobante')
                    ->label('Comprobante')
                    ->formatStateUsing(fn (TipoComprobante $state) => "{$state->value} — {$state->etiqueta()}")
                    ->searchable()
                    ->sortable(),

                TextColumn::make('prefijo')
                    ->label('Prefijo')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('rango')
                    ->label('Rango')
                    ->getStateUsing(fn (SecuenciaNcf $record) => "{$record->secuencia_desde} – {$record->secuencia_hasta}"),

                TextColumn::make('secuencia_actual')
                    ->label('Actual')
                    ->sortable(),

                TextColumn::make('restantes')
                    ->label('Restantes')
                    ->getStateUsing(fn (SecuenciaNcf $record) => app(SecuenciaNcfService::class)->restantes($record))
                    ->color(fn (int $state) => match (true) {
                        $state <= SecuenciaNcfService::UMBRAL_ALERTA => 'danger',
                        $state <= 200 => 'warning',
                        default => 'success',
                    })
                    ->weight('bold'),

                TextColumn::make('vencimiento')
                    ->label('Vencimiento')
                    ->date('d/m/Y')
                    ->placeholder('—')
                    ->color(fn (?SecuenciaNcf $record) => $record?->vencimiento?->isPast() ? 'danger' : null)
                    ->sortable(),

                IconColumn::make('activa')
                    ->label('Activa')
                    ->boolean()
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('tipo_comprobante')
                    ->label('Comprobante')
                    ->options(collect(TipoComprobante::cases())->mapWithKeys(
                        fn (TipoComprobante $tipo) => [$tipo->value => $tipo->etiqueta()]
                    )),

                TernaryFilter::make('activa')->label('Activa'),

                Filter::make('por_agotarse')
                    ->label('Por agotarse')
                    ->query(fn (Builder $query) => $query
                        ->whereNotNull('secuencia_hasta')
                        ->whereRaw('(secuencia_hasta - secuencia_actual + 1) <= 200')),

                Filter::make('vencidas')
                    ->label('Vencidas')
                    ->query(fn (Builder $query) => $query
                        ->whereNotNull('vencimiento')
                        ->where('vencimiento', '<', today())),
            ])
            ->recordActions([
                Action::make('verProximo')
                    ->label('Ver próximo NCF')
                    ->icon('heroicon-o-eye')
                    ->color('gray')
                    ->action(function (SecuenciaNcf $record): void {
                        $proximo = app(SecuenciaNcfService::class)->previsualizarSiguiente($record->tipo_comprobante);

                        $notificacion = Notification::make();

                        if ($proximo !== null) {
                            $notificacion->title("Próximo NCF: {$proximo}")->info();
                        } else {
                            $notificacion->title('No hay un NCF disponible para este comprobante')->warning();
                        }

                        $notificacion->send();
                    }),
            ])
            ->defaultSort('tipo_comprobante');
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListSecuenciasNcf::route('/'),
            'create' => Pages\CreateSecuenciaNcf::route('/create'),
            'edit' => Pages\EditSecuenciaNcf::route('/{record}/edit'),
        ];
    }
}
