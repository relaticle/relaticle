@props(['posts'])

@if($posts->isNotEmpty())
    <x-cloud::marketing.frame>
        <x-cloud::marketing.frame-header :label="__('Keep reading')" :lead="__('Related posts.')" />

        <ul class="bg-rail grid gap-px">
            @foreach($posts as $relatedPost)
                <x-cloud::blog.post-card :post="$relatedPost" heading="h3" />
            @endforeach
        </ul>
    </x-cloud::marketing.frame>
@endif
