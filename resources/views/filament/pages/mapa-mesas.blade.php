<x-filament-panels::page>
    <div class="flex gap-4" wire:poll.{{ $this->pollInterval }}s>

        {{-- ═══ MAPA DE MESAS ═══ --}}
        <div class="flex-1 min-w-0">
            @foreach ($this->getAreas() as $area)
                <div class="mb-6">
                    <h3 class="text-lg font-semibold mb-3 text-gray-900 dark:text-gray-100">{{ $area->nombre }}</h3>
                    <div class="grid grid-cols-2 sm:grid-cols-3 md:grid-cols-4 lg:grid-cols-5 gap-3">
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
                                $estaSeleccionada = $comanda && $comanda->id === $comandaAbiertaId;
                            @endphp

                            <div
                                class="border-2 rounded-xl p-4 cursor-pointer transition-all hover:shadow-lg {{ $color }} {{ $estaSeleccionada ? 'ring-2 ring-primary-500 ring-offset-2 dark:ring-offset-gray-900' : '' }}"
                                @if ($mesa->estaDisponible())
                                    wire:click="abrirComanda({{ $mesa->id }})"
                                @elseif ($comanda)
                                    wire:click="verComanda({{ $comanda->id }})"
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
                                            <div class="font-mono">{{ $comanda->created_at->diffForHumans(short: true) }}</div>
                                            <div class="font-semibold text-gray-900 dark:text-gray-100 mt-1">
                                                {{ $comanda->detalles->count() }} ítem{{ $comanda->detalles->count() !== 1 ? 's' : '' }}
                                            </div>
                                        </div>
                                    @endif
                                </div>
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

        {{-- ═══ PANEL LATERAL: DETALLE DE COMANDA ═══ --}}
        @if ($comanda = $this->getComandaActiva())
            <div class="w-96 shrink-0 bg-white dark:bg-gray-800 rounded-xl shadow-lg border border-gray-200 dark:border-gray-700 overflow-hidden flex flex-col max-h-[calc(100vh-10rem)]">

                {{-- Cabecera --}}
                <div class="px-4 py-3 bg-gray-50 dark:bg-gray-900 border-b border-gray-200 dark:border-gray-700 flex items-center justify-between">
                    <div>
                        <h3 class="font-bold text-gray-900 dark:text-gray-100">
                            Mesa {{ $comanda->mesa?->numero }} — {{ $comanda->numero }}
                        </h3>
                        <p class="text-xs text-gray-500 dark:text-gray-400">
                            {{ $comanda->mesero?->name ?? 'Sin mesero' }} · {{ $comanda->comensales }} comensal{{ $comanda->comensales > 1 ? 'es' : '' }}
                            · {{ $comanda->estado->etiqueta() }}
                        </p>
                    </div>
                    <button wire:click="cerrarPanel" class="text-gray-400 hover:text-gray-600 dark:hover:text-gray-300 p-1">
                        <x-heroicon-o-x-mark class="w-5 h-5" />
                    </button>
                </div>

                {{-- Ítems de la comanda --}}
                <div class="flex-1 overflow-y-auto p-4 space-y-2">
                    @forelse ($comanda->detalles as $detalle)
                        <div class="flex items-start justify-between p-2 rounded-lg bg-gray-50 dark:bg-gray-700/50 group">
                            <div class="min-w-0 flex-1">
                                <div class="flex items-center gap-2">
                                    <span class="font-medium text-sm text-gray-900 dark:text-gray-100 truncate">
                                        {{ $detalle->producto?->nombre ?? 'Producto eliminado' }}
                                    </span>
                                    <span class="text-gray-500 dark:text-gray-400 text-xs shrink-0">x{{ number_format((float) $detalle->cantidad, 0) }}</span>
                                </div>
                                @if ($detalle->notas)
                                    <div class="text-xs text-orange-600 dark:text-orange-400 italic mt-0.5">{{ $detalle->notas }}</div>
                                @endif
                                <span class="inline-flex items-center rounded-full px-1.5 py-0.5 text-[10px] mt-1
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
                            <div class="flex items-center gap-2 shrink-0 ml-2">
                                <span class="text-sm font-mono text-gray-700 dark:text-gray-300">
                                    ${{ number_format((float) $detalle->precio_unitario * (float) $detalle->cantidad, 2) }}
                                </span>
                                @if ($detalle->estado_preparacion === \App\Enums\EstadoPreparacion::PENDIENTE)
                                    <button
                                        wire:click="quitarDetalle({{ $detalle->id }})"
                                        wire:confirm="¿Quitar este producto?"
                                        class="opacity-0 group-hover:opacity-100 text-red-400 hover:text-red-600 transition-opacity"
                                        title="Quitar"
                                    >
                                        <x-heroicon-o-trash class="w-4 h-4" />
                                    </button>
                                @endif
                            </div>
                        </div>
                    @empty
                        <div class="text-center py-6 text-gray-400 dark:text-gray-500 text-sm">
                            Sin productos aún. Busca y agrega productos abajo.
                        </div>
                    @endforelse

                    {{-- Total --}}
                    @if ($comanda->detalles->isNotEmpty())
                        <div class="flex justify-between items-center pt-2 border-t border-gray-200 dark:border-gray-600">
                            <span class="font-semibold text-gray-900 dark:text-gray-100">Total</span>
                            <span class="font-bold font-mono text-lg text-gray-900 dark:text-gray-100">
                                ${{ number_format($comanda->detalles->sum(fn ($d) => (float) $d->precio_unitario * (float) $d->cantidad), 2) }}
                            </span>
                        </div>
                    @endif
                </div>

                {{-- Agregar producto --}}
                @if (in_array($comanda->estado, [\App\Enums\EstadoComanda::ABIERTA, \App\Enums\EstadoComanda::EN_PREPARACION]))
                    <div class="border-t border-gray-200 dark:border-gray-700 p-4 bg-gray-50 dark:bg-gray-900 space-y-3">
                        <div class="text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wider">
                            Agregar producto
                        </div>

                        {{-- Buscador --}}
                        <input
                            type="text"
                            wire:model.live.debounce.300ms="busquedaProducto"
                            placeholder="Buscar producto..."
                            class="w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-800 text-sm
                                   focus:border-primary-500 focus:ring-primary-500 dark:text-gray-100 dark:placeholder-gray-500"
                        />

                        {{-- Lista de productos --}}
                        <div class="max-h-40 overflow-y-auto space-y-1">
                            @foreach ($this->getProductosFiltrados() as $producto)
                                <button
                                    wire:click="agregarProducto({{ $producto->id }})"
                                    class="w-full flex items-center justify-between px-3 py-2 rounded-lg text-sm
                                           hover:bg-primary-50 dark:hover:bg-primary-900/20 transition-colors text-left"
                                >
                                    <span class="text-gray-900 dark:text-gray-100 truncate">{{ $producto->nombre }}</span>
                                    <span class="text-gray-500 dark:text-gray-400 font-mono shrink-0 ml-2">${{ number_format((float) $producto->precio, 2) }}</span>
                                </button>
                            @endforeach

                            @if ($this->getProductosFiltrados()->isEmpty())
                                <div class="text-center py-2 text-gray-400 text-xs">No se encontraron productos</div>
                            @endif
                        </div>

                        {{-- Cantidad y notas (colapsable) --}}
                        <div x-data="{ showOptions: false }">
                            <button @click="showOptions = !showOptions" class="text-xs text-primary-600 dark:text-primary-400 hover:underline">
                                <span x-text="showOptions ? 'Ocultar opciones' : 'Cantidad / notas'"></span>
                            </button>
                            <div x-show="showOptions" x-collapse class="mt-2 grid grid-cols-2 gap-2">
                                <div>
                                    <label class="text-xs text-gray-500 dark:text-gray-400">Cantidad</label>
                                    <input type="number" min="1" step="1"
                                        wire:model="cantidadProducto"
                                        class="w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-800 text-sm dark:text-gray-100" />
                                </div>
                                <div>
                                    <label class="text-xs text-gray-500 dark:text-gray-400">Notas</label>
                                    <input type="text"
                                        wire:model="notasProducto"
                                        placeholder="Sin cebolla..."
                                        class="w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-800 text-sm dark:text-gray-100" />
                                </div>
                            </div>
                        </div>
                    </div>
                @endif

                {{-- Acciones --}}
                <div class="border-t border-gray-200 dark:border-gray-700 p-3 flex flex-wrap gap-2 bg-white dark:bg-gray-800">
                    @if (in_array($comanda->estado, [\App\Enums\EstadoComanda::ABIERTA, \App\Enums\EstadoComanda::EN_PREPARACION]))
                        @php
                            $pendientes = $comanda->detalles->where('estado_preparacion', \App\Enums\EstadoPreparacion::PENDIENTE)->count();
                        @endphp
                        @if ($pendientes > 0)
                            <button wire:click="enviarACocina"
                                class="flex-1 px-3 py-2 bg-yellow-500 text-white rounded-lg text-sm font-medium hover:bg-yellow-600 transition-colors">
                                🍳 Enviar a cocina ({{ $pendientes }})
                            </button>
                        @endif
                    @endif

                    @can('comandas.cerrar')
                        @if (! in_array($comanda->estado, [\App\Enums\EstadoComanda::CERRADA, \App\Enums\EstadoComanda::CANCELADA]))
                            <button wire:click="cerrarComanda"
                                wire:confirm="Se generará la venta y se liberará la mesa. ¿Continuar?"
                                class="flex-1 px-3 py-2 bg-green-600 text-white rounded-lg text-sm font-medium hover:bg-green-700 transition-colors">
                                💵 Cerrar y facturar
                            </button>
                        @endif
                    @endcan

                    @can('comandas.cancelar')
                        @if (! in_array($comanda->estado, [\App\Enums\EstadoComanda::CERRADA, \App\Enums\EstadoComanda::CANCELADA]))
                            <button wire:click="cancelarComanda"
                                wire:confirm="¿Cancelar esta comanda? No se generará venta."
                                class="px-3 py-2 bg-red-500 text-white rounded-lg text-sm font-medium hover:bg-red-600 transition-colors">
                                ✕ Cancelar
                            </button>
                        @endif
                    @endcan
                </div>
            </div>
        @endif

    </div>
</x-filament-panels::page>
