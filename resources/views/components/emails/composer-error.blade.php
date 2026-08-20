{{-- Validation line under a header row. Wildcard keys (`to.*`) are their own
     bag entry, so a field with per-index rules needs both spellings. --}}
@props(['field'])

@error($field)
    <p {{ $attributes->class(['pb-1 text-xs text-danger-600 dark:text-danger-400']) }}>{{ $message }}</p>
@enderror
