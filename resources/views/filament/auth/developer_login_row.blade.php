<div
    @class(['relative flex items-center justify-between gap-3 px-3 py-2', 'ps-6' => $teammate])
    title="{{ $title }}"
>
    <form method="POST" action="{{ route('loginLinkLogin') }}">
        @csrf
        <input type="hidden" name="email" value="{{ $email }}">
        <input type="hidden" name="redirect_url" value="{{ url()->getAppUrl() }}">

        <button
            type="submit"
            class="cursor-pointer font-mono text-xs text-gray-600 after:absolute after:inset-0 hover:underline dark:text-gray-300"
        >
            {{ $email }}
        </button>
    </form>

    <x-filament::badge :color="$color">
        {{ $label }}
    </x-filament::badge>
</div>
