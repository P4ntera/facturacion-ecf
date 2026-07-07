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
                  <p class="text-sm" style="color:var(--text-muted)">{{ $this->clienteSeleccionado()->documento }}</p>
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
                <button type="button" class="btn btn-secondary" style="white-space:nowrap" wire:click="seleccionarConsumidorFinal">
                  Consumidor Final
                </button>
              </div>

              @if ($busquedaCliente !== '')
                <ul class="divide-y rounded" style="border:1px solid var(--border)">
                  @forelse ($this->clientesSugeridos() as $cliente)
                    <li>
                      <button
                        type="button"
                        class="flex w-full items-center justify-between px-3 py-2 text-left"
                        wire:click="seleccionarCliente({{ $cliente->id }})"
                      >
                        <span>{{ $cliente->nombre }}</span>
                        @if ($cliente->documento)
                          <span class="text-sm" style="color:var(--text-muted)">{{ $cliente->documento }}</span>
                        @endif
                      </button>
                    </li>
                  @empty
                    <li class="px-3 py-2 text-sm" style="color:var(--text-muted)">Sin resultados.</li>
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
              <p class="form-input" style="background-color:var(--surface-muted)">
                {{ $this->proximoNcf() ?? 'No disponible: carga un rango de NCF' }}
              </p>
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
            <ul class="mt-2 divide-y rounded" style="border:1px solid var(--border)">
              @forelse ($this->productosSugeridos() as $producto)
                <li>
                  <button
                    type="button"
                    class="flex w-full items-center justify-between px-3 py-2 text-left"
                    wire:click="agregarProducto({{ $producto->id }})"
                  >
                    <span>{{ $producto->codigo }} — {{ $producto->nombre }}</span>
                    <span class="text-sm" style="color:var(--text-muted)">
                      RD$ {{ $producto->precio }}
                      @if ($producto->controla_stock)
                        · stock {{ $producto->stock }}
                      @endif
                    </span>
                  </button>
                </li>
              @empty
                <li class="px-3 py-2 text-sm" style="color:var(--text-muted)">Sin resultados.</li>
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
                  <th>Cantidad</th>
                  <th>Precio</th>
                  <th>Descuento</th>
                  <th>Subtotal</th>
                  <th></th>
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
                            Stock insuficiente (disponible {{ $this->stockDeLinea($linea) }})
                          </span>
                        </div>
                      @endif
                    </td>
                    <td style="width:7rem">
                      <input
                        type="number"
                        min="0.001"
                        step="0.001"
                        class="form-input"
                        wire:model.live.debounce.400ms="carrito.{{ $indice }}.cantidad"
                      />
                    </td>
                    <td>{{ $linea['precio_unitario'] }}</td>
                    <td style="width:7rem">
                      <input
                        type="number"
                        min="0"
                        step="0.01"
                        class="form-input"
                        wire:model.live.debounce.400ms="carrito.{{ $indice }}.descuento"
                      />
                    </td>
                    <td>{{ $this->subtotalLinea($linea) }}</td>
                    <td>
                      <button type="button" class="btn btn-danger" wire:click="quitarLinea({{ $indice }})">
                        Quitar
                      </button>
                    </td>
                  </tr>
                @empty
                  <tr>
                    <td colspan="6" class="text-center" style="color:var(--text-muted)">
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
        <div class="card">
          <h3 class="card-title">Totales</h3>

          <div class="mb-4">
            <label class="form-label">Descuento global</label>
            <input type="number" min="0" step="0.01" class="form-input" wire:model.live.debounce.400ms="descuentoGlobal" />
          </div>

          <dl class="space-y-1 text-sm">
            <div class="flex items-center justify-between">
              <dt>Subtotal</dt>
              <dd>{{ $totales['subtotal'] }}</dd>
            </div>
            <div class="flex items-center justify-between">
              <dt>ITBIS 18%</dt>
              <dd>{{ $totales['itbis_18'] }}</dd>
            </div>
            <div class="flex items-center justify-between">
              <dt>ITBIS 16%</dt>
              <dd>{{ $totales['itbis_16'] }}</dd>
            </div>
            <div class="flex items-center justify-between">
              <dt>Descuento</dt>
              <dd>-{{ $totales['descuento'] }}</dd>
            </div>
          </dl>

          <div class="mt-3 flex items-center justify-between border-t pt-3" style="border-color:var(--border)">
            <span class="font-semibold" style="font-size:var(--fs-card)">Total</span>
            <span class="font-semibold" style="font-size:var(--fs-card)">{{ $totales['total'] }}</span>
          </div>

          @if ($this->hayLineasConStockInsuficiente())
            <p class="mt-3 text-sm" style="color:var(--danger)">
              Hay líneas con stock insuficiente; corrígelas para poder cobrar.
            </p>
          @endif

          <button
            type="button"
            class="btn btn-primary mt-4 w-full"
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
