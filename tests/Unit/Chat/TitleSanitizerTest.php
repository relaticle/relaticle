<?php

declare(strict_types=1);

use Relaticle\Chat\Support\TitleSanitizer;

it('strips bidi override characters', function (): void {
    $input = "innocent.exe\u{202E}gpj";
    expect(TitleSanitizer::clean($input))->toBe('innocent.exegpj');
});

it('strips all bidi control characters', function (): void {
    foreach (["\u{202A}", "\u{202B}", "\u{202C}", "\u{202D}", "\u{202E}", "\u{2066}", "\u{2067}", "\u{2068}", "\u{2069}"] as $char) {
        expect(TitleSanitizer::clean("a{$char}b"))->toBe('ab');
    }
});

it('collapses consecutive whitespace', function (): void {
    expect(TitleSanitizer::clean("hello   world\n\nfoo"))->toBe('hello world foo');
});

it('truncates to 200 chars', function (): void {
    expect(mb_strlen(TitleSanitizer::clean(str_repeat('a', 500))))->toBeLessThanOrEqual(200);
});
