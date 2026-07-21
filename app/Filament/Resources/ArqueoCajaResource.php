<?php

namespace App\Filament\Resources;

use App\Enums\EstadoArqueoCaja;
use App\Filament\Resources\ArqueoCajaResource\Pages;
use App\Models\ArqueoCaja;
use Filament\Actions\Action;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DatePicker;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\RepeatableEntry\TableColumn as InfolistTableColumn;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class ArqueoCajaResource extends Resource
{
    protected static ?string $model = ArqueoCaja::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-banknotes';

    protected static ?string $navigationLabel = 'Arqueos de Caja';

    protected static ?string $modelLabel = 'Arqueo de caja';

    protected static ?string $pluralModelLabel = 'Arqueos de caja';

    protected static ?string $slug = 'arqueos-caja';

    protected static string|\UnitEnum|null $navigationGroup = 'Ventas';

    // Solo lectura: la apertura/cierre se hace desde el Punto de Venta.
    public static function canCreate(): bool
    {
        return false;
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Datos del arqueo')
                ->columnSpanFull()
                ->columns(3)
                ->schema([
                    TextEntry::make('user.name')->label('Cajero'),
                    TextEntry::make('estado')->label('Estado')->badge()
                        ->formatStateUsing(fn (EstadoArqueoCaja $state) => $state->etiqueta())
                        ->color(fn (EstadoArqueoCaja $state) => $state === EstadoArqueoCaja::CERRADO ? 'success' : 'warning'),
                    TextEntry::make('abierto_en')->label('Abierto')->dateTime('d/m/Y H:i'),
                    TextEntry::make('cerrado_en')->label('Cerrado')->dateTime('d/m/Y H:i')->placeholder('—'),
                    TextEntry::make('fondo_inicial')->label('Fondo inicial')->money('DOP'),
                    TextEntry::make('total_ventas_efectivo')->label('Ventas en efectivo')->money('DOP')->placeholder('—'),
                    TextEntry::make('total_ventas_tarjeta')->label('Ventas con tarjeta')->money('DOP')->placeholder('—'),
                    TextEntry::make('total_ventas_transferencia')->label('Ventas por transferencia')->money('DOP')->placeholder('—'),
                    TextEntry::make('efectivo_esperado')->label('Efectivo esperado')->money('DOP')->placeholder('—'),
                    TextEntry::make('efectivo_contado')->label('Efectivo contado')->money('DOP')->placeholder('—'),
                    TextEntry::make('diferencia')->label('Diferencia')->money('DOP')->placeholder('—')
                        ->color(fn (?string $state) => $state === null ? null : (bccomp($state, '0', 2) < 0 ? 'danger' : 'success')),
                    TextEntry::make('notas')->label('Notas')->placeholder('—')->columnSpanFull(),
                ]),

            RepeatableEntry::make('ventas')
                ->label('Ventas del turno')
                ->table([
                    InfolistTableColumn::make('NCF'),
                    InfolistTableColumn::make('Forma de pago'),
                    InfolistTableColumn::make('Total'),
                    InfolistTableColumn::make('Estado'),
                ])
                ->schema([
                    TextEntry::make('ncf')->hiddenLabel()->placeholder('—'),
                    TextEntry::make('forma_pago')->hiddenLabel()->formatStateUsing(fn ($state) => $state->etiqueta()),
                    TextEntry::make('total')->hiddenLabel()->money('DOP'),
                    TextEntry::make('estado')->hiddenLabel()->formatStateUsing(fn ($state) => $state->value),
                ])
                ->columnSpanFull(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('user.name')
                    ->label('Cajero')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('abierto_en')
                    ->label('Abierto')
                    ->dateTime('d/m/Y H:i')
                    ->sortable(),

                TextColumn::make('cerrado_en')
                    ->label('Cerrado')
                    ->dateTime('d/m/Y H:i')
                    ->placeholder('—')
                    ->sortable(),

                TextColumn::make('fondo_inicial')
                    ->label('Fondo inicial')
                    ->money('DOP'),

                TextColumn::make('efectivo_esperado')
                    ->label('Efectivo esperado')
                    ->money('DOP')
                    ->placeholder('—'),

                TextColumn::make('efectivo_contado')
                    ->label('Efectivo contado')
                    ->money('DOP')
                    ->placeholder('—'),

                TextColumn::make('diferencia')
                    ->label('Diferencia')
                    ->money('DOP')
                    ->placeholder('—')
                    ->color(fn (?string $state) => $state === null ? null : (bccomp($state, '0', 2) < 0 ? 'danger' : 'success')),

                TextColumn::make('estado')
                    ->label('Estado')
                    ->badge()
                    ->formatStateUsing(fn (EstadoArqueoCaja $state) => $state->etiqueta())
                    ->color(fn (EstadoArqueoCaja $state) => $state === EstadoArqueoCaja::CERRADO ? 'success' : 'warning'),
            ])
            ->filters([
                SelectFilter::make('user_id')
                    ->label('Cajero')
                    ->relationship('user', 'name')
                    ->searchable()
                    ->preload(),

                SelectFilter::make('estado')
                    ->label('Estado')
                    ->options([
                        EstadoArqueoCaja::ABIERTO->value => 'Abierto',
                        EstadoArqueoCaja::CERRADO->value => 'Cerrado',
                    ]),

                Filter::make('fecha')
                    ->schema([
                        DatePicker::make('desde')->label('Desde'),
                        DatePicker::make('hasta')->label('Hasta'),
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        return $query
                            ->when($data['desde'] ?? null, fn (Builder $q, $desde) => $q->whereDate('abierto_en', '>=', $desde))
                            ->when($data['hasta'] ?? null, fn (Builder $q, $hasta) => $q->whereDate('abierto_en', '<=', $hasta));
                    }),
            ])
            ->recordActions([
                ViewAction::make(),

                Action::make('descargarPdf')
                    ->label('Descargar PDF')
                    ->icon('heroicon-o-document-arrow-down')
                    ->color('gray')
                    ->visible(fn (ArqueoCaja $record) => $record->estaCerrado())
                    ->url(fn (ArqueoCaja $record) => route('arqueos-caja.pdf', $record), shouldOpenInNewTab: true),
            ])
            ->defaultSort('abierto_en', 'desc');
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListArqueosCaja::route('/'),
            'view'  => Pages\ViewArqueoCaja::route('/{record}'),
        ];
    }
}
