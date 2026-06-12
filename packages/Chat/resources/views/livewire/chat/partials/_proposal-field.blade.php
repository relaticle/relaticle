{{-- Type-aware proposal field row. Expects Alpine scope var `field`:
     {label, value?|new?, old?, type?, values?} --}}
<div class="flex items-start gap-2 text-sm">
    <span class="shrink-0 font-medium text-gray-500 dark:text-gray-400" x-text="field.label + ':'"></span>

    <template x-if="field.old">
        <span class="flex items-center gap-1">
            <span class="text-gray-400 line-through" x-text="field.old"></span>
            <span class="text-gray-400" aria-hidden="true">&rarr;</span>
        </span>
    </template>

    <template x-if="field.type === 'badges' && Array.isArray(field.values) && field.values.length > 0">
        <span class="flex flex-wrap gap-1">
            <template x-for="(badge, badgeIdx) in field.values" :key="badgeIdx">
                <span class="inline-flex items-center rounded-md bg-gray-100 px-1.5 py-0.5 text-xs font-medium text-gray-700 dark:bg-gray-800 dark:text-gray-300" x-text="badge"></span>
            </template>
        </span>
    </template>

    <template x-if="field.type === 'boolean'">
        <span
            class="inline-flex items-center rounded-md px-1.5 py-0.5 text-xs font-medium"
            :class="(field.new ?? field.value) === 'Yes'
                ? 'bg-green-50 text-green-700 dark:bg-green-900/20 dark:text-green-400'
                : 'bg-gray-100 text-gray-600 dark:bg-gray-800 dark:text-gray-400'"
            x-text="field.new ?? field.value"
        ></span>
    </template>

    <template x-if="field.type === 'link' && Array.isArray(field.values) && field.values.length > 0">
        <span class="flex flex-wrap items-center gap-x-2 gap-y-0.5">
            <template x-for="(url, urlIdx) in field.values" :key="urlIdx">
                <a
                    :href="(String(url).startsWith('http') ? '' : 'https://') + url"
                    target="_blank"
                    rel="noopener noreferrer"
                    class="truncate text-primary-600 hover:underline dark:text-primary-400"
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
            class="truncate text-primary-600 hover:underline dark:text-primary-400"
            x-text="field.new ?? field.value"
        ></a>
    </template>

    <template x-if="!['badges', 'boolean', 'link'].includes(field.type)">
        <span class="text-gray-900 dark:text-white" x-text="field.new ?? field.value"></span>
    </template>
</div>
