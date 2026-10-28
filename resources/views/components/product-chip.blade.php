@props(['code' => null, 'title' => null])

@php
    // One product-chip palette for the whole portal. The colour lives in
    // ims-ui.css (--prod-*), never in inline styles, so Diesel cannot be
    // indigo here and amber there.
    $code = strtoupper(trim((string) $code));
    $tone = match ($code) {
        'U' => 'u',
        'D' => 'd',
        'P' => 'p',
        default => 'none',
    };
    $label = $code !== '' ? $code : '—';
    $tooltip = $title ?: ($code !== '' ? $code : 'No product set');
@endphp

<span {{ $attributes->merge(['class' => "prod-chip prod-chip--{$tone}"]) }} title="{{ $tooltip }}">{{ $label }}</span>
