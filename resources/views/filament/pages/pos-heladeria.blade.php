@php
  $empresa = $this->empresa();
  $config = $this->config();
  $presentacionActual = $this->presentacionActual();
  $total = $this->totalCarrito();
@endphp

<div
  class="pos-heladeria"
  x-data="{
    cobrarAbierto: false,
    recibido: '',
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
  x-on:venta-cobrada.window="cobrarAbierto = false; recibido = ''"
>
  <style>
    .pos-heladeria {
      --hel-primary: #e85d9a;
      --hel-bg: #1a1a2e;
      --hel-surface: #16213e;
      --hel-surface-hover: #1f3056;
      --hel-text: #eaeaea;
      --hel-text-muted: #a0a0b0;
      --hel-border: #2a2a4a;
      --hel-success: #22c55e;
      --hel-warning: #f59e0b;
      --hel-danger: #ef4444;
      font-family: 'Nunito', system-ui, sans-serif;
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
    .hel-tab.active { background: var(--hel-primary); color: white; }

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
    }
    .hel-card:hover { border-color: var(--hel-primary); transform: translateY(-2px); }
    .hel-card:active { transform: scale(0.97); }
    .hel-card.selected { border-color: var(--hel-primary); box-shadow: 0 0 0 2px var(--hel-primary); }

    .hel-card-emoji { font-size: 2rem; margin-bottom: 0.25rem; }
    .hel-card-name { font-weight: 600; font-size: 0.9rem; }
    .hel-card-price { color: var(--hel-primary); font-weight: 700; font-size: 0.85rem; margin-top: 0.25rem; }
    .hel-card-meta { color: var(--hel-text-muted); font-size: 0.75rem; }

    .hel-color-dot {
      width: 2rem; height: 2rem; border-radius: 50%;
      display: inline-block; margin-bottom: 0.25rem;
      border: 2px solid transparent;
    }
    .hel-card.selected .hel-color-dot { border-color: white; }

    .hel-section-title {
      font-size: 1rem; font-weight: 700; margin-bottom: 0.75rem;
      color: var(--hel-text); display: flex; align-items: center; gap: 0.5rem;
    }
    .hel-badge {
      background: var(--hel-primary); color: white;
      font-size: 0.7rem; padding: 0.15rem 0.5rem; border-radius: 1rem;
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
    .hel-step.active { background: var(--hel-primary); color: white; border-color: var(--hel-primary); }
    .hel-step.done { background: var(--hel-success); color: white; border-color: var(--hel-success); }
    .hel-step-arrow { color: var(--hel-text-muted); font-size: 0.8rem; }

    .hel-filter-chips {
      display: flex; gap: 0.5rem; flex-wrap: wrap; margin-bottom: 0.75rem;
    }
    .hel-chip {
      padding: 0.35rem 0.75rem; border-radius: 1.5rem; border: 1px solid var(--hel-border);
      background: transparent; color: var(--hel-text-muted); cursor: pointer;
      font-size: 0.8rem; transition: all 0.15s;
    }
    .hel-chip:hover { border-color: var(--hel-primary); color: var(--hel-text); }
    .hel-chip.active { background: var(--hel-primary); color: white; border-color: var(--hel-primary); }

    .hel-btn {
      padding: 0.75rem 1.25rem; border-radius: 0.5rem; border: none;
      font-weight: 700; cursor: pointer; font-size: 0.9rem;
      transition: all 0.15s; width: 100%; text-align: center;
      user-select: none; -webkit-user-select: none;
    }
    .hel-btn-primary { background: var(--hel-primary); color: white; }
    .hel-btn-primary:hover { filter: brightness(1.1); }
    .hel-btn-primary:active { transform: scale(0.98); }
    .hel-btn-outline {
      background: transparent; border: 1px solid var(--hel-border);
      color: var(--hel-text-muted);
    }
    .hel-btn-outline:hover { border-color: var(--hel-primary); color: var(--hel-text); }
    .hel-btn-danger { background: var(--hel-danger); color: white; }
    .hel-btn-success { background: var(--hel-success); color: white; }

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
      width: 1.75rem; height: 1.75rem; border-radius: 0.25rem; border: 1px solid var(--hel-border);
      background: var(--hel-surface-hover); color: var(--hel-text); cursor: pointer;
      display: flex; align-items: center; justify-content: center;
      font-size: 1rem; font-weight: 700;
    }
    .hel-cart-qty span { min-width: 1.5rem; text-align: center; font-size: 0.85rem; font-weight: 600; }
    .hel-cart-remove {
      background: none; border: none; color: var(--hel-danger); cursor: pointer;
      font-size: 1rem; padding: 0.25rem;
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
      font-size: 1.25rem; font-weight: 800;
      color: var(--hel-primary);
    }
    .hel-toolbar-actions { display: flex; gap: 0.5rem; }
    .hel-toolbar-btn {
      width: 2.5rem; height: 2.5rem; border-radius: 0.5rem; border: 1px solid var(--hel-border);
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
    .hel-input:focus { outline: none; border-color: var(--hel-primary); }

    .hel-empty {
      text-align: center; padding: 3rem 1rem;
      color: var(--hel-text-muted);
    }
    .hel-empty-emoji { font-size: 3rem; margin-bottom: 0.5rem; }
  </style>

  <div class="hel-grid">
    {{-- ====== PANEL PRINCIPAL ====== --}}
    <div style="display: flex; flex-direction: column;">
      {{-- Toolbar --}}
      <div class="hel-toolbar">
        <div class="hel-toolbar-title">🍦 POS Heladería</div>
        <div class="hel-toolbar-actions">
          <button class="hel-toolbar-btn" x-on:click="pantallaCompleta()" title="Pantalla completa">⛶</button>
          <a href="{{ \App\Filament\Pages\Dashboard::getUrl() }}" class="hel-toolbar-btn" title="Volver al panel">✕</a>
        </div>
      </div>

      {{-- Tabs --}}
      <div class="hel-tabs">
        <button class="hel-tab {{ $tab === 'armado' ? 'active' : '' }}" wire:click="cambiarTab('armado')">
          🍦 Helado Armado
        </button>
        <button class="hel-tab {{ $tab === 'combos' ? 'active' : '' }}" wire:click="cambiarTab('combos')">
          🍨 Combos
        </button>
        <button class="hel-tab {{ $tab === 'directo' ? 'active' : '' }}" wire:click="cambiarTab('directo')">
          🍭 Productos
        </button>
      </div>

      <div class="hel-main">
        {{-- ============ TAB: HELADO ARMADO ============ --}}
        @if($tab === 'armado')
          {{-- Steps indicator --}}
          <div class="hel-steps">
            <span class="hel-step {{ $paso === 'presentacion' ? 'active' : ($presentacionId ? 'done' : '') }}">1. Presentación</span>
            <span class="hel-step-arrow">→</span>
            <span class="hel-step {{ $paso === 'sabores' ? 'active' : (!empty($saboresSeleccionados) && $paso === 'toppings' ? 'done' : '') }}">2. Sabores</span>
            <span class="hel-step-arrow">→</span>
            <span class="hel-step {{ $paso === 'toppings' ? 'active' : '' }}">3. Toppings</span>
          </div>

          {{-- Paso 1: Presentaciones --}}
          @if($paso === 'presentacion')
            <div class="hel-section-title">Elige la presentación</div>
            <div class="hel-items-grid">
              @foreach($this->presentaciones() as $p)
                <div class="hel-card" wire:click="seleccionarPresentacion({{ $p->id }})">
                  <div class="hel-card-emoji">{{ $p->icono }}</div>
                  <div class="hel-card-name">{{ $p->nombre }}</div>
                  <div class="hel-card-meta">{{ $p->scoops_incluidos }} scoop{{ $p->scoops_incluidos > 1 ? 's' : '' }}</div>
                  @if($p->toppings_incluidos > 0)
                    <div class="hel-card-meta">+{{ $p->toppings_incluidos }} topping{{ $p->toppings_incluidos > 1 ? 's' : '' }}</div>
                  @endif
                  <div class="hel-card-price">RD$ {{ number_format($p->precio, 0) }}</div>
                </div>
              @endforeach
            </div>
          @endif

          {{-- Paso 2: Sabores --}}
          @if($paso === 'sabores' && $presentacionActual)
            <div class="hel-section-title">
              Elige tus sabores
              <span class="hel-badge">{{ count($saboresSeleccionados) }}/{{ $presentacionActual->scoops_incluidos }}</span>
            </div>

            {{-- Filtro por categoría --}}
            <div class="hel-filter-chips">
              <button class="hel-chip {{ !$filtroCategoria ? 'active' : '' }}" wire:click="$set('filtroCategoria', null)">Todos</button>
              @foreach(\App\Enums\CategoriaSabor::cases() as $cat)
                <button class="hel-chip {{ $filtroCategoria === $cat->value ? 'active' : '' }}"
                        wire:click="$set('filtroCategoria', '{{ $cat->value }}')">
                  {{ $cat->etiqueta() }}
                </button>
              @endforeach
            </div>

            <div class="hel-items-grid">
              @foreach($this->sabores() as $s)
                <div class="hel-card {{ in_array($s->id, $saboresSeleccionados) ? 'selected' : '' }}"
                     wire:click="toggleSabor({{ $s->id }})">
                  <div class="hel-color-dot" style="background: {{ $s->color_hex }};"></div>
                  <div class="hel-card-name">{{ $s->nombre }}</div>
                  @if($s->premium)
                    <div class="hel-card-meta" style="color: var(--hel-warning);">★ Premium</div>
                  @endif
                </div>
              @endforeach
            </div>

            <div style="display: flex; gap: 0.5rem; margin-top: 1rem;">
              <button class="hel-btn hel-btn-outline" wire:click="resetBuilder" style="flex: 0 0 auto; width: auto; padding: 0.75rem 1rem;">
                ← Atrás
              </button>
              <button class="hel-btn hel-btn-primary" wire:click="irAToppings"
                      @if(empty($saboresSeleccionados)) disabled style="opacity: 0.5;" @endif>
                Siguiente: Toppings →
              </button>
            </div>
          @endif

          {{-- Paso 3: Toppings --}}
          @if($paso === 'toppings' && $presentacionActual)
            <div class="hel-section-title">
              Agrega toppings
              @if($presentacionActual->toppings_incluidos > 0)
                <span class="hel-badge">{{ $presentacionActual->toppings_incluidos }} gratis</span>
              @endif
            </div>

            <div class="hel-items-grid">
              @foreach($this->toppings() as $t)
                @php
                  $esGratis = array_search($t->id, $toppingsSeleccionados) !== false
                              && array_search($t->id, $toppingsSeleccionados) < $presentacionActual->toppings_incluidos;
                @endphp
                <div class="hel-card {{ in_array($t->id, $toppingsSeleccionados) ? 'selected' : '' }}"
                     wire:click="toggleTopping({{ $t->id }})">
                  <div class="hel-card-emoji">{{ $t->icono }}</div>
                  <div class="hel-card-name">{{ $t->nombre }}</div>
                  <div class="hel-card-price">
                    @if($esGratis)
                      <span style="color: var(--hel-success);">¡Gratis!</span>
                    @else
                      +RD$ {{ number_format($t->precio, 0) }}
                    @endif
                  </div>
                </div>
              @endforeach
            </div>

            <div style="display: flex; gap: 0.5rem; margin-top: 1rem;">
              <button class="hel-btn hel-btn-outline" wire:click="$set('paso', 'sabores')" style="flex: 0 0 auto; width: auto; padding: 0.75rem 1rem;">
                ← Atrás
              </button>
              <button class="hel-btn hel-btn-primary" wire:click="agregarArmadoAlCarrito">
                🛒 Agregar al carrito
              </button>
            </div>
          @endif
        @endif

        {{-- ============ TAB: COMBOS ============ --}}
        @if($tab === 'combos')
          <div class="hel-section-title">Combos disponibles</div>
          <div class="hel-items-grid">
            @forelse($this->combos() as $c)
              <div class="hel-card" wire:click="agregarCombo({{ $c->id }})">
                <div class="hel-card-emoji">{{ $c->icono }}</div>
                <div class="hel-card-name">{{ $c->nombre }}</div>
                @if($c->descripcion)
                  <div class="hel-card-meta">{{ Str::limit($c->descripcion, 40) }}</div>
                @endif
                <div class="hel-card-meta">{{ $c->scoops_incluidos }}🍨 + {{ $c->toppings_incluidos }}🍫</div>
                <div class="hel-card-price">RD$ {{ number_format($c->precio, 0) }}</div>
              </div>
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
          <div class="hel-section-title">Productos directos</div>
          <div class="hel-items-grid">
            @forelse($this->productosDirectos() as $p)
              <div class="hel-card" wire:click="agregarProductoDirecto({{ $p->id }})">
                <div class="hel-card-emoji">🍭</div>
                <div class="hel-card-name">{{ $p->nombre }}</div>
                <div class="hel-card-price">RD$ {{ number_format($p->precio, 0) }}</div>
              </div>
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
    </div>

    {{-- ====== SIDEBAR: CARRITO ====== --}}
    <div class="hel-sidebar">
      <div class="hel-cart-header">
        <span>🛒 Carrito</span>
        @if(!empty($carrito))
          <button class="hel-cart-remove" wire:click="vaciarCarrito" title="Vaciar carrito">🗑</button>
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
              <button wire:click="decrementarCantidad({{ $index }})">−</button>
              <span>{{ $item['cantidad'] }}</span>
              <button wire:click="incrementarCantidad({{ $index }})">+</button>
            </div>
            <button class="hel-cart-remove" wire:click="quitarDelCarrito({{ $index }})">✕</button>
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
        <div class="hel-cart-total">
          <span>Total</span>
          <span style="color: var(--hel-primary);">RD$ {{ number_format($total, 2) }}</span>
        </div>

        @if(!empty($carrito))
          <button class="hel-btn hel-btn-primary" x-on:click="cobrarAbierto = true"
                  style="font-size: 1.1rem; padding: 1rem;">
            💳 Cobrar ({{ $this->cantidadItems() }} item{{ $this->cantidadItems() > 1 ? 's' : '' }})
          </button>
        @else
          <button class="hel-btn hel-btn-outline" disabled style="opacity: 0.4; cursor: not-allowed;">
            💳 Cobrar
          </button>
        @endif
      </div>
    </div>
  </div>

  {{-- ====== MODAL COBRAR ====== --}}
  <template x-if="cobrarAbierto">
    <div class="hel-overlay" x-on:click.self="cobrarAbierto = false">
      <div class="hel-modal" x-on:click.stop>
        <div class="hel-modal-title">💰 Cobrar venta</div>

        <div style="text-align: center; margin-bottom: 1rem;">
          <div style="font-size: 0.85rem; color: var(--hel-text-muted);">Total a cobrar</div>
          <div style="font-size: 2rem; font-weight: 800; color: var(--hel-primary);">
            RD$ {{ number_format($total, 2) }}
          </div>
        </div>

        <label style="font-size: 0.85rem; color: var(--hel-text-muted); display: block; margin-bottom: 0.25rem;">
          Efectivo recibido
        </label>
        <input
          type="number"
          class="hel-input"
          x-model="recibido"
          placeholder="0.00"
          inputmode="decimal"
          x-on:keydown.enter="$wire.cobrar()"
        >

        <div style="display: flex; justify-content: space-between; margin-bottom: 1rem; font-size: 0.9rem;">
          <span style="color: var(--hel-text-muted);">Cambio:</span>
          <span style="font-weight: 700; color: var(--hel-success);">
            RD$ <span x-text="cambio({{ $total }}).toFixed(2)"></span>
          </span>
        </div>

        <div style="display: flex; gap: 0.5rem;">
          <button class="hel-btn hel-btn-outline" x-on:click="cobrarAbierto = false; recibido = '';" style="flex: 1;">
            Cancelar
          </button>
          <button class="hel-btn hel-btn-success" wire:click="cobrar" style="flex: 1;">
            ✓ Confirmar
          </button>
        </div>
      </div>
    </div>
  </template>
</div>
