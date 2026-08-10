<?php

declare(strict_types=1);

use App\Models\Company;
use App\Models\People;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\DB;
use Relaticle\EmailIntegration\Enums\EmailDirection;
use Relaticle\EmailIntegration\Enums\EmailPrivacyTier;
use Relaticle\EmailIntegration\Models\ConnectedAccount;
use Relaticle\EmailIntegration\Models\Email;
use Relaticle\EmailIntegration\Models\EmailShare;
use Relaticle\EmailIntegration\Services\EmailSharingService;

mutates(EmailSharingService::class);

beforeEach(function (): void {
    $this->owner = User::factory()->withTeam()->create();
    $this->actingAs($this->owner);
    $this->team = $this->owner->currentTeam;
    Filament::setTenant($this->team);

    $this->account = ConnectedAccount::withoutEvents(fn () => ConnectedAccount::factory()->create([
        'team_id' => $this->team->id,
        'user_id' => $this->owner->id,
    ]));

    $this->service = app(EmailSharingService::class);
});

function makeSharingEmail(array $overrides = []): Email
{
    return Email::factory()->create(array_merge([
        'team_id' => test()->team->id,
        'user_id' => test()->owner->id,
        'connected_account_id' => test()->account->getKey(),
    ], $overrides));
}

it('creates an EmailShare record', function (): void {
    $viewer = User::factory()->create(['current_team_id' => $this->team->id]);
    $email = makeSharingEmail();

    $share = $this->service->shareEmail($email, $this->owner, $viewer, EmailPrivacyTier::FULL);

    expect($share)->toBeInstanceOf(EmailShare::class)
        ->and($share->email_id)->toBe($email->getKey())
        ->and($share->shared_by)->toBe($this->owner->getKey())
        ->and($share->shared_with)->toBe($viewer->getKey())
        ->and($share->tier)->toBe(EmailPrivacyTier::FULL->value);
});

it('updates an existing share when called again for the same viewer', function (): void {
    $viewer = User::factory()->create(['current_team_id' => $this->team->id]);
    $email = makeSharingEmail();

    $this->service->shareEmail($email, $this->owner, $viewer, EmailPrivacyTier::METADATA_ONLY);
    $this->service->shareEmail($email, $this->owner, $viewer, EmailPrivacyTier::FULL);

    $shares = EmailShare::where('email_id', $email->getKey())
        ->where('shared_with', $viewer->getKey())
        ->get();

    expect($shares)->toHaveCount(1)
        ->and($shares->first()->tier)->toBe(EmailPrivacyTier::FULL->value);
});

it('removes the share record', function (): void {
    $viewer = User::factory()->create(['current_team_id' => $this->team->id]);
    $email = makeSharingEmail();

    $this->service->shareEmail($email, $this->owner, $viewer, EmailPrivacyTier::FULL);
    $this->service->revokeShare($email, $viewer);

    $this->assertDatabaseMissing('email_shares', [
        'email_id' => $email->getKey(),
        'shared_with' => $viewer->getKey(),
    ]);
});

it('does nothing when no share exists', function (): void {
    $viewer = User::factory()->create(['current_team_id' => $this->team->id]);
    $email = makeSharingEmail();

    // Should not throw
    $this->service->revokeShare($email, $viewer);

    expect(EmailShare::where('email_id', $email->getKey())->count())->toBe(0);
});

it('updates the email privacy_tier', function (): void {
    $email = makeSharingEmail(['privacy_tier' => EmailPrivacyTier::METADATA_ONLY]);

    $this->service->setEmailTier($email, EmailPrivacyTier::FULL);

    expect($email->fresh()->privacy_tier)->toBe(EmailPrivacyTier::FULL);
});

it('shares all owner emails linked to a record', function (): void {
    $viewer = User::factory()->create(['current_team_id' => $this->team->id]);

    $person = People::create([
        'team_id' => $this->team->id,
        'name' => 'Jane Doe',
        'creator_id' => $this->owner->id,
    ]);

    $emailA = makeSharingEmail(['subject' => 'Email A']);
    $emailB = makeSharingEmail(['subject' => 'Email B']);

    $morphClass = (new People)->getMorphClass();
    DB::table('emailables')->insert([
        ['email_id' => $emailA->getKey(), 'emailable_type' => $morphClass, 'emailable_id' => $person->getKey(), 'created_at' => now(), 'updated_at' => now()],
        ['email_id' => $emailB->getKey(), 'emailable_type' => $morphClass, 'emailable_id' => $person->getKey(), 'created_at' => now(), 'updated_at' => now()],
    ]);

    $count = $this->service->shareAllOnRecord($person, $this->owner, $viewer, EmailPrivacyTier::SUBJECT);

    expect($count)->toBe(2);

    $this->assertDatabaseHas('email_shares', ['email_id' => $emailA->getKey(), 'shared_with' => $viewer->getKey(), 'tier' => EmailPrivacyTier::SUBJECT->value]);
    $this->assertDatabaseHas('email_shares', ['email_id' => $emailB->getKey(), 'shared_with' => $viewer->getKey(), 'tier' => EmailPrivacyTier::SUBJECT->value]);
});

it('only shares emails owned by the specified owner', function (): void {
    $otherUser = User::factory()->create(['current_team_id' => $this->team->id]);
    $viewer = User::factory()->create(['current_team_id' => $this->team->id]);

    $person = People::create([
        'team_id' => $this->team->id,
        'name' => 'Contact',
        'creator_id' => $this->owner->id,
    ]);

    $ownerEmail = makeSharingEmail(['subject' => 'Owner Email']);
    $otherEmail = Email::factory()->create([
        'team_id' => $this->team->id,
        'user_id' => $otherUser->id,
        'connected_account_id' => $this->account->getKey(),
        'subject' => 'Other User Email',
        'direction' => EmailDirection::INBOUND,
    ]);

    $morphClass = (new People)->getMorphClass();
    DB::table('emailables')->insert([
        ['email_id' => $ownerEmail->getKey(), 'emailable_type' => $morphClass, 'emailable_id' => $person->getKey(), 'created_at' => now(), 'updated_at' => now()],
        ['email_id' => $otherEmail->getKey(), 'emailable_type' => $morphClass, 'emailable_id' => $person->getKey(), 'created_at' => now(), 'updated_at' => now()],
    ]);

    $count = $this->service->shareAllOnRecord($person, $this->owner, $viewer, EmailPrivacyTier::FULL);

    expect($count)->toBe(1);
    $this->assertDatabaseHas('email_shares', ['email_id' => $ownerEmail->getKey(), 'shared_with' => $viewer->getKey()]);
    $this->assertDatabaseMissing('email_shares', ['email_id' => $otherEmail->getKey(), 'shared_with' => $viewer->getKey()]);
});

it('bulk updates privacy_tier on all owner emails linked to a record', function (): void {
    $company = Company::create([
        'team_id' => $this->team->id,
        'name' => 'Acme Corp',
        'creator_id' => $this->owner->id,
    ]);

    $emailA = makeSharingEmail(['privacy_tier' => EmailPrivacyTier::METADATA_ONLY]);
    $emailB = makeSharingEmail(['privacy_tier' => EmailPrivacyTier::METADATA_ONLY]);

    $morphClass = (new Company)->getMorphClass();
    DB::table('emailables')->insert([
        ['email_id' => $emailA->getKey(), 'emailable_type' => $morphClass, 'emailable_id' => $company->getKey(), 'created_at' => now(), 'updated_at' => now()],
        ['email_id' => $emailB->getKey(), 'emailable_type' => $morphClass, 'emailable_id' => $company->getKey(), 'created_at' => now(), 'updated_at' => now()],
    ]);

    $updated = $this->service->setTierForAllOnRecord($company, $this->owner, EmailPrivacyTier::FULL);

    expect($updated)->toBe(2)
        ->and($emailA->fresh()->privacy_tier)->toBe(EmailPrivacyTier::FULL)
        ->and($emailB->fresh()->privacy_tier)->toBe(EmailPrivacyTier::FULL);
});

it('returns 0 when no emails are linked to the record', function (): void {
    $person = People::create([
        'team_id' => $this->team->id,
        'name' => 'Nobody',
        'creator_id' => $this->owner->id,
    ]);

    $updated = $this->service->setTierForAllOnRecord($person, $this->owner, EmailPrivacyTier::FULL);

    expect($updated)->toBe(0);
});
