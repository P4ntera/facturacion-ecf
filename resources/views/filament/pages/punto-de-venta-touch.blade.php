@php
  $caja = $this->cajaSeleccionada();
  $empresa = $this->empresa();
  $turnoListo = $caja !== null && $this->turnoEnCajaSeleccionada();
@endphp

<div
  class="post"
  x-data="{
    buffer: '',
    modo: 'cantidad',
    teclado: false,
    cobrarAbierto: false,
    recibido: '',
    sonido: true,
    tecla(t) {
      if (t === '.' && this.buffer.includes('.')) return;
      if (this.buffer.length >= 9) return;
      this.buffer += t;
    },
    borrar() { this.buffer = ''; },
    fondo: '',
    teclaFondo(t) {
      if (t === '.' && this.fondo.includes('.')) return;
      if (this.fondo.length >= 9) return;
      this.fondo += t;
    },
    aplicar() {
      const idx = $wire.lineaSeleccionada;
      if (idx === null || this.buffer === '') return;
      if (this.modo === 'cantidad') { $wire.establecerCantidadLinea(idx, this.buffer); }
      else { $wire.establecerDescuentoLinea(idx, this.buffer); }
      this.buffer = '';
    },
    tocar(id) { $wire.tocarProducto(id, this.buffer); this.buffer = ''; },
    feedback() {
      navigator.vibrate?.(35);
      if (!this.sonido) return;
      try {
        const ctx = this._ctx ??= new (window.AudioContext || window.webkitAudioContext)();
        const o = ctx.createOscillator(); const g = ctx.createGain();
        o.frequency.value = 1320; g.gain.value = 0.06;
        o.connect(g); g.connect(ctx.destination); o.start(); o.stop(ctx.currentTime + 0.07);
      } catch (e) {}
    },
    pantallaCompleta() {
      if (document.fullscreenElement) { document.exitFullscreen?.(); }
      else { document.documentElement.requestFullscreen?.(); }
    },
    enfocar() { $nextTick(() => $refs.buscador?.focus()); },
    cambio(total) {
      const r = parseFloat(this.recibido || '0');
      return r > 0 ? Math.max(0, r - total) : 0;
    },
  }"
  x-on:abrir-ticket.window="window.open($event.detail.url, '_blank')"
  x-on:item-agregado.window="feedback(); enfocar()"
  x-on:producto-escaneado.window="feedback(); enfocar()"
  x-on:venta-cobrada.window="cobrarAbierto = false; recibido = ''; enfocar()"
  x-on:keydown.escape.window="cobrarAbierto = false"
>
  <style>
    .post {
      --pt-bg: #0f172a; --pt-card: #1e293b; --pt-card-2: #273549; --pt-borde: #334155;
      --pt-texto: #e2e8f0; --pt-muted: #94a3b8; --pt-primario: #5D87FF; --pt-exito: #13DEB9;
      --pt-peligro: #EF4444; --pt-aviso: #F59E0B;
      position: fixed; inset: 0; z-index: 30; display: flex; flex-direction: column;
      height: 100dvh; background: var(--pt-bg); color: var(--pt-texto);
      font-family: Inter, system-ui, sans-serif; color-scheme: dark;
      -webkit-user-select: none; user-select: none; -webkit-tap-highlight-color: transparent;
    }
    .post button { min-height: 48px; min-width: 48px; border: 0; border-radius: 10px; cursor: pointer;
      color: var(--pt-texto); background: var(--pt-card-2); font: inherit; font-weight: 600;
      touch-action: manipulation; transition: transform .06s, filter .06s; }
    .post button:active:not(:disabled) { transform: scale(.96); filter: brightness(1.25); }
    .post button:disabled { opacity: .4; cursor: not-allowed; }
    .post input, .post select { min-height: 48px; background: var(--pt-bg); color: var(--pt-texto);
      border: 1px solid var(--pt-borde); border-radius: 10px; padding: 0 .9rem; font: inherit; font-size: 1.05rem;
      width: 100%; -webkit-user-select: text; user-select: text; }
    .post input:focus, .post select:focus { outline: 2px solid var(--pt-primario); outline-offset: 0; }
    .pt-salir { display: inline-grid; place-items: center; min-width: 48px; min-height: 48px; border-radius: 10px; }
    .pt-salir:active { filter: brightness(1.25); }
    .pt-primario { background: var(--pt-primario) !important; color: #fff !important; }
    .pt-exito { background: var(--pt-exito) !important; color: #052e24 !important; }
    .pt-peligro { background: var(--pt-peligro) !important; color: #fff !important; }
    .pt-activo { outline: 3px solid var(--pt-primario); outline-offset: -3px; }
    .pt-muted { color: var(--pt-muted); }
    .pt-num { font-variant-numeric: tabular-nums; text-align: right; white-space: nowrap; }

    .pt-header { display: flex; align-items: center; gap: .75rem; padding: .5rem .75rem;
      background: var(--pt-card); border-bottom: 1px solid var(--pt-borde); flex-shrink: 0; }
    .pt-header img { height: 40px; width: auto; border-radius: 6px; }
    .pt-header .pt-empresa { font-weight: 700; font-size: 1.05rem; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
    .pt-header .pt-espacio { flex: 1; }
    .pt-chip { display: inline-flex; align-items: center; gap: .4rem; padding: 0 .9rem; min-height: 48px;
      border-radius: 10px; background: var(--pt-card-2); white-space: nowrap; }

    .pt-centro { flex: 1; display: grid; place-items: center; padding: 1rem; overflow: auto; }
    .pt-panel { background: var(--pt-card); border-radius: 16px; padding: 1.5rem; width: min(640px, 100%); }
    .pt-panel h2 { font-size: 1.5rem; font-weight: 700; margin-bottom: .25rem; }
    .pt-cajas { display: grid; grid-template-columns: repeat(auto-fill, minmax(150px, 1fr)); gap: .75rem; margin-top: 1rem; }
    .pt-cajas button { min-height: 96px; font-size: 1.15rem; display: flex; flex-direction: column; align-items: center; justify-content: center; gap: .25rem; }

    .pt-main { flex: 1; min-height: 0; display: grid; grid-template-columns: minmax(0, 5fr) minmax(0, 7fr); }
    .pt-izq { display: flex; flex-direction: column; min-height: 0; border-right: 1px solid var(--pt-borde); }
    .pt-items { flex: 1; overflow-y: auto; padding: .5rem; }
    .pt-item { width: 100%; display: grid; grid-template-columns: minmax(0, 1fr) auto auto; align-items: center; gap: .5rem;
      text-align: left; padding: .6rem .75rem !important; margin-bottom: .4rem; background: var(--pt-card) !important; font-weight: 500 !important; }
    .pt-item .pt-nombre { font-weight: 600; overflow: hidden; text-overflow: ellipsis; }
    .pt-item .pt-det { font-size: .85rem; }
    .pt-item-acciones { display: flex; gap: .4rem; margin: -.1rem 0 .5rem; justify-content: flex-end; }
    .pt-alerta { color: #fecaca; font-size: .85rem; }
    .pt-vacio { height: 100%; display: grid; place-items: center; text-align: center; color: var(--pt-muted); padding: 2rem; }
    .pt-totales { flex-shrink: 0; background: var(--pt-card); padding: .75rem; border-top: 1px solid var(--pt-borde); }
    .pt-totales dl > div { display: flex; justify-content: space-between; font-size: 1rem; padding: .1rem 0; }
    .pt-total { display: flex; flex-wrap: wrap; justify-content: space-between; align-items: baseline; column-gap: .75rem; margin: .4rem 0 .6rem; font-size: 1.9rem; font-weight: 800; }
    /* Un total muy largo baja de línea (alineado a la derecha) en vez de salirse de la columna. */
    .pt-total .pt-num { margin-left: auto; }
    .pt-acciones { display: grid; grid-template-columns: 2fr 1fr; gap: .5rem; }
    .pt-acciones button { min-height: 64px; font-size: 1.25rem; }

    .pt-der { display: flex; flex-direction: column; min-height: 0; padding: .5rem; gap: .5rem; }
    .pt-busqueda { position: relative; display: flex; gap: .5rem; }
    .pt-resultados { position: absolute; top: 56px; left: 0; right: 0; z-index: 5; max-height: 50vh; overflow-y: auto;
      background: var(--pt-card); border: 1px solid var(--pt-borde); border-radius: 12px; padding: .4rem; box-shadow: 0 16px 40px rgba(0,0,0,.5); }
    .pt-resultados button { width: 100%; display: flex; justify-content: space-between; gap: .5rem; text-align: left; margin-bottom: .3rem; padding: 0 .8rem; background: var(--pt-card-2); }
    .pt-categorias { display: flex; gap: .5rem; overflow-x: auto; padding-bottom: .25rem; flex-shrink: 0; }
    .pt-categorias button { padding: 0 1rem; white-space: nowrap; }
    .pt-productos { flex: 1; min-height: 0; overflow-y: auto; display: grid; grid-template-columns: repeat(auto-fill, minmax(128px, 1fr));
      grid-auto-rows: minmax(88px, auto); gap: .5rem; align-content: start; }
    .pt-productos button { display: flex; flex-direction: column; justify-content: space-between; align-items: flex-start; text-align: left;
      padding: .6rem; background: var(--pt-card); font-weight: 600; font-size: .95rem; line-height: 1.2; }
    .pt-productos .pt-precio { color: var(--pt-exito); font-variant-numeric: tabular-nums; font-size: 1rem; }
    .pt-teclado-ctn { flex-shrink: 0; display: grid; grid-template-columns: minmax(0, 1fr) minmax(0, 1fr); gap: .5rem; }
    .pt-display-buffer { min-height: 48px; display: flex; align-items: center; justify-content: flex-end; padding: 0 .9rem;
      background: var(--pt-bg); border: 1px solid var(--pt-borde); border-radius: 10px; font-size: 1.5rem; font-variant-numeric: tabular-nums; }
    .pt-teclado { display: grid; grid-template-columns: repeat(3, 1fr); gap: .4rem; }
    .pt-teclado button { font-size: 1.4rem; }
    .pt-modos { display: grid; grid-template-columns: 1fr 1fr; gap: .4rem; margin: .4rem 0; }

    .pt-modal-fondo { position: fixed; inset: 0; z-index: 40; background: rgba(2, 6, 23, .8); display: grid; place-items: center; padding: 1rem; }
    .pt-modal { background: var(--pt-card); border-radius: 16px; width: min(760px, 100%); max-height: 92dvh; overflow-y: auto; padding: 1.25rem; }
    .pt-modal h2 { font-size: 1.4rem; font-weight: 700; }
    .pt-seccion { margin-top: 1rem; }
    .pt-seccion > label { display: block; font-size: .85rem; color: var(--pt-muted); margin-bottom: .35rem; text-transform: uppercase; letter-spacing: .04em; }
    .pt-opciones { display: grid; grid-template-columns: repeat(auto-fill, minmax(150px, 1fr)); gap: .5rem; }
    .pt-opciones button { padding: .5rem; font-size: .95rem; }
    .pt-billetes { display: grid; grid-template-columns: repeat(4, 1fr); gap: .4rem; margin-top: .5rem; }

    @media (max-width: 1023px) {
      .pt-main { grid-template-columns: minmax(0, 1fr) minmax(0, 1fr); }
      .pt-teclado-ctn { grid-template-columns: 1fr; }
      .pt-teclado button { min-height: 48px; font-size: 1.2rem; }
      .pt-total { font-size: 1.5rem; }
      .pt-header .pt-ocultar-tablet { display: none; }
    }
    @media (max-width: 767px) {
      .pt-main { grid-template-columns: 1fr; grid-template-rows: minmax(0, 1fr) minmax(0, 1fr); }
      .pt-izq { border-right: 0; border-bottom: 1px solid var(--pt-borde); }
    }
  </style>

  {{-- HEADER --}}
  <header class="pt-header">
    @if ($empresa->logo)
      <img src="{{ \Illuminate\Support\Facades\Storage::disk('public')->url($empresa->logo) }}" alt="">
    @endif
    <span class="pt-empresa pt-ocultar-tablet">{{ $empresa->getFilamentName() }}</span>

    @if ($caja)
      <button type="button" class="pt-chip" wire:click="cambiarCaja" title="Cambiar de caja">
        <x-filament::icon icon="heroicon-o-computer-desktop" style="width:1.25rem;height:1.25rem" />
        {{ $caja->nombre }}
      </button>
    @endif

    <span class="pt-espacio"></span>

    <span class="pt-chip pt-ocultar-tablet">
      <x-filament::icon icon="heroicon-o-user" style="width:1.25rem;height:1.25rem" />
      {{ auth()->user()->name }}
    </span>
    <button type="button" x-on:click="sonido = !sonido" x-bind:title="sonido ? 'Silenciar' : 'Activar sonido'">
      <span x-show="sonido"><x-filament::icon icon="heroicon-o-speaker-wave" style="width:1.5rem;height:1.5rem" /></span>
      <span x-show="!sonido" x-cloak><x-filament::icon icon="heroicon-o-speaker-x-mark" style="width:1.5rem;height:1.5rem" /></span>
    </button>
    <button type="button" x-on:click="pantallaCompleta()" title="Pantalla completa">
      <x-filament::icon icon="heroicon-o-arrows-pointing-out" style="width:1.5rem;height:1.5rem" />
    </button>
    <a href="{{ \Filament\Facades\Filament::getUrl() }}" class="pt-salir pt-peligro" title="Salir del POS">
      <x-filament::icon icon="heroicon-o-x-mark" style="width:1.5rem;height:1.5rem" />
    </a>
  </header>

  @if ($caja === null)
    {{-- 1. Selector de caja (obligatorio antes de vender) --}}
    <div class="pt-centro" wire:key="pt-estado-caja">
      <div class="pt-panel">
        <h2>¿En qué caja vas a trabajar?</h2>
        <p class="pt-muted">Elige la caja registradora física en la que estás.</p>
        <div class="pt-cajas">
          @forelse ($this->cajasDisponibles() as $opcion)
            <button type="button" wire:click="seleccionarCaja({{ $opcion->id }})" wire:key="caja-{{ $opcion->id }}">
              <x-filament::icon icon="heroicon-o-computer-desktop" style="width:1.75rem;height:1.75rem" />
              {{ $opcion->nombre }}
              @if ($opcion->codigo)
                <span class="pt-muted" style="font-size:.85rem;font-weight:500">{{ $opcion->codigo }}</span>
              @endif
            </button>
          @empty
            <p class="pt-muted">No hay cajas registradoras activas. Un administrador debe crearlas en Configuración → Cajas registradoras.</p>
          @endforelse
        </div>
      </div>
    </div>
  @elseif (! $turnoListo)
    {{-- 2. Apertura del turno en la caja elegida --}}
    <div class="pt-centro" wire:key="pt-estado-turno">
      {{-- x-init solo corre cuando el panel se inserta (wire:key): si abrirCaja falla, el monto
           tecleado se conserva para reintentar. --}}
      <div class="pt-panel" x-init="fondo = ''">
        <h2>Abrir turno en {{ $caja->nombre }}</h2>
        <p class="pt-muted">Indica el fondo inicial de la gaveta.</p>
        <div class="pt-display-buffer" style="margin-top:1rem" x-text="'RD$ ' + (fondo || '0')"></div>
        <div class="pt-teclado" style="margin-top:.5rem">
          @foreach (['1', '2', '3', '4', '5', '6', '7', '8', '9', '.', '0'] as $t)
            <button type="button" x-on:click="teclaFondo('{{ $t }}')">{{ $t }}</button>
          @endforeach
          <button type="button" x-on:click="fondo = ''">C</button>
        </div>
        <button type="button" class="pt-exito" style="width:100%;margin-top:.75rem;min-height:64px;font-size:1.2rem"
          x-on:click="$wire.abrirCaja(fondo || '0')">
          Abrir turno
        </button>
      </div>
    </div>
  @else
    {{-- 3. Venta --}}
    <div class="pt-main" wire:key="pt-estado-venta">
      <section class="pt-izq">
        <div class="pt-items">
          @forelse ($carrito as $indice => $linea)
            <div wire:key="linea-{{ $indice }}-{{ $linea['producto_id'] }}-{{ $linea['presentacion_id'] ?? 'x' }}">
              <button type="button" class="pt-item {{ $lineaSeleccionada === $indice ? 'pt-activo' : '' }}" wire:click="seleccionarLinea({{ $indice }})">
                <span style="min-width:0">
                  <span class="pt-nombre" style="display:block">{{ $linea['nombre'] }}</span>
                  <span class="pt-det pt-muted">
                    RD$ {{ number_format((float) $linea['precio_unitario'], 2) }}
                    @if ((float) $linea['descuento'] > 0)
                      · desc. -{{ number_format((float) $linea['descuento'], 2) }}
                    @endif
                  </span>
                  @if ($this->lineaConStockInsuficiente($linea))
                    <span class="pt-alerta" style="display:block">Stock insuficiente (disp. {{ number_format((float) $this->stockDeLinea($linea), 2) }})</span>
                  @endif
                </span>
                <span class="pt-num" style="font-size:1.1rem">
                  @if (($linea['tipo_venta'] ?? null) === \App\Enums\TipoVenta::PESADO->value)
                    {{ number_format((float) $linea['cantidad'], 3) }} {{ $linea['unidad_base'] }}
                  @else
                    × {{ (int) $linea['cantidad'] }}
                  @endif
                </span>
                <span class="pt-num" style="font-size:1.1rem;min-width:6.5rem">{{ number_format((float) $this->subtotalLinea($linea), 2) }}</span>
              </button>

              @if ($lineaSeleccionada === $indice)
                <div class="pt-item-acciones">
                  @if (($linea['tipo_venta'] ?? null) !== \App\Enums\TipoVenta::PESADO->value)
                    <button type="button" wire:click="sumarALinea({{ $indice }}, -1)" aria-label="Restar uno">−</button>
                    <button type="button" wire:click="sumarALinea({{ $indice }}, 1)" aria-label="Sumar uno">+</button>
                  @endif
                  <button type="button" class="pt-peligro" style="padding:0 1rem" wire:click="quitarLinea({{ $indice }})">Quitar</button>
                </div>
              @endif
            </div>
          @empty
            <div class="pt-vacio">
              <div>
                <x-filament::icon icon="heroicon-o-shopping-cart" style="width:3rem;height:3rem;margin:0 auto .5rem" />
                Escanea o toca un producto para empezar.
              </div>
            </div>
          @endforelse
        </div>

        <div class="pt-totales">
          <dl>
            <div><dt class="pt-muted">Subtotal</dt><dd class="pt-num">{{ number_format((float) $totales['subtotal'], 2) }}</dd></div>
            @if ((float) $totales['descuento'] > 0)
              <div><dt class="pt-muted">Descuento</dt><dd class="pt-num">-{{ number_format((float) $totales['descuento'], 2) }}</dd></div>
            @endif
            <div><dt class="pt-muted">ITBIS</dt><dd class="pt-num">{{ number_format((float) $totales['total_itbis'], 2) }}</dd></div>
          </dl>
          <div class="pt-total">
            <span>TOTAL</span>
            <span class="pt-num">RD$ {{ number_format((float) $totales['total'], 2) }}</span>
          </div>
          @if ($this->hayLineasConStockInsuficiente())
            <p class="pt-alerta" style="margin-bottom:.5rem">Hay líneas con stock insuficiente; corrígelas para poder cobrar.</p>
          @endif
          <div class="pt-acciones">
            <button type="button" class="pt-exito" x-on:click="cobrarAbierto = true" @disabled(empty($carrito))>COBRAR</button>
            <button type="button" class="pt-peligro" wire:click="cancelarVenta" wire:confirm="¿Cancelar la venta en curso?" @disabled(empty($carrito))>CANCELAR</button>
          </div>
        </div>
      </section>

      <section class="pt-der">
        {{-- Búsqueda: nombre o código de barras (el lector USB "teclea" y manda Enter) --}}
        <div class="pt-busqueda">
          <input
            type="text"
            x-ref="buscador"
            x-init="$el.focus()"
            x-bind:inputmode="teclado ? 'text' : 'none'"
            autocomplete="off"
            placeholder="Escanear código o buscar producto..."
            wire:model.live.debounce.300ms="busquedaProducto"
            wire:keydown.enter="escanearOBuscar($event.target.value)"
          />
          <button type="button" x-on:click="teclado = !teclado; enfocar()" x-bind:class="teclado && 'pt-primario'" title="Teclado en pantalla">
            <x-filament::icon icon="heroicon-o-magnifying-glass" style="width:1.5rem;height:1.5rem" />
          </button>

          @if ($busquedaProducto !== '')
            <div class="pt-resultados">
              @forelse ($this->resultadosBusqueda() as $fila)
                @if ($fila['tipo'] === 'pesado')
                  <button type="button" x-on:click="tocar({{ $fila['producto_id'] }})" wire:key="res-p-{{ $fila['producto_id'] }}">
                    <span>{{ $fila['etiqueta'] }} <span class="pt-muted">(teclea el peso primero)</span></span>
                    <span class="pt-muted">{{ $fila['precio_texto'] }}</span>
                  </button>
                @else
                  <button type="button" wire:key="res-{{ $fila['producto_id'] }}-{{ $fila['presentacion_id'] ?? 'x' }}"
                    wire:click="{{ $fila['presentacion_id'] ? 'agregarPresentacion('.$fila['presentacion_id'].')' : 'agregarProducto('.$fila['producto_id'].')' }}"
                    x-on:click="feedback()">
                    <span>{{ $fila['etiqueta'] }}</span>
                    <span class="pt-muted">{{ $fila['precio_texto'] }}</span>
                  </button>
                @endif
              @empty
                <p class="pt-muted" style="padding:.75rem">Sin resultados.</p>
              @endforelse
            </div>
          @endif
        </div>

        <div class="pt-categorias">
          <button type="button" class="{{ $categoriaId === null ? 'pt-primario' : '' }}" wire:click="seleccionarCategoria(null)">Todas</button>
          @foreach ($this->categorias() as $categoria)
            <button type="button" wire:key="cat-{{ $categoria->id }}" class="{{ $categoriaId === $categoria->id ? 'pt-primario' : '' }}"
              wire:click="seleccionarCategoria({{ $categoria->id }})">{{ $categoria->nombre }}</button>
          @endforeach
        </div>

        <div class="pt-productos">
          @foreach ($this->productosGrid() as $producto)
            <button type="button" wire:key="prod-{{ $producto['id'] }}" x-on:click="tocar({{ $producto['id'] }})" @disabled($producto['agotado'])>
              <span>{{ $producto['nombre'] }}</span>
              <span class="pt-precio">
                {{ $producto['precio_texto'] }}
                @if ($producto['pesado']) <span class="pt-muted" style="font-size:.75rem">⚖</span> @endif
                @if ($producto['agotado']) <span class="pt-alerta" style="font-size:.75rem">agotado</span> @endif
              </span>
            </button>
          @endforeach
        </div>

        <div class="pt-teclado-ctn">
          <div>
            <div class="pt-display-buffer" x-text="buffer || '0'"></div>
            <div class="pt-modos">
              <button type="button" x-bind:class="modo === 'cantidad' && 'pt-primario'" x-on:click="modo = 'cantidad'">Cantidad</button>
              <button type="button" x-bind:class="modo === 'descuento' && 'pt-primario'" x-on:click="modo = 'descuento'">Desc. RD$</button>
            </div>
            <p class="pt-muted" style="font-size:.8rem;line-height:1.3">
              Teclea y toca un producto para agregar esa cantidad (o el peso), o selecciona una línea y pulsa ⏎.
            </p>
          </div>
          <div class="pt-teclado">
            @foreach (['1', '2', '3', '4', '5', '6', '7', '8', '9'] as $t)
              <button type="button" x-on:click="tecla('{{ $t }}')">{{ $t }}</button>
            @endforeach
            <button type="button" x-on:click="borrar()">C</button>
            <button type="button" x-on:click="tecla('0')">0</button>
            <button type="button" class="pt-primario" x-on:click="aplicar()" x-bind:disabled="$wire.lineaSeleccionada === null || buffer === ''">⏎</button>
            <button type="button" x-on:click="tecla('.')" style="grid-column: span 3">.</button>
          </div>
        </div>
      </section>
    </div>

    {{-- MODAL DE COBRO --}}
    <div class="pt-modal-fondo" x-show="cobrarAbierto" x-cloak x-transition.opacity>
      <div class="pt-modal" x-on:click.outside="cobrarAbierto = false">
        <div style="display:flex;justify-content:space-between;align-items:center;gap:1rem">
          <h2>Cobrar RD$ {{ number_format((float) $totales['total'], 2) }}</h2>
          <button type="button" x-on:click="cobrarAbierto = false" aria-label="Cerrar"><x-filament::icon icon="heroicon-o-x-mark" style="width:1.5rem;height:1.5rem" /></button>
        </div>

        <div class="pt-seccion">
          <label>Comprobante</label>
          <div class="pt-opciones">
            @foreach ($this->tiposComprobante() as $valor => $etiqueta)
              <button type="button" wire:key="tipo-{{ $valor }}" class="{{ $tipoComprobante === (string) $valor ? 'pt-primario' : '' }}"
                wire:click="$set('tipoComprobante', '{{ $valor }}')">{{ $etiqueta }}</button>
            @endforeach
          </div>
        </div>

        <div class="pt-seccion">
          <label>Cliente</label>
          @if ($this->clienteSeleccionado())
            <div style="display:flex;gap:.5rem;align-items:center;justify-content:space-between">
              <div>
                <strong>{{ $this->clienteSeleccionado()->nombre }}</strong>
                @if ($this->clienteSeleccionado()->documento)
                  <span class="pt-muted"> · {{ $this->clienteSeleccionado()->documento }}</span>
                @endif
              </div>
              <button type="button" style="padding:0 1rem" wire:click="quitarCliente">Quitar</button>
            </div>
          @else
            <strong style="display:block;margin-bottom:.35rem">{{ \App\Models\Venta::ETIQUETA_AL_PORTADOR }}</strong>
            <input type="text" placeholder="Buscar cliente: nombre, RNC o cédula (opcional)..." wire:model.live.debounce.300ms="busquedaCliente" />
            @if ($busquedaCliente !== '')
              <div style="margin-top:.5rem;display:grid;gap:.35rem">
                @forelse ($this->clientesSugeridos() as $cliente)
                  <button type="button" wire:key="cli-{{ $cliente->id }}" style="text-align:left;padding:0 .8rem" wire:click="seleccionarCliente({{ $cliente->id }})">
                    {{ $cliente->nombre }} <span class="pt-muted">{{ $cliente->documento }}</span>
                  </button>
                @empty
                  <button type="button" wire:click="buscarClienteEnDgii">Sin resultados locales — buscar en DGII/JCE</button>
                @endforelse
              </div>
            @endif
          @endif
          @if ($this->mensajeFaltaRncComprador())
            <p class="pt-alerta" style="margin-top:.5rem">{{ $this->mensajeFaltaRncComprador() }}</p>
          @endif
        </div>

        <div class="pt-seccion">
          <label>Forma de pago</label>
          <div class="pt-opciones">
            @foreach ($this->formasPago() as $valor => $etiqueta)
              <button type="button" wire:key="fp-{{ $valor }}" class="{{ $formaPago === $valor ? 'pt-primario' : '' }}"
                wire:click="$set('formaPago', '{{ $valor }}')">{{ $etiqueta }}</button>
            @endforeach
          </div>
        </div>

        @if ($this->descuentosDisponibles()->isNotEmpty())
          <div class="pt-seccion">
            <label>Descuento global</label>
            <select wire:model.live="descuentoId">
              <option value="">Sin descuento</option>
              @foreach ($this->descuentosDisponibles() as $descuento)
                <option value="{{ $descuento->id }}">{{ $descuento->nombre }} ({{ number_format((float) $descuento->porcentaje, 2) }}%)</option>
              @endforeach
            </select>
          </div>
        @endif

        @if ($formaPago === \App\Enums\FormaPago::EFECTIVO->value)
          <div class="pt-seccion" x-data="{ total: {{ (float) $totales['total'] }} }">
            <label>Efectivo recibido</label>
            <input type="number" inputmode="decimal" min="0" step="0.01" x-model="recibido" placeholder="0.00" />
            <div class="pt-billetes">
              @foreach ([100, 200, 500, 1000, 2000] as $billete)
                <button type="button" x-on:click="recibido = String((parseFloat(recibido || '0') + {{ $billete }}).toFixed(2))">+{{ number_format($billete) }}</button>
              @endforeach
              <button type="button" x-on:click="recibido = total.toFixed(2)">Exacto</button>
              <button type="button" x-on:click="recibido = ''" style="grid-column: span 2">Limpiar</button>
            </div>
            <p style="margin-top:.6rem;font-size:1.3rem;font-weight:700">
              Cambio: <span class="pt-num" x-text="'RD$ ' + cambio(total).toLocaleString('es-DO', { minimumFractionDigits: 2, maximumFractionDigits: 2 })"></span>
            </p>
          </div>
        @endif

        <button type="button" class="pt-exito" style="width:100%;min-height:72px;font-size:1.4rem;margin-top:1.25rem"
          wire:click="cobrar" wire:loading.attr="disabled" wire:target="cobrar" @disabled(! $this->puedeCobrar())>
          <span wire:loading.remove wire:target="cobrar">CONFIRMAR COBRO</span>
          <span wire:loading wire:target="cobrar">Procesando…</span>
        </button>
      </div>
    </div>
  @endif
</div>
