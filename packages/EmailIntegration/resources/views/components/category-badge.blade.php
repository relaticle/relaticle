@props(['category'])

<x-filament::badge :color="$category->getColor()" size="sm" {{ $attributes }}>
    {{ $category->getLabel() }}
</x-filament::badge>
