<?php

namespace App\Filament\Resources\CompraResource\Pages;

use App\Filament\Resources\CompraResource;
use App\Filament\Resources\DevolucionCompraResource;
use App\Models\Compra;
use App\Services\CostoPrecioService;
use Filament\Actions\Action;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Repeater\TableColumn;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use RuntimeException;

class ViewCompra extends ViewRecord
{
    protected static string $resource = CompraResource::class;

    protected function getHeaderActions(): array
    {
        return [
            self::revisarPreciosAction(),

            Action::make('registrarDevolucion')
                ->label('Registrar devolución')
                ->icon('heroicon-o-arrow-uturn-left')
                ->color('gray')
                ->visible(fn (Compra $record): bool => ! $record->estaAnulada() && (auth()->user()?->can('devoluciones.crear') ?? false))
                ->url(fn (Compra $record): string => DevolucionCompraResource::getUrl('create', ['compra_id' => $record->id])),
        ];
    }

    /**
     * Precios sugeridos (costo + % de ganancia) de los productos de esta compra que quedaron
     * distintos al precio actual. Nada se aplica solo: el usuario marca cuáles y puede ajustar
     * el precio antes de guardarlo.
     */
    public static function revisarPreciosAction(): Action
    {
        return Action::make('revisarPrecios')
            ->label('Revisar precios')
            ->icon('heroicon-o-currency-dollar')
            ->color('warning')
            ->visible(fn (Compra $record): bool => ! $record->estaAnulada()
                && (auth()->user()?->can('productos.editar') ?? false)
                && app(CostoPrecioService::class)->sugerenciasParaCompra($record)->isNotEmpty())
            ->modalHeading('Precios sugeridos')
            ->modalDescription('Calculados con el costo actual y el porcentaje de ganancia de cada producto o su categoría. Marca los que quieres aplicar; puedes ajustar el precio nuevo.')
            ->modalWidth('5xl')
            ->modalSubmitActionLabel('Aplicar precios')
            ->fillForm(fn (Compra $record): array => [
                'precios' => app(CostoPrecioService::class)->sugerenciasParaCompra($record)
                    ->map(fn (array $s) => [
                        'producto_id' => $s['producto']->id,
                        'nombre' => $s['producto']->nombre,
                        'costo' => (string) $s['producto']->costo,
                        'precio_actual' => $s['precio_actual'],
                        'precio_nuevo' => $s['precio_sugerido'],
                        'aplicar' => true,
                    ])
                    ->all(),
            ])
            ->schema([
                Repeater::make('precios')
                    ->hiddenLabel()
                    ->table([
                        TableColumn::make('Producto'),
                        TableColumn::make('Costo'),
                        TableColumn::make('Precio actual'),
                        TableColumn::make('Precio nuevo'),
                        TableColumn::make('Aplicar'),
                    ])
                    ->schema([
                        Hidden::make('producto_id'),
                        TextEntry::make('nombre')->hiddenLabel(),
                        TextEntry::make('costo')->hiddenLabel()->money('DOP'),
                        TextEntry::make('precio_actual')->hiddenLabel()->money('DOP'),
                        TextInput::make('precio_nuevo')
                            ->hiddenLabel()
                            ->numeric()
                            ->prefix('RD$')
                            ->minValue(0.01)
                            ->required(),
                        Toggle::make('aplicar')->hiddenLabel(),
                    ])
                    ->addable(false)
                    ->deletable(false)
                    ->reorderable(false),
            ])
            ->action(function (Compra $record, array $data): void {
                $precios = collect($data['precios'] ?? [])
                    ->filter(fn (array $fila) => (bool) ($fila['aplicar'] ?? false))
                    ->mapWithKeys(fn (array $fila) => [$fila['producto_id'] => $fila['precio_nuevo']])
                    ->all();

                if ($precios === []) {
                    Notification::make()->title('No marcaste ningún precio para aplicar.')->warning()->send();

                    return;
                }

                try {
                    $actualizados = app(CostoPrecioService::class)->aplicarPrecios($precios, $record->empresa);
                } catch (RuntimeException $e) {
                    Notification::make()->title($e->getMessage())->danger()->send();

                    return;
                }

                Notification::make()
                    ->title($actualizados === 1 ? 'Se actualizó 1 precio' : "Se actualizaron {$actualizados} precios")
                    ->success()
                    ->send();
            });
    }
}
