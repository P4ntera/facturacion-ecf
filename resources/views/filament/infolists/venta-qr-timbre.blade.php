@php
    $url = $getState();
@endphp

@if ($url)
    <div class="flex flex-col items-start gap-2">
        <div class="rounded-lg border border-gray-200 bg-white p-2 dark:border-gray-700">
            {!! \SimpleSoftwareIO\QrCode\Facades\QrCode::size(160)->generate($url) !!}
        </div>
        <a href="{{ $url }}" target="_blank" rel="noopener" class="text-sm text-primary-600 underline">
            {{ $url }}
        </a>
    </div>
@endif
