@props(['record'])

@php
    use App\Filament\Resources\CompanyResource;
    use App\Filament\Resources\OpportunityResource;
    use App\Filament\Resources\PeopleResource;

    $url = match ($record['type']) {
        'company' => CompanyResource::getUrl('view', ['record' => $record['id']]),
        'opportunity' => OpportunityResource::getUrl('view', ['record' => $record['id']]),
        'people' => PeopleResource::getUrl('view', ['record' => $record['id']]),
    };
@endphp

<a
    href="{{ $url }}"
    {{ $attributes->class(['max-w-[9rem] items-center truncate rounded-md bg-gray-100 px-1.5 py-0.5 text-[11px] font-medium leading-4 text-gray-600 hover:bg-gray-200 dark:bg-white/5 dark:text-gray-300 dark:hover:bg-white/10']) }}
>{{ $record['name'] }}</a>
