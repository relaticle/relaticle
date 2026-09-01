<?php

declare(strict_types=1);

use App\Models\User;
use Filament\Facades\Filament;
use Relaticle\EmailIntegration\Filament\Resources\EmailTemplateResource\Pages\ManageEmailTemplates;
use Relaticle\EmailIntegration\Models\EmailTemplate;

mutates(EmailTemplate::class);

beforeEach(function (): void {
    $this->user = User::factory()->withTeam()->create();
    $this->actingAs($this->user);
    $this->team = $this->user->currentTeam;
    Filament::setTenant($this->team);
});

it('bulk delete removes the user\'s own templates', function (): void {
    $mineA = EmailTemplate::factory()->create([
        'team_id' => $this->team->id,
        'created_by' => $this->user->id,
    ]);
    $mineB = EmailTemplate::factory()->create([
        'team_id' => $this->team->id,
        'created_by' => $this->user->id,
    ]);

    livewire(ManageEmailTemplates::class)
        ->selectTableRecords([$mineA, $mineB])
        ->callAction([['name' => 'delete', 'context' => ['table' => true, 'bulk' => true]]]);

    expect(EmailTemplate::whereKey($mineA->getKey())->exists())->toBeFalse()
        ->and(EmailTemplate::whereKey($mineB->getKey())->exists())->toBeFalse();
});

it('bulk delete preserves a shared template created by another user', function (): void {
    $mine = EmailTemplate::factory()->create([
        'team_id' => $this->team->id,
        'created_by' => $this->user->id,
    ]);

    $otherUser = User::factory()->create();
    $this->team->users()->attach($otherUser);

    $theirShared = EmailTemplate::factory()->shared()->create([
        'team_id' => $this->team->id,
        'created_by' => $otherUser->id,
    ]);

    livewire(ManageEmailTemplates::class)
        ->selectTableRecords([$mine, $theirShared])
        ->callAction([['name' => 'delete', 'context' => ['table' => true, 'bulk' => true]]]);

    expect(EmailTemplate::whereKey($mine->getKey())->exists())->toBeFalse()
        ->and(EmailTemplate::whereKey($theirShared->getKey())->exists())->toBeTrue();
});
