<x-filament-panels::page>
    <div wire:poll.{{ $this->pollInterval }}s>
        @foreach ($this->getAreas() as $area)
            <div class="mb-6">
                <h3 class="text-lg font-semibold mb-3 text-gray-900 dark:text-gray-100">{{ $area->nombre }}</h3>
                <div class="grid grid-cols-2 sm:grid-cols-3 md:grid-cols-4 lg:grid-cols-6 gap-4">
                    @foreach ($area->mesas as $mesa)
                        @php
                            $comanda = $mesa->comandaActiva;
                            $colorMap = [
                                'disponible' => 'bg-green-100 border-green-400 dark:bg-green-900/30 dark:border-green-600',
                                'ocupada' => 'bg-red-100 border-red-400 dark:bg-red-900/30 dark:border-red-600',
                                'reservada' => 'bg-yellow-100 border-yellow-400 dark:bg-yellow-900/30 dark:border-yellow-600',
                                'fuera_de_servicio' => 'bg-gray-100 border-gray-400 dark:bg-gray-700 dark:border-gray-500',
                            ];
                            $color = $colorMap[$mesa->estado->value] ?? $colorMap['disponible'];
                        @endphp
                        <div
                            class="border-2 rounded-xl p-4 cursor-pointer transition-all hover:shadow-lg {{ $color }}"
                            @if ($mesa->estaDisponible())
                                wire:click="abrirComanda({{ $mesa->id }})"
                            @else
                                x-data="{ open: false }"
                                @click="open = true"
                            @endif
                        >
                            <div class="text-center">
                                <div class="text-2xl font-bold text-gray-900 dark:text-gray-100">{{ $mesa->numero }}</div>
                                <div class="text-xs text-gray-500 dark:text-gray-400">{{ $mesa->capacidad }} personas</div>
                                <div class="mt-1">
                                    <span class="inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium
                                        @if ($mesa->estado === \App\Enums\EstadoMesa::DISPONIBLE) text-green-700 dark:text-green-300
                                        @elseif ($mesa->estado === \App\Enums\EstadoMesa::OCUPADA) text-red-700 dark:text-red-300
                                        @elseif ($mesa->estado === \App\Enums\EstadoMesa::RESERVADA) text-yellow-700 dark:text-yellow-300
                                        @else text-gray-700 dark:text-gray-300
                                        @endif
                                    ">
                                        {{ $mesa->estado->etiqueta() }}
                                    </span>
                                </div>

                                @if ($comanda)
                                    <div class="mt-2 text-xs text-gray-600 dark:text-gray-400">
                                        <div>{{ $comanda->mesero?->name ?? 'Sin mesero' }}</div>
                                        <div>{{ $comanda->comensales }} comensal{{ $comanda->comensales > 1 ? 'es' : '' }}</div>
                                        <div class="font-mono">{{ $comanda->created_at->diffForHumans(short: true) }}</div>
                                        <div class="font-semibold text-gray-900 dark:text-gray-100 mt-1">
                                            {{ $comanda->detalles->count() }} ítem{{ $comanda->detalles->count() !== 1 ? 's' : '' }}
                                        </div>
                                    </div>
                                @endif
                            </div>

                            {{-- Modal de comanda activa --}}
                            @if ($comanda)
                                <div x-show="open" x-cloak @click.away="open = false"
                                    class="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4">
                                    <div @click.stop class="bg-white dark:bg-gray-800 rounded-xl shadow-2xl w-full max-w-lg max-h-[80vh] overflow-y-auto p-6">
                                        <div class="flex justify-between items-center mb-4">
                                            <h3 class="text-lg font-bold text-gray-900 dark:text-gray-100">
                                                Mesa {{ $mesa->numero }} — {{ $comanda->numero }}
                                            </h3>
                                            <button @click="open = false" class="text-gray-400 hover:text-gray-600 dark:hover:text-gray-300">
                                                <x-heroicon-o-x-mark class="w-5 h-5" />
                                            </button>
                                        </div>

                                        <div class="text-sm text-gray-600 dark:text-gray-400 mb-4">
                                            Mesero: {{ $comanda->mesero?->name ?? 'Sin mesero' }} |
                                            Estado: {{ $comanda->estado->etiqueta() }} |
                                            {{ $comanda->comensales }} comensal{{ $comanda->comensales > 1 ? 'es' : '' }}
                                        </div>

                                        {{-- Lista de productos --}}
                                        <div class="space-y-2 mb-4">
                                            @foreach ($comanda->detalles as $detalle)
                                                <div class="flex justify-between items-center p-2 rounded bg-gray-50 dark:bg-gray-700/50">
                                                    <div>
                                                        <span class="font-medium text-gray-900 dark:text-gray-100">{{ $detalle->producto->nombre }}</span>
                                                        <span class="text-gray-500 dark:text-gray-400 text-sm ml-1">x{{ number_format((float) $detalle->cantidad, 0) }}</span>
                                                        @if ($detalle->notas)
                                                            <div class="text-xs text-orange-600 dark:text-orange-400 italic">{{ $detalle->notas }}</div>
                                                        @endif
                                                    </div>
                                                    <div class="flex items-center gap-2">
                                                        <span class="text-sm font-mono text-gray-700 dark:text-gray-300">
                                                            ${{ number_format((float) $detalle->precio_unitario * (float) $detalle->cantidad, 2) }}
                                                        </span>
                                                        <span class="inline-flex items-center rounded-full px-1.5 py-0.5 text-xs
                                                            @if ($detalle->estado_preparacion === \App\Enums\EstadoPreparacion::PENDIENTE) bg-gray-200 text-gray-700 dark:bg-gray-600 dark:text-gray-300
                                                            @elseif ($detalle->estado_preparacion === \App\Enums\EstadoPreparacion::EN_PREPARACION) bg-yellow-200 text-yellow-700 dark:bg-yellow-900/50 dark:text-yellow-300
                                                            @elseif ($detalle->estado_preparacion === \App\Enums\EstadoPreparacion::LISTO) bg-green-200 text-green-700 dark:bg-green-900/50 dark:text-green-300
                                                            @elseif ($detalle->estado_preparacion === \App\Enums\EstadoPreparacion::ENTREGADO) bg-blue-200 text-blue-700 dark:bg-blue-900/50 dark:text-blue-300
                                                            @else bg-red-200 text-red-700 dark:bg-red-900/50 dark:text-red-300
                                                            @endif
                                                        ">
                                                            {{ $detalle->estado_preparacion->etiqueta() }}
                                                        </span>
                                                    </div>
                                                </div>
                                            @endforeach
                                        </div>

                                        {{-- Acciones --}}
                                        <div class="flex flex-wrap gap-2 border-t pt-4 dark:border-gray-600">
                                            @if (in_array($comanda->estado, [\App\Enums\EstadoComanda::ABIERTA, \App\Enums\EstadoComanda::EN_PREPARACION]))
                                                <button wire:click="enviarACocina({{ $comanda->id }})" @click="open = false"
                                                    class="px-3 py-2 bg-yellow-500 text-white rounded-lg text-sm font-medium hover:bg-yellow-600">
                                                    Enviar a cocina
                                                </button>
                                            @endif

                                            @can('comandas.cerrar')
                                                @if (! in_array($comanda->estado, [\App\Enums\EstadoComanda::CERRADA, \App\Enums\EstadoComanda::CANCELADA]))
                                                    <button wire:click="cerrarComanda({{ $comanda->id }}, { sin_comprobante: true })" @click="open = false"
                                                        wire:confirm="Se generará la venta y se liberará la mesa. ¿Continuar?"
                                                        class="px-3 py-2 bg-green-600 text-white rounded-lg text-sm font-medium hover:bg-green-700">
                                                        Cerrar y facturar
                                                    </button>
                                                @endif
                                            @endcan

                                            @can('comandas.transferir')
                                                <button wire:click="$dispatch('open-transfer-modal', { comandaId: {{ $comanda->id }} })" @click="open = false"
                                                    class="px-3 py-2 bg-blue-500 text-white rounded-lg text-sm font-medium hover:bg-blue-600">
                                                    Transferir
                                                </button>
                                            @endcan

                                            @can('comandas.cancelar')
                                                @if ($comanda->estado !== \App\Enums\EstadoComanda::CERRADA)
                                                    <button wire:click="cancelarComanda({{ $comanda->id }})" @click="open = false"
                                                        wire:confirm="¿Cancelar esta comanda? No se generará venta."
                                                        class="px-3 py-2 bg-red-500 text-white rounded-lg text-sm font-medium hover:bg-red-600">
                                                        Cancelar
                                                    </button>
                                                @endif
                                            @endcan
                                        </div>
                                    </div>
                                </div>
                            @endif
                        </div>
                    @endforeach
                </div>
            </div>
        @endforeach

        @if ($this->getAreas()->isEmpty())
            <div class="text-center py-12 text-gray-500 dark:text-gray-400">
                No hay áreas configuradas. Crea áreas y mesas desde el menú Restaurante.
            </div>
        @endif
    </div>
</x-filament-panels::page>
