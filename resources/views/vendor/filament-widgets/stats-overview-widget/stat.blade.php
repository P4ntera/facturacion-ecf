{{--
    Override de vendor/filament/widgets/resources/views/stats-overview-widget/stat.blade.php
    (Filament v4). Filament no expone forma nativa de pintar el ÍCONO de un Stat con un fondo de
    color suave (solo colorea la descripción y el mini-gráfico vía ->color()) — la única forma de
    lograrlo es este override. Único cambio respecto al original: el ícono se envuelve en un
    <span class="stat-icon-chip stat-icon-chip-{color}">, estilado en theme.css con los tokens del
    proyecto (--primary-100, --success-100, etc: la misma paleta Stitch en un solo lugar, no la
    paleta OKLCH interna de Filament). El resto del markup es una copia fiel del original: si
    Filament cambia esta vista en una actualización, hay que revisar el diff a mano.
--}}
@php
    use Filament\Support\Enums\IconPosition;
    use Filament\Widgets\View\Components\StatsOverviewWidgetComponent\StatComponent\DescriptionComponent;
    use Filament\Widgets\View\Components\StatsOverviewWidgetComponent\StatComponent\StatsOverviewWidgetStatChartComponent;
    use Illuminate\View\ComponentAttributeBag;

    $chartColor = $getChartColor() ?? 'gray';
    $descriptionColor = $getDescriptionColor() ?? 'gray';
    $descriptionIcon = $getDescriptionIcon();
    $descriptionIconPosition = $getDescriptionIconPosition();
    $url = $getUrl();
    $tag = $url ? 'a' : 'div';
    $chartDataChecksum = $generateChartDataChecksum();
    $color = $getColor() ?? 'gray';
@endphp

<{!! $tag !!}
    @if ($url)
        {{ \Filament\Support\generate_href_html($url, $shouldOpenUrlInNewTab()) }}
    @endif
    {{
        $getExtraAttributeBag()
            ->class([
                'fi-wi-stats-overview-stat',
            ])
    }}
>
    <div class="fi-wi-stats-overview-stat-content">
        <div class="fi-wi-stats-overview-stat-label-ctn">
            @if ($icon = $getIcon())
                <span class="stat-icon-chip stat-icon-chip-{{ $color }}">
                    {{ \Filament\Support\generate_icon_html($icon) }}
                </span>
            @endif

            <span class="fi-wi-stats-overview-stat-label">
                {{ $getLabel() }}
            </span>
        </div>

        <div class="fi-wi-stats-overview-stat-value">
            {{ $getValue() }}
        </div>

        @if ($description = $getDescription())
            <div
                {{ (new ComponentAttributeBag)->color(DescriptionComponent::class, $descriptionColor)->class(['fi-wi-stats-overview-stat-description']) }}
            >
                @if ($descriptionIcon && in_array($descriptionIconPosition, [IconPosition::Before, 'before']))
                    {{ \Filament\Support\generate_icon_html($descriptionIcon, attributes: (new \Illuminate\View\ComponentAttributeBag)) }}
                @endif

                <span>
                    {{ $description }}
                </span>

                @if ($descriptionIcon && in_array($descriptionIconPosition, [IconPosition::After, 'after']))
                    {{ \Filament\Support\generate_icon_html($descriptionIcon, attributes: (new \Illuminate\View\ComponentAttributeBag)) }}
                @endif
            </div>
        @endif
    </div>

    @if ($chart = $getChart())
        {{-- An empty function to initialize the Alpine component with until it's loaded with `x-load`. This removes the need for `x-ignore`, allowing the chart to be updated via Livewire polling. --}}
        <div x-data="{ statsOverviewStatChart() {} }">
            <div
                x-load
                x-load-src="{{ \Filament\Support\Facades\FilamentAsset::getAlpineComponentSrc('stats-overview/stat/chart', 'filament/widgets') }}"
                x-data="statsOverviewStatChart({
                            dataChecksum: @js($chartDataChecksum),
                            labels: @js(array_keys($chart)),
                            values: @js(array_values($chart)),
                        })"
                {{ (new ComponentAttributeBag)->color(StatsOverviewWidgetStatChartComponent::class, $chartColor)->class(['fi-wi-stats-overview-stat-chart']) }}
            >
                <canvas x-ref="canvas"></canvas>

                <span
                    x-ref="backgroundColorElement"
                    class="fi-wi-stats-overview-stat-chart-bg-color"
                ></span>

                <span
                    x-ref="borderColorElement"
                    class="fi-wi-stats-overview-stat-chart-border-color"
                ></span>
            </div>
        </div>
    @endif
</{!! $tag !!}>
