@php
  $empresa = $this->empresa();
  $config = $this->config();
  $presentacionActual = $this->presentacionActual();

  // El color de acento lo elige cada negocio: el texto que va encima se calcula (oscuro o blanco)
  // para que siempre se lea. Con el rosa por defecto, el blanco no llega al contraste mínimo (4.5:1).
  $luminancia = function (string $hex): float {
      $hex = ltrim($hex, '#');
      $hex = strlen($hex) === 3 ? $hex[0].$hex[0].$hex[1].$hex[1].$hex[2].$hex[2] : $hex;
      $canales = array_map(function (string $par): float {
          $c = hexdec($par) / 255;

          return $c <= 0.03928 ? $c / 12.92 : (($c + 0.055) / 1.055) ** 2.4;
      }, str_split(str_pad($hex, 6, '0'), 2));

      return 0.2126 * $canales[0] + 0.7152 * $canales[1] + 0.0722 * $canales[2];
  };
  $lumAccent = $luminancia((string) $colorAccent);
  $sobreAccent = (1.05 / ($lumAccent + 0.05)) >= (($lumAccent + 0.05) / 0.0565) ? '#ffffff' : '#14141f';
@endphp

<div
  class="pos-heladeria"
  x-data="{
    cobrarAbierto: false,
    recibido: '',
    colorPickerAbierto: false,
    colorTemp: @js($colorAccent),
    feedback() {
      navigator.vibrate?.(35);
      try {
        const ctx = this._ctx ??= new (window.AudioContext || window.webkitAudioContext)();
        const o = ctx.createOscillator(); const g = ctx.createGain();
        o.frequency.value = 1320; g.gain.value = 0.06;
        o.connect(g); g.connect(ctx.destination); o.start(); o.stop(ctx.currentTime + 0.07);
      } catch (e) {}
    },
    cambio(total) {
      const r = parseFloat(this.recibido || '0');
      return r > 0 ? Math.max(0, r - total) : 0;
    },
    pantallaCompleta() {
      if (document.fullscreenElement) { document.exitFullscreen?.(); }
      else { document.documentElement.requestFullscreen?.(); }
    },
  }"
  x-on:item-agregado.window="feedback()"
  x-on:venta-cobrada.window="cobrarAbierto = false; recibido = ''; if ($event.detail?.ticketUrl) { window.open($event.detail.ticketUrl, '_blank'); }"
  x-on:color-actualizado.window="colorPickerAbierto = false"
>
  <style>
    .pos-heladeria {
      --hel-primary: {{ $colorAccent }};
      --hel-on-primary: {{ $sobreAccent }};
      --hel-on-success: #052e16;
      --hel-bg: #1a1a2e;
      --hel-surface: #16213e;
      --hel-surface-hover: #1f3056;
      --hel-text: #eaeaea;
      --hel-text-muted: #a0a0b0;
      --hel-border: #2a2a4a;
      --hel-success: #22c55e;
      --hel-warning: #f59e0b;
      --hel-danger: #ef4444;
      font-family: 'Inter', system-ui, sans-serif;
      background: var(--hel-bg);
      color: var(--hel-text);
      min-height: 100vh;
    }

    .hel-grid { display: grid; grid-template-columns: 1fr 340px; height: 100vh; }
    @media (max-width: 768px) { .hel-grid { grid-template-columns: 1fr; } }

    .hel-main { overflow-y: auto; padding: 1rem; }
    .hel-sidebar {
      background: var(--hel-surface);
      border-left: 1px solid var(--hel-border);
      display: flex; flex-direction: column;
      overflow-y: auto;
    }

    .hel-tabs {
      display: flex; gap: 0.25rem; padding: 0.5rem;
      background: var(--hel-surface);
      border-bottom: 1px solid var(--hel-border);
    }
    .hel-tab {
      flex: 1; padding: 0.75rem; border-radius: 0.5rem; border: none;
      background: transparent; color: var(--hel-text-muted); cursor: pointer;
      font-size: 0.875rem; font-weight: 600; transition: all 0.15s;
      text-align: center;
    }
    .hel-tab:hover { background: var(--hel-surface-hover); color: var(--hel-text); }
    .hel-tab.active { background: var(--hel-primary); color: var(--hel-on-primary); }

    .hel-card {
      background: var(--hel-surface);
      border: 1px solid var(--hel-border);
      border-radius: 0.75rem;
      padding: 1rem;
      cursor: pointer;
      transition: all 0.15s;
      text-align: center;
      user-select: none;
      -webkit-user-select: none;
      -webkit-tap-highlight-color: transparent;
      /* Es un <button>: hereda tipografía y color en vez de los del navegador. */
      font: inherit; color: inherit; width: 100%; display: block;
    }
    .hel-card > span { display: block; }
    .hel-card > span.hel-color-dot { display: inline-block; }
    .hel-card:hover { border-color: var(--hel-primary); transform: translateY(-2px); }
    .hel-card:active { transform: scale(0.97); }
    .hel-card.selected { border-color: var(--hel-primary); box-shadow: 0 0 0 2px var(--hel-primary); }

    .hel-card-emoji { font-size: 2rem; margin-bottom: 0.25rem; }
    .hel-card-name { font-weight: 600; font-size: 0.9rem; }
    .hel-card-price { color: var(--hel-primary); font-weight: 700; font-size: 0.85rem; margin-top: 0.25rem; }
    .hel-card-meta { color: var(--hel-text-muted); font-size: 0.8rem; }

    .hel-color-dot {
      width: 2rem; height: 2rem; border-radius: 50%;
      display: inline-block; margin-bottom: 0.25rem;
      border: 2px solid transparent;
    }
    .hel-card.selected .hel-color-dot { border-color: white; }

    .hel-section-title {
      font-size: 1rem; font-weight: 700; margin: 0 0 0.75rem;
      color: var(--hel-text); display: flex; align-items: center; gap: 0.5rem;
    }
    .hel-badge {
      background: var(--hel-primary); color: var(--hel-on-primary);
      font-size: 0.75rem; padding: 0.15rem 0.5rem; border-radius: 1rem;
    }

    .hel-items-grid {
      display: grid;
      grid-template-columns: repeat(auto-fill, minmax(120px, 1fr));
      gap: 0.75rem;
    }
    @media (min-width: 1024px) {
      .hel-items-grid { grid-template-columns: repeat(auto-fill, minmax(140px, 1fr)); }
    }

    .hel-steps {
      display: flex; gap: 0.5rem; padding: 0 0.5rem; margin-bottom: 1rem;
      align-items: center;
    }
    .hel-step {
      padding: 0.4rem 0.75rem; border-radius: 2rem; font-size: 0.8rem;
      font-weight: 600; background: var(--hel-surface); color: var(--hel-text-muted);
      border: 1px solid var(--hel-border);
    }
    .hel-step.active { background: var(--hel-primary); color: var(--hel-on-primary); border-color: var(--hel-primary); }
    .hel-step.done { background: var(--hel-success); color: var(--hel-on-success); border-color: var(--hel-success); }
    .hel-step-arrow { color: var(--hel-text-muted); font-size: 0.8rem; }

    .hel-filter-chips {
      display: flex; gap: 0.5rem; flex-wrap: wrap; margin-bottom: 0.75rem;
    }
    .hel-chip {
      padding: 0.35rem 0.9rem; min-height: 2.75rem; border-radius: 1.5rem; border: 1px solid var(--hel-border);
      background: transparent; color: var(--hel-text-muted); cursor: pointer;
      font-size: 0.8rem; transition: all 0.15s;
    }
    .hel-chip:hover { border-color: var(--hel-primary); color: var(--hel-text); }
    .hel-chip.active { background: var(--hel-primary); color: var(--hel-on-primary); border-color: var(--hel-primary); }

    .hel-btn {
      padding: 0.75rem 1.25rem; border-radius: 0.5rem; border: none;
      font-weight: 700; cursor: pointer; font-size: 0.9rem;
      transition: all 0.15s; width: 100%; text-align: center;
      user-select: none; -webkit-user-select: none;
    }
    .hel-btn-primary { background: var(--hel-primary); color: var(--hel-on-primary); }
    .hel-btn-primary:hover { filter: brightness(1.1); }
    .hel-btn-primary:active { transform: scale(0.98); }
    .hel-btn-outline {
      background: transparent; border: 1px solid var(--hel-border);
      color: var(--hel-text-muted);
    }
    .hel-btn-outline:hover { border-color: var(--hel-primary); color: var(--hel-text); }
    .hel-btn-danger { background: var(--hel-danger); color: white; }
    .hel-btn-success { background: var(--hel-success); color: var(--hel-on-success); }

    /* Carrito */
    .hel-cart-header {
      padding: 1rem; border-bottom: 1px solid var(--hel-border);
      font-weight: 700; font-size: 1.1rem; display: flex;
      justify-content: space-between; align-items: center;
    }
    .hel-cart-items { flex: 1; overflow-y: auto; padding: 0.5rem; }
    .hel-cart-item {
      display: flex; align-items: center; gap: 0.5rem;
      padding: 0.5rem; border-radius: 0.5rem;
      border-bottom: 1px solid var(--hel-border);
    }
    .hel-cart-item:last-child { border-bottom: none; }
    .hel-cart-item-info { flex: 1; min-width: 0; }
    .hel-cart-item-name { font-size: 0.8rem; font-weight: 600; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
    .hel-cart-item-price { font-size: 0.75rem; color: var(--hel-primary); }
    .hel-cart-qty {
      display: flex; align-items: center; gap: 0.25rem;
    }
    .hel-cart-qty button {
      width: 2.75rem; height: 2.75rem; border-radius: 0.5rem; border: 1px solid var(--hel-border);
      background: var(--hel-surface-hover); color: var(--hel-text); cursor: pointer;
      display: flex; align-items: center; justify-content: center;
      font-size: 1rem; font-weight: 700;
    }
    .hel-cart-qty span { min-width: 1.5rem; text-align: center; font-size: 0.85rem; font-weight: 600; }
    .hel-cart-remove {
      background: none; border: none; color: var(--hel-danger); cursor: pointer;
      font-size: 1rem; padding: 0.25rem;
      min-width: 2.75rem; min-height: 2.75rem;
    }

    .hel-cart-footer {
      padding: 1rem; border-top: 1px solid var(--hel-border);
    }
    .hel-cart-total {
      display: flex; justify-content: space-between;
      font-size: 1.25rem; font-weight: 700; margin-bottom: 0.75rem;
    }

    .hel-toolbar {
      display: flex; align-items: center; justify-content: space-between;
      padding: 0.75rem 1rem;
      background: var(--hel-surface);
      border-bottom: 1px solid var(--hel-border);
    }
    .hel-toolbar-title {
      font-size: 1.25rem; font-weight: 800; margin: 0;
      color: var(--hel-primary);
    }
    .hel-toolbar-actions { display: flex; gap: 0.5rem; }
    .hel-toolbar-btn {
      width: 2.75rem; height: 2.75rem; border-radius: 0.5rem; border: 1px solid var(--hel-border);
      text-decoration: none;
      background: var(--hel-surface); color: var(--hel-text-muted); cursor: pointer;
      display: flex; align-items: center; justify-content: center; font-size: 1.1rem;
    }
    .hel-toolbar-btn:hover { border-color: var(--hel-primary); color: var(--hel-text); }

    /* Overlay modal cobrar */
    .hel-overlay {
      position: fixed; inset: 0; background: rgba(0,0,0,0.7);
      display: flex; align-items: center; justify-content: center; z-index: 50;
    }
    .hel-modal {
      background: var(--hel-surface); border-radius: 1rem; padding: 1.5rem;
      width: 90%; max-width: 400px;
    }
    .hel-modal-title { font-size: 1.25rem; font-weight: 700; margin-bottom: 1rem; text-align: center; }
    .hel-input {
      width: 100%; padding: 0.75rem; border-radius: 0.5rem;
      border: 1px solid var(--hel-border); background: var(--hel-bg);
      color: var(--hel-text); font-size: 1.1rem; text-align: center;
      margin-bottom: 0.75rem;
    }
    .hel-input:focus { border-color: var(--hel-primary); }

    .hel-empty {
      text-align: center; padding: 3rem 1rem;
      color: var(--hel-text-muted);
    }
    .hel-empty-emoji { font-size: 3rem; margin-bottom: 0.5rem; }

    /* Foco siempre visible (teclado, lector de código de barras, navegación por tabulador). */
    .pos-heladeria :focus-visible { outline: 3px solid #ffffff; outline-offset: 2px; }

    @media (prefers-reduced-motion: reduce) {
      .pos-heladeria *, .pos-heladeria *::before, .pos-heladeria *::after { transition: none !important; animation: none !important; }
      .hel-card:hover, .hel-card:active, .hel-btn-primary:active { transform: none; }
    }

    .hel-solo-lector { position: absolute; width: 1px; height: 1px; overflow: hidden; clip: rect(0 0 0 0); white-space: nowrap; }

    [x-cloak] { display: none !important; }
  </style>

  <div class="hel-grid">
    {{-- ====== PANEL PRINCIPAL ====== --}}
    <main style="display: flex; flex-direction: column;">
      {{-- Toolbar --}}
      <div class="hel-toolbar">
        <h1 class="hel-toolbar-title"><span aria-hidden="true">🍦</span> POS Heladería</h1>
        <div class="hel-toolbar-actions">
          @can('heladeria.configurar')
            <div style="position: relative;">
              <button type="button" class="hel-toolbar-btn" x-on:click="colorPickerAbierto = !colorPickerAbierto" title="Color del POS"
                      aria-label="Cambiar el color del POS" x-bind:aria-expanded="colorPickerAbierto.toString()">
                <span style="display:inline-block;width:1rem;height:1rem;border-radius:50%;background:var(--hel-primary);border:2px solid var(--hel-text-muted);"></span>
              </button>
              {{-- Color picker popover --}}
              <div x-show="colorPickerAbierto" x-on:click.outside="colorPickerAbierto = false"
                   x-transition.opacity
                   style="position:absolute;right:0;top:3rem;background:var(--hel-surface);border:1px solid var(--hel-border);border-radius:0.75rem;padding:1rem;z-index:60;width:220px;box-shadow:0 8px 24px rgba(0,0,0,0.4);">
                <div style="font-size:0.85rem;font-weight:700;margin-bottom:0.5rem;color:var(--hel-text);">🎨 Color accent</div>
                <input type="color" x-model="colorTemp" aria-label="Color personalizado" style="width:100%;height:2.5rem;border:none;border-radius:0.5rem;cursor:pointer;background:transparent;">
                {{-- Presets --}}
                <div style="display:flex;gap:0.35rem;margin-top:0.5rem;flex-wrap:wrap;">
                  @foreach(['#e85d9a','#8b5cf6','#3b82f6','#06b6d4','#22c55e','#f59e0b','#ef4444','#ec4899'] as $preset)
                    <button type="button" x-on:click="colorTemp = '{{ $preset }}'" aria-label="Usar el color {{ $preset }}"
                            style="width:2rem;height:2rem;border-radius:50%;border:2px solid {{ $preset === ($colorAccent) ? 'white' : 'transparent' }};background:{{ $preset }};cursor:pointer;"
                            title="{{ $preset }}"></button>
                  @endforeach
                </div>
                <button type="button" class="hel-btn hel-btn-primary" x-on:click="$wire.guardarColorAccent(colorTemp)"
                        style="margin-top:0.75rem;font-size:0.85rem;padding:0.5rem;">
                  ✓ Aplicar
                </button>
              </div>
            </div>
          @endcan
          <button type="button" class="hel-toolbar-btn" x-on:click="pantallaCompleta()" title="Pantalla completa" aria-label="Pantalla completa"><span aria-hidden="true">⛶</span></button>
          <a href="{{ \Filament\Pages\Dashboard::getUrl() }}" class="hel-toolbar-btn" title="Volver al panel" aria-label="Salir del POS y volver al panel"><span aria-hidden="true">✕</span></a>
        </div>
      </div>

      {{-- Tabs --}}
      <div class="hel-tabs" role="tablist" aria-label="Tipo de producto">
        <button type="button" role="tab" aria-selected="{{ $tab === 'armado' ? 'true' : 'false' }}" class="hel-tab {{ $tab === 'armado' ? 'active' : '' }}" wire:click="cambiarTab('armado')">
          🍦 Helado Armado
        </button>
        <button type="button" role="tab" aria-selected="{{ $tab === 'combos' ? 'true' : 'false' }}" class="hel-tab {{ $tab === 'combos' ? 'active' : '' }}" wire:click="cambiarTab('combos')">
          🍨 Combos
        </button>
        <button type="button" role="tab" aria-selected="{{ $tab === 'directo' ? 'true' : 'false' }}" class="hel-tab {{ $tab === 'directo' ? 'active' : '' }}" wire:click="cambiarTab('directo')">
          🍭 Productos
        </button>
      </div>

      <div class="hel-main">
        {{-- ============ TAB: HELADO ARMADO ============ --}}
        @if($tab === 'armado')
          {{-- Steps indicator --}}
          <div class="hel-steps" role="list" aria-label="Pasos para armar el helado">
            <span role="listitem" @if($paso === 'presentacion') aria-current="step" @endif class="hel-step {{ $paso === 'presentacion' ? 'active' : ($presentacionId ? 'done' : '') }}">1. Presentación</span>
            <span class="hel-step-arrow" aria-hidden="true">→</span>
            <span role="listitem" @if($paso === 'sabores') aria-current="step" @endif class="hel-step {{ $paso === 'sabores' ? 'active' : (!empty($saboresSeleccionados) && $paso === 'toppings' ? 'done' : '') }}">2. Sabores</span>
            <span class="hel-step-arrow" aria-hidden="true">→</span>
            <span role="listitem" @if($paso === 'toppings') aria-current="step" @endif class="hel-step {{ $paso === 'toppings' ? 'active' : '' }}">3. Toppings</span>
          </div>

          {{-- Paso 1: Presentaciones --}}
          @if($paso === 'presentacion')
            <h2 class="hel-section-title">Elige la presentación</h2>
            <div class="hel-items-grid">
              @foreach($this->presentaciones() as $p)
                <button type="button" class="hel-card" wire:click="seleccionarPresentacion({{ $p->id }})">
                  <span class="hel-card-emoji">{{ $p->icono }}</span>
                  <span class="hel-card-name">{{ $p->nombre }}</span>
                  <span class="hel-card-meta">{{ $p->scoops_incluidos }} scoop{{ $p->scoops_incluidos > 1 ? 's' : '' }}</span>
                  @if($p->toppings_incluidos > 0)
                    <span class="hel-card-meta">+{{ $p->toppings_incluidos }} topping{{ $p->toppings_incluidos > 1 ? 's' : '' }}</span>
                  @endif
                  <span class="hel-card-price">RD$ {{ number_format($p->precio, 0) }}</span>
                </button>
              @endforeach
            </div>
          @endif

          {{-- Paso 2: Sabores --}}
          @if($paso === 'sabores' && $presentacionActual)
            <h2 class="hel-section-title">
              Elige tus sabores
              <span class="hel-badge">{{ count($saboresSeleccionados) }}/{{ $presentacionActual->scoops_incluidos }}</span>
            </h2>

            {{-- Filtro por categoría --}}
            <div class="hel-filter-chips">
              <button type="button" aria-pressed="{{ !$filtroCategoria ? 'true' : 'false' }}" class="hel-chip {{ !$filtroCategoria ? 'active' : '' }}" wire:click="$set('filtroCategoria', null)">Todos</button>
              @foreach(\App\Enums\CategoriaSabor::cases() as $cat)
                <button type="button" aria-pressed="{{ $filtroCategoria === $cat->value ? 'true' : 'false' }}" class="hel-chip {{ $filtroCategoria === $cat->value ? 'active' : '' }}"
                        wire:click="$set('filtroCategoria', '{{ $cat->value }}')">
                  {{ $cat->etiqueta() }}
                </button>
              @endforeach
            </div>

            <div class="hel-items-grid">
              @foreach($this->sabores() as $s)
                <button type="button" class="hel-card {{ in_array($s->id, $saboresSeleccionados) ? 'selected' : '' }}"
                     aria-pressed="{{ in_array($s->id, $saboresSeleccionados) ? 'true' : 'false' }}"
                     wire:click="toggleSabor({{ $s->id }})">
                  <span class="hel-color-dot" style="background: {{ $s->color_hex }};"></span>
                  <span class="hel-card-name">{{ $s->nombre }}</span>
                  @if($s->premium)
                    <span class="hel-card-meta" style="color: var(--hel-warning);">★ Premium</span>
                  @endif
                </button>
              @endforeach
            </div>

            <div style="display: flex; gap: 0.5rem; margin-top: 1rem;">
              <button type="button" class="hel-btn hel-btn-outline" wire:click="resetBuilder" style="flex: 0 0 auto; width: auto; padding: 0.75rem 1rem;">
                ← Atrás
              </button>
              <button type="button" class="hel-btn hel-btn-primary" wire:click="irAToppings"
                      @if(empty($saboresSeleccionados)) disabled style="opacity: 0.5;" @endif>
                Siguiente: Toppings →
              </button>
            </div>
          @endif

          {{-- Paso 3: Toppings --}}
          @if($paso === 'toppings' && $presentacionActual)
            <h2 class="hel-section-title">
              Agrega toppings
              @if($presentacionActual->toppings_incluidos > 0)
                <span class="hel-badge">{{ $presentacionActual->toppings_incluidos }} gratis</span>
              @endif
            </h2>

            <div class="hel-items-grid">
              @foreach($this->toppings() as $t)
                @php
                  $esGratis = array_search($t->id, $toppingsSeleccionados) !== false
                              && array_search($t->id, $toppingsSeleccionados) < $presentacionActual->toppings_incluidos;
                @endphp
                <button type="button" class="hel-card {{ in_array($t->id, $toppingsSeleccionados) ? 'selected' : '' }}"
                     aria-pressed="{{ in_array($t->id, $toppingsSeleccionados) ? 'true' : 'false' }}"
                     wire:click="toggleTopping({{ $t->id }})">
                  <span class="hel-card-emoji">{{ $t->icono }}</span>
                  <span class="hel-card-name">{{ $t->nombre }}</span>
                  <span class="hel-card-price">
                    @if($esGratis)
                      <span style="color: var(--hel-success);">¡Gratis!</span>
                    @else
                      +RD$ {{ number_format($t->precio, 0) }}
                    @endif
                  </span>
                </button>
              @endforeach
            </div>

            <div style="display: flex; gap: 0.5rem; margin-top: 1rem;">
              <button type="button" class="hel-btn hel-btn-outline" wire:click="$set('paso', 'sabores')" style="flex: 0 0 auto; width: auto; padding: 0.75rem 1rem;">
                ← Atrás
              </button>
              <button type="button" class="hel-btn hel-btn-primary" wire:click="agregarArmadoAlCarrito">
                🛒 Agregar al carrito
              </button>
            </div>
          @endif
        @endif

        {{-- ============ TAB: COMBOS ============ --}}
        @if($tab === 'combos')
          <h2 class="hel-section-title">Combos disponibles</h2>
          <div class="hel-items-grid">
            @forelse($this->combos() as $c)
              <button type="button" class="hel-card" wire:click="agregarCombo({{ $c->id }})">
                <span class="hel-card-emoji">{{ $c->icono }}</span>
                <span class="hel-card-name">{{ $c->nombre }}</span>
                @if($c->descripcion)
                  <span class="hel-card-meta">{{ Str::limit($c->descripcion, 40) }}</span>
                @endif
                <span class="hel-card-meta">{{ $c->scoops_incluidos }}🍨 + {{ $c->toppings_incluidos }}🍫</span>
                <span class="hel-card-price">RD$ {{ number_format($c->precio, 0) }}</span>
              </button>
            @empty
              <div class="hel-empty" style="grid-column: 1 / -1;">
                <div class="hel-empty-emoji">🍨</div>
                <p>No hay combos configurados</p>
              </div>
            @endforelse
          </div>
        @endif

        {{-- ============ TAB: PRODUCTOS DIRECTOS ============ --}}
        @if($tab === 'directo')
          <h2 class="hel-section-title">Productos directos</h2>
          <div class="hel-items-grid">
            @forelse($this->productosDirectos() as $p)
              <button type="button" class="hel-card" wire:click="agregarProductoDirecto({{ $p->id }})">
                <span class="hel-card-emoji">🍭</span>
                <span class="hel-card-name">{{ $p->nombre }}</span>
                <span class="hel-card-price">RD$ {{ number_format($p->precio, 0) }}</span>
              </button>
            @empty
              <div class="hel-empty" style="grid-column: 1 / -1;">
                <div class="hel-empty-emoji">🍭</div>
                <p>No hay productos directos</p>
                <p style="font-size: 0.8rem;">Crea productos en el catálogo general para que aparezcan aquí</p>
              </div>
            @endforelse
          </div>
        @endif
      </div>
    </main>

    {{-- ====== SIDEBAR: CARRITO ====== --}}
    <aside class="hel-sidebar" aria-label="Carrito">
      <div class="hel-cart-header">
        <h2 style="margin: 0; font-size: inherit; font-weight: inherit;"><span aria-hidden="true">🛒</span> Carrito</h2>
        @if(!empty($carrito))
          <button type="button" class="hel-cart-remove" wire:click="vaciarCarrito" title="Vaciar carrito" aria-label="Vaciar el carrito"><span aria-hidden="true">🗑</span></button>
        @endif
      </div>

      <div class="hel-cart-items">
        @forelse($carrito as $index => $item)
          <div class="hel-cart-item">
            <div class="hel-cart-item-info">
              <div class="hel-cart-item-name" title="{{ $item['descripcion'] }}">{{ $item['descripcion'] }}</div>
              <div class="hel-cart-item-price">RD$ {{ number_format($item['precio'] * $item['cantidad'], 0) }}</div>
            </div>
            <div class="hel-cart-qty">
              <button type="button" wire:click="decrementarCantidad({{ $index }})" aria-label="Quitar uno de {{ $item['descripcion'] }}"><span aria-hidden="true">−</span></button>
              <span><span class="hel-solo-lector">Cantidad: </span>{{ $item['cantidad'] }}</span>
              <button type="button" wire:click="incrementarCantidad({{ $index }})" aria-label="Agregar uno más de {{ $item['descripcion'] }}"><span aria-hidden="true">+</span></button>
            </div>
            <button type="button" class="hel-cart-remove" wire:click="quitarDelCarrito({{ $index }})" aria-label="Quitar {{ $item['descripcion'] }} del carrito"><span aria-hidden="true">✕</span></button>
          </div>
        @empty
          <div class="hel-empty">
            <div class="hel-empty-emoji">🍦</div>
            <p>Carrito vacío</p>
            <p style="font-size: 0.8rem;">Selecciona helados, combos o productos</p>
          </div>
        @endforelse
      </div>

      <div class="hel-cart-footer">
        @php($totalesCarrito = $this->totalesCarrito())
        @if((float) $totalesCarrito['itbis'] > 0)
          <div style="display: flex; justify-content: space-between; font-size: 0.8rem; color: var(--hel-text-muted);">
            <span>Subtotal</span><span>RD$ {{ number_format((float) $totalesCarrito['subtotal'], 2) }}</span>
          </div>
          <div style="display: flex; justify-content: space-between; font-size: 0.8rem; color: var(--hel-text-muted); margin-bottom: 0.25rem;">
            <span>ITBIS</span><span>RD$ {{ number_format((float) $totalesCarrito['itbis'], 2) }}</span>
          </div>
        @endif
        <div class="hel-cart-total" aria-live="polite">
          <span>Total</span>
          <span style="color: var(--hel-primary);">RD$ {{ number_format($this->totalCarrito(), 2) }}</span>
        </div>

        @if(!empty($carrito))
          <button type="button" class="hel-btn hel-btn-primary" x-on:click="cobrarAbierto = true"
                  style="font-size: 1.1rem; padding: 1rem;">
            💳 Cobrar ({{ $this->cantidadItems() }} item{{ $this->cantidadItems() > 1 ? 's' : '' }})
          </button>
        @else
          <button type="button" class="hel-btn hel-btn-outline" disabled style="opacity: 0.4; cursor: not-allowed;">
            💳 Cobrar
          </button>
        @endif
      </div>
    </aside>
  </div>

  {{-- ====== MODAL COBRAR ====== --}}
  <div class="hel-overlay" x-show="cobrarAbierto" x-cloak x-on:click.self="cobrarAbierto = false"
       x-on:keydown.escape.window="cobrarAbierto = false"
       x-init="$watch('cobrarAbierto', abierto => abierto && $nextTick(() => ($refs.modalCobrar.querySelector('input') ?? $refs.modalCobrar.querySelector('button'))?.focus()))"
       x-transition:enter="transition ease-out duration-150"
       x-transition:enter-start="opacity-0" x-transition:enter-end="opacity-100"
       x-transition:leave="transition ease-in duration-100"
       x-transition:leave-start="opacity-100" x-transition:leave-end="opacity-0">
    <div class="hel-modal" x-on:click.stop x-ref="modalCobrar" role="dialog" aria-modal="true" aria-labelledby="hel-cobrar-titulo">
      <h2 class="hel-modal-title" id="hel-cobrar-titulo" style="margin-top: 0;"><span aria-hidden="true">💰</span> Cobrar venta</h2>

      <div style="text-align: center; margin-bottom: 1rem;">
        <div style="font-size: 0.85rem; color: var(--hel-text-muted);">Total a cobrar</div>
        <div style="font-size: 2rem; font-weight: 800; color: var(--hel-primary);">
          RD$ {{ number_format($this->totalCarrito(), 2) }}
        </div>
      </div>

      {{-- Comprobante: solo si la empresa tiene más de una opción (configuración fiscal) --}}
      @if(count($this->opcionesComprobante()) > 1)
        <div id="hel-lbl-comprobante" style="font-size: 0.85rem; color: var(--hel-text-muted); display: block; margin-bottom: 0.25rem;">
          Comprobante
        </div>
        <div role="group" aria-labelledby="hel-lbl-comprobante" style="display: flex; flex-wrap: wrap; gap: 0.5rem; margin-bottom: 0.75rem;">
          @foreach($this->opcionesComprobante() as $valor => $etiqueta)
            <button type="button" aria-pressed="{{ $comprobante === (string) $valor ? 'true' : 'false' }}"
              class="hel-chip {{ $comprobante === (string) $valor ? 'active' : '' }}"
              wire:click="$set('comprobante', '{{ $valor }}')"
              style="flex: 1 1 45%; text-align: center; padding: 0.5rem; font-size: 0.8rem;"
            >{{ $etiqueta }}</button>
          @endforeach
        </div>
      @endif

      {{-- Cliente: opcional en consumo, obligatorio en crédito fiscal --}}
      @if($this->conComprobante())
        <label for="hel-buscar-cliente" style="font-size: 0.85rem; color: var(--hel-text-muted); display: block; margin-bottom: 0.25rem;">
          Cliente (RNC/Cédula) — obligatorio para crédito fiscal
        </label>
        @if($cliente = $this->clienteSeleccionado())
          <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 0.75rem; font-size: 0.9rem;">
            <span><strong>{{ $cliente->nombre }}</strong> · {{ $cliente->documento ?: 'sin documento' }}</span>
            <button type="button" class="hel-chip" wire:click="quitarCliente" style="padding: 0.25rem 0.6rem;">Quitar</button>
          </div>
        @else
          <input type="text" id="hel-buscar-cliente" class="hel-input" wire:model.live.debounce.400ms="buscarCliente" placeholder="Buscar por nombre o RNC/cédula">
          <div style="margin-bottom: 0.75rem;">
            @foreach($this->clientesEncontrados() as $encontrado)
              <button type="button" class="hel-chip" wire:click="seleccionarCliente({{ $encontrado->id }})"
                      style="display: block; width: 100%; text-align: left; padding: 0.4rem 0.6rem; margin-top: 0.25rem; font-size: 0.85rem;">
                {{ $encontrado->nombre }} · {{ $encontrado->documento ?: 'sin documento' }}
              </button>
            @endforeach
          </div>
        @endif
      @endif

      {{-- Forma de pago --}}
      <div id="hel-lbl-forma-pago" style="font-size: 0.85rem; color: var(--hel-text-muted); display: block; margin-bottom: 0.25rem;">
        Forma de pago
      </div>
      <div role="group" aria-labelledby="hel-lbl-forma-pago" style="display: flex; gap: 0.5rem; margin-bottom: 0.75rem;">
        @foreach(\App\Enums\FormaPago::cases() as $fp)
          @if($fp !== \App\Enums\FormaPago::MIXTO)
            <button type="button" aria-pressed="{{ $formaPago === $fp->value ? 'true' : 'false' }}"
              class="hel-chip {{ $formaPago === $fp->value ? 'active' : '' }}"
              wire:click="$set('formaPago', '{{ $fp->value }}')"
              style="flex: 1; text-align: center; padding: 0.5rem;"
            >
              @if($fp === \App\Enums\FormaPago::EFECTIVO) 💵 @elseif($fp === \App\Enums\FormaPago::TARJETA) 💳 @else 🏦 @endif
              {{ $fp->etiqueta() }}
            </button>
          @endif
        @endforeach
      </div>

      {{-- Efectivo recibido (solo si es efectivo) --}}
      @if($formaPago === 'efectivo')
        <label for="hel-efectivo-recibido" style="font-size: 0.85rem; color: var(--hel-text-muted); display: block; margin-bottom: 0.25rem;">
          Efectivo recibido
        </label>
        <input
          type="number"
          id="hel-efectivo-recibido"
          class="hel-input"
          x-model="recibido"
          placeholder="0.00"
          inputmode="decimal"
          min="0"
          x-on:keydown.enter="$wire.cobrar(parseFloat(recibido || '0'))"
        >

        <div aria-live="polite" style="display: flex; justify-content: space-between; margin-bottom: 1rem; font-size: 0.9rem;">
          <span style="color: var(--hel-text-muted);">Cambio:</span>
          <span style="font-weight: 700; color: var(--hel-success);">
            RD$ <span x-text="cambio({{ $this->totalCarrito() }}).toFixed(2)"></span>
          </span>
        </div>
      @else
        <div style="text-align: center; padding: 1rem 0; margin-bottom: 0.75rem; color: var(--hel-text-muted); font-size: 0.9rem;">
          @if($formaPago === 'tarjeta') 💳 Pago con tarjeta @else 🏦 Pago por transferencia @endif
        </div>
      @endif

      <div style="display: flex; gap: 0.5rem;">
        <button type="button" class="hel-btn hel-btn-outline" x-on:click="cobrarAbierto = false; recibido = '';" style="flex: 1;">
          Cancelar
        </button>
        <button type="button" class="hel-btn hel-btn-success" x-on:click="$wire.cobrar(parseFloat(recibido || '0'))" style="flex: 1;">
          ✓ Confirmar
        </button>
      </div>
    </div>
  </div>
</div>
