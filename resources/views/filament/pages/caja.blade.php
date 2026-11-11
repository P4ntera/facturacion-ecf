<x-filament-panels::page>
  <div
    class="pos-screen"
    x-data="{ modalCobro: false }"
    x-on:abrir-ticket.window="modalCobro = false; window.open($event.detail.url, '_blank')"
    x-on:producto-escaneado.window="$nextTick(() => $refs.buscadorProducto?.focus())"
    x-on:keydown.escape.window="modalCobro = false"
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
                    placeholder="Buscar cliente por nombre o documento..."
                    wire:model.live.debounce.300ms="busquedaCliente"
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
          @if (empty($carrito))
            <div class="pos-carrito-vacio">
              <svg xmlns="http://www.w3.org/2000/svg" width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">
                <circle cx="8" cy="21" r="1"/><circle cx="19" cy="21" r="1"/>
                <path d="M2.05 2.05h2l2.66 12.42a2 2 0 0 0 2 1.58h9.78a2 2 0 0 0 1.95-1.57l1.65-7.43H5.12"/>
              </svg>
              <p>Escanea un código de barras o busca productos arriba para comenzar.</p>
            </div>
          @else
            <h3 class="card-title">Carrito ({{ count($carrito) }})</h3>
            <div class="overflow-x-auto">
              <table class="table">
                <thead>
                  <tr>
                    <th>Descripción</th>
                    <th class="pos-num">Cant.</th>
                    <th class="pos-num">Precio</th>
                    <th class="pos-num">Desc.</th>
                    <th class="pos-num">Subtotal</th>
                    <th></th>
                  </tr>
                </thead>
                <tbody>
                  @foreach ($carrito as $indice => $linea)
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
                  @endforeach
                </tbody>
              </table>
            </div>
          @endif
        </div>
      </div>

      <div class="pos-side">
        {{-- Totales --}}
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

          <button
            type="button"
            class="btn btn-cobrar-caja mt-2"
            x-on:click="modalCobro = true"
            @disabled(empty($carrito) || $this->hayLineasConStockInsuficiente())
          >
            Cobrar — RD$ {{ number_format((float) ($totales['total'] ?? 0), 2) }}
          </button>
        </div>
      </div>
      </div>

      {{-- ============ MODAL DE COBRO ============ --}}
      <div
        class="pos-modal-overlay"
        x-show="modalCobro"
        x-cloak
        x-transition:enter="pos-modal-enter"
        x-transition:enter-start="pos-modal-enter-start"
        x-transition:enter-end="pos-modal-enter-end"
        x-transition:leave="pos-modal-leave"
        x-transition:leave-start="pos-modal-leave-start"
        x-transition:leave-end="pos-modal-leave-end"
        x-on:click.self="modalCobro = false"
      >
        <div class="pos-modal" x-on:click.stop>
          <div class="pos-modal-header">
            <h3>Cobrar</h3>
            <button type="button" class="pos-modal-close" x-on:click="modalCobro = false">&times;</button>
          </div>

          <div class="pos-modal-body">
            {{-- Resumen del total --}}
            <div class="pos-modal-total">
              <span>Total a cobrar</span>
              <span class="pos-total-valor">RD$ {{ number_format((float) $totales['total'], 2) }}</span>
            </div>

            {{-- Forma de pago --}}
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
                <input type="number" step="0.01" min="0" max="99999999.99" class="form-input" wire:model.live.debounce.400ms="montoEfectivo"
                  placeholder="{{ $totales['total'] ?? '0.00' }}" />
              </div>
              @if (bccomp($this->vueltoCalculado(), '0.00', 2) > 0)
                <div class="pos-modal-vuelto">
                  Vuelto: RD$ {{ number_format((float) $this->vueltoCalculado(), 2) }}
                </div>
              @endif
            @endif

            @if ($formaPago === \App\Enums\FormaPago::MIXTO->value)
              <div class="mt-2" style="display:grid;grid-template-columns:1fr 1fr;gap:0.5rem;">
                <div>
                  <label class="form-label">Efectivo</label>
                  <input type="number" step="0.01" min="0" max="99999999.99" class="form-input" wire:model.live.debounce.400ms="montoEfectivo" />
                </div>
                <div>
                  <label class="form-label">Tarjeta</label>
                  <input type="number" step="0.01" min="0" max="99999999.99" class="form-input" wire:model.live.debounce.400ms="montoTarjeta" />
                </div>
              </div>
              @if (bccomp($this->vueltoCalculado(), '0.00', 2) > 0)
                <div class="pos-modal-vuelto">
                  Vuelto: RD$ {{ number_format((float) $this->vueltoCalculado(), 2) }}
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
          </div>

          <div class="pos-modal-footer">
            <button
              type="button"
              class="btn btn-secondary"
              x-on:click="modalCobro = false"
            >
              Cancelar
            </button>
            <button
              type="button"
              class="btn btn-cobrar-caja"
              wire:click="cobrar"
              @disabled(! $this->puedeCobrar())
            >
              Confirmar cobro — RD$ {{ number_format((float) ($totales['total'] ?? 0), 2) }}
            </button>
          </div>
        </div>
      </div>
    @endif
  </div>
</x-filament-panels::page>
