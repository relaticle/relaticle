@use(Relaticle\Cloud\Blog\BlogCover)
@props(['source', 'sizes'])
@php
    $cover = new BlogCover($source);
    $srcset = $cover->srcset();
@endphp
<picture class="block">
    @if($srcset !== '')
        <source type="image/webp" srcset="{{ $srcset }}" sizes="{{ $sizes }}">
    @endif
    <img src="{{ $cover->url() }}" {{ $attributes }}>
</picture>
