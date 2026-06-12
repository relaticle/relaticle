<?php

declare(strict_types=1);

use App\Models\User;
use Filament\Facades\Filament;
use Relaticle\EmailIntegration\Filament\Pages\EmailInboxPage;
use Relaticle\EmailIntegration\Models\ConnectedAccount;
use Relaticle\EmailIntegration\Models\EmailSignature;
use Relaticle\EmailIntegration\Models\EmailTemplate;

beforeEach(function (): void {
    $this->user = User::factory()->withTeam()->create();
    $this->actingAs($this->user);
    $this->team = $this->user->currentTeam;
    Filament::setTenant($this->team);

    $this->account = ConnectedAccount::withoutEvents(fn () => ConnectedAccount::factory()->create([
        'team_id' => $this->team->id,
        'user_id' => $this->user->id,
        'email_address' => 'sender@example.com',
        'display_name' => 'Test Sender',
    ]));
});

it('keeps the default signature in the body when a template is selected', function (): void {
    $signature = EmailSignature::withoutEvents(fn () => EmailSignature::factory()->create([
        'connected_account_id' => $this->account->id,
        'content_html' => '<p>Best, Test Sender</p>',
        'is_default' => true,
    ]));

    $template = EmailTemplate::factory()->create([
        'team_id' => $this->team->id,
        'created_by' => $this->user->id,
        'subject' => 'Promo subject',
        'body_html' => '<p>Template body here</p>',
    ]);

    livewire(EmailInboxPage::class)
        ->mountAction('composeEmail')
        ->set('mountedActions.0.data.template_id', $template->id)
        ->assertSet('mountedActions.0.data.subject', 'Promo subject')
        ->assertSet('mountedActions.0.data.signature_id', $signature->id)
        ->assertSet('mountedActions.0.data.body_html', function (mixed $body) use ($signature): bool {
            // RichEditor holds state as a ProseMirror doc array; the signature is
            // a dedicated customBlock node carrying the signature id.
            $json = json_encode($body);

            return str_contains($json, 'Template body here')
                && str_contains($json, 'customBlock')
                && str_contains($json, $signature->id);
        });
});

it('removes the signature block when "no signature" is selected', function (): void {
    $signature = EmailSignature::withoutEvents(fn () => EmailSignature::factory()->create([
        'connected_account_id' => $this->account->id,
        'content_html' => '<p>Best, Test Sender</p>',
        'is_default' => true,
    ]));

    livewire(EmailInboxPage::class)
        ->mountAction('composeEmail')
        // Default load attaches the signature block...
        ->assertSet('mountedActions.0.data.body_html', fn (mixed $body): bool => str_contains((string) json_encode($body), 'customBlock'))
        // ...selecting the empty option strips it.
        ->set('mountedActions.0.data.signature_id', null)
        ->assertSet('mountedActions.0.data.body_html', fn (mixed $body): bool => ! str_contains((string) json_encode($body), 'customBlock'));
});
