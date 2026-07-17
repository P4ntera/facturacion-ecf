<x-filament-panels::page>
    <div class="flex flex-col gap-6">
        @forelse ($this->gruposPorProveedor() as $grupo)
            <div class="rounded-xl border border-gray-200 bg-white p-4 shadow-sm dark:border-gray-700 dark:bg-gray-900">
                <div class="mb-3 flex items-center justify-between gap-4">
                    <div>
                        @if ($grupo['proveedor'])
                            <h3 class="text-base font-semibold text-gray-950 dark:text-white">
                                {{ $grupo['proveedor']->nombre }}
                            </h3>
                            <p class="text-xs text-gray-500 dark:text-gray-400">Proveedor principal</p>
                        @else
                            <h3 class="text-base font-semibold text-warning-600 dark:text-warning-400">
                                Sin proveedor asignado
                            </h3>
                            <p class="text-xs text-gray-500 dark:text-gray-400">
                                Asigna un proveedor principal desde
                                <a href="{{ \App\Filament\Resources\ProveedorResource::getUrl('index') }}" class="underline">Proveedores</a>
                                para poder generar un pedido.
                            </p>
                        @endif
                    </div>

                    @if ($grupo['proveedor'])
                        <a
                            href="{{ \App\Filament\Resources\PedidoCompraResource::getUrl('create', [
                                'proveedor_id' => $grupo['proveedor']->id,
                                'producto_ids' => $grupo['productos']->pluck('id')->implode(','),
                            ]) }}"
                            class="fi-btn fi-btn-color-primary inline-flex items-center gap-1 rounded-lg bg-primary-600 px-3 py-2 text-sm font-semibold text-white hover:bg-primary-500"
                        >
                            Crear pedido
                        </a>
                    @endif
                </div>

                <table class="w-full text-left text-sm">
                    <thead>
                        <tr class="border-b border-gray-200 text-xs uppercase text-gray-500 dark:border-gray-700 dark:text-gray-400">
                            <th class="py-2 pr-4">Producto</th>
                            <th class="py-2 pr-4">Stock actual</th>
                            <th class="py-2 pr-4">Stock mínimo</th>
                            <th class="py-2 pr-4">Cantidad sugerida</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                        @foreach ($grupo['productos'] as $producto)
                            <tr>
                                <td class="py-2 pr-4 text-gray-950 dark:text-white">{{ $producto->nombre }}</td>
                                <td class="py-2 pr-4 text-danger-600 dark:text-danger-400">{{ number_format((float) $producto->stock, 2) }}</td>
                                <td class="py-2 pr-4 text-gray-500 dark:text-gray-400">{{ number_format((float) $producto->stock_minimo, 2) }}</td>
                                <td class="py-2 pr-4 text-gray-950 dark:text-white">
                                    {{ number_format(max(1, (float) $producto->stock_minimo - (float) $producto->stock), 2) }}
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @empty
            <div class="rounded-xl border border-gray-200 bg-white p-8 text-center text-sm text-gray-500 shadow-sm dark:border-gray-700 dark:bg-gray-900 dark:text-gray-400">
                No hay productos bajo el mínimo de stock en este momento.
            </div>
        @endforelse
    </div>
</x-filament-panels::page>
