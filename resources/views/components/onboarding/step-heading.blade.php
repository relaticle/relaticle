<h1
    tabindex="-1"
    x-data
    x-init="$nextTick(() => $el.focus({ preventScroll: true }))"
    {{ $attributes->class(['text-xl font-bold tracking-tight text-gray-950 outline-none dark:text-white']) }}
>
    {{ $slot }}
</h1>
