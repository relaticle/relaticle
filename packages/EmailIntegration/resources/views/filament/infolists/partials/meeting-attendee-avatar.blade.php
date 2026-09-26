@php
    /** @var string $src */
    /** @var string $alt */
    /** @var bool $hasName */
    $hasName ??= false;
    $size ??= 'md';
    $class ??= '';
    $isSmall = $size === 'sm';
@endphp

@if ($hasName && $src !== '')
    <x-filament::avatar
        :src="$src"
        :alt="$alt"
        :size="$isSmall ? 'size-6' : 'md'"
        :circular="true"
        data-testid="attendee-avatar-initials"
        @class([
            'block shrink-0',
            $class,
        ])
    />
@else
    <span
        data-testid="attendee-avatar-guest"
        aria-hidden="true"
        @class([
            'flex shrink-0 items-center justify-center rounded-full bg-gray-100 text-gray-500 dark:bg-white/10 dark:text-gray-400',
            'size-6' => $isSmall,
            'size-8' => ! $isSmall,
            $class,
        ])
    >
        <x-filament::icon
            :icon="\Filament\Support\Icons\Heroicon::OutlinedUser"
            @class([
                'size-3' => $isSmall,
                'size-4' => ! $isSmall,
            ])
        />
    </span>
@endif
