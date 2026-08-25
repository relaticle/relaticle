{{-- Type-aware proposal field row. Expects Alpine scope var `field`:
     {label, value?|new?, old?, type?, values?} --}}
<div class="flex items-start gap-3">
    <span class="w-24 shrink-0 text-[length:var(--text-micro)] font-medium leading-5 text-gray-400 sm:w-28 dark:text-gray-500" x-text="field.label"></span>

    <span class="flex min-w-0 flex-1 flex-wrap items-center gap-x-1.5 gap-y-0.5 text-sm">
        <template x-if="field.old">
            <span class="flex items-center gap-1.5">
                <span class="text-gray-400 line-through decoration-gray-300 dark:text-gray-500 dark:decoration-gray-600" x-text="field.old"></span>
                <x-heroicon-m-arrow-right class="h-3 w-3 text-gray-400 dark:text-gray-500" aria-hidden="true" />
            </span>
        </template>

        <template x-if="field.type === 'badges' && Array.isArray(field.values) && field.values.length > 0">
            <span class="flex flex-wrap gap-1">
                <template x-for="(badge, badgeIdx) in field.values" :key="badgeIdx">
                    <span class="inline-flex items-center rounded-full bg-gray-100 px-2 py-0.5 text-[length:var(--text-micro)] font-medium text-gray-700 dark:bg-white/10 dark:text-gray-300" x-text="badge"></span>
                </template>
            </span>
        </template>

        <template x-if="field.type === 'boolean'">
            <span
                class="inline-flex items-center rounded-full px-2 py-0.5 text-[length:var(--text-micro)] font-medium"
                :class="(field.new ?? field.value) === 'Yes'
                    ? 'bg-green-50 text-green-700 dark:bg-green-400/10 dark:text-green-400'
                    : 'bg-gray-100 text-gray-600 dark:bg-white/10 dark:text-gray-400'"
                x-text="field.new ?? field.value"
            ></span>
        </template>

        {{-- `truncate` only bites when the box is allowed to shrink. Every anchor
             here is a flex item, whose default `min-width: auto` is its own
             untruncated text width, so a UTM-length URL bursts the card sideways
             on a phone (measured: a 155-char href rendered 1162px wide inside a
             316px card). min-w-0 on the anchors, and on the multi-value row that
             holds them, lets the ellipsis do its job instead. --}}
        <template x-if="field.type === 'link' && Array.isArray(field.values) && field.values.length > 0">
            <span class="flex min-w-0 flex-wrap items-center gap-x-2 gap-y-0.5">
                <template x-for="(url, urlIdx) in field.values" :key="urlIdx">
                    <a
                        :href="(String(url).startsWith('http') ? '' : 'https://') + url"
                        target="_blank"
                        rel="noopener noreferrer"
                        class="min-w-0 truncate text-primary-600 hover:underline dark:text-primary-400"
                        x-text="url"
                    ></a>
                </template>
            </span>
        </template>

        <template x-if="field.type === 'link' && !(Array.isArray(field.values) && field.values.length > 0) && (field.new ?? field.value)">
            <a
                :href="(String(field.new ?? field.value).startsWith('http') ? '' : 'https://') + (field.new ?? field.value)"
                target="_blank"
                rel="noopener noreferrer"
                class="min-w-0 truncate text-primary-600 hover:underline dark:text-primary-400"
                x-text="field.new ?? field.value"
            ></a>
        </template>

        <template x-if="!['badges', 'boolean', 'link'].includes(field.type)">
            <span class="font-medium text-gray-900 dark:text-white" x-text="field.new ?? field.value"></span>
        </template>
    </span>
</div>
