<?php

declare(strict_types=1);

use App\Actions\Company\CreateCompany;
use App\Actions\People\CreatePeople;
use App\Features\EmailIntegration;
use App\Models\Company;
use App\Models\People;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Bus\UniqueLock;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Laravel\Pennant\Feature;
use Relaticle\EmailIntegration\Actions\LinkEmailAction;
use Relaticle\EmailIntegration\Actions\LinkEmailToRecord;
use Relaticle\EmailIntegration\Actions\LinkMeetingAction;
use Relaticle\EmailIntegration\Actions\UnlinkEmailFromRecord;
use Relaticle\EmailIntegration\Enums\ContactCreationMode;
use Relaticle\EmailIntegration\Enums\EmailAccountStatus;
use Relaticle\EmailIntegration\Jobs\RelinkRecordHistoryJob;
use Relaticle\EmailIntegration\Models\ConnectedAccount;
use Relaticle\EmailIntegration\Models\Email;
use Relaticle\EmailIntegration\Models\EmailParticipant;
use Relaticle\EmailIntegration\Models\Meeting;
use Relaticle\EmailIntegration\Models\MeetingAttendee;
use Relaticle\EmailIntegration\Support\QueueRecordHistoryRelink;
use Relaticle\ImportWizard\Data\ColumnData;
use Relaticle\ImportWizard\Enums\RowMatchAction;
use Relaticle\ImportWizard\Store\ImportStore;
use Tests\Helpers\ImportExecutionFixture;

mutates(RelinkRecordHistoryJob::class, QueueRecordHistoryRelink::class, LinkEmailAction::class, LinkMeetingAction::class, UnlinkEmailFromRecord::class);

afterEach(function (): void {
    if (isset($this->import)) {
        ImportStore::delete($this->import->id);
    }
});

beforeEach(function (): void {
    $this->user = User::factory()->withWorkspace()->create();
    $this->actingAs($this->user);
    $this->workspace = $this->user->currentWorkspace;
    Filament::setTenant($this->workspace);
    $this->workspace->update(['contact_creation_mode' => ContactCreationMode::None, 'auto_create_companies' => false]);

    $this->account = ConnectedAccount::withoutEvents(fn (): ConnectedAccount => ConnectedAccount::factory()->create([
        'workspace_id' => $this->workspace->id,
        'user_id' => $this->user->id,
    ]));

    $this->linkedEmailFrom = function (string $address): Email {
        $email = Email::factory()->create([
            'workspace_id' => $this->workspace->id,
            'user_id' => $this->user->id,
            'connected_account_id' => $this->account->getKey(),
        ]);

        EmailParticipant::factory()->from()->create([
            'email_id' => $email->getKey(),
            'email_address' => $address,
        ]);

        resolve(LinkEmailAction::class)->execute($email);

        return $email;
    };
});

it('links earlier mail to a company created later with that domain', function (): void {
    $email = ($this->linkedEmailFrom)('ceo@acme.com');
    expect($email->companies()->count())->toBe(0);

    $company = resolve(CreateCompany::class)->execute($this->user, [
        'name' => 'Acme',
        'custom_fields' => ['domains' => ['https://www.acme.com']],
    ]);

    expect($email->companies()->pluck('companies.id')->all())->toBe([$company->getKey()]);
});

it('links earlier mail to a person created later with that address', function (): void {
    $email = ($this->linkedEmailFrom)('Jane.Doe@Partner.com');

    $person = resolve(CreatePeople::class)->execute($this->user, [
        'name' => 'Jane Doe',
        'custom_fields' => ['emails' => ['jane.doe@partner.com']],
    ]);

    expect($email->people()->pluck('people.id')->all())->toBe([$person->getKey()]);
});

it('links an earlier meeting to a company created later with that domain', function (): void {
    $meeting = Meeting::factory()->create([
        'workspace_id' => $this->workspace->id,
        'connected_account_id' => $this->account->getKey(),
    ]);
    MeetingAttendee::factory()->create([
        'meeting_id' => $meeting->getKey(),
        'email_address' => 'guest@acme.com',
        'is_self' => false,
    ]);
    resolve(LinkMeetingAction::class)->execute($meeting->fresh());

    $company = resolve(CreateCompany::class)->execute($this->user, [
        'name' => 'Acme',
        'custom_fields' => ['domains' => ['acme.com']],
    ]);

    expect($meeting->companies()->pluck('companies.id')->all())->toBe([$company->getKey()]);
});

it('queues a relink again only when the record addresses change', function (): void {
    Bus::fake();

    $person = resolve(CreatePeople::class)->execute($this->user, [
        'name' => 'Jane Doe',
        'custom_fields' => ['emails' => ['jane@partner.com']],
    ]);
    $releaseUniqueLock = fn () => (new UniqueLock(Cache::driver()))->release(new RelinkRecordHistoryJob($person));

    Bus::assertDispatched(RelinkRecordHistoryJob::class, fn (RelinkRecordHistoryJob $job): bool => $job->record->is($person)
        && $job->uniqueId() === "relink-record-history-people-{$person->getKey()}");

    $releaseUniqueLock();
    $person->update(['name' => 'Jane D.', 'custom_fields' => ['emails' => ['jane@partner.com']]]);
    Bus::assertDispatchedTimes(RelinkRecordHistoryJob::class, 1);

    $releaseUniqueLock();
    $person->update(['custom_fields' => ['emails' => ['jane@partner.com', 'jane.doe@partner.com']]]);
    Bus::assertDispatchedTimes(RelinkRecordHistoryJob::class, 2);
});

it('links earlier mail to a person created by an import', function (): void {
    $email = ($this->linkedEmailFrom)('jane@partner.com');

    ImportExecutionFixture::readyStore($this, ['Name', 'Email'], [
        ImportExecutionFixture::row(2, ['Name' => 'Jane', 'Email' => 'jane@partner.com'], ['match_action' => RowMatchAction::Create->value]),
    ], [
        ColumnData::toField(source: 'Name', target: 'name'),
        ColumnData::toField(source: 'Email', target: 'custom_fields_emails'),
    ]);
    ImportExecutionFixture::run($this);

    expect($email->people()->pluck('name')->all())->toBe(['Jane']);
});

it('links earlier mail when an import adds an address to an existing person', function (): void {
    $email = ($this->linkedEmailFrom)('jane@newco.com');
    $person = People::factory()->create(['workspace_id' => $this->workspace->id, 'name' => 'Jane']);

    ImportExecutionFixture::readyStore($this, ['Name', 'Email'], [
        ImportExecutionFixture::row(2, ['Name' => 'Jane', 'Email' => 'jane@newco.com'], [
            'match_action' => RowMatchAction::Update->value,
            'matched_id' => (string) $person->getKey(),
        ]),
    ], [
        ColumnData::toField(source: 'Name', target: 'name'),
        ColumnData::toField(source: 'Email', target: 'custom_fields_emails'),
    ]);
    ImportExecutionFixture::run($this);

    expect($email->people()->pluck('people.id')->all())->toBe([$person->getKey()]);
});

it('counts a company reached through its person in the company email metrics', function (): void {
    $email = ($this->linkedEmailFrom)('jane@gmail.com');
    $company = Company::factory()->create(['workspace_id' => $this->workspace->id]);

    resolve(CreatePeople::class)->execute($this->user, [
        'name' => 'Jane',
        'company_id' => $company->getKey(),
        'custom_fields' => ['emails' => ['jane@gmail.com']],
    ]);

    expect($email->companies()->pluck('companies.id')->all())->toBe([$company->getKey()])
        ->and($company->fresh()->email_count)->toBe(1);
});

it('only relinks mail from the new record domain', function (): void {
    $unrelated = ($this->linkedEmailFrom)('buyer@elsewhere.com');
    $this->workspace->update(['contact_creation_mode' => ContactCreationMode::All]);

    resolve(CreateCompany::class)->execute($this->user, [
        'name' => 'Acme',
        'custom_fields' => ['domains' => ['acme.com']],
    ]);

    expect($unrelated->people()->count())->toBe(0);
});

it('does not queue a relink when the feature is off', function (): void {
    Bus::fake();
    Feature::deactivate(EmailIntegration::class);

    resolve(CreateCompany::class)->execute($this->user, [
        'name' => 'Acme',
        'custom_fields' => ['domains' => ['acme.com']],
    ]);

    Bus::assertNotDispatched(RelinkRecordHistoryJob::class);
});

it('counts a company reached through its person in the company meeting metrics', function (): void {
    $meeting = Meeting::factory()->create([
        'workspace_id' => $this->workspace->id,
        'connected_account_id' => $this->account->getKey(),
    ]);
    MeetingAttendee::factory()->create([
        'meeting_id' => $meeting->getKey(),
        'email_address' => 'jane@gmail.com',
        'is_self' => false,
    ]);
    resolve(LinkMeetingAction::class)->execute($meeting->fresh());
    $company = Company::factory()->create(['workspace_id' => $this->workspace->id]);

    resolve(CreatePeople::class)->execute($this->user, [
        'name' => 'Jane',
        'company_id' => $company->getKey(),
        'custom_fields' => ['emails' => ['jane@gmail.com']],
    ]);

    expect($meeting->companies()->pluck('companies.id')->all())->toBe([$company->getKey()])
        ->and($company->fresh()->meeting_count)->toBe(1);
});

it('does not queue a relink in a workspace without a connected mailbox', function (): void {
    Bus::fake();
    $this->account->delete();

    resolve(CreatePeople::class)->execute($this->user, [
        'name' => 'Jane Doe',
        'custom_fields' => ['emails' => ['jane@partner.com']],
    ]);

    Bus::assertNotDispatched(RelinkRecordHistoryJob::class);
});

it('still relinks while the only mailbox has a sync error', function (): void {
    $email = ($this->linkedEmailFrom)('jane@partner.com');
    $this->account->update(['status' => EmailAccountStatus::ERROR]);

    resolve(CreatePeople::class)->execute($this->user, [
        'name' => 'Jane Doe',
        'custom_fields' => ['emails' => ['jane@partner.com']],
    ]);

    expect($email->people()->count())->toBe(1);
});

it('leaves mail from a public email domain alone when a company claims it', function (): void {
    $email = ($this->linkedEmailFrom)('someone@gmail.com');
    $this->workspace->update(['contact_creation_mode' => ContactCreationMode::All]);

    resolve(CreateCompany::class)->execute($this->user, [
        'name' => 'Google',
        'custom_fields' => ['domains' => ['gmail.com']],
    ]);

    expect($email->companies()->count())->toBe(0)
        ->and($email->people()->count())->toBe(0);
});

it('does not touch a participant already linked to another person', function (): void {
    $email = ($this->linkedEmailFrom)('shared@partner.com');
    $existing = People::factory()->create(['workspace_id' => $this->workspace->id]);
    EmailParticipant::query()->where('email_id', $email->getKey())->update(['contact_id' => $existing->getKey()]);

    resolve(CreatePeople::class)->execute($this->user, [
        'name' => 'Duplicate',
        'custom_fields' => ['emails' => ['shared@partner.com']],
    ]);

    expect($email->people()->count())->toBe(0);
});

it('keeps an unlinked company off the mail when a new person of that company relinks it', function (): void {
    $email = ($this->linkedEmailFrom)('ana@airbnb.com');
    $company = resolve(CreateCompany::class)->execute($this->user, ['name' => 'Airbnb']);
    resolve(LinkEmailToRecord::class)->execute($this->user, $email, $company);
    resolve(UnlinkEmailFromRecord::class)->execute($this->user, $email, $company);

    resolve(CreatePeople::class)->execute($this->user, [
        'name' => 'Ana Ruiz',
        'company_id' => $company->getKey(),
        'custom_fields' => ['emails' => ['ana@airbnb.com']],
    ]);

    expect($email->people()->count())->toBe(1)
        ->and($email->companies()->whereKey($company->getKey())->exists())->toBeFalse();
});
