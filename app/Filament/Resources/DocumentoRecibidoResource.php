<?php

namespace App\Filament\Resources;

use App\Enums\CanalRecepcionEcf;
use App\Enums\EstadoAprobacionComercial;
use App\Enums\EstadoReenvioPac;
use App\Enums\TipoComprobante;
use App\Filament\Resources\DocumentoRecibidoResource\Pages;
use App\Models\DocumentoRecibido;
use App\Services\Dgii\DgiiGatewayInterface;
use Filament\Actions\Action;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Solo lectura: lo que llegó a nuestro endpoint público de recepción (los e-CF que otras
 * empresas nos enviaron) y ya reenviamos al PAC — ver RecepcionEcfService. Solo muestra el canal
 * "recepción"; las notificaciones de aprobación comercial que nos envían sobre NUESTROS propios
 * e-CF son un log técnico aparte, no un "documento recibido" de negocio.
 */
class DocumentoRecibidoResource extends Resource
{
    protected static ?string $model = DocumentoRecibido::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-inbox-arrow-down';

    protected static ?string $navigationLabel = 'e-CF Recibidos';

    protected static ?string $modelLabel = 'Documento recibido';

    protected static ?string $pluralModelLabel = 'e-CF Recibidos';

    protected static string|\UnitEnum|null $navigationGroup = 'Compras';

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->where('canal', CanalRecepcionEcf::RECEPCION);
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            TextEntry::make('fecha_emision')->label('Fecha de emisión')->date('d/m/Y')->placeholder('—'),
            TextEntry::make('razon_social_emisor')->label('Emisor')->placeholder('—'),
            TextEntry::make('rnc_emisor')->label('RNC emisor')->placeholder('—'),
            TextEntry::make('encf')->label('e-NCF')->placeholder('—'),
            TextEntry::make('tipo_comprobante')
                ->label('Tipo')
                ->placeholder('—')
                ->formatStateUsing(fn (?string $state) => $state !== null
                    ? (TipoComprobante::tryFrom($state)?->etiqueta() ?? $state)
                    : null),
            TextEntry::make('monto_total')->label('Monto')->money('DOP')->placeholder('—'),
            TextEntry::make('estado_reenvio')
                ->label('Estado')
                ->badge()
                ->formatStateUsing(fn (EstadoReenvioPac $state) => $state->etiqueta())
                ->color(fn (EstadoReenvioPac $state) => self::colorEstadoReenvio($state)),
            TextEntry::make('aprobacion_comercial')
                ->label('Nuestra aprobación comercial')
                ->badge()
                ->placeholder('Pendiente')
                ->formatStateUsing(fn (?EstadoAprobacionComercial $state) => ($state ?? EstadoAprobacionComercial::PENDIENTE)->etiqueta())
                ->color(fn (?EstadoAprobacionComercial $state) => match ($state) {
                    EstadoAprobacionComercial::ACEPTADO => 'success',
                    EstadoAprobacionComercial::RECHAZADO => 'danger',
                    default => 'gray',
                }),
            TextEntry::make('error')
                ->label('Error')
                ->color('danger')
                ->visible(fn (DocumentoRecibido $record) => filled($record->error))
                ->columnSpanFull(),
            TextEntry::make('created_at')->label('Recibido el')->dateTime('d/m/Y H:i'),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('fecha_emision')
                    ->label('Fecha')
                    ->date('d/m/Y')
                    ->placeholder('—')
                    ->sortable(),

                TextColumn::make('razon_social_emisor')
                    ->label('Emisor')
                    ->placeholder('—')
                    ->searchable()
                    ->description(fn (DocumentoRecibido $record) => $record->rnc_emisor),

                TextColumn::make('encf')
                    ->label('e-NCF')
                    ->placeholder('—')
                    ->searchable(),

                TextColumn::make('tipo_comprobante')
                    ->label('Tipo')
                    ->placeholder('—')
                    ->formatStateUsing(fn (?string $state) => $state !== null
                        ? (TipoComprobante::tryFrom($state)?->etiqueta() ?? $state)
                        : null),

                TextColumn::make('monto_total')
                    ->label('Monto')
                    ->money('DOP')
                    ->placeholder('—')
                    ->sortable(),

                TextColumn::make('estado_reenvio')
                    ->label('Estado')
                    ->badge()
                    ->formatStateUsing(fn (EstadoReenvioPac $state) => $state->etiqueta())
                    ->color(fn (EstadoReenvioPac $state) => self::colorEstadoReenvio($state)),

                TextColumn::make('aprobacion_comercial')
                    ->label('Nuestra aprobación')
                    ->badge()
                    ->placeholder('Pendiente')
                    ->formatStateUsing(fn (?EstadoAprobacionComercial $state) => ($state ?? EstadoAprobacionComercial::PENDIENTE)->etiqueta())
                    ->color(fn (?EstadoAprobacionComercial $state) => match ($state) {
                        EstadoAprobacionComercial::ACEPTADO => 'success',
                        EstadoAprobacionComercial::RECHAZADO => 'danger',
                        default => 'gray',
                    }),
            ])
            ->filters([
                SelectFilter::make('tipo_comprobante')
                    ->label('Tipo de comprobante')
                    ->options(collect(TipoComprobante::cases())->mapWithKeys(
                        fn (TipoComprobante $tipo) => [$tipo->value => $tipo->etiqueta()]
                    )),

                Filter::make('emisor')
                    ->schema([
                        TextInput::make('valor')->label('Emisor (nombre o RNC)'),
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        return $query->when(
                            $data['valor'] ?? null,
                            fn (Builder $q, $valor) => $q->where(fn (Builder $q2) => $q2
                                ->where('razon_social_emisor', 'ilike', "%{$valor}%")
                                ->orWhere('rnc_emisor', 'ilike', "%{$valor}%")),
                        );
                    }),

                Filter::make('fecha')
                    ->schema([
                        DatePicker::make('desde')->label('Desde'),
                        DatePicker::make('hasta')->label('Hasta'),
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        return $query
                            ->when($data['desde'] ?? null, fn (Builder $q, $desde) => $q->whereDate('fecha_emision', '>=', $desde))
                            ->when($data['hasta'] ?? null, fn (Builder $q, $hasta) => $q->whereDate('fecha_emision', '<=', $hasta));
                    }),
            ])
            ->recordActions([
                ViewAction::make()->label('Ver'),

                Action::make('aprobarComercialmente')
                    ->label('Aprobación comercial')
                    ->icon('heroicon-o-check-badge')
                    ->color('gray')
                    ->schema([
                        Select::make('decision')
                            ->label('Decisión')
                            ->options([
                                EstadoAprobacionComercial::ACEPTADO->value => 'Aceptar',
                                EstadoAprobacionComercial::RECHAZADO->value => 'Rechazar',
                            ])
                            ->required(),
                    ])
                    ->action(function (DocumentoRecibido $record, array $data): void {
                        $decision = EstadoAprobacionComercial::from($data['decision']);

                        $respuesta = app(DgiiGatewayInterface::class)->registrarAprobacionComercial([
                            'encf' => $record->encf,
                            'rncEmisor' => $record->rnc_emisor,
                            'decision' => $decision->value,
                        ]);

                        if (! $respuesta->exito) {
                            Notification::make()->title($respuesta->errorMessage ?? 'No se pudo registrar la aprobación comercial.')->danger()->send();

                            return;
                        }

                        $record->update(['aprobacion_comercial' => $decision]);

                        Notification::make()->title('Aprobación comercial registrada: '.$decision->etiqueta())->success()->send();
                    }),
            ])
            ->defaultSort('fecha_emision', 'desc');
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListDocumentosRecibidos::route('/'),
            'view' => Pages\ViewDocumentoRecibido::route('/{record}'),
        ];
    }

    private static function colorEstadoReenvio(EstadoReenvioPac $estado): string
    {
        return match ($estado) {
            EstadoReenvioPac::REENVIADO => 'success',
            EstadoReenvioPac::ERROR_REENVIO => 'danger',
            EstadoReenvioPac::RECHAZADO_VALIDACION => 'warning',
        };
    }
}
