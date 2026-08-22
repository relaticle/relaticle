{{-- "View <record>" anchor for a resolved proposal. $record: Alpine
     expression for the {url, label} record object; the caller guards on
     `.url` being present and sets the surrounding font size. --}}
<a :href="{{ $record }}.url" wire:navigate class="inline-flex items-center gap-1 font-medium text-primary-600 hover:underline dark:text-primary-400">
    <span x-text="{{ $record }}.label ? 'View ' + {{ $record }}.label : 'View'"></span>
    <x-heroicon-o-arrow-top-right-on-square class="h-3 w-3" aria-hidden="true" />
</a>
