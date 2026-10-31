<?php

declare(strict_types=1);

use App\Filament\Resources\PeopleResource;
use App\Filament\Resources\PeopleResource\RelationManagers\EmailsRelationManager;
use App\Models\People;
use App\Models\User;
use Relaticle\EmailIntegration\Enums\EmailPrivacyTier;
use Relaticle\EmailIntegration\Models\ConnectedAccount;
use Relaticle\EmailIntegration\Models\Email;
use Relaticle\EmailIntegration\Models\EmailBody;
use Relaticle\EmailIntegration\Support\EmailHtmlSanitizer;

mutates(EmailHtmlSanitizer::class);

function readerFrameStyle(): string
{
    return <<<'JS'
        (() => {
            const doc = document.querySelector('.fi-email-reader-panel iframe').contentDocument;
            const body = getComputedStyle(doc.body);
            const styled = doc.querySelector('.own');

            return {
                theme: doc.documentElement.dataset.theme ?? null,
                scheme: getComputedStyle(doc.documentElement).colorScheme,
                font: body.fontFamily,
                size: body.fontSize,
                padding: body.paddingLeft,
                background: body.backgroundColor,
                ownFont: styled ? getComputedStyle(styled).fontFamily : null,
            };
        })();
    JS;
}

it('renders an email body in the app font and follows the app theme rather than the system one', function (string $systemTheme): void {
    $user = User::factory()->withWorkspace()->create();
    $workspace = $user->currentWorkspace;
    $account = ConnectedAccount::withoutEvents(fn (): ConnectedAccount => ConnectedAccount::factory()->create([
        'user_id' => $user->id, 'workspace_id' => $workspace->id, 'sync_cursor' => 'done',
    ]));
    $person = People::factory()->create(['workspace_id' => $workspace->id, 'creator_id' => $user->id]);
    $email = Email::factory()->create([
        'workspace_id' => $workspace->id, 'user_id' => $user->id, 'connected_account_id' => $account->id,
        'subject' => 'Pricing for 40 seats', 'privacy_tier' => EmailPrivacyTier::FULL,
    ]);
    EmailBody::create([
        'email_id' => $email->id,
        'body_html' => '<p>Thanks for the call.</p><p class="own" style="font-family: Georgia, serif">Signed</p>',
        'body_text' => 'Thanks for the call.',
    ]);
    $person->emails()->attach($email->id);
    $relation = array_search(EmailsRelationManager::class, PeopleResource::getRelations(), true);
    $systemIsDark = $systemTheme === 'inDarkMode';

    $page = loginViaBrowser($user, $systemTheme)
        ->assertPathIs("/app/{$workspace->slug}")
        ->resize(1440, 900)
        ->navigate("/app/{$workspace->slug}/people/{$person->id}?relation={$relation}")
        ->waitForText('Pricing for 40 seats')
        ->click('Pricing for 40 seats')
        ->assertVisible('.fi-email-reader-panel iframe')
        ->assertScript("document.querySelector('.fi-email-reader-panel iframe').contentDocument.readyState === 'complete'");

    $style = $page->script(readerFrameStyle());

    expect($style['theme'])->toBe($systemIsDark ? 'dark' : 'light')
        ->and($style['scheme'])->toBe($systemIsDark ? 'dark' : 'light')
        ->and($style['font'])->not->toContain('Times')
        ->and($style['size'])->toBe('14px')
        ->and($style['padding'])->toBe('16px')
        ->and($style['ownFont'])->toContain('Georgia');

    $appTheme = $systemIsDark ? 'light' : 'dark';
    $page->script("window.dispatchEvent(new CustomEvent('theme-changed', { detail: '{$appTheme}' }))");
    $page->assertScript("document.querySelector('.fi-email-reader-panel iframe').contentDocument.documentElement.dataset.theme === '{$appTheme}'");

    $style = $page->script(readerFrameStyle());

    expect($style['scheme'])->toBe($appTheme)
        ->and($style['background'])->toBe($appTheme === 'dark' ? 'rgba(0, 0, 0, 0)' : 'rgb(255, 255, 255)');

    $page->screenshot(filename: "email-reader-body-app-{$appTheme}-system-".($systemIsDark ? 'dark' : 'light'))
        ->assertNoJavaScriptErrors();
})->with(['inLightMode', 'inDarkMode']);
