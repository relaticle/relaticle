<x-documentation::layout :document="$document ?? null">
    <div class="grid grid-cols-12 gap-6 lg:gap-8 min-h-screen">
        <!-- Left Sidebar: Documentation Types -->
        <div class="hidden sm:block col-span-12 sm:col-span-3 lg:col-span-2 relative">
            <div class="sticky top-24 pt-0.5 max-h-[calc(100vh-6rem)] overflow-y-auto pr-4 pb-16">
                <h2 class="text-sm font-semibold text-black dark:text-white mb-4 flex items-center space-x-2">
                    <x-heroicon-o-book-open class="h-4 w-4 text-primary dark:text-primary-400" />
                    <span>Documentation</span>
                </h2>
                <div class="flex flex-col space-y-1 border-l border-gray-200 dark:border-gray-800">
                    @foreach($documentTypes as $typeKey => $typeValue)
                        <a href="{{ route('documentation.show', $typeKey) }}"
                           class="pl-4 py-2 text-sm rounded-r-md flex items-center gap-2 transition-all
                                      {{ $currentType === $typeKey
                                        ? 'border-l-2 border-primary border-l-primary-500 -ml-[1px] pl-[17px] dark:border-l-primary-400 bg-primary-50/50 dark:bg-primary-900/10 text-primary-600 dark:text-primary-400 font-medium'
                                        : 'text-gray-700 dark:text-gray-300 hover:bg-gray-50 dark:hover:bg-gray-800/50 hover:border-l hover:border-l-gray-300 dark:hover:border-l-gray-700 hover:-ml-[1px] hover:pl-[17px]' }}">
                            <span>{{ $typeValue['title'] }}</span>
                        </a>
                    @endforeach
                </div>
            </div>
        </div>

        <!-- Main Content Column -->
        <div class="col-span-12 sm:col-span-9 md:col-span-6 lg:col-span-7 px-4">
            <!-- Documentation content with improved typography -->
            <div id="documentation-content"
                 class="prose prose-sm sm:prose-base lg:prose-lg dark:prose-invert max-w-none mx-auto">
                {!! $content !!}
            </div>

            @php
                $editFile = config("documentation.documents.{$currentType}.file");
            @endphp
            @if($editFile)
                <div class="mt-10 pt-6 border-t border-gray-200 dark:border-gray-800">
                    <a href="https://github.com/Relaticle/relaticle/edit/main/packages/Documentation/resources/markdown/{{ $editFile }}"
                       target="_blank"
                       rel="noopener noreferrer"
                       class="inline-flex items-center gap-2 text-sm text-gray-500 dark:text-gray-400 hover:text-primary-600 dark:hover:text-primary-400 transition-colors">
                        <x-ri-github-fill class="w-4 h-4"/>
                        Edit this page on GitHub
                    </a>
                </div>
            @endif
        </div>

        <!-- Right Sidebar: Table of Contents -->
        <aside class="hidden lg:block pb-16 col-span-3 print-hidden ">
            <div class="sticky top-[5rem] pt-0.5 overflow-y-auto pb-16">
                @if(count($tableOfContents))
                    <h3 class="text-sm font-semibold text-black dark:text-white mb-4 flex items-center space-x-2">
                        <x-heroicon-o-list-bullet class="h-4 w-4 text-primary dark:text-primary-400" />
                        <span>On this page</span>
                    </h3>
                    <nav>
                        <ul class="space-y-2.5">
                            @foreach($tableOfContents as $fragment => $title)
                                <li class="text-sm">
                                    <a href="#{{ $fragment }}"
                                       class="group flex items-center text-gray-600 dark:text-gray-400 hover:text-primary-600 dark:hover:text-primary-400 transition-colors border-l border-gray-200 dark:border-gray-800 pl-3 py-1 hover:border-primary-500 dark:hover:border-primary-400">
                                        <span class="truncate">{{ $title }}</span>
                                    </a>
                                </li>
                            @endforeach
                        </ul>
                    </nav>
                @endif
            </div>
        </aside>
    </div>
</x-documentation::layout>
