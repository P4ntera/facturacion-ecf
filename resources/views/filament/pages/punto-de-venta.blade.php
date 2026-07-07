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
  >
    <div class="grid grid-cols-1 gap-4 lg:grid-cols-3">
      <div class="space-y-4 lg:col-span-2">
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
              <button type="button" class="btn btn-secondary" wire:click="quitarCliente">Cambiar</button>
            </div>
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
                    <li class="pos-vacio">Sin resultados.</li>
                  @endforelse
                </ul>
              @endif
            </div>
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
              <label class="form-label">Próximo e-NCF</label>
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
            placeholder="Buscar por código o nombre..."
            wire:model.live.debounce.300ms="busquedaProducto"
          />

          @if ($busquedaProducto !== '')
            <ul class="pos-resultados">
              @forelse ($this->productosSugeridos() as $producto)
                <li>
                  <button type="button" wire:click="agregarProducto({{ $producto->id }})">
                    <span>{{ $producto->codigo }} — {{ $producto->nombre }}</span>
                    <span class="flex items-center gap-2 pos-muted">
                      RD$ {{ number_format((float) $producto->precio, 2) }}
                      @if ($producto->controla_stock)
                        <span class="badge {{ (float) $producto->stock > 0 ? 'badge-success' : 'badge-danger' }}">
                          stock {{ number_format((float) $producto->stock, 2) }}
                        </span>
                      @endif
                    </span>
                  </button>
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
                        min="0.001"
                        step="0.001"
                        class="form-input"
                        wire:model.live.debounce.400ms="carrito.{{ $indice }}.cantidad"
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

      {{-- Totales --}}
      <div class="space-y-4">
        <div class="card pos-totales">
          <h3 class="card-title">Totales</h3>

          <div class="mb-4">
            <label class="form-label">Descuento global</label>
            <input type="number" min="0" step="0.01" class="form-input" wire:model.live.debounce.400ms="descuentoGlobal" />
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
            <p class="mt-3 pos-alerta">
              Hay líneas con stock insuficiente; corrígelas para poder cobrar.
            </p>
          @endif

          <button
            type="button"
            class="btn btn-primary btn-cobrar mt-4"
            wire:click="cobrar"
            @disabled(! $this->puedeCobrar())
          >
            Cobrar
          </button>
        </div>
      </div>
    </div>
  </div>
</x-filament-panels::page>
