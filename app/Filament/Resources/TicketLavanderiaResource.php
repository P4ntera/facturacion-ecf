<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Enums\EstadoPagoTicket;
use App\Enums\EstadoTicketLavanderia;
use App\Enums\FormaPago;
use App\Enums\Modulo;
use App\Filament\Concerns\RestringidoPorModulo;
use App\Filament\Resources\TicketLavanderiaResource\Pages;
use App\Models\Cliente;
use App\Models\PrecioLavanderia;
use App\Models\ServicioLavanderia;
use App\Models\TicketLavanderia;
use App\Models\TipoPrenda;
use App\Services\TicketLavanderiaService;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Illuminate\Support\HtmlString;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class TicketLavanderiaResource extends Resource
{
    use RestringidoPorModulo;

    protected static ?string $model = TicketLavanderia::class;

    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedTicket;

    protected static string|\UnitEnum|null $navigationGroup = 'Lavandería';

    protected static ?int $navigationSort = 4;

    protected static ?string $navigationLabel = 'Tickets';

    protected static ?string $pluralModelLabel = 'Tickets de Lavandería';

    protected static ?string $modelLabel = 'Ticket de Lavandería';

    public static function modulo(): Modulo
    {
        return Modulo::LAVANDERIA_TICKETS;
    }

    public static function form(Schema $schema): Schema
    {
        $empresaId = Filament::getTenant()?->id;

        return $schema->columns(5)->components([
            Section::make('Información del Ticket')
                ->icon(Heroicon::OutlinedUser)
                ->columnSpan(2)
                ->schema([
                    Select::make('cliente_id')
                        ->label('Cliente')
                        ->options(fn () => Cliente::where('empresa_id', $empresaId)
                            ->where('activo', true)
                            ->pluck('nombre', 'id'))
                        ->searchable()
                        ->nullable()
                        ->preload()
                        ->placeholder('Buscar cliente...')
                        ->helperText('Opcional — dejar vacío para venta al portador')
                        ->createOptionForm([
                            TextInput::make('nombre')
                                ->label('Nombre')
                                ->required()
                                ->maxLength(255),
                            TextInput::make('telefono')
                                ->label('Teléfono')
                                ->tel()
                                ->regex('/^[\d\s\+\-\(\)]+$/')
                                ->validationMessages(['regex' => 'Solo dígitos, espacios, +, - y paréntesis.'])
                                ->maxLength(20),
                        ])
                        ->createOptionUsing(function (array $data) use ($empresaId): int {
                            return Cliente::create([
                                'empresa_id' => $empresaId,
                                'nombre' => $data['nombre'],
                                'telefono' => $data['telefono'] ?? null,
                                'activo' => true,
                            ])->getKey();
                        }),

                    DateTimePicker::make('fecha_prometida')
                        ->label('Fecha de Entrega')
                        ->minDate(now()->startOfDay())
                        ->seconds(false)
                        ->required(),

                    Textarea::make('observaciones')
                        ->label('Observaciones')
                        ->placeholder('Notas generales del ticket...')
                        ->rows(3)
                        ->columnSpanFull(),

                    Toggle::make('pagar_ahora')
                        ->label('¿Paga ahora?')
                        ->helperText('Cobrar al recibir las prendas')
                        ->default(false)
                        ->dehydrated(false)
                        ->live(),

                    Section::make('Cobro')
                        ->icon(Heroicon::OutlinedBanknotes)
                        ->visible(fn (Get $get): bool => (bool) $get('pagar_ahora'))
                        ->columns(2)
                        ->schema([
                            Select::make('datos_venta.forma_pago')
                                ->label('Forma de Pago')
                                ->options(
                                    collect(FormaPago::cases())
                                        ->reject(fn (FormaPago $fp) => $fp === FormaPago::MIXTO)
                                        ->mapWithKeys(fn (FormaPago $fp) => [$fp->value => $fp->etiqueta()])
                                )
                                ->default(FormaPago::EFECTIVO->value)
                                ->required()
                                ->live()
                                ->columnSpanFull(),

                            TextInput::make('datos_venta.monto_recibido')
                                ->label('Monto Recibido')
                                ->numeric()
                                ->prefix('RD$')
                                ->required()
                                ->minValue(0)
                                ->visible(fn (Get $get): bool => ($get('datos_venta.forma_pago') ?? FormaPago::EFECTIVO->value) === FormaPago::EFECTIVO->value)
                                ->live(debounce: 500)
                                ->afterStateUpdated(function (Get $get, Set $set) {
                                    $recibido = (float) ($get('datos_venta.monto_recibido') ?? 0);
                                    // Calcular total sumando detalles
                                    $detalles = $get('detalles') ?? [];
                                    $total = collect($detalles)->sum(fn ($d) => ((float) ($d['precio_unitario'] ?? 0)) * ((int) ($d['cantidad'] ?? 1)));
                                    $vuelto = max(0, round($recibido - $total, 2));
                                    $set('datos_venta.vuelto', $vuelto > 0 ? $vuelto : null);
                                }),

                            Placeholder::make('datos_venta.vuelto_display')
                                ->label('Devuelta')
                                ->visible(fn (Get $get): bool => ($get('datos_venta.forma_pago') ?? FormaPago::EFECTIVO->value) === FormaPago::EFECTIVO->value)
                                ->content(function (Get $get): HtmlString {
                                    $vuelto = (float) ($get('datos_venta.vuelto') ?? 0);

                                    return new HtmlString(
                                        '<span class="text-2xl font-bold text-success-600 dark:text-success-400">RD$ ' . number_format($vuelto, 2) . '</span>'
                                    );
                                }),

                            TextInput::make('datos_venta.vuelto')
                                ->hidden()
                                ->dehydrated(),
                        ]),
                ]),

            Section::make('Prendas')
                ->icon(Heroicon::OutlinedShoppingBag)
                ->columnSpan(3)
                ->schema([
                    Repeater::make('detalles')
                        ->label('')
                        ->schema([
                            Select::make('tipo_prenda_id')
                                ->label('Tipo de Prenda')
                                ->columnSpan(3)
                                ->placeholder('Buscar prenda...')
                                ->searchable()
                                ->required()
                                ->allowHtml()
                                ->options(function () use ($empresaId) {
                                    return TipoPrenda::where('empresa_id', $empresaId)
                                        ->where('activo', true)
                                        ->orderBy('nombre')
                                        ->get()
                                        ->mapWithKeys(fn (TipoPrenda $p) => [
                                            $p->id => '<div class="py-0.5">'
                                                .'<div class="font-medium">'.e($p->nombre).'</div>'
                                                .($p->descripcion ? '<div class="text-xs text-gray-400 dark:text-gray-500">'.e($p->descripcion).'</div>' : '')
                                                .'</div>',
                                        ]);
                                })
                                ->getOptionLabelUsing(fn ($value) => TipoPrenda::find($value)?->nombre)
                                ->createOptionForm([
                                    TextInput::make('nombre')
                                        ->label('Nombre')
                                        ->required()
                                        ->maxLength(255),
                                ])
                                ->createOptionUsing(function (array $data) use ($empresaId): int {
                                    return TipoPrenda::create([
                                        'empresa_id' => $empresaId,
                                        'nombre' => $data['nombre'],
                                        'activo' => true,
                                    ])->getKey();
                                })
                                ->live()
                                ->afterStateUpdated(function (Get $get, Set $set, ?string $state) use ($empresaId) {
                                    $servicioId = $get('servicio_lavanderia_id');
                                    if ($state && $servicioId) {
                                        $precio = PrecioLavanderia::precioParaCombinacion((int) $empresaId, (int) $state, (int) $servicioId)
                                            ?? ServicioLavanderia::find($servicioId)?->precio_base;
                                        if ($precio !== null) {
                                            $set('precio_unitario', $precio);
                                            $cantidad = max(1, (int) ($get('cantidad') ?: 1));
                                            $set('subtotal', round((float) $precio * $cantidad, 2));
                                        }
                                    }
                                }),

                            Select::make('servicio_lavanderia_id')
                                ->label('Servicio')
                                ->columnSpan(3)
                                ->placeholder('Buscar servicio...')
                                ->searchable()
                                ->required()
                                ->allowHtml()
                                ->options(function () use ($empresaId) {
                                    return ServicioLavanderia::where('empresa_id', $empresaId)
                                        ->where('activo', true)
                                        ->orderBy('nombre')
                                        ->get()
                                        ->mapWithKeys(fn (ServicioLavanderia $s) => [
                                            $s->id => '<div class="py-0.5">'
                                                .'<div class="font-medium">'.e($s->nombre).'</div>'
                                                .($s->descripcion ? '<div class="text-xs text-gray-400 dark:text-gray-500">'.e($s->descripcion).'</div>' : '')
                                                .'<div class="text-xs text-primary-600 dark:text-primary-400">RD$ '.number_format((float) $s->precio_base, 2).'</div>'
                                                .'</div>',
                                        ]);
                                })
                                ->getOptionLabelUsing(fn ($value) => ServicioLavanderia::find($value)?->nombre)
                                ->createOptionForm([
                                    TextInput::make('nombre')
                                        ->label('Nombre')
                                        ->required()
                                        ->maxLength(255),
                                    TextInput::make('precio_base')
                                        ->label('Precio Base')
                                        ->required()
                                        ->numeric()
                                        ->prefix('RD$')
                                        ->minValue(0),
                                    Toggle::make('aplica_itbis')
                                        ->label('Aplica ITBIS')
                                        ->default(true),
                                ])
                                ->createOptionUsing(function (array $data) use ($empresaId): int {
                                    return ServicioLavanderia::create([
                                        'empresa_id' => $empresaId,
                                        'nombre' => $data['nombre'],
                                        'precio_base' => $data['precio_base'],
                                        'aplica_itbis' => $data['aplica_itbis'] ?? true,
                                        'activo' => true,
                                    ])->getKey();
                                })
                                ->live()
                                ->afterStateUpdated(function (Get $get, Set $set, ?string $state) use ($empresaId) {
                                    if ($state) {
                                        $tipoPrendaId = $get('tipo_prenda_id');
                                        $servicio = ServicioLavanderia::find($state);

                                        // Prioridad: 1) matriz prenda×servicio, 2) precio_base del servicio
                                        $precio = null;
                                        if ($tipoPrendaId) {
                                            $precio = PrecioLavanderia::precioParaCombinacion((int) $empresaId, (int) $tipoPrendaId, (int) $state);
                                        }
                                        $precio = $precio ?? $servicio?->precio_base;

                                        if ($precio !== null) {
                                            $set('precio_unitario', $precio);
                                            $cantidad = max(1, (int) ($get('cantidad') ?: 1));
                                            $set('subtotal', round((float) $precio * $cantidad, 2));
                                        }
                                    }
                                }),

                            TextInput::make('cantidad')
                                ->label('Cant.')
                                ->columnSpan(2)
                                ->numeric()
                                ->default(1)
                                ->minValue(1)
                                ->required()
                                ->live()
                                ->afterStateUpdated(function (Get $get, Set $set, ?string $state) {
                                    $precio = (float) ($get('precio_unitario') ?: 0);
                                    $cantidad = max(1, (int) ($state ?: 1));
                                    $set('subtotal', round($precio * $cantidad, 2));
                                }),

                            TextInput::make('precio_unitario')
                                ->label('Precio Unit.')
                                ->columnSpan(2)
                                ->numeric()
                                ->prefix('RD$')
                                ->minValue(0)
                                ->required()
                                ->live()
                                ->afterStateUpdated(function (Get $get, Set $set, ?string $state) {
                                    $precio = (float) ($state ?: 0);
                                    $cantidad = max(1, (int) ($get('cantidad') ?: 1));
                                    $set('subtotal', round($precio * $cantidad, 2));
                                }),

                            TextInput::make('subtotal')
                                ->label('Subtotal')
                                ->columnSpan(2)
                                ->numeric()
                                ->prefix('RD$')
                                ->disabled()
                                ->dehydrated(false),

                        ])
                        ->columns(12)
                        ->minItems(1)
                        ->defaultItems(1)
                        ->required()
                        ->addActionLabel('Agregar prenda')
                        ->reorderable(false)
                        ->live(),

                    Placeholder::make('total_prendas')
                        ->label('')
                        ->hiddenLabel()
                        ->content(function (Get $get): HtmlString {
                            $total = 0;
                            foreach ($get('detalles') ?? [] as $detalle) {
                                $precio = (float) ($detalle['precio_unitario'] ?? 0);
                                $cantidad = max(1, (int) ($detalle['cantidad'] ?? 1));
                                $total += $precio * $cantidad;
                            }

                            return new HtmlString(
                                '<div class="text-right"><span class="text-sm text-gray-500 dark:text-gray-400 me-2">Total</span>'
                                .'<span class="text-lg font-bold">RD$ '.number_format($total, 2).'</span></div>'
                            );
                        }),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('numero')
                    ->label('Número')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('cliente.nombre')
                    ->label('Cliente')
                    ->placeholder('Portador')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('recepcionista.name')
                    ->label('Recepcionista')
                    ->sortable(),

                TextColumn::make('estado')
                    ->label('Estado')
                    ->badge()
                    ->formatStateUsing(fn (EstadoTicketLavanderia $state) => $state->etiqueta())
                    ->color(fn (EstadoTicketLavanderia $state) => $state->color()),

                TextColumn::make('estado_pago')
                    ->label('Pago')
                    ->badge()
                    ->formatStateUsing(fn (EstadoPagoTicket $state) => $state->etiqueta())
                    ->color(fn (EstadoPagoTicket $state) => $state->color()),

                TextColumn::make('total')
                    ->label('Total')
                    ->money('DOP')
                    ->sortable(),

                TextColumn::make('fecha_prometida')
                    ->label('Entrega')
                    ->dateTime('d/m/Y h:i A')
                    ->placeholder('—')
                    ->sortable(),

                TextColumn::make('created_at')
                    ->label('Recibido')
                    ->dateTime('d/m/Y H:i')
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('estado')
                    ->options(collect(EstadoTicketLavanderia::cases())
                        ->mapWithKeys(fn (EstadoTicketLavanderia $e) => [$e->value => $e->etiqueta()])
                        ->all()),

                SelectFilter::make('estado_pago')
                    ->label('Estado de Pago')
                    ->options(collect(EstadoPagoTicket::cases())
                        ->mapWithKeys(fn (EstadoPagoTicket $e) => [$e->value => $e->etiqueta()])
                        ->all()),

                Filter::make('rango')
                    ->schema([
                        DatePicker::make('desde')->label('Desde'),
                        DatePicker::make('hasta')->label('Hasta'),
                    ])
                    ->query(function (Builder $query, array $data) {
                        return $query
                            ->when($data['desde'] ?? null, fn (Builder $q, $d) => $q->whereDate('created_at', '>=', $d))
                            ->when($data['hasta'] ?? null, fn (Builder $q, $d) => $q->whereDate('created_at', '<=', $d));
                    }),
            ])
            ->defaultSort('created_at', 'desc')
            ->actions([
                Action::make('marcar_listo')
                    ->label('Listo')
                    ->icon('heroicon-o-check')
                    ->color('success')
                    ->visible(fn (TicketLavanderia $record): bool => in_array($record->estado, [
                        EstadoTicketLavanderia::RECIBIDO,
                        EstadoTicketLavanderia::EN_PROCESO,
                    ]))
                    ->requiresConfirmation()
                    ->action(function (TicketLavanderia $record): void {
                        try {
                            app(TicketLavanderiaService::class)->avanzarEstado($record);
                            Notification::make()->title('Marcado como Listo')->success()->send();
                        } catch (\RuntimeException $e) {
                            Notification::make()->title($e->getMessage())->danger()->send();
                        }
                    }),

                Action::make('cobrar')
                    ->label('Cobrar')
                    ->icon('heroicon-o-banknotes')
                    ->color('primary')
                    ->visible(fn (TicketLavanderia $record): bool => $record->estado_pago === EstadoPagoTicket::PENDIENTE
                        && $record->estado !== EstadoTicketLavanderia::CANCELADO)
                    ->modalHeading('Cobrar Ticket')
                    ->modalDescription(fn (TicketLavanderia $record): string => 'Total: RD$ ' . number_format((float) $record->total, 2))
                    ->schema(fn (TicketLavanderia $record): array => [
                        Select::make('forma_pago')
                            ->label('Forma de Pago')
                            ->options(
                                collect(FormaPago::cases())
                                    ->reject(fn (FormaPago $fp) => $fp === FormaPago::MIXTO)
                                    ->mapWithKeys(fn (FormaPago $fp) => [$fp->value => $fp->etiqueta()])
                            )
                            ->default(FormaPago::EFECTIVO->value)
                            ->required()
                            ->live(),

                        TextInput::make('monto_recibido')
                            ->label('Monto Recibido')
                            ->numeric()
                            ->prefix('RD$')
                            ->required()
                            ->minValue((float) $record->total)
                            ->visible(fn (Get $get): bool => ($get('forma_pago') ?? FormaPago::EFECTIVO->value) === FormaPago::EFECTIVO->value)
                            ->live(debounce: 500)
                            ->afterStateUpdated(function (Get $get, Set $set) use ($record) {
                                $recibido = (float) ($get('monto_recibido') ?? 0);
                                $total = (float) $record->total;
                                $vuelto = max(0, round($recibido - $total, 2));
                                $set('vuelto', $vuelto > 0 ? $vuelto : 0);
                            }),

                        Placeholder::make('vuelto_display')
                            ->label('Devuelta')
                            ->visible(fn (Get $get): bool => ($get('forma_pago') ?? FormaPago::EFECTIVO->value) === FormaPago::EFECTIVO->value)
                            ->content(function (Get $get): HtmlString {
                                $vuelto = (float) ($get('vuelto') ?? 0);

                                return new HtmlString(
                                    '<span class="text-2xl font-bold text-success-600 dark:text-success-400">RD$ ' . number_format($vuelto, 2) . '</span>'
                                );
                            }),

                        TextInput::make('vuelto')
                            ->hidden()
                            ->default(0),
                    ])
                    ->action(function (TicketLavanderia $record, array $data): void {
                        $formaPago = FormaPago::tryFrom($data['forma_pago'] ?? '') ?? FormaPago::EFECTIVO;

                        $datosVenta = [
                            'forma_pago' => $formaPago,
                            'user_id' => auth()->id(),
                        ];

                        if ($formaPago === FormaPago::EFECTIVO) {
                            $montoRecibido = (float) ($data['monto_recibido'] ?? 0);
                            $datosVenta['monto_efectivo'] = $montoRecibido > 0 ? $montoRecibido : null;
                            $datosVenta['vuelto'] = (float) ($data['vuelto'] ?? 0) ?: null;
                        }

                        try {
                            app(TicketLavanderiaService::class)->cobrar($record, Filament::getTenant(), $datosVenta);
                            Notification::make()->title('Ticket cobrado')->success()->send();
                        } catch (\RuntimeException $e) {
                            Notification::make()->title($e->getMessage())->danger()->send();
                        }
                    }),

                Action::make('entregar')
                    ->label('Entregar')
                    ->icon('heroicon-o-check-circle')
                    ->color('success')
                    ->visible(fn (TicketLavanderia $record): bool => $record->estado === EstadoTicketLavanderia::LISTO
                        && $record->estado_pago === EstadoPagoTicket::PAGADO)
                    ->requiresConfirmation()
                    ->modalDescription('Se marcará como entregado.')
                    ->action(function (TicketLavanderia $record): void {
                        try {
                            app(TicketLavanderiaService::class)->entregar($record, Filament::getTenant());
                            Notification::make()->title('Ticket entregado')->success()->send();
                        } catch (\RuntimeException $e) {
                            Notification::make()->title($e->getMessage())->danger()->send();
                        }
                    }),

                Action::make('cancelar')
                    ->label('Cancelar')
                    ->icon('heroicon-o-x-circle')
                    ->color('danger')
                    ->visible(fn (TicketLavanderia $record): bool => ! in_array($record->estado, [
                        EstadoTicketLavanderia::ENTREGADO,
                        EstadoTicketLavanderia::CANCELADO,
                    ]))
                    ->requiresConfirmation()
                    ->modalHeading('¿Cancelar este ticket?')
                    ->modalDescription('Esta acción no se puede deshacer.')
                    ->action(function (TicketLavanderia $record): void {
                        try {
                            app(TicketLavanderiaService::class)->cancelar($record);
                            Notification::make()->title('Ticket cancelado')->warning()->send();
                        } catch (\RuntimeException $e) {
                            Notification::make()->title($e->getMessage())->danger()->send();
                        }
                    }),
            ]);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            \Filament\Infolists\Components\TextEntry::make('numero')->label('Número'),
            \Filament\Infolists\Components\TextEntry::make('cliente.nombre')->label('Cliente')->placeholder('Portador'),
            \Filament\Infolists\Components\TextEntry::make('recepcionista.name')->label('Recepcionista'),
            \Filament\Infolists\Components\TextEntry::make('estado')
                ->label('Estado')
                ->badge()
                ->formatStateUsing(fn (EstadoTicketLavanderia $state) => $state->etiqueta())
                ->color(fn (EstadoTicketLavanderia $state) => $state->color()),
            \Filament\Infolists\Components\TextEntry::make('estado_pago')
                ->label('Estado de Pago')
                ->badge()
                ->formatStateUsing(fn (EstadoPagoTicket $state) => $state->etiqueta())
                ->color(fn (EstadoPagoTicket $state) => $state->color()),
            \Filament\Infolists\Components\TextEntry::make('total')->label('Total')->money('DOP'),
            \Filament\Infolists\Components\TextEntry::make('itbis')->label('ITBIS')->money('DOP')
                ->visible(fn (TicketLavanderia $record): bool => (float) $record->itbis > 0),
            \Filament\Infolists\Components\TextEntry::make('fecha_prometida')->label('Fecha Prometida')->dateTime('d/m/Y h:i A')->placeholder('—'),
            \Filament\Infolists\Components\TextEntry::make('observaciones')->label('Observaciones')->placeholder('—')->columnSpanFull(),
            \Filament\Infolists\Components\TextEntry::make('created_at')->label('Recibido')->dateTime('d/m/Y H:i'),
            \Filament\Infolists\Components\TextEntry::make('entregado_en')->label('Entregado')->dateTime('d/m/Y H:i')->placeholder('—'),
            \Filament\Infolists\Components\TextEntry::make('venta.numero_comprobante')->label('Comprobante')->placeholder('—'),

            \Filament\Infolists\Components\RepeatableEntry::make('detalles')
                ->label('Prendas')
                ->schema([
                    \Filament\Infolists\Components\TextEntry::make('tipoPrenda.nombre')->label('Prenda'),
                    \Filament\Infolists\Components\TextEntry::make('servicioLavanderia.nombre')->label('Servicio'),
                    \Filament\Infolists\Components\TextEntry::make('cantidad')->label('Cant.'),
                    \Filament\Infolists\Components\TextEntry::make('precio_unitario')->label('Precio unit.')->money('DOP'),
                    \Filament\Infolists\Components\TextEntry::make('subtotal')->label('Subtotal')->money('DOP'),
                    \Filament\Infolists\Components\TextEntry::make('observaciones')->label('Observaciones')->placeholder('—'),
                ])
                ->columns(6),
        ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListTicketsLavanderia::route('/'),
            'create' => Pages\CreateTicketLavanderia::route('/create'),
            'view' => Pages\ViewTicketLavanderia::route('/{record}'),
        ];
    }
}
