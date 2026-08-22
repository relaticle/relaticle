{{-- One rounded prompt chip, rendered as the single child of a
     <template x-for>. $item: name of the Alpine loop variable holding
     {label, prompt}; $click: the Alpine click expression. Blade-escaped
     `&gt;` in the attribute decodes back to `>` before Alpine reads it,
     same round-trip _composer-bar documents for $sendDisabled. --}}
<button
    type="button"
    x-on:click="{{ $click }}"
    x-text="{{ $item }}.label"
    class="inline-flex items-center gap-1.5 rounded-full border border-[var(--surface-card-border)] bg-[var(--surface-card-bg)] px-3 py-1.5 text-xs font-medium text-gray-700 transition hover:border-primary-300 hover:bg-primary-50 hover:text-primary-700 dark:text-gray-300 dark:hover:border-primary-700 dark:hover:bg-primary-900/20 dark:hover:text-primary-300"
></button>
