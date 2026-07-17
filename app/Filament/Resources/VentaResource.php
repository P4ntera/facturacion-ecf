<?php

namespace App\Filament\Resources;

use App\Enums\AmbienteEcf;
use App\Enums\AnchoPapel;
use App\Enums\EstadoFiscal;
use App\Enums\EstadoVenta;
use App\Enums\EventoEcf;
use App\Enums\ModuloImpresion;
use App\Enums\TipoComprobante;
use App\Exceptions\VentaYaAnuladaException;
use App\Filament\Resources\VentaResource\Pages;
use App\Jobs\EnviarEcfJob;
use App\Models\Venta;
use App\Services\Dgii\EnvioEcfService;
use App\Services\Impresion\ImpresionService;
use App\Services\VentaService;
use Filament\Actions\Action;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Textarea;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\RepeatableEntry\TableColumn;
use Filament\Infolists\Components\TextEntry;
use Filament\Infolists\Components\ViewEntry;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class VentaResource extends Resource
{
    protected static ?string $model = Venta::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-receipt-percent';

    protected static ?string $navigationLabel = 'Ventas';

    protected static ?string $modelLabel = 'Venta';

    protected static ?string $pluralModelLabel = 'Ventas';

    protected static string|\UnitEnum|null $navigationGroup = 'Ventas';

    // Las ventas se crean únicamente desde el Punto de Venta (VentaService::registrar).
    public static function canCreate(): bool
    {
        return false;
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Comprobante')
                ->columns(3)
                ->schema([
                    TextEntry::make('fecha')->label('Fecha')->dateTime('d/m/Y H:i'),
                    TextEntry::make('ncf')->label('e-NCF'),
                    TextEntry::make('tipo_comprobante')
                        ->label('Tipo')
                        ->formatStateUsing(fn (TipoComprobante $state) => $state->etiqueta()),
                    TextEntry::make('cliente.nombre')->label('Cliente'),
                    TextEntry::make('estado')
                        ->label('Estado')
                        ->badge()
                        ->color(fn (EstadoVenta $state) => $state === EstadoVenta::ANULADA ? 'danger' : 'success'),
                    TextEntry::make('estado_fiscal')
                        ->label('Estado fiscal')
                        ->badge()
                        ->formatStateUsing(fn (EstadoFiscal $state) => self::etiquetaEstadoFiscal($state))
                        ->color(fn (EstadoFiscal $state) => self::colorEstadoFiscal($state)),
                    TextEntry::make('motivo_anulacion')
                        ->label('Motivo de anulación')
                        ->visible(fn (Venta $record) => $record->estaAnulada())
                        ->columnSpan(3),
                ]),

            Section::make('Estado fiscal DGII')
                ->columns(4)
                ->visible(fn (Venta $record) => $record->esElectronica())
                ->schema([
                    TextEntry::make('estado_fiscal')
                        ->label('Estado')
                        ->badge()
                        ->formatStateUsing(fn (EstadoFiscal $state) => self::etiquetaEstadoFiscal($state))
                        ->color(fn (EstadoFiscal $state) => self::colorEstadoFiscal($state)),
                    TextEntry::make('ecf_track_id')
                        ->label('Track ID')
                        ->placeholder('—'),
                    TextEntry::make('codigo_seguridad')
                        ->label('Código de seguridad')
                        ->placeholder('—'),
                    TextEntry::make('ambiente')
                        ->label('Ambiente')
                        ->placeholder('—')
                        ->formatStateUsing(fn (?AmbienteEcf $state) => $state?->etiqueta()),

                    TextEntry::make('descargar_xml')
                        ->label('XML firmado')
                        ->getStateUsing(fn () => 'Descargar XML')
                        ->url(fn (Venta $record) => route('ventas.ecf.xml', $record))
                        ->openUrlInNewTab()
                        ->icon('heroicon-o-arrow-down-tray')
                        ->visible(fn (Venta $record) => $record->pac_id !== null || $record->xml_url !== null)
                        ->columnSpan(4),

                    ViewEntry::make('dgii_url')
                        ->label('Timbre / QR')
                        ->view('filament.infolists.venta-qr-timbre')
                        ->visible(fn (Venta $record) => $record->dgii_url !== null)
                        ->columnSpan(4),

                    RepeatableEntry::make('eventos')
                        ->label('Historial DGII')
                        ->getStateUsing(fn (Venta $record) => collect(data_get($record->ecf_respuesta, 'eventos', []))
                            ->sortBy('timestamp')
                            ->values()
                            ->all())
                        ->schema([
                            TextEntry::make('timestamp')
                                ->label('Fecha/hora')
                                ->dateTime('d/m/Y H:i:s'),
                            TextEntry::make('status')
                                ->label('Evento')
                                ->formatStateUsing(fn (?string $state) => $state !== null
                                    ? (EventoEcf::tryFrom($state)?->etiqueta() ?? $state)
                                    : null),
                        ])
                        ->columns(2)
                        ->visible(fn (Venta $record) => filled(data_get($record->ecf_respuesta, 'eventos')))
                        ->columnSpan(4),
                ]),

            Section::make('Líneas')
                ->schema([
                    RepeatableEntry::make('detalles')
                        ->label('')
                        ->table([
                            TableColumn::make('Descripción'),
                            TableColumn::make('Cantidad'),
                            TableColumn::make('Precio'),
                            TableColumn::make('ITBIS'),
                            TableColumn::make('Subtotal'),
                        ])
                        ->schema([
                            TextEntry::make('descripcion')->label(''),
                            TextEntry::make('cantidad')->label(''),
                            TextEntry::make('precio_unitario')->label('')->money('DOP'),
                            TextEntry::make('itbis_monto')->label('')->money('DOP'),
                            TextEntry::make('subtotal')->label('')->money('DOP'),
                        ]),
                ]),

            Section::make('Totales')
                ->columns(3)
                ->schema([
                    TextEntry::make('subtotal')->label('Subtotal')->money(fn (Venta $record) => $record->moneda),
                    TextEntry::make('itbis_18')->label('ITBIS 18%')->money(fn (Venta $record) => $record->moneda),
                    TextEntry::make('itbis_16')->label('ITBIS 16%')->money(fn (Venta $record) => $record->moneda),
                    TextEntry::make('monto_gravado_0')->label('Exento/0%')->money(fn (Venta $record) => $record->moneda),
                    TextEntry::make('descuento')->label('Descuento')->money(fn (Venta $record) => $record->moneda),
                    TextEntry::make('total')->label('Total')->money(fn (Venta $record) => $record->moneda)->weight('bold'),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('fecha')
                    ->label('Fecha')
                    ->dateTime('d/m/Y H:i')
                    ->sortable(),

                TextColumn::make('ncf')
                    ->label('e-NCF')
                    ->searchable()
                    ->placeholder('—'),

                TextColumn::make('cliente.nombre')
                    ->label('Cliente')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('tipo_comprobante')
                    ->label('Tipo')
                    ->formatStateUsing(fn (TipoComprobante $state) => $state->etiqueta())
                    ->sortable(),

                TextColumn::make('total')
                    ->label('Total')
                    ->formatStateUsing(fn (Venta $record) => "{$record->moneda} ".number_format((float) $record->total, 2))
                    ->sortable(),

                TextColumn::make('estado')
                    ->label('Estado')
                    ->badge()
                    ->color(fn (EstadoVenta $state) => $state === EstadoVenta::ANULADA ? 'danger' : 'success'),

                TextColumn::make('estado_fiscal')
                    ->label('Estado fiscal')
                    ->badge()
                    ->formatStateUsing(fn (EstadoFiscal $state) => self::etiquetaEstadoFiscal($state))
                    ->color(fn (EstadoFiscal $state) => self::colorEstadoFiscal($state)),
            ])
            ->filters([
                SelectFilter::make('estado')
                    ->label('Estado')
                    ->options([
                        EstadoVenta::EMITIDA->value => 'Emitida',
                        EstadoVenta::ANULADA->value => 'Anulada',
                    ]),

                SelectFilter::make('estado_fiscal')
                    ->label('Estado fiscal')
                    ->options(collect(EstadoFiscal::cases())->mapWithKeys(
                        fn (EstadoFiscal $estado) => [$estado->value => self::etiquetaEstadoFiscal($estado)]
                    )),

                SelectFilter::make('tipo_comprobante')
                    ->label('Tipo de comprobante')
                    ->options(collect(TipoComprobante::cases())->mapWithKeys(
                        fn (TipoComprobante $tipo) => [$tipo->value => $tipo->etiqueta()]
                    )),

                SelectFilter::make('ambiente')
                    ->label('Ambiente DGII')
                    ->options(collect(AmbienteEcf::cases())->mapWithKeys(
                        fn (AmbienteEcf $ambiente) => [$ambiente->value => $ambiente->etiqueta()]
                    )),

                SelectFilter::make('cliente_id')
                    ->label('Cliente')
                    ->relationship('cliente', 'nombre')
                    ->searchable(),

                Filter::make('fecha')
                    ->schema([
                        DatePicker::make('desde')->label('Desde'),
                        DatePicker::make('hasta')->label('Hasta'),
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        return $query
                            ->when($data['desde'] ?? null, fn (Builder $q, $desde) => $q->whereDate('fecha', '>=', $desde))
                            ->when($data['hasta'] ?? null, fn (Builder $q, $hasta) => $q->whereDate('fecha', '<=', $hasta));
                    }),
            ])
            ->recordActions([
                ViewAction::make()->label('Ver'),

                Action::make('imprimir')
                    ->label('Imprimir')
                    ->icon('heroicon-o-printer')
                    ->color('gray')
                    ->url(fn (Venta $record) => route('ventas.pdf', $record))
                    ->openUrlInNewTab(),

                self::reimprimirTicketAction(),

                Action::make('anular')
                    ->label('Anular')
                    ->icon('heroicon-o-x-circle')
                    ->color('danger')
                    ->visible(fn (Venta $record) => $record->estado === EstadoVenta::EMITIDA
                        && (auth()->user()?->can('anular_ventas') ?? false))
                    ->requiresConfirmation()
                    ->schema([
                        Textarea::make('motivo')
                            ->label('Motivo de la anulación')
                            ->required()
                            ->rows(3),
                    ])
                    ->action(function (Venta $record, array $data): void {
                        try {
                            app(VentaService::class)->anular($record, $data['motivo'], auth()->id());
                        } catch (VentaYaAnuladaException $e) {
                            Notification::make()->title($e->getMessage())->danger()->send();

                            return;
                        }

                        Notification::make()->title('Venta anulada correctamente')->success()->send();
                    }),

                self::refrescarEstadoAction(),
                self::reintentarEnvioAction(),
            ])
            ->defaultSort('fecha', 'desc');
    }

    /**
     * Mismo criterio dual que ImpresoraResource::probarImpresion(): si la impresora resuelta
     * (del usuario que reimprime, o la predeterminada del módulo) es de RED, ->url() resuelve a
     * null y se ejecuta ->action() en el servidor sin diálogo; si es NAVEGADOR o no hay ninguna
     * configurada, ->url() abre el ticket en una pestaña para que el usuario elija su impresora.
     */
    public static function reimprimirTicketAction(): Action
    {
        return Action::make('reimprimirTicket')
            ->label('Reimprimir ticket')
            ->icon('heroicon-o-printer')
            ->color('gray')
            ->action(function (Venta $record): void {
                $impresora = app(ImpresionService::class)->resolverImpresora(ModuloImpresion::FACTURACION, auth()->user());
                $resultado = app(ImpresionService::class)->imprimirTicket($record, $impresora);

                if ($resultado['modo'] !== 'red') {
                    return;
                }

                if ($resultado['exito']) {
                    Notification::make()->title('Ticket impreso')->success()->send();

                    return;
                }

                Notification::make()->title('No se pudo imprimir el ticket')->body($resultado['error'])->danger()->send();
            })
            ->url(function (Venta $record) {
                $impresora = app(ImpresionService::class)->resolverImpresora(ModuloImpresion::FACTURACION, auth()->user());

                return $impresora?->esDeRed()
                    ? null
                    : app(ImpresionService::class)->urlTicket($record, $impresora?->ancho_papel ?? AnchoPapel::MM80);
            }, shouldOpenInNewTab: true);
    }

    public static function refrescarEstadoAction(): Action
    {
        return Action::make('refrescarEstado')
            ->label('Refrescar estado')
            ->icon('heroicon-o-arrow-path')
            ->color('gray')
            ->visible(fn (Venta $record) => $record->pac_id !== null
                && (auth()->user()?->can('gestionar_ecf') ?? false))
            ->action(function (Venta $record): void {
                $respuesta = app(EnvioEcfService::class)->refrescarEstado($record);
                $record->refresh();

                if (! $respuesta->exito) {
                    Notification::make()->title($respuesta->errorMessage)->danger()->send();

                    return;
                }

                Notification::make()
                    ->title('Estado fiscal actualizado: '.self::etiquetaEstadoFiscal($record->estado_fiscal))
                    ->success()
                    ->send();
            });
    }

    public static function reintentarEnvioAction(): Action
    {
        return Action::make('reintentarEnvio')
            ->label('Reintentar envío')
            ->icon('heroicon-o-paper-airplane')
            ->color('warning')
            ->visible(fn (Venta $record) => (auth()->user()?->can('gestionar_ecf') ?? false)
                && ($record->estado_fiscal === EstadoFiscal::PENDIENTE || isset($record->ecf_respuesta['error'])))
            ->requiresConfirmation()
            ->action(function (Venta $record): void {
                EnviarEcfJob::dispatch($record);

                Notification::make()->title('Reintento de envío encolado')->success()->send();
            });
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListVentas::route('/'),
            'view' => Pages\ViewVenta::route('/{record}'),
        ];
    }

    public static function etiquetaEstadoFiscal(EstadoFiscal $estado): string
    {
        return match ($estado) {
            EstadoFiscal::NO_APLICA => 'No aplica',
            EstadoFiscal::PENDIENTE => 'Pendiente',
            EstadoFiscal::EN_PROCESO => 'En proceso',
            EstadoFiscal::ACEPTADO => 'Aceptado',
            EstadoFiscal::ACEPTADO_CONDICIONAL => 'Aceptado condicional',
            EstadoFiscal::RECHAZADO => 'Rechazado',
            // Consumo (32) por debajo del umbral: el PAC lo convirtió a RFCE (aceptado).
            EstadoFiscal::RFCE => 'Aceptado (RFCE)',
        };
    }

    public static function colorEstadoFiscal(EstadoFiscal $estado): string
    {
        return match ($estado) {
            EstadoFiscal::ACEPTADO, EstadoFiscal::RFCE => 'success',
            EstadoFiscal::ACEPTADO_CONDICIONAL => 'warning',
            EstadoFiscal::RECHAZADO => 'danger',
            EstadoFiscal::EN_PROCESO => 'info',
            EstadoFiscal::PENDIENTE, EstadoFiscal::NO_APLICA => 'gray',
        };
    }
}
