{{-- ESC abre el listado de compras solo si el foco no está ya dentro de un modal
     (evita pelear con el cierre nativo de modales de Filament, que también usa Escape). --}}
<div
    x-data
    x-on:keydown.window.escape="if (! $event.target.closest('.fi-modal')) { $wire.mountAction('verListado'); }"
></div>
