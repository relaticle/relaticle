<?php

declare(strict_types=1);

use App\Models\Company;
use App\Models\People;
use App\Models\User;
use Filament\Facades\Filament;
use Relaticle\EmailIntegration\Models\ConnectedAccount;
use Relaticle\EmailIntegration\Models\EmailSignature;
use Relaticle\EmailIntegration\Models\EmailTemplate;
use Relaticle\EmailIntegration\Services\EmailTemplateRenderService;

mutates(EmailTemplateRenderService::class);

beforeEach(function (): void {
    $this->user = User::factory()->withTeam()->create();
    $this->actingAs($this->user);
    $this->team = $this->user->currentTeam;
    Filament::setTenant($this->team);
});

it('renders {name} and {company} for a People record', function (): void {
    $company = Company::create([
        'team_id' => $this->team->id,
        'name' => 'Acme Corp',
        'creator_id' => $this->user->id,
    ]);

    $person = People::create([
        'team_id' => $this->team->id,
        'name' => 'Jane Doe',
        'company_id' => $company->id,
        'creator_id' => $this->user->id,
    ]);

    $template = EmailTemplate::create([
        'team_id' => $this->team->id,
        'created_by' => $this->user->id,
        'name' => 'Test Template',
        'subject' => 'Hello {name}',
        'body_html' => '<p>Hi {name}, you work at {company}.</p>',
    ]);

    $result = app(EmailTemplateRenderService::class)->render($template, $person);

    expect($result['subject'])->toBe('Hello Jane Doe')
        ->and($result['body_html'])->toBe('<p>Hi Jane Doe, you work at Acme Corp.</p>');
});

it('HTML-escapes merge values in the body but not the plain-text subject', function (): void {
    // A contact name can be attacker-influenced (auto-created from an inbound email).
    $person = People::create([
        'team_id' => $this->team->id,
        'name' => '<img src=x onerror=alert(1)> & Co',
        'creator_id' => $this->user->id,
    ]);

    $template = EmailTemplate::create([
        'team_id' => $this->team->id,
        'created_by' => $this->user->id,
        'name' => 'XSS Template',
        'subject' => 'Hello {name}',
        'body_html' => '<p>Hi {name}</p>',
    ]);

    $result = app(EmailTemplateRenderService::class)->render($template, $person);

    // Body: markup is neutralised so the value cannot inject nodes into the email HTML.
    expect($result['body_html'])
        ->toContain('&lt;img src=x onerror=alert(1)&gt;')
        ->not->toContain('<img src=x');

    // Subject is plain text — escaping there would corrupt legitimate '&'/'<' characters.
    expect($result['subject'])->toBe('Hello <img src=x onerror=alert(1)> & Co');
});

it('renders {name} for a Company record', function (): void {
    $company = Company::create([
        'team_id' => $this->team->id,
        'name' => 'Beta Ltd',
        'creator_id' => $this->user->id,
    ]);

    $template = EmailTemplate::create([
        'team_id' => $this->team->id,
        'created_by' => $this->user->id,
        'name' => 'Company Template',
        'subject' => 'About {name}',
        'body_html' => '<p>Hello {company} team.</p>',
    ]);

    $result = app(EmailTemplateRenderService::class)->render($template, $company);

    expect($result['subject'])->toBe('About Beta Ltd')
        ->and($result['body_html'])->toBe('<p>Hello Beta Ltd team.</p>');
});

it('appends the signature below the body via renderWithSignature', function (): void {
    $account = ConnectedAccount::withoutEvents(
        fn () => ConnectedAccount::factory()->create([
            'team_id' => $this->team->id,
            'user_id' => $this->user->id,
        ])
    );

    $signature = EmailSignature::withoutEvents(
        fn () => EmailSignature::factory()->create([
            'connected_account_id' => $account->id,
            'content_html' => '<p>Best, Jane</p>',
        ])
    );

    $template = EmailTemplate::create([
        'team_id' => $this->team->id,
        'created_by' => $this->user->id,
        'name' => 'Sig Template',
        'subject' => 'Promo',
        'body_html' => '<p>Template body</p>',
    ]);

    $result = app(EmailTemplateRenderService::class)->renderWithSignature($template, null, $signature);

    expect($result['subject'])->toBe('Promo')
        ->and($result['body_html'])->toContain('<p>Template body</p>')
        ->and($result['body_html'])->toContain('data-id="signature"')
        ->and($result['body_html'])->toContain((string) $signature->id);
});

it('returns the plain body when renderWithSignature gets no signature', function (): void {
    $template = EmailTemplate::create([
        'team_id' => $this->team->id,
        'created_by' => $this->user->id,
        'name' => 'No Sig Template',
        'subject' => 'Promo',
        'body_html' => '<p>Template body</p>',
    ]);

    $result = app(EmailTemplateRenderService::class)->renderWithSignature($template);

    expect($result['body_html'])->toBe('<p>Template body</p>');
});

it('leaves placeholders unchanged when no record is passed', function (): void {
    $template = EmailTemplate::create([
        'team_id' => $this->team->id,
        'created_by' => $this->user->id,
        'name' => 'Generic Template',
        'subject' => 'Hello {name}',
        'body_html' => '<p>Hi {name} from {company}.</p>',
    ]);

    $result = app(EmailTemplateRenderService::class)->render($template, null);

    expect($result['subject'])->toBe('Hello {name}')
        ->and($result['body_html'])->toBe('<p>Hi {name} from {company}.</p>');
});

it('renders empty string when People record has no company', function (): void {
    $person = People::create([
        'team_id' => $this->team->id,
        'name' => 'Solo Person',
        'creator_id' => $this->user->id,
    ]);

    $template = EmailTemplate::create([
        'team_id' => $this->team->id,
        'created_by' => $this->user->id,
        'name' => 'No Company Template',
        'subject' => 'Hi {name}',
        'body_html' => '<p>You work at {company}.</p>',
    ]);

    $result = app(EmailTemplateRenderService::class)->render($template, $person);

    expect($result['subject'])->toBe('Hi Solo Person')
        ->and($result['body_html'])->toBe('<p>You work at .</p>');
});
