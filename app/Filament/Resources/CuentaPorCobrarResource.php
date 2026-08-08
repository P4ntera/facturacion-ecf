<?php

namespace App\Filament\Resources;

use App\Enums\EstadoCuenta;
use App\Enums\FormaPago;
use App\Enums\Modulo;
use App\Exceptions\PagoExcedeSaldoException;
use App\Filament\Concerns\RestringidoPorModulo;
use App\Filament\Resources\CuentaPorCobrarResource\Pages;
use App\Models\CuentaPorCobrar;
use App\Models\PagoRecibido;
use App\Services\CuentaPorCobrarService;
use Filament\Actions\Action;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\RepeatableEntry\TableColumn as InfolistTableColumn;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use RuntimeException;

class CuentaPorCobrarResource extends Resource
{
    use RestringidoPorModulo;

    protected static ?string $model = CuentaPorCobrar::class;

    public static function modulo(): Modulo
    {
        return Modulo::CUENTAS_POR_COBRAR;
    }

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-arrow-down-circle';

    protected static ?string $navigationLabel = 'Cuentas por Cobrar';

    protected static ?string $modelLabel = 'Cuenta por Cobrar';

    protected static ?string $pluralModelLabel = 'Cuentas por Cobrar';

    protected static ?string $slug = 'cuentas-por-cobrar';

    protected static string|\UnitEnum|null $navigationGroup = 'Cuentas';

    // Nace automáticamente al facturar a crédito (VentaService::registrar()); no se crea a mano.
    public static function canCreate(): bool
    {
        return false;
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Datos de la cuenta')
                ->columnSpanFull()
                ->columns(3)
                ->schema([
                    TextEntry::make('cliente.nombre')->label('Cliente'),
                    TextEntry::make('venta.ncf')->label('NCF de la venta')->placeholder('—'),
                    TextEntry::make('estadoLabel')->label('Estado')->badge()
                        ->state(fn (CuentaPorCobrar $record) => $record->estado()->etiqueta())
                        ->color(fn (CuentaPorCobrar $record) => $record->estado()->color()),
                    TextEntry::make('monto_total')->label('Monto total')->money('DOP'),
                    TextEntry::make('monto_pagado')->label('Monto pagado')->money('DOP'),
                    TextEntry::make('montoPendiente')->label('Monto pendiente')
                        ->state(fn (CuentaPorCobrar $record) => $record->montoPendiente())
                        ->money('DOP'),
                    TextEntry::make('fecha_emision')->label('Fecha de emisión')->date('d/m/Y'),
                    TextEntry::make('fecha_vencimiento')->label('Fecha de vencimiento')->date('d/m/Y'),
                    TextEntry::make('diasVencido')->label('Días vencido')
                        ->state(fn (CuentaPorCobrar $record) => $record->diasVencido() > 0 ? $record->diasVencido() : '—'),
                ]),

            RepeatableEntry::make('pagos')
                ->label('Historial de pagos')
                ->table([
                    InfolistTableColumn::make('Fecha'),
                    InfolistTableColumn::make('Monto'),
                    InfolistTableColumn::make('Forma de pago'),
                    InfolistTableColumn::make('Referencia'),
                    InfolistTableColumn::make('Registrado por'),
                    InfolistTableColumn::make('Estado'),
                ])
                ->schema([
                    TextEntry::make('fecha')->hiddenLabel()->date('d/m/Y'),
                    TextEntry::make('monto')->hiddenLabel()->money('DOP'),
                    TextEntry::make('forma_pago')->hiddenLabel()
                        ->formatStateUsing(fn (FormaPago $state) => $state->etiqueta()),
                    TextEntry::make('referencia')->hiddenLabel()->placeholder('—'),
                    TextEntry::make('user.name')->hiddenLabel()->placeholder('—'),
                    TextEntry::make('estado')->hiddenLabel()->badge()
                        ->formatStateUsing(fn ($state) => $state->value === 'anulado' ? 'Anulado' : 'Registrado')
                        ->color(fn ($state) => $state->value === 'anulado' ? 'danger' : 'success'),
                ])
                ->columnSpanFull(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('cliente.nombre')
                    ->label('Cliente')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('venta.ncf')
                    ->label('NCF')
                    ->placeholder('—'),

                TextColumn::make('monto_total')
                    ->label('Total')
                    ->money('DOP')
                    ->sortable(),

                TextColumn::make('monto_pagado')
                    ->label('Pagado')
                    ->money('DOP'),

                TextColumn::make('pendiente')
                    ->label('Pendiente')
                    ->state(fn (CuentaPorCobrar $record) => $record->montoPendiente())
                    ->money('DOP'),

                TextColumn::make('fecha_vencimiento')
                    ->label('Vence')
                    ->date('d/m/Y')
                    ->sortable(),

                TextColumn::make('estadoLabel')
                    ->label('Estado')
                    ->badge()
                    ->state(fn (CuentaPorCobrar $record) => $record->estado()->etiqueta())
                    ->color(fn (CuentaPorCobrar $record) => $record->estado()->color()),
            ])
            ->filters([
                SelectFilter::make('estado')
                    ->label('Estado')
                    ->options(collect(EstadoCuenta::cases())->mapWithKeys(fn (EstadoCuenta $e) => [$e->value => $e->etiqueta()]))
                    ->query(fn (Builder $query, array $data) => self::aplicarFiltroEstado($query, $data['value'] ?? null)),

                Filter::make('fecha_vencimiento')
                    ->schema([
                        DatePicker::make('desde')->label('Desde'),
                        DatePicker::make('hasta')->label('Hasta'),
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        return $query
                            ->when($data['desde'] ?? null, fn (Builder $q, $desde) => $q->whereDate('fecha_vencimiento', '>=', $desde))
                            ->when($data['hasta'] ?? null, fn (Builder $q, $hasta) => $q->whereDate('fecha_vencimiento', '<=', $hasta));
                    }),
            ])
            ->recordActions([
                ViewAction::make(),

                Action::make('registrarPago')
                    ->label('Registrar pago')
                    ->icon('heroicon-o-banknotes')
                    ->color('success')
                    ->visible(fn (CuentaPorCobrar $record): bool => $record->estado() !== EstadoCuenta::PAGADA
                        && (auth()->user()?->can('cxc.cobrar') ?? false))
                    ->schema([
                        TextInput::make('monto')
                            ->label('Monto')
                            ->numeric()
                            ->prefix('RD$')
                            ->minValue(0.01)
                            ->required(),
                        DatePicker::make('fecha')
                            ->label('Fecha del pago')
                            ->default(now())
                            ->required(),
                        Select::make('forma_pago')
                            ->label('Forma de pago')
                            ->options(collect(FormaPago::cases())->mapWithKeys(fn (FormaPago $f) => [$f->value => $f->etiqueta()]))
                            ->default(FormaPago::EFECTIVO->value)
                            ->required(),
                        TextInput::make('referencia')
                            ->label('Referencia')
                            ->placeholder('Ej. número de transferencia o cheque'),
                    ])
                    ->action(function (CuentaPorCobrar $record, array $data): void {
                        try {
                            app(CuentaPorCobrarService::class)->registrarPago($record, $data, auth()->id());
                        } catch (PagoExcedeSaldoException|RuntimeException $e) {
                            Notification::make()->title($e->getMessage())->danger()->send();

                            return;
                        }

                        Notification::make()->title('Pago registrado correctamente')->success()->send();
                    }),

                Action::make('anularPago')
                    ->label('Anular un pago')
                    ->icon('heroicon-o-x-circle')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->visible(fn (CuentaPorCobrar $record): bool => $record->pagosVigentes()->exists()
                        && (auth()->user()?->can('cxc.anular') ?? false))
                    ->schema(fn (CuentaPorCobrar $record) => [
                        Select::make('pago_id')
                            ->label('Pago a anular')
                            ->options($record->pagosVigentes()->get()->mapWithKeys(
                                fn (PagoRecibido $p) => [$p->id => "RD$" . number_format((float) $p->monto, 2) . ' — ' . $p->fecha->format('d/m/Y')]
                            ))
                            ->required(),
                        Textarea::make('motivo')
                            ->label('Motivo de la anulación')
                            ->required()
                            ->rows(2),
                    ])
                    ->action(function (array $data): void {
                        $pago = PagoRecibido::findOrFail($data['pago_id']);

                        try {
                            app(CuentaPorCobrarService::class)->anularPago($pago, $data['motivo']);
                        } catch (RuntimeException $e) {
                            Notification::make()->title($e->getMessage())->danger()->send();

                            return;
                        }

                        Notification::make()->title('Pago anulado')->success()->send();
                    }),
            ])
            ->defaultSort('fecha_vencimiento', 'asc');
    }

    private static function aplicarFiltroEstado(Builder $query, ?string $estado): Builder
    {
        return match ($estado) {
            EstadoCuenta::PAGADA->value => $query->whereColumn('monto_pagado', '>=', 'monto_total'),
            EstadoCuenta::VENCIDA->value => $query->whereColumn('monto_pagado', '<', 'monto_total')
                ->whereDate('fecha_vencimiento', '<', today()),
            EstadoCuenta::PARCIAL->value => $query->whereColumn('monto_pagado', '<', 'monto_total')
                ->whereDate('fecha_vencimiento', '>=', today())
                ->where('monto_pagado', '>', 0),
            EstadoCuenta::PENDIENTE->value => $query->whereColumn('monto_pagado', '<', 'monto_total')
                ->whereDate('fecha_vencimiento', '>=', today())
                ->where('monto_pagado', '<=', 0),
            default => $query,
        };
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListCuentasPorCobrar::route('/'),
            'view' => Pages\ViewCuentaPorCobrar::route('/{record}'),
        ];
    }
}
