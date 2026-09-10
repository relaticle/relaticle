@props(['value', 'label'])

<button
    type="button"
    x-data="{ copied: false }"
    x-on:click="navigator.clipboard.writeText(@js($value)).then(() => { copied = true; setTimeout(() => copied = false, 2000) })"
    aria-label="{{ $label }}"
    class="inline-flex shrink-0 cursor-pointer items-center gap-1.5 rounded-md px-2 py-1 text-xs font-medium text-gray-500 transition-colors hover:text-gray-900 dark:text-gray-400 dark:hover:text-white"
>
    <template x-if="! copied">
        <span class="inline-flex items-center gap-1.5">
            <x-ri-file-copy-line class="size-3.5" />
            {{ __('profile.sections.mfa.copy') }}
        </span>
    </template>

    <template x-if="copied">
        <span class="inline-flex items-center gap-1.5 text-success-600 dark:text-success-400">
            <x-ri-check-line class="size-3.5" />
            {{ __('profile.sections.mfa.copied') }}
        </span>
    </template>
</button>
