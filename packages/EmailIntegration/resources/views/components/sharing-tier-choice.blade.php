@props(['selected', 'label', 'model' => 'sharingTier'])

@php
    use Relaticle\EmailIntegration\Enums\EmailPrivacyTier;
@endphp

<div {{ $attributes->class('flex flex-col gap-2.5') }} role="radiogroup" aria-label="{{ $label }}">
    @foreach (EmailPrivacyTier::offeredAtConnect() as $tier)
        <label
            data-tier="{{ $tier->value }}"
            @class([
                'flex cursor-pointer items-center gap-3.5 rounded-xl bg-white p-3 pe-3.5 dark:bg-gray-900',
                'ring-2 ring-primary-600 outline-4 outline-offset-2 outline-primary-600/15 dark:ring-primary-500 dark:outline-primary-500/20' => $selected === $tier->value,
                'ring-1 ring-gray-950/10 dark:ring-white/10' => $selected !== $tier->value,
            ])
        >
            <span class="flex h-16 w-21 shrink-0 flex-col gap-1.5 overflow-hidden rounded-lg bg-gray-50 px-2 pt-2 ring-1 ring-gray-950/5 dark:bg-white/5 dark:ring-white/10" aria-hidden="true">
                <span class="flex items-center gap-1">
                    <span class="size-2.5 shrink-0 rounded-full bg-primary-300"></span>
                    <span class="h-1 w-8 rounded-full bg-gray-400"></span>
                </span>
                @if ($tier->showsSubject())
                    <span class="h-1.5 w-12 rounded-full bg-gray-700 dark:bg-gray-200"></span>
                @else
                    <x-filament::icon icon="lucide-lock" class="size-2.5 text-gray-400" />
                @endif
                <span class="h-1 w-full rounded-full bg-gray-200 dark:bg-white/10"></span>
                <span class="h-1 w-2/3 rounded-full bg-gray-200 dark:bg-white/10"></span>
            </span>

            <span class="min-w-0 flex-1">
                <span class="block text-sm font-semibold text-gray-950 dark:text-white">{{ $tier->getLabel() }}</span>
                <span class="mt-0.5 block text-sm/4.5 text-gray-500 dark:text-gray-400">{{ $tier->getDescription() }}</span>
            </span>

            <input type="radio" name="{{ $model }}" wire:model.live="{{ $model }}" value="{{ $tier->value }}" class="fi-radio-input shrink-0" />
        </label>
    @endforeach
</div>
