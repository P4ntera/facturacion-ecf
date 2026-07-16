<?php

declare(strict_types=1);

namespace App\Filament\Pages\Reportes;

use App\Filament\Exports\ProductoExporter;
use App\Services\ReporteService;
use BackedEnum;
use Filament\Schemas\Components\EmbeddedTable;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Schema;
use Filament\Support\Enums\FontWeight;
use Filament\Support\Enums\TextSize;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\Number;

class ReporteInventario extends ReportePage
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArchiveBox;

    protected static ?string $navigationLabel = 'Inventario';

    protected static ?string $title = 'Reporte de inventario';

    protected static ?string $slug = 'reportes/inventario';

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Resumen')
                ->schema([
                    Text::make(fn () => 'Valor total del inventario: '.Number::currency(
                        (float) app(ReporteService::class)->valorInventario(),
                        'DOP',
                    ))
                        ->size(TextSize::Large)
                        ->weight(FontWeight::Bold),
                ]),

            Section::make('Productos bajo mínimo')
                ->schema([
                    EmbeddedTable::make(),
                ]),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(fn () => app(ReporteService::class)->productosBajoMinimoQuery())
            ->columns([
                TextColumn::make('codigo')
                    ->label('Código'),

                TextColumn::make('nombre')
                    ->label('Producto')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('stock')
                    ->label('Stock actual')
                    ->numeric()
                    ->sortable()
                    ->color('danger'),

                TextColumn::make('stock_minimo')
                    ->label('Stock mínimo')
                    ->numeric()
                    ->sortable(),

                TextColumn::make('costo')
                    ->label('Costo')
                    ->money('DOP'),
            ])
            ->defaultSort('nombre');
    }

    protected function pdfRouteName(): string
    {
        return 'reportes.inventario.pdf';
    }

    protected function exporterClass(): string
    {
        return ProductoExporter::class;
    }
}
