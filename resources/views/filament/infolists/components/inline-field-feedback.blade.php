@php
    /** @var string|null $status */
    /** @var bool $showUndo */
@endphp

@if (filled($status) || $showUndo)
    <div
        class="fi-inline-field-feedback"
        x-data="{ showSaved: {{ filled($status) ? 'true' : 'false' }} }"
        x-init="if (showSaved) setTimeout(() => showSaved = false, 3000)"
    >
        @if (filled($status))
            <p
                class="fi-inline-field-saved"
                role="status"
                x-show="showSaved"
                x-transition.opacity.duration.400ms
            >
                <span class="fi-inline-field-saved-check" aria-hidden="true">✓</span>
                {{ $status }}
            </p>
        @endif

        @if ($showUndo)
            <button
                type="button"
                class="fi-inline-field-undo"
                wire:click.stop="undoInlineField"
            >
                {{ __('filament/inline-edit.undo') }}
            </button>
        @endif
    </div>
@endif
