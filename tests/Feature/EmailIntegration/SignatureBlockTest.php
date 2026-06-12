<?php

declare(strict_types=1);

use App\Models\User;
use Filament\Facades\Filament;
use Relaticle\EmailIntegration\Filament\RichContent\SignatureBlock;
use Relaticle\EmailIntegration\Models\ConnectedAccount;
use Relaticle\EmailIntegration\Models\EmailSignature;
use Relaticle\EmailIntegration\Services\EmailTemplateRenderService;

beforeEach(function (): void {
    $this->user = User::factory()->withTeam()->create();
    $this->actingAs($this->user);
    $this->team = $this->user->currentTeam;
    Filament::setTenant($this->team);

    $this->account = ConnectedAccount::withoutEvents(fn () => ConnectedAccount::factory()->create([
        'team_id' => $this->team->id,
        'user_id' => $this->user->id,
    ]));

    $this->signature = EmailSignature::withoutEvents(fn () => EmailSignature::factory()->create([
        'connected_account_id' => $this->account->id,
        'content_html' => '<p>Best, Jane</p>',
    ]));

    $this->service = app(EmailTemplateRenderService::class);
});

it('appends a signature block carrying the signature id', function (): void {
    $body = $this->service->applySignatureBlock('<p>Hello</p>', $this->signature);

    expect($body)->toContain('<p>Hello</p>')
        ->toContain('data-id="'.SignatureBlock::ID.'"')
        ->toContain('signature_id');
});

it('replaces an existing signature block instead of stacking', function (): void {
    $once = $this->service->applySignatureBlock('<p>Hello</p>', $this->signature);
    $twice = $this->service->applySignatureBlock($once, $this->signature);

    expect(substr_count($twice, 'data-id="'.SignatureBlock::ID.'"'))->toBe(1);
});

it('removes the signature block when no signature is given', function (): void {
    $withBlock = $this->service->applySignatureBlock('<p>Hello</p>', $this->signature);
    $cleared = $this->service->applySignatureBlock($withBlock, null);

    expect($cleared)->toContain('<p>Hello</p>')
        ->not->toContain('data-id="'.SignatureBlock::ID.'"');
});

it('expands the signature block into signature html when rendered for sending', function (): void {
    $body = $this->service->applySignatureBlock('<p>Hello</p>', $this->signature);

    $sent = $this->service->renderForSending($body);

    expect($sent)->toContain('Hello')
        ->toContain('Best, Jane')
        ->not->toContain('data-type="customBlock"');
});
