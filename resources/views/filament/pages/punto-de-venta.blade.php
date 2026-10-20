@use('Illuminate\Support\Facades\Storage')
<x-filament-panels::page>
  <div
    class="pos-screen"
    x-data
    x-init="
      /*
       * Paso 1: el POS es una pantalla de trabajo, así que entra con el sidebar colapsado
       * para aprovechar el ancho. Usamos el store global de Alpine que trae Filament
       * ($store.sidebar, ver vendor/filament/filament/resources/js/stores/sidebar.js) para
       * cerrarlo al montar esta página y restaurar el estado que tenía al salir de ella
       * (evento `livewire:navigate`, que Livewire dispara justo antes de navegar a otra
       * página). El botón de hamburguesa del topbar de Filament no se toca: sigue
       * funcionando en todo momento para reabrir el sidebar manualmente.
       */
      let posSidebarAbiertoAntes = $store.sidebar.isOpen;
      $store.sidebar.close();

      let posRestaurarSidebar = () => {
        if (posSidebarAbiertoAntes) {
          $store.sidebar.open();
        }
        document.removeEventListener('livewire:navigate', posRestaurarSidebar);
      };
      document.addEventListener('livewire:navigate', posRestaurarSidebar);
    "
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
      {{-- Caja abierta: solo estado informativo. El cierre se hace desde Arqueos de Caja. --}}
      <div class="card pos-estado-caja">
        <div class="flex items-center justify-between flex-wrap gap-2">
          <div>
            <span class="badge badge-success">Caja abierta</span>
            <span class="pos-muted">
              {{ $this->arqueoAbierto()->user?->name ?? 'Usuario' }} —
              desde {{ $this->arqueoAbierto()->abierto_en->format('d/m/Y H:i') }} —
              fondo RD$ {{ number_format((float) $this->arqueoAbierto()->fondo_inicial, 2) }}
            </span>
          </div>
        </div>
      </div>

      <div class="pos-grid">
      <div class="pos-main">
        {{-- Cliente --}}
        <div class="card">
          <h3 class="card-title">Cliente</h3>

          @if ($this->clienteSeleccionado())
            <div class="flex items-center justify-between">
              <div>
                <p class="font-semibold">{{ $this->clienteSeleccionado()->nombre }}</p>
                @if ($this->clienteSeleccionado()->documento)
                  <p class="pos-muted">{{ $this->clienteSeleccionado()->documento }}</p>
                @endif
              </div>
              <button type="button" class="btn btn-secondary" wire:click="quitarCliente">Quitar</button>
            </div>
          @else
            <div class="space-y-2">
              <p class="font-semibold">{{ \App\Models\Venta::ETIQUETA_AL_PORTADOR }}</p>
              <input
                type="text"
                class="form-input"
                placeholder="Buscar cliente por nombre o documento (opcional)..."
                wire:model.live.debounce.300ms="busquedaCliente"
              />

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

          @if ($this->mensajeFaltaRncComprador())
            <p class="mt-2 pos-alerta">{{ $this->mensajeFaltaRncComprador() }}</p>
          @endif
        </div>

        {{-- Comprobante --}}
        <div class="card">
          <h3 class="card-title">Comprobante</h3>
          <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
            <div>
              <label class="form-label">Tipo de comprobante</label>
              <select class="form-select" wire:model.live="tipoComprobante">
                @foreach ($this->tiposComprobante() as $valor => $etiqueta)
                  <option value="{{ $valor }}">{{ $etiqueta }}</option>
                @endforeach
              </select>
            </div>
            <div>
              <label class="form-label">Próximo NCF</label>
              <div>
                @if ($this->proximoNcf())
                  <span class="badge badge-success">{{ $this->proximoNcf() }}</span>
                @else
                  <span class="badge badge-danger">No disponible: carga un rango de NCF</span>
                @endif
              </div>
            </div>
          </div>
        </div>

        {{-- Buscar producto --}}
        <div class="card">
          <h3 class="card-title">Productos</h3>
          <input
            type="text"
            class="form-input"
            x-ref="buscadorProducto"
            placeholder="Buscar por código, nombre o escanear código de barras..."
            wire:model.live.debounce.300ms="busquedaProducto"
            wire:keydown.enter="escanearOBuscar($event.target.value)"
          />

          @if ($busquedaProducto !== '')
            <ul class="pos-resultados">
              @forelse ($this->resultadosBusqueda() as $fila)
                <li>
                  @if ($fila['tipo'] === 'pesado')
                    <div class="flex items-center justify-between gap-2 pos-resultado-pesado" x-data="{ peso: '1.000' }">
                      <span class="flex items-center gap-2">
                        @if ($fila['imagen'])
                          <img src="{{ Storage::disk('public')->url($fila['imagen']) }}" alt="" class="pos-thumb" />
                        @endif
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
                      <span class="flex items-center gap-2">
                        @if ($fila['imagen'])
                          <img src="{{ Storage::disk('public')->url($fila['imagen']) }}" alt="" class="pos-thumb" />
                        @endif
                        {{ $fila['etiqueta'] }}
                      </span>
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
                      @if (in_array($linea['producto_id'], $this->productosEnPromocion()))
                        <span class="badge badge-success" title="Promoción aplicada automáticamente">🏷️ Promo</span>
                      @endif
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
                        min="{{ $linea['tipo_venta'] === 'pesado' ? '0.001' : '1' }}"
                        step="{{ $linea['tipo_venta'] === 'pesado' ? '0.001' : '1' }}"
                        class="form-input"
                        wire:model.live.debounce.400ms="carrito.{{ $indice }}.cantidad"
                      />
                      @if ($linea['tipo_venta'] === 'pesado')
                        <span class="pos-muted">{{ $linea['unidad_base'] }}</span>
                      @endif
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

      {{-- Totales --}}
      <div class="pos-side">
        @if ($this->promocionesVigentes()->isNotEmpty())
          <div class="card mb-2" style="background: #f0fdf4; border: 1px solid #bbf7d0; border-radius: 8px; padding: 0.75rem;">
            <h4 style="font-size: 0.85rem; font-weight: 600; color: #166534; margin-bottom: 0.5rem;">🏷️ Promociones activas</h4>
            <ul style="list-style: none; padding: 0; margin: 0; font-size: 0.8rem;">
              @foreach ($this->promocionesVigentes() as $promo)
                <li style="padding: 0.2rem 0; color: #15803d;">
                  <strong>{{ $promo->nombre }}</strong>
                  <span style="color: #6b7280;">({{ $promo->tipo->etiqueta() }}{{ $promo->valor ? ' — ' . ($promo->tipo->value === 'porcentaje' ? $promo->valor . '%' : 'RD$ ' . number_format((float) $promo->valor, 2)) : '' }})</span>
                  <span style="color: #9ca3af; font-size: 0.75rem;">hasta {{ $promo->fecha_fin->format('d/m/Y') }}</span>
                </li>
              @endforeach
            </ul>
          </div>
        @endif

        <div class="card pos-totales">
          <h3 class="card-title">Totales</h3>

          <div class="mb-2">
            <label class="form-label">Descuento global</label>
            <select class="form-select" wire:model.live="descuentoId">
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
            <select class="form-select" wire:model.live="formaPago">
              @foreach ($this->formasPago() as $valor => $etiqueta)
                <option value="{{ $valor }}">{{ $etiqueta }}</option>
              @endforeach
            </select>
          </div>

          @if ($formaPago === \App\Enums\FormaPago::EFECTIVO->value)
            <div class="mt-2">
              <label class="form-label">Monto recibido (efectivo)</label>
              <input type="number" step="0.01" min="0" class="form-input" wire:model.blur="montoEfectivo"
                placeholder="{{ $totales['total'] ?? '0.00' }}" />
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
              <input type="checkbox" wire:model.live="ventaACredito" />
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
            @disabled(! $this->puedeCobrar())
          >
            Cobrar
          </button>
        </div>
      </div>
      </div>
    @endif
  </div>
</x-filament-panels::page>
