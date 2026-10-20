<x-filament-panels::page>
  <div
    class="pos-screen"
    x-data="{ mostrarAyuda: false }"
    x-on:abrir-ticket.window="window.open($event.detail.url, '_blank')"
    x-on:producto-escaneado.window="$nextTick(() => $refs.buscadorProducto?.focus())"
  >
    @if ($this->arqueoAbierto() === null)
      {{-- Sin caja abierta: se bloquea la pantalla de venta hasta abrirla --}}
      <div class="card pos-apertura-caja" x-data="{ fondoInicial: '0.00' }">
        <h3 class="card-title">Abrir caja</h3>
        <p class="pos-muted">Debes abrir la caja con un fondo inicial antes de poder cobrar.</p>
        <div class="flex flex-wrap items-end gap-2 mt-2">
          <div>
            <label class="form-label">Fondo inicial</label>
            <input type="number" min="0" step="0.01" class="form-input" x-model="fondoInicial" />
          </div>
          <button type="button" class="btn btn-primary" x-on:click="$wire.abrirCaja(fondoInicial)">
            Abrir caja
          </button>
        </div>
      </div>
    @else
      <div class="pos-grid">
      <div class="pos-main">
        {{-- Fila superior compacta: buscar cliente + buscar producto, solo sobre el carrito --}}
        <div class="pos-top-row">
          <div class="card pos-buscador-compacto">
            <h3 class="card-title">Cliente</h3>

            @if ($this->clienteSeleccionado())
              <div class="flex items-center justify-between">
                <div>
                  <p class="font-semibold">{{ $this->clienteSeleccionado()->nombre }}</p>
                  @if ($this->clienteSeleccionado()->documento)
                    <p class="pos-muted">{{ $this->clienteSeleccionado()->documento }}</p>
                  @endif
                </div>
                <button type="button" class="btn btn-secondary" wire:click="quitarCliente">Cambiar</button>
              </div>

              @if ($this->mensajeFaltaRncComprador())
                <p class="mt-2 pos-alerta">{{ $this->mensajeFaltaRncComprador() }}</p>
              @endif
            @else
              <div class="space-y-2">
                <div class="flex gap-2">
                  <input
                    type="text"
                    class="form-input"
                    placeholder="Buscar cliente por nombre o documento... (F3)"
                    wire:model.live.debounce.300ms="busquedaCliente"
                    x-ref="buscadorCliente"
                  />
                  <button type="button" class="btn btn-secondary pos-nowrap" wire:click="seleccionarConsumidorFinal">
                    Consumidor Final
                  </button>
                </div>

                @if ($busquedaCliente !== '')
                  <ul class="pos-resultados">
                    @forelse ($this->clientesSugeridos() as $cliente)
                      <li>
                        <button type="button" wire:click="seleccionarCliente({{ $cliente->id }})">
                          <span>{{ $cliente->nombre }}</span>
                          @if ($cliente->documento)
                            <span class="pos-muted">{{ $cliente->documento }}</span>
                          @endif
                        </button>
                      </li>
                    @empty
                      <li class="pos-vacio">
                        Sin resultados locales.
                        <button type="button" class="btn btn-secondary pos-nowrap" wire:click="buscarClienteEnDgii">
                          Buscar en DGII/JCE
                        </button>
                      </li>
                    @endforelse
                  </ul>
                @endif
              </div>
            @endif

            <div class="mt-2">
              <label class="form-label">
                <input type="checkbox" wire:model.live="creditoFiscal" />
                ¿Con comprobante de crédito fiscal? (para el negocio del cliente)
              </label>
            </div>
          </div>

          <div class="card pos-buscador-compacto">
            <h3 class="card-title">Productos</h3>
            <input
              type="text"
              class="form-input"
              x-ref="buscadorProducto"
              placeholder="Buscar por código, nombre o escanear código de barras... (F2)"
              wire:model.live.debounce.300ms="busquedaProducto"
              wire:keydown.enter="escanearOBuscar($event.target.value)"
            />

            @if ($busquedaProducto !== '')
              <ul class="pos-resultados">
                @forelse ($this->resultadosBusqueda() as $fila)
                  <li>
                    @if ($fila['tipo'] === 'pesado')
                      <div class="flex items-center justify-between gap-2 pos-resultado-pesado" x-data="{ peso: '1.000' }">
                        <span>
                          {{ $fila['etiqueta'] }}
                          <span class="pos-muted">({{ $fila['precio_texto'] }})</span>
                        </span>
                        <span class="flex items-center gap-2">
                          <input type="number" min="0.001" step="0.001" class="form-input pos-input-sm" x-model="peso" />
                          <button
                            type="button"
                            class="btn btn-secondary pos-nowrap"
                            x-on:click="$wire.agregarProductoPorPeso({{ $fila['producto_id'] }}, peso)"
                          >
                            Agregar
                          </button>
                        </span>
                      </div>
                    @else
                      <button
                        type="button"
                        wire:click="{{ $fila['presentacion_id'] ? 'agregarPresentacion('.$fila['presentacion_id'].')' : 'agregarProducto('.$fila['producto_id'].')' }}"
                      >
                        <span>{{ $fila['etiqueta'] }}</span>
                        <span class="flex items-center gap-2 pos-muted">
                          {{ $fila['precio_texto'] }}
                          @if ($fila['controla_stock'])
                            <span class="badge {{ $fila['stock'] > 0 ? 'badge-success' : 'badge-danger' }}">
                              stock {{ number_format($fila['stock'], 2) }}
                            </span>
                          @endif
                        </span>
                      </button>
                    @endif
                  </li>
                @empty
                  <li class="pos-vacio">Sin resultados.</li>
                @endforelse
              </ul>
            @endif
          </div>
        </div>

        {{-- Carrito --}}
        <div class="card">
          <h3 class="card-title">Carrito</h3>
          <div class="overflow-x-auto">
            <table class="table">
              <thead>
                <tr>
                  <th>Descripción</th>
                  <th class="pos-num">Cantidad</th>
                  <th class="pos-num">Precio</th>
                  <th class="pos-num">Descuento</th>
                  <th class="pos-num">Subtotal</th>
                  <th>Acción</th>
                </tr>
              </thead>
              <tbody>
                @forelse ($carrito as $indice => $linea)
                  <tr wire:key="linea-{{ $indice }}">
                    <td>
                      {{ $linea['codigo'] }} — {{ $linea['nombre'] }}
                      @if ($this->lineaConStockInsuficiente($linea))
                        <div class="mt-1">
                          <span class="badge badge-danger">
                            Stock insuficiente (disponible {{ number_format((float) $this->stockDeLinea($linea), 2) }})
                          </span>
                        </div>
                      @endif
                    </td>
                    <td class="pos-num">
                      <input
                        type="number"
                        min="0"
                        step="1"
                        class="form-input"
                        wire:model.live="carrito.{{ $indice }}.cantidad"
                      />
                    </td>
                    <td class="pos-num">RD$ {{ number_format((float) $linea['precio_unitario'], 2) }}</td>
                    <td class="pos-num">
                      <input
                        type="number"
                        min="0"
                        step="0.01"
                        class="form-input"
                        wire:model.live.debounce.400ms="carrito.{{ $indice }}.descuento"
                      />
                    </td>
                    <td class="pos-num">RD$ {{ number_format((float) $this->subtotalLinea($linea), 2) }}</td>
                    <td>
                      <button type="button" class="btn btn-danger" wire:click="quitarLinea({{ $indice }})">
                        Quitar
                      </button>
                    </td>
                  </tr>
                @empty
                  <tr>
                    <td colspan="6" class="text-center pos-muted">
                      Agrega productos al carrito buscándolos arriba.
                    </td>
                  </tr>
                @endforelse
              </tbody>
            </table>
          </div>
        </div>
      </div>

      <div class="pos-side">
        {{-- Totales --}}
        <div class="card pos-totales">
          <h3 class="card-title">Totales</h3>

          <div class="mb-2">
            <label class="form-label">Descuento global</label>
            <select class="form-select" wire:model.live="descuentoId" x-ref="selectDescuento">
              <option value="">Sin descuento</option>
              @foreach ($this->descuentosDisponibles() as $descuento)
                <option value="{{ $descuento->id }}">{{ $descuento->nombre }} ({{ number_format((float) $descuento->porcentaje, 2) }}%)</option>
              @endforeach
            </select>
          </div>

          <dl>
            <div>
              <dt>Subtotal</dt>
              <dd>RD$ {{ number_format((float) $totales['subtotal'], 2) }}</dd>
            </div>
            <div>
              <dt>ITBIS 18%</dt>
              <dd>RD$ {{ number_format((float) $totales['itbis_18'], 2) }}</dd>
            </div>
            <div>
              <dt>ITBIS 16%</dt>
              <dd>RD$ {{ number_format((float) $totales['itbis_16'], 2) }}</dd>
            </div>
            <div>
              <dt>Descuento</dt>
              <dd>-RD$ {{ number_format((float) $totales['descuento'], 2) }}</dd>
            </div>
          </dl>

          <div class="pos-total-final">
            <span class="pos-total-label">Total</span>
            <span class="pos-total-valor">RD$ {{ number_format((float) $totales['total'], 2) }}</span>
          </div>

          @if ($this->hayLineasConStockInsuficiente())
            <p class="mt-2 pos-alerta">
              Hay líneas con stock insuficiente; corrígelas para poder cobrar.
            </p>
          @endif

          <div class="mt-2">
            <label class="form-label">Forma de pago</label>
            <select class="form-select" wire:model.live="formaPago" x-ref="selectFormaPago">
              @foreach ($this->formasPago() as $valor => $etiqueta)
                <option value="{{ $valor }}">{{ $etiqueta }}</option>
              @endforeach
            </select>
          </div>

          @if ($formaPago === \App\Enums\FormaPago::EFECTIVO->value)
            <div class="mt-2">
              <label class="form-label">Monto recibido (efectivo)</label>
              <input type="number" step="0.01" min="0" class="form-input" wire:model.blur="montoEfectivo"
                x-ref="inputEfectivo" placeholder="{{ $totales['total'] ?? '0.00' }}" />
            </div>
            @if (bccomp($this->vueltoCalculado(), '0.00', 2) > 0)
              <div class="mt-1" style="font-weight:600;color:var(--success-500);font-size:1.1rem;">
                Vuelto: ${{ number_format((float) $this->vueltoCalculado(), 2) }}
              </div>
            @endif
          @endif

          @if ($formaPago === \App\Enums\FormaPago::MIXTO->value)
            <div class="mt-2" style="display:grid;grid-template-columns:1fr 1fr;gap:0.5rem;">
              <div>
                <label class="form-label">Efectivo</label>
                <input type="number" step="0.01" min="0" class="form-input" wire:model.blur="montoEfectivo" />
              </div>
              <div>
                <label class="form-label">Tarjeta</label>
                <input type="number" step="0.01" min="0" class="form-input" wire:model.blur="montoTarjeta" />
              </div>
            </div>
            @if (bccomp($this->vueltoCalculado(), '0.00', 2) > 0)
              <div class="mt-1" style="font-weight:600;color:var(--success-500);font-size:1.1rem;">
                Vuelto: ${{ number_format((float) $this->vueltoCalculado(), 2) }}
              </div>
            @endif
          @endif

          <div class="mt-2">
            <label class="form-label">
              <input type="checkbox" wire:model.live="ventaACredito" x-ref="checkCredito" />
              Vender a crédito (fiar al cliente)
            </label>
          </div>

          @if ($ventaACredito)
            <div class="mt-2">
              <label class="form-label">Fecha límite de pago</label>
              <input type="date" class="form-input" wire:model.live="fechaLimitePago" />
              <p style="color:var(--text-muted);font-size:0.8125rem;margin-top:0.25rem;">Si la dejas vacía, se usan 30 días desde hoy.</p>
            </div>
          @endif

          <button
            type="button"
            class="btn btn-primary btn-cobrar mt-2"
            wire:click="cobrar"
            x-ref="btnCobrar"
            @disabled(! $this->puedeCobrar())
          >
            Cobrar (F8)
          </button>
        </div>
      </div>
      </div>
    @endif

      {{-- Barra de atajos de teclado al pie --}}
      <div class="pos-shortcuts-bar">
        <span class="pos-shortcut"><kbd>F1</kbd> Ayuda</span>
        <span class="pos-shortcut"><kbd>F2</kbd> Producto</span>
        <span class="pos-shortcut"><kbd>F3</kbd> Cliente</span>
        <span class="pos-shortcut"><kbd>F4</kbd> Pago</span>
        <span class="pos-shortcut"><kbd>F5</kbd> Descuento</span>
        <span class="pos-shortcut"><kbd>F6</kbd> Efectivo</span>
        <span class="pos-shortcut"><kbd>F7</kbd> Crédito</span>
        <span class="pos-shortcut"><kbd>F8</kbd> Cobrar</span>
        <span class="pos-shortcut"><kbd>F9</kbd> Limpiar</span>
        <span class="pos-shortcut"><kbd>Esc</kbd> Cerrar</span>
      </div>

      {{-- Overlay de ayuda (F1) --}}
      <div class="pos-help-overlay" x-show="mostrarAyuda" x-transition.opacity x-cloak>
        <div class="pos-help-card" x-on:click.outside="mostrarAyuda = false">
          <h3>Atajos de teclado</h3>
          <div class="pos-help-grid">
            <div class="pos-help-section">
              <h4>Acciones rápidas</h4>
              <dl>
                <div><dt><kbd>F2</kbd></dt><dd>Buscar producto</dd></div>
                <div><dt><kbd>F3</kbd></dt><dd>Buscar cliente</dd></div>
                <div><dt><kbd>F4</kbd></dt><dd>Cambiar forma de pago</dd></div>
                <div><dt><kbd>F5</kbd></dt><dd>Descuento global</dd></div>
                <div><dt><kbd>F6</kbd></dt><dd>Monto efectivo</dd></div>
                <div><dt><kbd>F7</kbd></dt><dd>Venta a crédito</dd></div>
                <div><dt><kbd>F8</kbd></dt><dd>Cobrar</dd></div>
                <div><dt><kbd>F9</kbd></dt><dd>Limpiar carrito</dd></div>
              </dl>
            </div>
            <div class="pos-help-section">
              <h4>Navegación</h4>
              <dl>
                <div><dt><kbd>↑</kbd> <kbd>↓</kbd></dt><dd>Navegar resultados</dd></div>
                <div><dt><kbd>Enter</kbd></dt><dd>Seleccionar / escanear</dd></div>
                <div><dt><kbd>Esc</kbd></dt><dd>Cerrar búsqueda</dd></div>
                <div><dt><kbd>Supr</kbd></dt><dd>Quitar última línea</dd></div>
              </dl>
            </div>
          </div>
          <p class="pos-help-footer">Presiona <kbd>F1</kbd> o <kbd>Esc</kbd> para cerrar</p>
        </div>
      </div>
  </div>

  @pushOnce('scripts')
  <script>
    document.addEventListener('DOMContentLoaded', function() {
      // Colapsar sidebar al entrar al POS
      if (typeof Alpine !== 'undefined' && Alpine.store('sidebar')) {
        var sidebarAntes = Alpine.store('sidebar').isOpen;
        Alpine.store('sidebar').close();
        document.addEventListener('livewire:navigate', function() {
          if (sidebarAntes && Alpine.store('sidebar')) Alpine.store('sidebar').open();
        }, { once: true });
      }
    });

    // Atajos de teclado POS — listener global
    document.addEventListener('keydown', function(ev) {
      var posScreen = document.querySelector('.pos-screen');
      if (!posScreen) return;
      if (document.querySelector('[role=dialog]')) return;

      var tag = ev.target.tagName;
      var enInput = (tag === 'INPUT' || tag === 'TEXTAREA' || tag === 'SELECT');

      var fDirectas = {F1:1,F2:1,F3:1,F4:1,F5:1,F6:1,F7:1,F8:1,F9:1};
      var ahkMapa = {Digit1:'F1',Digit2:'F2',Digit3:'F3',Digit4:'F4',Digit5:'F5',Digit6:'F6',Digit7:'F7',Digit8:'F8',Digit9:'F9'};
      var atajo = null;

      if (fDirectas[ev.key]) {
        atajo = ev.key;
      } else if (ev.ctrlKey && ev.altKey && ev.shiftKey) {
        atajo = ahkMapa[ev.code] || null;
      }

      var alpineData = Alpine.$data(posScreen);

      if (atajo) {
        ev.preventDefault();
        ev.stopPropagation();
        switch (atajo) {
          case 'F1': alpineData.mostrarAyuda = !alpineData.mostrarAyuda; break;
          case 'F2': alpineData.mostrarAyuda = false; posScreen.querySelector('[x-ref=buscadorProducto]')?.focus(); break;
          case 'F3': alpineData.mostrarAyuda = false; posScreen.querySelector('[x-ref=buscadorCliente]')?.focus(); break;
          case 'F4':
            var sp = posScreen.querySelector('[x-ref=selectFormaPago]');
            if (sp) {
              sp.selectedIndex = (sp.selectedIndex + 1) % sp.options.length;
              sp.dispatchEvent(new Event('change', { bubbles: true }));
              sp.dispatchEvent(new Event('input', { bubbles: true }));
            }
            break;
          case 'F5': posScreen.querySelector('[x-ref=selectDescuento]')?.focus(); break;
          case 'F6': posScreen.querySelector('[x-ref=inputEfectivo]')?.focus(); break;
          case 'F7': posScreen.querySelector('[x-ref=checkCredito]')?.click(); break;
          case 'F8': posScreen.querySelector('[x-ref=btnCobrar]')?.click(); break;
          case 'F9':
            if (confirm('¿Limpiar todo el carrito?')) {
              var wireEl = posScreen.closest('[wire\\:id]');
              if (wireEl) Livewire.find(wireEl.getAttribute('wire:id')).call('limpiarCarrito');
            }
            break;
        }
        return;
      }

      var buscadorProducto = posScreen.querySelector('[x-ref=buscadorProducto]');
      var buscadorCliente = posScreen.querySelector('[x-ref=buscadorCliente]');

      switch (ev.key) {
        case 'Escape':
          if (alpineData.mostrarAyuda) { alpineData.mostrarAyuda = false; ev.preventDefault(); break; }
          if (enInput) {
            ev.target.value = '';
            ev.target.dispatchEvent(new Event('input', { bubbles: true }));
            ev.preventDefault();
          }
          break;
        case 'ArrowDown':
        case 'ArrowUp':
          var dir = ev.key === 'ArrowDown' ? 1 : -1;
          if (buscadorProducto === document.activeElement) {
            ev.preventDefault();
          } else if (buscadorCliente === document.activeElement) {
            ev.preventDefault();
          }
          break;
        case 'Delete':
          if (!enInput) {
            ev.preventDefault();
            var wireEl = posScreen.closest('[wire\\:id]');
            if (wireEl) Livewire.find(wireEl.getAttribute('wire:id')).call('quitarUltimaLinea');
          }
          break;
      }
    });
  </script>
  @endPushOnce
</x-filament-panels::page>
