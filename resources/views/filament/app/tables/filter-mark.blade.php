<template x-if="{{ $of }}.image || {{ $of }}.icon">
    <span class="fi-filter-mark" x-data="{ broken: false }" x-bind:class="{ 'fi-square': {{ $of }}.circular === false }" @if ($tagged ?? false) data-filter-mark @endif>
        <template x-if="{{ $of }}.image"><img x-show="! broken" x-bind:src="{{ $of }}.image" alt="" loading="lazy" x-on:error="broken = true" /></template>
        <svg x-show="{{ $of }}.icon && (broken || ! {{ $of }}.image)" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path x-bind:d="{{ $of }}.icon"></path></svg>
    </span>
</template>
