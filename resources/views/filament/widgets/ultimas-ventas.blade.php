<x-filament::section heading="Últimas ventas">
    <x-slot name="afterHeader">
        <a href="{{ $this->getVerTodasUrl() }}" class="dashboard-widget-link">
            Ver todas
        </a>
    </x-slot>

    @php $ventas = $this->getVentas(); @endphp

    @if ($ventas->isEmpty())
        <p class="dashboard-widget-empty">Todavía no hay ventas registradas.</p>
    @else
        <ul class="ultimas-ventas-list">
            @foreach ($ventas as $venta)
                @php $badge = $this->estadoFiscalBadge($venta->estado_fiscal); @endphp

                <li class="ultimas-ventas-item">
                    <div class="ultimas-ventas-item-info">
                        <span class="ultimas-ventas-ncf">{{ $venta->ncf ?? '—' }}</span>
                        <span class="ultimas-ventas-detalle">
                            {{ $venta->cliente?->nombre ?? 'Consumidor final' }}
                            · {{ $venta->forma_pago?->etiqueta() ?? '—' }}
                        </span>
                    </div>

                    <div class="ultimas-ventas-item-monto">
                        <span class="ultimas-ventas-monto">{{ \Illuminate\Support\Number::currency((float) $venta->total, 'DOP') }}</span>
                        <span class="badge-estado {{ $badge['class'] }}">{{ $badge['label'] }}</span>
                    </div>
                </li>
            @endforeach
        </ul>
    @endif
</x-filament::section>
