{{--
    Override of ink::components.structured-data.

    v2.1.1 encodes the schema with JSON_UNESCAPED_SLASHES, so a post title or
    excerpt containing "</script>" closes the block early: the structured data is
    invalid and the rest of the JSON renders as visible text at the top of the
    post. The JSON_HEX_* flags escape the characters that can terminate the tag.

    Fixed upstream on relaticle/ink 2.x — delete this file once the app is on a
    release that carries it.
--}}
<script type="application/ld+json">
    {!! json_encode($schema, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) !!}
</script>
