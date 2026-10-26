<?php

declare(strict_types=1);

namespace App\Filament\Pages\Reportes;

use App\Enums\EstadoPagoTicket;
use App\Enums\EstadoTicketLavanderia;
use App\Filament\Exports\TicketLavanderiaExporter;
use App\Models\TicketLavanderia;
use BackedEnum;
use Filament\Facades\Filament;
use Filament\Forms\Components\DatePicker;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\Summarizers\Summarizer;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Query\Builder as QueryBuilder;

class ReporteTicketsLavanderia extends ReportePage
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedTicket;

    protected static ?string $navigationLabel = 'Tickets de Lavandería';

    protected static ?int $navigationSort = 80;

    protected static ?string $title = 'Reporte de tickets de lavandería';

    protected static ?string $slug = 'reportes/tickets-lavanderia';

    protected static string|\UnitEnum|null $navigationGroup = 'Lavandería';

    public static function canAccess(): bool
    {
        return auth()->user()?->can('lavanderia.ver_reportes') ?? false;
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(
                fn () => TicketLavanderia::query()
                    ->where('empresa_id', Filament::getTenant()->id)
                    ->whereBetween('created_at', [$this->rangoDesde(), $this->rangoHasta()])
                    ->with(['cliente', 'recepcionista', 'detalles'])
            )
            ->columns([
                TextColumn::make('numero')
                    ->label('Número')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('created_at')
                    ->label('Fecha')
                    ->dateTime('d/m/Y H:i')
                    ->sortable(),

                TextColumn::make('cliente.nombre')
                    ->label('Cliente')
                    ->placeholder('Sin cliente')
                    ->searchable(),

                TextColumn::make('recepcionista.nombre')
                    ->label('Recepcionista'),

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

                TextColumn::make('itbis')
                    ->label('ITBIS')
                    ->money('DOP'),

                TextColumn::make('total')
                    ->label('Total')
                    ->money('DOP')
                    ->summarize(
                        Summarizer::make()
                            ->label('Total (no cancelados)')
                            ->using(fn (QueryBuilder $query) => $query->where('estado', '!=', EstadoTicketLavanderia::CANCELADO->value)->sum('total'))
                            ->money('DOP'),
                    ),
            ])
            ->filters([
                Filter::make('rango')
                    ->schema([
                        DatePicker::make('desde')
                            ->label('Desde')
                            ->default(fn () => now()->startOfMonth()->toDateString()),
                        DatePicker::make('hasta')
                            ->label('Hasta')
                            ->default(fn () => now()->endOfMonth()->toDateString()),
                    ]),

                SelectFilter::make('estado')
                    ->label('Estado')
                    ->options(EstadoTicketLavanderia::class),

                SelectFilter::make('estado_pago')
                    ->label('Estado de Pago')
                    ->options(EstadoPagoTicket::class),
            ])
            ->defaultSort('created_at', 'desc');
    }

    protected function pdfRouteName(): string
    {
        return 'reportes.tickets-lavanderia.pdf';
    }

    protected function pdfRouteParams(): array
    {
        return ['desde' => $this->rangoDesde()->toDateString(), 'hasta' => $this->rangoHasta()->toDateString()];
    }

    protected function exporterClass(): string
    {
        return TicketLavanderiaExporter::class;
    }
}
