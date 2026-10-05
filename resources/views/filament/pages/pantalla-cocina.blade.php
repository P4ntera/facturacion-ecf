<x-filament-panels::page>
    <div wire:poll.15s class="grid grid-cols-1 md:grid-cols-3 gap-6">
        {{-- Columna: En preparación (enviados a cocina) --}}
        <div>
            <h3 class="text-lg font-bold mb-3 px-3 py-2 bg-yellow-100 dark:bg-yellow-900/40 text-yellow-800 dark:text-yellow-200 rounded-lg text-center">
                En preparación
            </h3>
            <div class="space-y-3">
                @forelse ($this->getItemsPorEstado(\App\Enums\EstadoPreparacion::EN_PREPARACION) as $detalle)
                    @php
                        $minutosDesdeEnvio = $detalle->enviado_cocina_en ? $detalle->enviado_cocina_en->diffInMinutes(now()) : 0;
                        $retrasado = $minutosDesdeEnvio > 30;
                    @endphp
                    <div class="border rounded-lg p-3 {{ $retrasado ? 'border-red-500 bg-red-50 dark:bg-red-900/20 animate-pulse' : 'border-yellow-300 bg-yellow-50 dark:bg-yellow-900/10 dark:border-yellow-700' }}">
                        <div class="flex justify-between items-start">
                            <div>
                                <span class="text-xs font-bold text-gray-500 dark:text-gray-400">
                                    Mesa {{ $detalle->comanda->mesa->numero }} · {{ $detalle->comanda->numero }}
                                </span>
                                <div class="text-base font-bold text-gray-900 dark:text-gray-100 mt-1">
                                    {{ number_format((float) $detalle->cantidad, 0) }}x {{ $detalle->producto->nombre }}
                                </div>
                                @if ($detalle->notas)
                                    <div class="text-sm font-semibold text-orange-600 dark:text-orange-400 mt-1">
                                        ⚠ {{ $detalle->notas }}
                                    </div>
                                @endif
                            </div>
                            <div class="text-right">
                                <span class="text-xs font-mono {{ $retrasado ? 'text-red-600 dark:text-red-400 font-bold' : 'text-gray-500 dark:text-gray-400' }}">
                                    {{ $minutosDesdeEnvio }}min
                                </span>
                            </div>
                        </div>
                        @can('cocina.preparar')
                            <button wire:click="avanzarEstado({{ $detalle->id }})"
                                class="mt-2 w-full py-2 bg-green-600 text-white rounded-lg text-sm font-bold hover:bg-green-700 transition-colors">
                                Marcar LISTO
                            </button>
                        @endcan
                    </div>
                @empty
                    <div class="text-center py-8 text-gray-400 dark:text-gray-500 text-sm">Sin ítems</div>
                @endforelse
            </div>
        </div>

        {{-- Columna: Pendiente (recién agregados, aún no en cocina) --}}
        <div>
            <h3 class="text-lg font-bold mb-3 px-3 py-2 bg-gray-100 dark:bg-gray-700 text-gray-800 dark:text-gray-200 rounded-lg text-center">
                Pendiente
            </h3>
            <div class="space-y-3">
                @forelse ($this->getItemsPorEstado(\App\Enums\EstadoPreparacion::PENDIENTE) as $detalle)
                    <div class="border rounded-lg p-3 border-gray-300 bg-gray-50 dark:bg-gray-800/50 dark:border-gray-600">
                        <div class="flex justify-between items-start">
                            <div>
                                <span class="text-xs font-bold text-gray-500 dark:text-gray-400">
                                    Mesa {{ $detalle->comanda->mesa->numero }} · {{ $detalle->comanda->numero }}
                                </span>
                                <div class="text-base font-bold text-gray-900 dark:text-gray-100 mt-1">
                                    {{ number_format((float) $detalle->cantidad, 0) }}x {{ $detalle->producto->nombre }}
                                </div>
                                @if ($detalle->notas)
                                    <div class="text-sm font-semibold text-orange-600 dark:text-orange-400 mt-1">
                                        ⚠ {{ $detalle->notas }}
                                    </div>
                                @endif
                            </div>
                        </div>
                        @can('cocina.preparar')
                            <button wire:click="avanzarEstado({{ $detalle->id }})"
                                class="mt-2 w-full py-2 bg-yellow-500 text-white rounded-lg text-sm font-bold hover:bg-yellow-600 transition-colors">
                                Iniciar preparación
                            </button>
                        @endcan
                    </div>
                @empty
                    <div class="text-center py-8 text-gray-400 dark:text-gray-500 text-sm">Sin ítems</div>
                @endforelse
            </div>
        </div>

        {{-- Columna: Listo (esperando servir) --}}
        <div>
            <h3 class="text-lg font-bold mb-3 px-3 py-2 bg-green-100 dark:bg-green-900/40 text-green-800 dark:text-green-200 rounded-lg text-center">
                Listo para servir
            </h3>
            <div class="space-y-3">
                @forelse ($this->getItemsPorEstado(\App\Enums\EstadoPreparacion::LISTO) as $detalle)
                    <div class="border rounded-lg p-3 border-green-300 bg-green-50 dark:bg-green-900/10 dark:border-green-700">
                        <div class="flex justify-between items-start">
                            <div>
                                <span class="text-xs font-bold text-gray-500 dark:text-gray-400">
                                    Mesa {{ $detalle->comanda->mesa->numero }} · {{ $detalle->comanda->numero }}
                                </span>
                                <div class="text-base font-bold text-gray-900 dark:text-gray-100 mt-1">
                                    {{ number_format((float) $detalle->cantidad, 0) }}x {{ $detalle->producto->nombre }}
                                </div>
                                @if ($detalle->notas)
                                    <div class="text-sm text-orange-600 dark:text-orange-400 mt-1">{{ $detalle->notas }}</div>
                                @endif
                            </div>
                            <span class="text-xs font-mono text-gray-500 dark:text-gray-400">
                                {{ $detalle->preparado_en?->diffForHumans(short: true) }}
                            </span>
                        </div>
                    </div>
                @empty
                    <div class="text-center py-8 text-gray-400 dark:text-gray-500 text-sm">Sin ítems</div>
                @endforelse
            </div>
        </div>
    </div>
</x-filament-panels::page>
