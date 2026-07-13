<div
    x-data="{
        activa: false,
        abrir(tecla) {
            this.activa = true;
            $wire.set('quickSearch', tecla ?? '');
            this.$nextTick(() => this.$refs.buscador?.focus());
        },
        cerrar() {
            this.activa = false;
            $wire.set('quickSearch', '');
        },
    }"
    x-on:keydown.window="
        if (
            ! activa
            && document.activeElement.tagName !== 'INPUT'
            && document.activeElement.tagName !== 'TEXTAREA'
            && document.activeElement.tagName !== 'SELECT'
            && $event.key.length === 1
            && ! $event.ctrlKey && ! $event.metaKey && ! $event.altKey
        ) { abrir($event.key); }
    "
    x-on:keydown.escape.window="if (activa) cerrar();"
>
    <div
        x-show="activa"
        x-cloak
        x-transition
        class="fi-input-wrapper mb-4 flex items-center gap-2 rounded-lg border border-gray-300 bg-white px-3 py-2 shadow-sm dark:border-gray-600 dark:bg-gray-900"
    >
        <x-heroicon-o-magnifying-glass class="h-5 w-5 shrink-0 text-gray-400" />

        <input
            x-ref="buscador"
            type="text"
            wire:model.live.debounce.300ms="quickSearch"
            placeholder="Escribe para buscar por proveedor o NCF…"
            class="flex-1 border-0 bg-transparent p-0 text-sm text-gray-950 focus:ring-0 dark:text-white"
        />

        <button
            type="button"
            x-on:click="cerrar()"
            class="shrink-0 text-gray-400 hover:text-gray-600 dark:hover:text-gray-300"
        >
            <x-heroicon-o-x-mark class="h-5 w-5" />
        </button>
    </div>

    {{ $this->table }}
</div>
