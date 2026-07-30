<?php

namespace App\Filament\Resources;

use App\Enums\EstadoCuenta;
use App\Enums\FormaPago;
use App\Enums\Modulo;
use App\Exceptions\PagoExcedeSaldoException;
use App\Filament\Concerns\RestringidoPorModulo;
use App\Filament\Resources\CuentaPorPagarResource\Pages;
use App\Models\CuentaPorPagar;
use App\Models\PagoRealizado;
use App\Services\CuentaPorPagarService;
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

class CuentaPorPagarResource extends Resource
{
    use RestringidoPorModulo;

    protected static ?string $model = CuentaPorPagar::class;

    public static function modulo(): Modulo
    {
        return Modulo::CUENTAS_POR_PAGAR;
    }

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-arrow-up-circle';

    protected static ?string $navigationLabel = 'Cuentas por Pagar';

    protected static ?string $modelLabel = 'Cuenta por Pagar';

    protected static ?string $pluralModelLabel = 'Cuentas por Pagar';

    protected static ?string $slug = 'cuentas-por-pagar';

    protected static string|\UnitEnum|null $navigationGroup = 'Cuentas';

    // Nace automáticamente al comprar a crédito (CompraService::crear()); no se crea a mano.
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
                    TextEntry::make('proveedor.nombre')->label('Proveedor'),
                    TextEntry::make('compra.ncf')->label('NCF de la compra')->placeholder('—'),
                    TextEntry::make('estadoLabel')->label('Estado')->badge()
                        ->state(fn (CuentaPorPagar $record) => $record->estado()->etiqueta())
                        ->color(fn (CuentaPorPagar $record) => $record->estado()->color()),
                    TextEntry::make('monto_total')->label('Monto total')->money('DOP'),
                    TextEntry::make('monto_pagado')->label('Monto pagado')->money('DOP'),
                    TextEntry::make('montoPendiente')->label('Monto pendiente')
                        ->state(fn (CuentaPorPagar $record) => $record->montoPendiente())
                        ->money('DOP'),
                    TextEntry::make('fecha_emision')->label('Fecha de emisión')->date('d/m/Y'),
                    TextEntry::make('fecha_vencimiento')->label('Fecha de vencimiento')->date('d/m/Y'),
                    TextEntry::make('diasVencido')->label('Días vencido')
                        ->state(fn (CuentaPorPagar $record) => $record->diasVencido() > 0 ? $record->diasVencido() : '—'),
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
                TextColumn::make('proveedor.nombre')
                    ->label('Proveedor')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('compra.ncf')
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
                    ->state(fn (CuentaPorPagar $record) => $record->montoPendiente())
                    ->money('DOP'),

                TextColumn::make('fecha_vencimiento')
                    ->label('Vence')
                    ->date('d/m/Y')
                    ->sortable(),

                TextColumn::make('estadoLabel')
                    ->label('Estado')
                    ->badge()
                    ->state(fn (CuentaPorPagar $record) => $record->estado()->etiqueta())
                    ->color(fn (CuentaPorPagar $record) => $record->estado()->color()),
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
                    ->visible(fn (CuentaPorPagar $record): bool => $record->estado() !== EstadoCuenta::PAGADA
                        && (auth()->user()?->can('cxp.pagar') ?? false))
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
                    ->action(function (CuentaPorPagar $record, array $data): void {
                        try {
                            app(CuentaPorPagarService::class)->registrarPago($record, $data, auth()->id());
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
                    ->visible(fn (CuentaPorPagar $record): bool => $record->pagosVigentes()->exists()
                        && (auth()->user()?->can('cxp.anular') ?? false))
                    ->schema(fn (CuentaPorPagar $record) => [
                        Select::make('pago_id')
                            ->label('Pago a anular')
                            ->options($record->pagosVigentes()->get()->mapWithKeys(
                                fn (PagoRealizado $p) => [$p->id => "RD$" . number_format((float) $p->monto, 2) . ' — ' . $p->fecha->format('d/m/Y')]
                            ))
                            ->required(),
                        Textarea::make('motivo')
                            ->label('Motivo de la anulación')
                            ->required()
                            ->rows(2),
                    ])
                    ->action(function (array $data): void {
                        $pago = PagoRealizado::findOrFail($data['pago_id']);

                        try {
                            app(CuentaPorPagarService::class)->anularPago($pago, $data['motivo']);
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
            'index' => Pages\ListCuentasPorPagar::route('/'),
            'view' => Pages\ViewCuentaPorPagar::route('/{record}'),
        ];
    }
}
