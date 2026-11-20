@props(['html'])

{!! preg_replace('/<pre(?=[\s>])(?![^>]*\btabindex=)/i', '<pre tabindex="0"', $html) !!}
