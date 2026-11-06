@props(['record', 'unlinkEmailId' => null, 'canUnlink' => false, 'menuItems' => false])

@php
    use App\Filament\Resources\CompanyResource;
    use App\Filament\Resources\OpportunityResource;
    use App\Filament\Resources\PeopleResource;

    $url = match ($record['type']) {
        'company' => CompanyResource::getUrl('view', ['record' => $record['id']]),
        'opportunity' => OpportunityResource::getUrl('view', ['record' => $record['id']]),
        'people' => PeopleResource::getUrl('view', ['record' => $record['id']]),
    };
    $chipClasses = 'max-w-[9rem] items-center truncate rounded-md bg-gray-100 px-1.5 py-0.5 text-[11px] font-medium leading-4 text-gray-600 hover:bg-gray-200 dark:bg-white/5 dark:text-gray-300 dark:hover:bg-white/10';
@endphp

@if ($menuItems)
    <x-filament::dropdown.list.item tag="a" :href="$url" icon="ri-external-link-line">
        {{ $record['name'] }}
    </x-filament::dropdown.list.item>

    @if ($canUnlink)
        <x-filament::dropdown.list.item
            icon="ri-link-unlink"
            color="danger"
            data-unlink-record="{{ $record['type'] }}:{{ $record['id'] }}"
            wire:click="mountAction('unlinkRecord', {{ Js::from(['emailId' => $unlinkEmailId, 'targetType' => $record['type'], 'targetId' => $record['id']]) }})"
        >
            {{ __('filament/pages/email-inbox.drawer.links.unlink_named', ['name' => $record['name']]) }}
        </x-filament::dropdown.list.item>
    @endif
@elseif ($unlinkEmailId === null)
    <a
        href="{{ $url }}"
        {{ $attributes->class([$chipClasses]) }}
    >{{ $record['name'] }}</a>
@else
    <x-filament::dropdown placement="bottom-start" :attributes="$attributes->merge(['data-linked-record' => $record['type'].':'.$record['id']])">
        <x-slot name="trigger">
            <button
                type="button"
                aria-label="{{ __('filament/pages/email-inbox.drawer.links.menu', ['name' => $record['name']]) }}"
                class="{{ $chipClasses }} inline-flex max-w-[12rem] gap-0.5 py-1 text-xs outline-none focus-visible:ring-2 focus-visible:ring-primary-500"
            >
                <span class="truncate">{{ $record['name'] }}</span>
                <x-filament::icon icon="ri-arrow-down-s-line" class="size-3.5 shrink-0" aria-hidden="true" />
            </button>
        </x-slot>

        <x-filament::dropdown.list>
            <x-filament::dropdown.list.item tag="a" :href="$url" icon="ri-external-link-line">
                {{ __('filament/pages/email-inbox.drawer.links.open') }}
            </x-filament::dropdown.list.item>

            @if ($canUnlink)
                <x-filament::dropdown.list.item
                    icon="ri-link-unlink"
                    color="danger"
                    data-unlink-record
                    wire:click="mountAction('unlinkRecord', {{ Js::from(['emailId' => $unlinkEmailId, 'targetType' => $record['type'], 'targetId' => $record['id']]) }})"
                >
                    {{ __('filament/pages/email-inbox.drawer.links.unlink') }}
                </x-filament::dropdown.list.item>
            @endif
        </x-filament::dropdown.list>
    </x-filament::dropdown>
@endif
