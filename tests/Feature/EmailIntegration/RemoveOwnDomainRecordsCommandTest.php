<?php

declare(strict_types=1);

use App\Enums\CreationSource;
use App\Enums\CustomFields\CompanyField;
use App\Enums\CustomFields\PeopleField;
use App\Models\Company;
use App\Models\CustomField;
use App\Models\Note;
use App\Models\People;
use App\Models\User;
use App\Support\CurrentSource;
use App\Support\CurrentWorkspace;
use Filament\Facades\Filament;
use Relaticle\EmailIntegration\Console\Commands\RemoveOwnDomainRecordsCommand;

mutates(RemoveOwnDomainRecordsCommand::class);

beforeEach(function (): void {
    $this->user = User::factory()->withWorkspace()->create(['email' => 'owner@ownco.com']);
    $this->workspace = $this->user->currentWorkspace;
    $this->actingAs($this->user);
    Filament::setTenant($this->workspace);
    resolve(CurrentWorkspace::class)->set($this->workspace);

    $this->person = fn (string $name, array $emails, CreationSource $source = CreationSource::MAILBOX): People => CurrentSource::during($source, function () use ($name, $emails, $source): People {
        $person = People::query()->create([
            'workspace_id' => $this->workspace->getKey(),
            'name' => $name,
            'creation_source' => $source,
        ]);

        $person->saveCustomFieldValue(($this->field)('people', PeopleField::EMAILS->value), $emails, $this->workspace);

        return $person;
    });

    $this->company = fn (string $name, string $domain, CreationSource $source = CreationSource::MAILBOX): Company => CurrentSource::during($source, function () use ($name, $domain, $source): Company {
        $company = Company::query()->create([
            'workspace_id' => $this->workspace->getKey(),
            'name' => $name,
            'creation_source' => $source,
        ]);

        $company->saveCustomFieldValue(($this->field)('company', CompanyField::DOMAINS->value), [$domain], $this->workspace);

        return $company;
    });

    $this->field = fn (string $entityType, string $code): CustomField => CustomField::query()
        ->withoutGlobalScopes()
        ->where('tenant_id', $this->workspace->getKey())
        ->where('entity_type', $entityType)
        ->where('code', $code)
        ->firstOrFail();
});

it('reports own-domain records the mailbox sync created without deleting them', function (): void {
    $colleague = ($this->person)('Colleague', ['colleague@ownco.com']);
    $ownCompany = ($this->company)('Ownco', 'ownco.com');
    CurrentSource::during(CreationSource::MAILBOX, fn (): bool => $colleague->update(['company_id' => $ownCompany->getKey()]));

    $this->artisan('email:remove-own-domain-records')
        ->expectsOutputToContain('1 person(s) and 1 company(ies) on own domains. Re-run with --force to delete them.')
        ->assertSuccessful();

    expect($colleague->fresh()->trashed())->toBeFalse()
        ->and($ownCompany->fresh()->trashed())->toBeFalse();
});

it('deletes only untouched own-domain records the mailbox sync created', function (): void {
    $colleague = ($this->person)('Colleague', ['colleague@ownco.com']);
    $ownCompany = ($this->company)('Ownco', 'ownco.com');
    CurrentSource::during(CreationSource::MAILBOX, fn (): bool => $colleague->update(['company_id' => $ownCompany->getKey()]));

    $client = ($this->person)('Client', ['client@acme.com']);
    $clientCompany = ($this->company)('Acme', 'acme.com');
    $addedByHand = ($this->person)('Teammate', ['teammate@ownco.com'], CreationSource::WEB);
    $mixed = ($this->person)('Two Hats', ['twohats@ownco.com', 'twohats@acme.com']);
    $withNote = ($this->person)('Noted', ['noted@ownco.com']);
    $withNote->notes()->attach(Note::factory()->for($this->workspace)->create());
    $renamed = ($this->person)('Renamed', ['renamed@ownco.com']);
    $renamed->update(['name' => 'Renamed By Hand']);

    $this->artisan('email:remove-own-domain-records', ['--force' => true])
        ->expectsOutputToContain('1 person(s) and 1 company(ies) on own domains deleted.')
        ->assertSuccessful();

    expect($colleague->fresh()->trashed())->toBeTrue()
        ->and($ownCompany->fresh()->trashed())->toBeTrue()
        ->and(collect([$client, $clientCompany, $addedByHand, $mixed, $withNote, $renamed])
            ->every(fn (People|Company $record): bool => ! $record->fresh()->trashed()))->toBeTrue();
});

it('keeps an own-domain company that still has a person the command keeps', function (): void {
    $ownCompany = ($this->company)('Ownco', 'ownco.com');
    $teammate = ($this->person)('Teammate', ['teammate@ownco.com'], CreationSource::WEB);
    CurrentSource::during(CreationSource::MAILBOX, fn (): bool => $teammate->update(['company_id' => $ownCompany->getKey()]));

    $this->artisan('email:remove-own-domain-records', ['--force' => true])
        ->expectsOutputToContain('0 person(s) and 0 company(ies) on own domains deleted.')
        ->assertSuccessful();

    expect($ownCompany->fresh()->trashed())->toBeFalse();
});
