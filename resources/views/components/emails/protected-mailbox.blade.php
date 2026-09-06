@props([
    'heading',
    'description',
])

<div {{ $attributes->class(['flex min-h-full flex-col items-center justify-center']) }}>
    <x-filament::empty-state
        :heading="$heading"
        :description="$description"
        icon="heroicon-o-shield-check"
        icon-color="primary"
        :contained="false"
    />
</div>
