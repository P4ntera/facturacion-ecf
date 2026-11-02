<?php

declare(strict_types=1);

namespace App\Filament\Pages\Reportes;

use App\Filament\Exports\ProduccionServicioExporter;
use App\Models\DetalleTicketLavanderia;
use BackedEnum;
use Filament\Facades\Filament;
use Filament\Forms\Components\DatePicker;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\Summarizers\Summarizer;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Table;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;

class ReporteProduccionServicio extends ReportePage
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedChartBar;

    protected static ?string $navigationLabel = 'Producción por Servicio';

    protected static ?int $navigationSort = 81;

    protected static ?string $title = 'Reporte de producción por servicio';

    protected static ?string $slug = 'reportes/produccion-servicio';

    protected static string|\UnitEnum|null $navigationGroup = 'Lavandería';

    public static function canAccess(): bool
    {
        return auth()->user()?->can('lavanderia.ver_reportes') ?? false;
    }

    /**
     * Filament inyecta ORDER BY <modelo>.id para paginar; con GROUP BY en PostgreSQL
     * eso falla (42803). Solución: envolver la query agregada como subquery con un
     * id sintético vía ROW_NUMBER(), para que Filament ordene/pagine por ese id sin
     * romper el GROUP BY interno.
     */
    public function table(Table $table): Table
    {
        return $table
            ->query(
                fn () => DetalleTicketLavanderia::query()
                    ->from(DB::raw('(' . $this->buildAggregateSubquery() . ') as detalle_tickets_lavanderia'))
                    ->select('*')
            )
            ->columns([
                TextColumn::make('servicio')
                    ->label('Servicio')
                    ->sortable(),

                TextColumn::make('tipo_prenda')
                    ->label('Tipo de Prenda')
                    ->sortable(),

                TextColumn::make('total_prendas')
                    ->label('Total Prendas')
                    ->numeric()
                    ->sortable()
                    ->summarize(
                        Summarizer::make()
                            ->label('Total')
                            ->using(fn (QueryBuilder $query) => $query->sum('total_prendas'))
                            ->numeric(),
                    ),

                TextColumn::make('total_ingresos')
                    ->label('Total Ingresos')
                    ->money('DOP')
                    ->sortable()
                    ->summarize(
                        Summarizer::make()
                            ->label('Total')
                            ->using(fn (QueryBuilder $query) => $query->sum('total_ingresos'))
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
            ])
            ->defaultSort('servicio', 'asc');
    }

    /**
     * Construye el SQL de la subquery agregada con ROW_NUMBER() como id.
     *
     * Los valores se interpolan de forma segura: empresa_id es un int del tenant
     * autenticado, y las fechas se generan internamente con Carbon — no hay entrada
     * de usuario directa.
     */
    protected function buildAggregateSubquery(): string
    {
        $empresaId = (int) Filament::getTenant()->id;
        $desde = $this->rangoDesde()->toDateTimeString();
        $hasta = $this->rangoHasta()->toDateTimeString();

        return <<<SQL
            SELECT ROW_NUMBER() OVER (ORDER BY "servicios_lavanderia"."nombre", "tipos_prenda"."nombre") as id,
                   "servicios_lavanderia"."nombre" as servicio,
                   "tipos_prenda"."nombre" as tipo_prenda,
                   SUM("detalle_tickets_lavanderia"."cantidad") as total_prendas,
                   SUM("detalle_tickets_lavanderia"."subtotal") as total_ingresos
            FROM "detalle_tickets_lavanderia"
            INNER JOIN "tickets_lavanderia"
                ON "detalle_tickets_lavanderia"."ticket_lavanderia_id" = "tickets_lavanderia"."id"
            INNER JOIN "servicios_lavanderia"
                ON "detalle_tickets_lavanderia"."servicio_lavanderia_id" = "servicios_lavanderia"."id"
            INNER JOIN "tipos_prenda"
                ON "detalle_tickets_lavanderia"."tipo_prenda_id" = "tipos_prenda"."id"
            WHERE "tickets_lavanderia"."empresa_id" = {$empresaId}
              AND "tickets_lavanderia"."created_at" BETWEEN '{$desde}' AND '{$hasta}'
              AND "tickets_lavanderia"."estado" != 'cancelado'
            GROUP BY "servicios_lavanderia"."nombre", "tipos_prenda"."nombre"
        SQL;
    }

    protected function pdfRouteName(): string
    {
        return 'reportes.produccion-servicio.pdf';
    }

    protected function pdfRouteParams(): array
    {
        return ['desde' => $this->rangoDesde()->toDateString(), 'hasta' => $this->rangoHasta()->toDateString()];
    }

    protected function exporterClass(): string
    {
        return ProduccionServicioExporter::class;
    }
}
