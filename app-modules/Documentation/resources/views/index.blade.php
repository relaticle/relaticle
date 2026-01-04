<x-documentation::layout :document="null" class="documentation-hub">
    <div class="max-w-5xl mx-auto">
        <!-- Hero Text - Enhanced Typography -->
        <div class="text-center space-y-6 max-w-3xl mx-auto mb-12">
            <h1 class="text-4xl sm:text-5xl font-bold text-black dark:text-white leading-[1.1] tracking-tight">
                <span class="relative inline-block">
                    <span class="relative z-10">Documentation</span>
                    <span class="absolute bottom-2 sm:left-0 right-1/4 w-1/2 sm:w-full h-3 bg-primary/10 dark:bg-primary/20 -rotate-1 z-0"></span>
                </span>
            </h1>

            <p class="text-lg text-gray-600 dark:text-gray-300 max-w-2xl mx-auto leading-relaxed">
                Welcome to the Relaticle documentation hub. Here you'll find guides and resources to help you get the
                most out of Relaticle CRM.
            </p>
        </div>

        <div class="mb-12 max-w-xl mx-auto">
            <x-documentation::search-form  />
        </div>

        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
            @php
                $documentIcons = [
                    'getting-started' => 'heroicon-o-rocket-launch',
                    'import' => 'heroicon-o-arrow-up-tray',
                    'developer' => 'heroicon-o-code-bracket',
                    'api' => 'heroicon-o-variable',
                ];
            @endphp

            @foreach($documentTypes as $type => $document)
                <x-documentation::card
                    :title="$document['title']"
                    :description="$document['description'] ?? ''"
                    :link="route('documentation.show', ['type' => $type])"
                    :icon="$documentIcons[$type] ?? null"
                />
            @endforeach
        </div>
    </div>
</x-documentation::layout>
