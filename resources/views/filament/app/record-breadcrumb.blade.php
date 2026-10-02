<a href="{{ $url }}" wire:navigate class="fi-record-breadcrumb-link">
    <x-filament::icon :icon="$icon" class="fi-record-breadcrumb-icon" />
    <span>{{ $label }}</span>
</a>
<span class="fi-record-breadcrumb-separator" aria-hidden="true">/</span>
