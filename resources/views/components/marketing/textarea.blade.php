@props([
    'label' => null,
    'required' => false,
])

<div>
    @if($label)
        <label for="{{ $attributes->get('id') }}" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5">
            {{ $label }}@if($required) <span class="text-red-500">*</span>@endif
        </label>
    @endif

    <textarea {{ $attributes->merge([
        'class' => 'w-full rounded-lg border border-gray-200 dark:border-white/[0.08] bg-white dark:bg-white/[0.03] px-4 py-3 text-sm text-gray-900 dark:text-white placeholder-gray-400 dark:placeholder-gray-500 outline-none focus:border-primary focus:ring-2 focus:ring-primary/20 transition-colors resize-none',
    ]) }}>{{ $slot }}</textarea>

    @error($attributes->get('name'))
        <p class="mt-1.5 text-xs text-red-500">{{ $message }}</p>
    @enderror
</div>
