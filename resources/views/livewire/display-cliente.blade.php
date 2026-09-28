@php
  $mensaje = $caja?->mensaje_display ?: '¡Gracias por su compra!';
  $moneda = fn ($valor) => number_format((float) $valor, 2);
@endphp

<div class="dc" wire:poll.1s>
  <style>
    /* Paleta slate/sky: azul oscuro en vez de negro puro, legible a distancia. El header usa Sky 600
       (no Sky 500) y el monto del total Sky 400 para que el texto pase contraste WCAG (≥ 4:1). */
    .dc {
      --display-bg: #0f172a; --display-card-bg: #1e293b; --display-header-bg: #0284c7;
      --display-text: #f1f5f9; --display-text-muted: #94a3b8; --display-accent: #0ea5e9;
      --display-accent-claro: #38bdf8; --display-border: #334155; --display-total-bg: #0c4a6e;
      min-height: 100vh; height: 100vh; display: flex; flex-direction: column;
      background: var(--display-bg); color: var(--display-text);
      font-family: Inter, system-ui, -apple-system, 'Segoe UI', sans-serif; overflow: hidden; cursor: none; }
    .dc * { box-sizing: border-box; }
    .dc-header { display: flex; align-items: center; gap: 1.25rem; padding: 1.25rem 2rem; background: var(--display-header-bg); color: #fff; }
    .dc-header img { height: 64px; width: auto; border-radius: 8px; background: #fff; }
    .dc-empresa { font-size: 2rem; font-weight: 800; flex: 1; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
    .dc-caja { font-size: 1.5rem; font-weight: 600; color: rgba(255, 255, 255, .9); white-space: nowrap; }
    .dc-items { flex: 1; overflow-y: auto; padding: 1rem 2rem; scroll-behavior: smooth; }
    .dc-fila { display: grid; grid-template-columns: minmax(0, 1fr) 9rem 14rem; gap: 1rem; align-items: baseline; font-size: 1.75rem; padding: .6rem 1rem; }
    .dc-cabecera { font-size: 1.1rem; color: var(--display-text-muted); text-transform: uppercase; letter-spacing: .06em; border-bottom: 1px solid var(--display-border); margin-bottom: .5rem; }
    .dc-item { background: var(--display-card-bg); border: 1px solid var(--display-border); border-radius: 12px; margin-bottom: .5rem; animation: dc-entra .45s ease-out; }
    .dc-item .dc-nombre { overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
    .dc-item .dc-desc { display: block; font-size: 1.1rem; color: #fca5a5; }
    .dc-item .dc-cant { color: var(--display-text-muted); }
    .dc-num { text-align: right; font-variant-numeric: tabular-nums; white-space: nowrap; }
    @keyframes dc-entra { from { opacity: 0; transform: translateX(-24px); background: var(--display-total-bg); } to { opacity: 1; transform: none; background: var(--display-card-bg); } }
    .dc-pie { padding: 1.25rem 2rem 1.75rem; border-top: 1px solid var(--display-border); }
    .dc-linea { display: flex; justify-content: flex-end; gap: 2rem; font-size: 1.75rem; color: var(--display-text-muted); }
    .dc-linea span:last-child { min-width: 14rem; text-align: right; font-variant-numeric: tabular-nums; }
    .dc-separador { margin: .75rem 0 0 auto; max-width: 32rem; border-top: 2px solid var(--display-border); }
    .dc-total { margin-top: .75rem; display: flex; justify-content: space-between; align-items: baseline; gap: 1rem;
      padding: 1rem 1.75rem; background: var(--display-total-bg); border: 2px solid var(--display-accent); border-radius: 16px;
      font-size: 3.25rem; font-weight: 800; color: #fff; }
    .dc-total span:last-child { color: var(--display-accent-claro); font-variant-numeric: tabular-nums; }
    .dc-espera { flex: 1; display: grid; place-items: center; text-align: center; padding: 2rem; }
    .dc-espera h1 { font-size: 3.5rem; font-weight: 800; margin: 0 0 .5rem; }
    .dc-espera p { font-size: 1.75rem; color: var(--display-text-muted); margin: 0; }
    .dc-gracias h1 { color: #13DEB9; animation: dc-entra .5s ease-out; }
    .dc-gracias p strong { color: var(--display-accent-claro); }
    @media (max-width: 900px) {
      .dc-fila { grid-template-columns: minmax(0, 1fr) 6rem 12rem; font-size: 1.5rem; }
      .dc-total { font-size: 2.5rem; }
      .dc-empresa { font-size: 1.5rem; }
    }
    /* Al final a propósito (después del @media): con subgrid, Cant. e Importe toman el ancho del valor más largo de TODAS las filas (y
       siguen alineadas entre filas): un importe millonario nunca se sale de su tarjeta. Los
       anchos fijos de .dc-fila quedan de respaldo para navegadores sin subgrid (TVs viejas). */
    @supports (grid-template-columns: subgrid) {
      .dc-items { display: grid; grid-template-columns: minmax(0, 1fr) auto auto; column-gap: 1rem; align-content: start; }
      .dc-fila { grid-column: 1 / -1; grid-template-columns: subgrid; }
    }
  </style>

  @if ($caja === null)
    <div class="dc-espera">
      <div>
        <h1>Display no disponible</h1>
        <p>Esta caja fue desactivada o su enlace cambió.</p>
      </div>
    </div>
  @else
    <header class="dc-header">
      @if ($empresa?->logo)
        <img src="{{ \Illuminate\Support\Facades\Storage::disk('public')->url($empresa->logo) }}" alt="">
      @endif
      <span class="dc-empresa">{{ $empresa?->getFilamentName() }}</span>
      <span class="dc-caja">{{ $caja->nombre }}</span>
    </header>

    @if ($datos['estado'] === 'gracias')
      <div class="dc-espera dc-gracias">
        <div>
          <h1>{{ $mensaje }}</h1>
          <p>Total pagado: <strong>RD$ {{ $moneda($datos['total']) }}</strong></p>
        </div>
      </div>
    @elseif (empty($datos['items']))
      <div class="dc-espera">
        <div>
          <h1>Bienvenido</h1>
          <p>{{ $caja->nombre }} — en espera del próximo cliente</p>
        </div>
      </div>
    @else
      <div class="dc-items" x-data x-init="new MutationObserver(() => $el.scrollTop = $el.scrollHeight).observe($el, { childList: true, subtree: true }); $el.scrollTop = $el.scrollHeight">
        <div class="dc-fila dc-cabecera">
          <span>Producto</span><span class="dc-num">Cant.</span><span class="dc-num">Importe</span>
        </div>
        @foreach ($datos['items'] as $item)
          <div class="dc-fila dc-item" wire:key="dc-{{ $item['clave'] }}">
            <span class="dc-nombre">
              {{ $item['nombre'] }}
              @if ((float) $item['descuento'] > 0)
                <span class="dc-desc">Descuento -{{ $moneda($item['descuento']) }}</span>
              @endif
            </span>
            <span class="dc-num dc-cant">{{ $item['cantidad'] }}</span>
            <span class="dc-num">RD$ {{ $moneda($item['importe']) }}</span>
          </div>
        @endforeach
      </div>

      <footer class="dc-pie">
        <div class="dc-linea"><span>Subtotal</span><span>RD$ {{ $moneda($datos['subtotal']) }}</span></div>
        @if ((float) $datos['descuento'] > 0)
          <div class="dc-linea"><span>Descuento</span><span>-RD$ {{ $moneda($datos['descuento']) }}</span></div>
        @endif
        <div class="dc-linea"><span>ITBIS</span><span>RD$ {{ $moneda($datos['itbis']) }}</span></div>
        <div class="dc-separador"></div>
        <div class="dc-total"><span>TOTAL</span><span>RD$ {{ $moneda($datos['total']) }}</span></div>
      </footer>
    @endif
  @endif
</div>
