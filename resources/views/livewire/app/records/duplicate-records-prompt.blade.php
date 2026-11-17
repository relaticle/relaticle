<div>
    @if ($hasDuplicates)
        {{ $this->content }}

        <x-filament-actions::modals />
    @endif
</div>
