@php
    $documentationActive = \Laravel\Pennant\Feature::active(\App\Features\Documentation::class);
    $linkClass = 'font-medium text-gray-900 underline decoration-gray-300 underline-offset-2 hover:decoration-gray-900 dark:text-white dark:decoration-gray-600 dark:hover:decoration-white';
    $source = static fn (string $label, string $category, string $slug): string => $documentationActive
        ? '<a href="'.e(route('help.show', ['category' => $category, 'slug' => $slug])).'" class="'.$linkClass.'">'.e($label).'</a>'
        : '<span class="'.$linkClass.'">'.e($label).'</span>';
@endphp

<div class="bg-dots flex h-full min-h-[inherit] items-center justify-center p-4 sm:p-8 md:p-12">
    <x-cloud::marketing.chat-window class="w-full max-w-lg">

        <div class="space-y-3 p-4 sm:p-5" data-ai-docs-answer>
            <p class="ml-auto w-fit max-w-[85%] rounded-2xl rounded-br-md bg-gray-100 px-4 py-2.5 text-sm leading-relaxed text-gray-900 dark:bg-white/10 dark:text-gray-100">
                {{ __('How do I connect Claude to Relaticle?') }}
            </p>

            <div class="space-y-3 px-1 text-sm leading-relaxed text-gray-900 dark:text-gray-100">
                <p>{{ __('In Claude, open Settings, then Connectors, and add a custom connector with this URL:') }} <code class="rounded bg-gray-100 px-1 py-0.5 text-micro dark:bg-white/10">{{ url()->getMcpUrl() }}</code></p>
                <ul class="list-disc space-y-1 ps-5 marker:text-gray-400">
                    <li>{{ __('Claude sends you here to sign in. You approve access and pick one workspace.') }}</li>
                    <li>{{ __('It acts as you, inside that workspace only.') }}</li>
                    <li>{{ __('Its changes apply directly, without the review card you get in this chat.') }}</li>
                </ul>
                <p>{!! __('The full steps are in :article.', [
                    'article' => $source(__('Connect Claude or ChatGPT to Relaticle'), 'ai-assistant', 'connect-claude-or-chatgpt'),
                ]) !!}</p>
            </div>
        </div>
    </x-cloud::marketing.chat-window>
</div>
