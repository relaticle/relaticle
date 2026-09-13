@props([
    'showWordmark' => true,
    'size' => 'md',
])

{{-- Crabdev: replaces Relaticle's logo everywhere (see CrmServiceProvider). Inline
     styles on purpose, so it does not depend on Tailwind compiling this folder. --}}
@php
    $fontSize = ['sm' => '1.125rem', 'md' => '1.25rem', 'lg' => '1.5rem'][$size] ?? '1.25rem';
    $markSize = ['sm' => '1.75rem', 'md' => '2rem', 'lg' => '2.5rem'][$size] ?? '2rem';
    $accent = config('crm.brand.color');
@endphp

@if ($showWordmark)
    <span {{ $attributes->merge(['style' => "display:inline-flex;align-items:baseline;gap:0.375rem;font-family:'Space Grotesk',ui-sans-serif,system-ui,sans-serif;font-size:{$fontSize};font-weight:700;letter-spacing:-0.02em;line-height:1"]) }}>
        {{ config('crm.brand.company') }}<span aria-hidden="true" style="display:inline-block;width:0.45em;height:0.45em;background-color:{{ $accent }}"></span><span style="font-weight:500;opacity:0.6">CRM</span>
    </span>
@else
    <span aria-hidden="true" {{ $attributes->merge(['style' => "display:inline-flex;align-items:center;justify-content:center;width:{$markSize};height:{$markSize};border-radius:0.375rem;background-color:#0c0e12;color:{$accent};font-family:ui-monospace,monospace;font-weight:700;font-size:calc({$markSize} * 0.45)"]) }}>C_</span>
@endif
