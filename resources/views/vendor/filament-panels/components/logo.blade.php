@php
    $brandName = filament()->getBrandName();
    $empresa = \Filament\Facades\Filament::getTenant();
@endphp

<div {{ $attributes->class(['fi-logo', 'sidebar-brand']) }}>
    <span class="sidebar-brand-icono" aria-hidden="true">{{ Str::substr($brandName, 0, 1) }}</span>

    <span class="sidebar-brand-texto">
        <span class="sidebar-brand-nombre">{{ $brandName }}</span>

        @if ($empresa)
            <span class="sidebar-brand-subtitulo">{{ $empresa->getFilamentName() }}</span>
        @endif
    </span>
</div>
