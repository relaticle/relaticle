@props([
    'suggestions' => [],
])

@php
    use Illuminate\Support\Str;

    $wireModel = $attributes->wire('model')->value();
    $listId = Str::slug($wireModel ?: 'recipients').'-suggestions';
@endphp

<div
    x-data="{
        values: $wire.$entangle('{{ $wireModel }}'),
        newValue: '',

        commit(raw = null) {
            const value = (raw ?? this.newValue).trim().replace(/,$/, '');

            if (! value) {
                return;
            }

            if (! this.values.includes(value)) {
                this.values = [...this.values, value];
            }

            this.newValue = '';
        },

        removeLast() {
            if (this.newValue !== '') {
                return;
            }

            this.values = this.values.slice(0, -1);
        },

        remove(value) {
            this.values = this.values.filter((v) => v !== value);
        },

        handleKeydown(event) {
            if (event.key === 'Enter' || event.key === ',') {
                event.preventDefault();
                this.commit();

                return;
            }

            if (event.key === 'Tab' && this.newValue.trim() !== '') {
                event.preventDefault();
                this.commit();

                return;
            }

            if (event.key === 'Backspace' && this.newValue === '') {
                this.removeLast();
            }
        },
    }"
    @if ($wireModel) wire:ignore @endif
    {{ $attributes->whereDoesntStartWith('wire:model')->merge(['class' => 'flex min-h-[1.75rem] min-w-0 flex-wrap items-center gap-1']) }}
>
    <template x-for="value in values" :key="value">
        <span class="inline-flex max-w-full items-center gap-1 rounded-md bg-gray-100 px-1.5 py-0.5 text-xs font-medium text-gray-700 dark:bg-gray-800 dark:text-gray-300">
            <span class="truncate" x-text="value"></span>
            <button type="button" x-on:click="remove(value)" :aria-label="'Remove ' + value" class="shrink-0 text-gray-400 hover:text-gray-600 dark:hover:text-gray-300">
                <x-heroicon-m-x-mark class="h-3 w-3" />
            </button>
        </span>
    </template>

    <input
        type="text"
        list="{{ $listId }}"
        x-model="newValue"
        x-on:keydown="handleKeydown($event)"
        x-on:blur="commit()"
        class="min-w-[8rem] flex-1 border-0 bg-transparent p-0 text-sm focus:outline-none focus:ring-0"
    />
    <datalist id="{{ $listId }}">
        @foreach ($suggestions as $suggestion)
            <option value="{{ $suggestion }}"></option>
        @endforeach
    </datalist>
</div>
