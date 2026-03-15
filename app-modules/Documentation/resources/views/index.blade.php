<x-documentation::layout :document="null" class="documentation-hub">
    <div class="max-w-5xl mx-auto">
        <div class="text-center space-y-5 max-w-3xl mx-auto mb-12">
            <h1 class="font-display text-4xl sm:text-5xl font-bold text-gray-950 dark:text-white leading-[1.1] tracking-[-0.02em]">
                Documentation
            </h1>
            <p class="text-lg text-gray-500 dark:text-gray-400 max-w-2xl mx-auto leading-relaxed">
                Guides and resources to help you get the most out of Relaticle CRM.
            </p>
        </div>

        <div class="mb-12 max-w-lg mx-auto">
            <x-documentation::search-form />
        </div>

        @php
            $documentIcons = [
                'getting-started' => 'ri-rocket-2-line',
                'import' => 'ri-upload-2-line',
                'developer' => 'ri-code-s-slash-line',
                'api' => 'ri-terminal-box-line',
                'mcp' => 'ri-cpu-line',
            ];
        @endphp

        <div class="border-t border-gray-200/60 dark:border-white/[0.04] divide-y divide-gray-200/60 dark:divide-white/[0.04]">
            <div class="grid grid-cols-1 md:grid-cols-2 divide-y md:divide-y-0 md:divide-x divide-gray-200/60 dark:divide-white/[0.04]">
                @foreach($documentTypes as $type => $document)
                    @if($loop->index % 2 === 0 && !$loop->first)
                        </div>
                        <div class="grid grid-cols-1 md:grid-cols-2 divide-y md:divide-y-0 md:divide-x divide-gray-200/60 dark:divide-white/[0.04]">
                    @endif
                    <x-documentation::card
                        :title="$document['title']"
                        :description="$document['description'] ?? ''"
                        :link="isset($document['url']) ? $document['url'] : route('documentation.show', ['type' => $type])"
                        :icon="$documentIcons[$type] ?? null"
                    />
                @endforeach
            </div>
        </div>
    </div>
</x-documentation::layout>
