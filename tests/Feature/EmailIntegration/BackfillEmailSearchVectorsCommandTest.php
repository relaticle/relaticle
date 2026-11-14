<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Console\QueuedCommand;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Relaticle\EmailIntegration\Console\Commands\BackfillEmailSearchVectorsCommand;
use Relaticle\EmailIntegration\Models\ConnectedAccount;
use Relaticle\EmailIntegration\Models\Email;

mutates(BackfillEmailSearchVectorsCommand::class);

beforeEach(function (): void {
    $owner = User::factory()->withWorkspace()->create();
    $account = ConnectedAccount::withoutEvents(fn (): ConnectedAccount => ConnectedAccount::factory()->create([
        'workspace_id' => $owner->current_workspace_id, 'user_id' => $owner->getKey(),
    ]));

    $this->last = Email::factory()->create([
        'workspace_id' => $owner->current_workspace_id,
        'user_id' => $owner->getKey(),
        'connected_account_id' => $account->getKey(),
        'subject' => 'Orchard update',
    ]);

    $columns = array_values(array_diff(Schema::getColumnListing('emails'), ['id', 'rfc_message_id', 'provider_message_id']));
    $list = implode(', ', array_map(fn (string $column): string => "\"{$column}\"", $columns));

    DB::statement(
        "insert into emails (id, {$list}) select '01' || lpad(page::text, 24, '0'), {$list} from emails, generate_series(1, 1000) as page where emails.id = ?",
        [$this->last->getKey()],
    );
    DB::table('emails')->update(['subject_search' => null, 'body_search' => null]);
});

it('stops when its time runs out and queues the rest after the last email it filled', function (): void {
    Queue::fake();
    DB::listen(function (QueryExecuted $query): void {
        if (str_starts_with($query->sql, 'update "emails"')) {
            $this->travel(241)->seconds();
        }
    });

    $this->artisan('email:backfill-search-vectors', ['--force' => true])
        ->expectsOutputToContain('1000 email(s) filled. The rest is queued.')
        ->assertSuccessful();

    $lastFilled = '01'.str_pad('1000', 24, '0', STR_PAD_LEFT);

    expect(DB::table('emails')->whereNull('body_search')->pluck('id')->all())->toBe([$this->last->getKey()]);
    Queue::assertPushedOn('imports', QueuedCommand::class, fn (QueuedCommand $job): bool => (fn (): array => $this->data)->call($job) === [
        'email:backfill-search-vectors',
        ['--force' => true, '--after' => $lastFilled, '--chunk' => '20000', '--max-seconds' => '240'],
    ]);
});

it('fills every email in one run while its time lasts', function (): void {
    Queue::fake();

    $this->artisan('email:backfill-search-vectors', ['--force' => true])
        ->expectsOutputToContain('1001 email(s) filled.')
        ->assertSuccessful();

    expect(DB::table('emails')->whereNull('subject_search')->orWhereNull('body_search')->exists())->toBeFalse()
        ->and(DB::table('emails')->where('id', $this->last->getKey())->value('subject_search'))->toBe("'orchard':1 'update':2");
    Queue::assertNothingPushed();
});
