@use(Illuminate\Support\Str)
@use(Relaticle\Cloud\Marketing\Support\BlogCategoryName)
@use(Relaticle\Ink\Ink)
@php
    echo '<?xml version="1.0" encoding="UTF-8"?>';
    $locale = Ink::requestLocale();
@endphp
<rss version="2.0" xmlns:atom="http://www.w3.org/2005/Atom">
    <channel>
        <title>{{ __('Relaticle Engineering Blog') }}</title>
        <link>{{ route(Ink::routeName('index', $locale)) }}</link>
        <description>{{ __('Deep dives into building an open-source CRM for AI agents.') }}</description>
        <language>{{ $locale }}</language>
        <lastBuildDate>{{ $posts->first()?->published_at?->toRfc2822String() }}</lastBuildDate>
        <atom:link href="{{ route(Ink::routeName('feed', $locale)) }}" rel="self" type="application/rss+xml" />

        @foreach($posts as $post)
            <item>
                <title>{{ $post->title }}</title>
                <link>{{ route(Ink::routeName('show', $locale), $post->slug) }}</link>
                <guid isPermaLink="true">{{ route(Ink::routeName('show', $locale), $post->slug) }}</guid>
                <description><![CDATA[{{ $post->excerpt ?: Str::limit(strip_tags($post->content), 300) }}]]></description>
                <pubDate>{{ $post->published_at->toRfc2822String() }}</pubDate>
                @if($post->category)
                    <category>{{ BlogCategoryName::of($post->category) }}</category>
                @endif
                @if(config('ink.feed.author_email'))
                    <author>{{ config('ink.feed.author_email') }} ({{ $post->author?->name }})</author>
                @endif
            </item>
        @endforeach
    </channel>
</rss>
