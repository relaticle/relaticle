<?php

declare(strict_types=1);

use Relaticle\Chat\Support\PromptText;

it('strips newlines', function (): void {
    expect(PromptText::sanitize("line one\nline two", 300))->toBe('line one line two');
});

it('strips control characters', function (): void {
    expect(PromptText::sanitize("hello\x07world", 300))->toBe('hello world');
});

it('strips double quotes', function (): void {
    expect(PromptText::sanitize('say "hello"', 300))->toBe('say hello');
});

it('strips backslashes', function (): void {
    expect(PromptText::sanitize('path\\to\\file', 300))->toBe('pathtofile');
});

it('collapses consecutive whitespace', function (): void {
    expect(PromptText::sanitize("foo   bar\t baz", 300))->toBe('foo bar baz');
});

it('caps output at the given max length', function (): void {
    $long = str_repeat('a', 50);
    expect(PromptText::sanitize($long, 10))->toBe(str_repeat('a', 10));
});

it('keeps clean text intact', function (): void {
    expect(PromptText::sanitize('Create 5 unique tasks', 300))->toBe('Create 5 unique tasks');
});
