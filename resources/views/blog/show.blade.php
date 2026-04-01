<x-guest-layout
    :title="$post->title . ' - ' . config('app.name') . ' Blog'"
    :description="$post->excerpt"
    :ogTitle="$post->title"
    :ogDescription="$post->excerpt"
    :ogImage="$post->featured_image ? asset('storage/' . $post->featured_image) : null">
    @push('header')
        <x-blog::meta-tags :post="$post" />
        <x-blog::feed-link />
    @endpush

    <x-blog::structured-data :post="$post" />

    <div class="pt-32 pb-24 md:pt-40 md:pb-32 bg-white dark:bg-black">
        <div class="max-w-5xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="grid grid-cols-12 gap-6 lg:gap-12">
                <!-- Main Content -->
                <article class="col-span-12 lg:col-span-8 xl:col-span-9">
                    <div class="mb-6">
                        <a href="{{ route('blog.index') }}"
                           class="inline-flex items-center gap-1.5 text-sm text-gray-400 dark:text-gray-500 hover:text-gray-600 dark:hover:text-gray-300 transition-colors">
                            <x-ri-arrow-left-line class="w-4 h-4" />
                            Back to blog
                        </a>
                    </div>

                    <x-blog::post-header :post="$post" />
                    <x-blog::post-body :post="$post" />
                    <x-blog::related-posts :posts="$relatedPosts" />
                </article>

                <!-- Right Sidebar: Table of Contents -->
                <aside class="hidden lg:block col-span-4 xl:col-span-3">
                    @php
                        $toc = [];
                        preg_match_all('/<h2.*><a.*id="([^"]+)".*>#<\/a>([^<]+)/', $post->toHtml(), $tocMatches);
                        if (!empty($tocMatches[1]) && !empty($tocMatches[2])) {
                            $toc = array_combine($tocMatches[1], $tocMatches[2]);
                        }
                    @endphp

                    @if(count($toc))
                        <div class="sticky top-24 pt-0.5 overflow-y-auto pb-16">
                            <h3 class="text-sm font-semibold text-black dark:text-white mb-4 flex items-center space-x-2">
                                <x-heroicon-o-list-bullet class="h-4 w-4 text-primary dark:text-primary-400" />
                                <span>On this page</span>
                            </h3>
                            <nav>
                                <ul class="space-y-2.5">
                                    @foreach($toc as $fragment => $title)
                                        <li class="text-sm">
                                            <a href="#{{ $fragment }}"
                                               class="group flex items-center text-gray-600 dark:text-gray-400 hover:text-primary-600 dark:hover:text-primary-400 transition-colors border-l border-gray-200 dark:border-gray-800 pl-3 py-1 hover:border-primary-500 dark:hover:border-primary-400">
                                                <span class="truncate">{{ $title }}</span>
                                            </a>
                                        </li>
                                    @endforeach
                                </ul>
                            </nav>
                        </div>
                    @endif
                </aside>
            </div>
        </div>
    </div>
</x-guest-layout>
