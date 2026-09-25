<x-filament::section heading="Alertas del sistema">
    @php $alertas = $this->getAlertas(); @endphp

    @if ($alertas->isEmpty())
        <p class="dashboard-widget-empty">No hay alertas activas. Todo en orden.</p>
    @else
        <ul class="alertas-list">
            @foreach ($alertas as $alerta)
                <li class="alertas-item">
                    <span class="alertas-dot alertas-dot-{{ $alerta['color'] }}" aria-hidden="true"></span>

                    <div class="alertas-item-texto">
                        <span class="alertas-item-titulo">{{ $alerta['titulo'] }}</span>
                        <span class="alertas-item-detalle">{{ $alerta['detalle'] }}</span>
                    </div>
                </li>
            @endforeach
        </ul>
    @endif
</x-filament::section>
