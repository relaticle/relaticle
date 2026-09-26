<?php

declare(strict_types=1);

use App\Filament\Resources\PeopleResource\Pages\PeopleEmailsPage;
use App\Models\People;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\DB;
use Relaticle\EmailIntegration\Models\ConnectedAccount;
use Relaticle\EmailIntegration\Models\Email;
use Relaticle\EmailIntegration\Models\EmailParticipant;
use Relaticle\EmailIntegration\Services\EmailVisibilityService;

mutates(EmailVisibilityService::class);

beforeEach(function (): void {
    $this->user = User::factory()->withWorkspace()->create();
    $this->workspace = $this->user->currentWorkspace;

    $this->account = ConnectedAccount::withoutEvents(fn (): ConnectedAccount => ConnectedAccount::factory()->create([
        'workspace_id' => $this->workspace->id,
        'user_id' => $this->user->id,
    ]));

    $this->person = People::factory()->create([
        'workspace_id' => $this->workspace->id,
        'creator_id' => $this->user->id,
    ]);

    $this->actingAs($this->user);
    Filament::setTenant($this->workspace);
});

function linkEmailsToPerson(People $person, User $user, ConnectedAccount $account, int $count): void
{
    foreach (range(1, $count) as $index) {
        $email = Email::factory()->inbound()->full()->create([
            'workspace_id' => $user->currentWorkspace->id,
            'user_id' => $user->id,
            'connected_account_id' => $account->getKey(),
            'subject' => "Pipeline update {$index}",
        ]);

        EmailParticipant::factory()->from()->create([
            'email_id' => $email->getKey(),
            'email_address' => "contact{$index}@vendor{$index}.test",
        ]);

        $person->emails()->attach($email->getKey());
    }
}

function queriesToRenderPeopleEmails(People $person): int
{
    app()->forgetScopedInstances();

    $queries = 0;
    DB::listen(function () use (&$queries): void {
        $queries++;
    });

    livewire(PeopleEmailsPage::class, ['record' => $person->getKey()])
        ->assertSee('Pipeline update 1');

    return $queries;
}

it('renders a record mailbox without a query per visible email', function (): void {
    linkEmailsToPerson($this->person, $this->user, $this->account, 2);
    $withTwoEmails = queriesToRenderPeopleEmails($this->person);

    linkEmailsToPerson($this->person, $this->user, $this->account, 10);
    $withTwelveEmails = queriesToRenderPeopleEmails($this->person);

    expect($withTwelveEmails - $withTwoEmails)->toBeLessThan(10);
});
