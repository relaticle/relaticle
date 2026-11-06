<?php

declare(strict_types=1);

use App\Models\Company;
use App\Models\CustomField;
use App\Models\Opportunity;
use App\Models\User;
use App\Models\Workspace;
use App\Support\CurrentWorkspace;
use Filament\Facades\Filament;
use Filament\Notifications\NotificationMailbox;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Sleep;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Relaticle\EmailIntegration\Actions\ApplyEmailLinkChanges;
use Relaticle\EmailIntegration\Actions\LinkEmailAction;
use Relaticle\EmailIntegration\Actions\LinkEmailToRecord;
use Relaticle\EmailIntegration\Actions\PrepareEmailRecordLink;
use Relaticle\EmailIntegration\Actions\RefreshConversationSummary;
use Relaticle\EmailIntegration\Actions\RestoreEmailRecordLinks;
use Relaticle\EmailIntegration\Actions\UnlinkEmailFromRecord;
use Relaticle\EmailIntegration\Data\ConversationFilters;
use Relaticle\EmailIntegration\Enums\ConversationView;
use Relaticle\EmailIntegration\Enums\EmailDirection;
use Relaticle\EmailIntegration\Enums\EmailPrivacyTier;
use Relaticle\EmailIntegration\Enums\EmailStatus;
use Relaticle\EmailIntegration\Filament\Concerns\HasConversationReader;
use Relaticle\EmailIntegration\Filament\Concerns\HasEmailReaderActions;
use Relaticle\EmailIntegration\Filament\Pages\EmailInboxPage;
use Relaticle\EmailIntegration\Jobs\RecomputeRecordEmailMetricsJob;
use Relaticle\EmailIntegration\Livewire\EmailAccessNotificationHandler;
use Relaticle\EmailIntegration\Models\ConnectedAccount;
use Relaticle\EmailIntegration\Models\Email;
use Relaticle\EmailIntegration\Models\EmailLinkSuppression;
use Relaticle\EmailIntegration\Models\EmailParticipant;
use Relaticle\EmailIntegration\Models\EmailShare;
use Relaticle\EmailIntegration\Queries\ConversationsQuery;
use Relaticle\EmailIntegration\Services\EmailVisibilityService;
use Relaticle\EmailIntegration\Services\RecordCommunicationMetrics;
use Symfony\Component\HttpKernel\Exception\HttpException;

mutates(
    ApplyEmailLinkChanges::class,
    LinkEmailAction::class,
    LinkEmailToRecord::class,
    UnlinkEmailFromRecord::class,
    PrepareEmailRecordLink::class,
    RestoreEmailRecordLinks::class,
    RecordCommunicationMetrics::class,
    RecomputeRecordEmailMetricsJob::class,
    HasEmailReaderActions::class,
    HasConversationReader::class,
);

beforeEach(function (): void {
    $this->owner = User::factory()->withWorkspace()->create();
    $this->workspace = $this->owner->currentWorkspace;
    $this->teammate = User::factory()->create(['current_workspace_id' => $this->workspace->getKey()]);
    $this->workspace->users()->attach($this->teammate, ['role' => 'member']);
    $this->company = Company::factory()->for($this->workspace)->create(['name' => 'Airbnb']);

    $this->actingAs($this->owner);
    Filament::setTenant($this->workspace);
});

function linkingEmail(User $owner, array $attributes = []): Email
{
    $mailbox = ConnectedAccount::query()
        ->where('workspace_id', $owner->current_workspace_id)
        ->where('user_id', $owner->getKey())
        ->first() ?? ConnectedAccount::withoutEvents(fn (): ConnectedAccount => ConnectedAccount::factory()->create([
            'workspace_id' => $owner->current_workspace_id,
            'user_id' => $owner->getKey(),
            'status' => 'active',
        ]));

    return Email::factory()->create([
        'workspace_id' => $owner->current_workspace_id,
        'user_id' => $owner->getKey(),
        'connected_account_id' => $mailbox->getKey(),
        'conversation_key' => 'airbnb-renewal',
        'rfc_message_id' => '<renewal@airbnb.test>',
        'direction' => EmailDirection::INBOUND,
        'status' => EmailStatus::SYNCED,
        'privacy_tier' => EmailPrivacyTier::SUBJECT,
        'is_internal' => false,
        'sent_at' => now(),
        'linked_at' => now(),
        ...$attributes,
    ]);
}

function linkSource(Email $email, Company $company): ?string
{
    $source = DB::table('emailables')
        ->where('email_id', $email->getKey())
        ->where('emailable_type', $company->getMorphClass())
        ->where('emailable_id', $company->getKey())
        ->value('link_source');

    return $source === null ? null : (string) $source;
}

it('refuses an unlink by a teammate who cannot see the email and keeps the link', function (): void {
    $email = linkingEmail($this->owner, ['privacy_tier' => EmailPrivacyTier::PRIVATE, 'privacy_tier_customized' => true]);
    $email->companies()->attach($this->company->getKey(), ['link_source' => 'auto']);

    expect(fn (): mixed => resolve(UnlinkEmailFromRecord::class)->execute($this->teammate, $email, $this->company))
        ->toThrow(HttpException::class);

    expect(linkSource($email, $this->company))->toBe('auto')
        ->and(EmailLinkSuppression::query()->count())->toBe(0);
});

it('refuses to link an email to a record from another workspace', function (): void {
    $foreign = Company::factory()->for(Workspace::factory())->create();
    $email = linkingEmail($this->owner);

    expect(fn (): mixed => resolve(LinkEmailToRecord::class)->execute($this->owner, $email, $foreign))
        ->toThrow(HttpException::class);

    expect(DB::table('emailables')->where('email_id', $email->getKey())->count())->toBe(0);
});

it('pins the copies the user reached and leaves the copies they only see as they were', function (): void {
    $own = linkingEmail($this->owner);
    $sharedCopy = linkingEmail($this->teammate, ['rfc_message_id' => '<renewal-shared@airbnb.test>']);
    EmailShare::factory()->create(['email_id' => $sharedCopy->getKey(), 'shared_with' => $this->owner->getKey(), 'shared_by' => $this->teammate->getKey()]);
    $teammateCopy = linkingEmail($this->teammate);
    $teammateCopy->companies()->attach($this->company->getKey(), ['link_source' => 'auto']);
    $privateCopy = linkingEmail($this->teammate, [
        'rfc_message_id' => '<renewal-reply@airbnb.test>',
        'privacy_tier' => EmailPrivacyTier::PRIVATE,
        'privacy_tier_customized' => true,
    ]);

    resolve(LinkEmailToRecord::class)->execute($this->owner, $own, $this->company);

    expect(linkSource($own, $this->company))->toBe('manual')
        ->and(linkSource($sharedCopy, $this->company))->toBe('manual')
        ->and(linkSource($teammateCopy, $this->company))->toBe('auto')
        ->and(linkSource($privateCopy, $this->company))->toBeNull();
});

it('refuses a teammate who reached no copy and leaves the owner\'s copy on the record', function (EmailPrivacyTier $tier): void {
    $owners = linkingEmail($this->owner, ['privacy_tier' => $tier, 'privacy_tier_customized' => true]);
    $owners->companies()->attach($this->company->getKey(), ['link_source' => 'auto']);
    $stripe = Company::factory()->for($this->workspace)->create(['name' => 'Stripe']);

    expect(fn (): mixed => resolve(UnlinkEmailFromRecord::class)->execute($this->teammate, $owners, $this->company))
        ->toThrow(ValidationException::class, 'Only people who hold this conversation')
        ->and(fn (): mixed => resolve(LinkEmailToRecord::class)->execute($this->teammate, $owners, $stripe))
        ->toThrow(ValidationException::class, 'Only people who hold this conversation');

    expect(linkSource($owners, $this->company))->toBe('auto')
        ->and(DB::table('emailables')->where('emailable_id', $stripe->getKey())->exists())->toBeFalse()
        ->and(EmailLinkSuppression::query()->count())->toBe(0);
})->with([EmailPrivacyTier::SUBJECT, EmailPrivacyTier::METADATA_ONLY]);

it('unlinks only the teammate\'s own copy when the owner\'s copy is visible to them below full', function (EmailPrivacyTier $tier): void {
    $owners = linkingEmail($this->owner, ['privacy_tier' => $tier, 'privacy_tier_customized' => true]);
    $teammates = linkingEmail($this->teammate, ['rfc_message_id' => '<renewal-cc@airbnb.test>']);
    $owners->companies()->attach($this->company->getKey(), ['link_source' => 'auto']);
    $teammates->companies()->attach($this->company->getKey(), ['link_source' => 'auto']);

    resolve(UnlinkEmailFromRecord::class)->execute($this->teammate, $owners, $this->company);

    expect(linkSource($owners, $this->company))->toBe('auto')
        ->and(linkSource($teammates, $this->company))->toBeNull()
        ->and(EmailLinkSuppression::query()->pluck('email_id')->all())->toBe([$teammates->getKey()]);
})->with([EmailPrivacyTier::SUBJECT, EmailPrivacyTier::METADATA_ONLY]);

it('unlinks the copies the user reached, so the conversation leaves the record for the user', function (): void {
    $own = linkingEmail($this->owner);
    $teammateCopy = linkingEmail($this->teammate);
    resolve(LinkEmailToRecord::class)->execute($this->owner, $own, $this->company);
    resolve(RefreshConversationSummary::class)->execute($this->workspace->getKey(), 'airbnb-renewal');
    $conversations = resolve(ConversationsQuery::class);
    $onCompany = fn (): array => $conversations->paginate($this->owner, ConversationView::All, ConversationFilters::forRecord($this->company))->rows;

    expect($onCompany())->toHaveCount(1)
        ->and($conversations->counts($this->owner)['unlinked'])->toBe(0);

    resolve(UnlinkEmailFromRecord::class)->execute($this->owner, $own, $this->company);

    expect($onCompany())->toBeEmpty()
        ->and($conversations->counts($this->owner)['unlinked'])->toBe(1)
        ->and(linkSource($own, $this->company))->toBeNull()
        ->and(linkSource($teammateCopy, $this->company))->toBeNull()
        ->and(EmailLinkSuppression::query()->where('user_id', $this->owner->getKey())->pluck('email_id')->all())->toBe([$own->getKey()]);
});

it('links a record again after an unlink and clears the suppression', function (): void {
    $email = linkingEmail($this->owner);
    resolve(LinkEmailToRecord::class)->execute($this->owner, $email, $this->company);
    resolve(UnlinkEmailFromRecord::class)->execute($this->owner, $email, $this->company);

    resolve(LinkEmailToRecord::class)->execute($this->owner, $email, $this->company);
    resolve(LinkEmailAction::class)->reapply($email->fresh());

    expect(linkSource($email, $this->company))->toBe('manual')
        ->and(EmailLinkSuppression::query()->count())->toBe(0);
});

it('counts a newly linked email once and recounts the record after an unlink', function (): void {
    $this->travelTo(now()->setDate(2026, 6, 1)->startOfDay());
    $earlier = linkingEmail($this->owner, [
        'conversation_key' => 'airbnb-kickoff',
        'rfc_message_id' => '<kickoff@airbnb.test>',
        'direction' => EmailDirection::OUTBOUND,
        'sent_at' => now()->subDays(3),
    ]);
    $latest = linkingEmail($this->owner);
    $link = resolve(LinkEmailToRecord::class);

    $link->execute($this->owner, $earlier, $this->company);
    $link->execute($this->owner, $latest, $this->company);
    $link->execute($this->owner, $latest, $this->company);

    expect($this->company->fresh())
        ->email_count->toBe(2)
        ->inbound_email_count->toBe(1)
        ->outbound_email_count->toBe(1)
        ->last_email_at->toEqual(now());

    resolve(UnlinkEmailFromRecord::class)->execute($this->owner, $latest, $this->company);

    expect($this->company->fresh())
        ->email_count->toBe(1)
        ->inbound_email_count->toBe(0)
        ->outbound_email_count->toBe(1)
        ->last_email_at->toEqual(now()->subDays(3))
        ->last_interaction_at->toEqual(now()->subDays(3));
});

it('counts a copy once when the link comes before its own sync linking', function (): void {
    $pending = linkingEmail($this->owner, ['linked_at' => null]);

    resolve(LinkEmailToRecord::class)->execute($this->owner, $pending, $this->company);

    expect($this->company->fresh()->email_count)->toBe(0);

    resolve(LinkEmailAction::class)->execute($pending->fresh());

    expect($this->company->fresh()->email_count)->toBe(1);
});

it('leaves undelivered copies of the conversation off the record and out of its counts', function (): void {
    $sent = linkingEmail($this->owner);
    $queued = linkingEmail($this->owner, [
        'rfc_message_id' => '<queued@airbnb.test>',
        'direction' => EmailDirection::OUTBOUND,
        'status' => EmailStatus::QUEUED,
        'sent_at' => null,
    ]);

    resolve(LinkEmailToRecord::class)->execute($this->owner, $sent, $this->company);

    expect(linkSource($queued, $this->company))->toBeNull()
        ->and($this->company->fresh()->email_count)->toBe(1);
});

it('takes a suppressed link off the email when sync linking finds both', function (): void {
    $email = linkingEmail($this->owner);
    $email->companies()->attach($this->company->getKey(), ['link_source' => 'auto']);
    $this->company->forceFill(['email_count' => 1, 'inbound_email_count' => 1])->saveQuietly();
    EmailLinkSuppression::factory()->create([
        'workspace_id' => $this->workspace->getKey(),
        'email_id' => $email->getKey(),
        'emailable_type' => $this->company->getMorphClass(),
        'emailable_id' => $this->company->getKey(),
    ]);

    resolve(LinkEmailAction::class)->reapply($email->fresh());

    expect(linkSource($email, $this->company))->toBeNull()
        ->and($this->company->fresh()->email_count)->toBe(0);
});

it('reads the copies it unlinks only after the conversation lock is free', function (): void {
    if (! extension_loaded('pgsql')) {
        $this->markTestSkipped('Needs ext-pgsql to hold the conversation lock from a second connection.');
    }

    $email = linkingEmail($this->owner);
    $email->companies()->attach($this->company->getKey(), ['link_source' => 'auto']);
    $late = Email::factory()->make([
        'id' => strtolower((string) Str::ulid()),
        'workspace_id' => $this->workspace->getKey(),
        'user_id' => $this->owner->getKey(),
        'connected_account_id' => $email->connected_account_id,
        'conversation_key' => 'airbnb-renewal',
        'rfc_message_id' => '<renewal-late@airbnb.test>',
        'direction' => EmailDirection::INBOUND,
        'status' => EmailStatus::SYNCED,
        'privacy_tier' => EmailPrivacyTier::SUBJECT,
        'is_internal' => false,
        'sent_at' => now(),
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    $row = array_map(fn (mixed $value): mixed => $value instanceof BackedEnum ? $value->value : $value, $late->getAttributes());
    $insert = DB::table('emails')->getGrammar()->compileInsert(DB::table('emails'), [$row]);
    $lock = DB::getQueryGrammar()->substituteBindingsIntoRawSql('select pg_advisory_xact_lock(?, hashtext(?))', [Email::CONVERSATION_LOCK_CLASS, $this->workspace->getKey().':airbnb-renewal']);
    $waiting = 'select 1 from pg_locks where locktype = \'advisory\' and classid = '.Email::CONVERSATION_LOCK_CLASS.' and objsubid = 2 and database = (select oid from pg_database where datname = current_database())';
    $connection = config('database.connections.'.config('database.default'));
    $holder = pg_connect(sprintf("host='%s' port='%s' dbname='%s' user='%s' password='%s'", $connection['host'], $connection['port'], $connection['database'], $connection['username'], $connection['password']), PGSQL_CONNECT_FORCE_NEW);
    $this->beforeApplicationDestroyed(function () use ($holder, $late): void {
        pg_query_params($holder, 'delete from emails where id = $1', [$late->getKey()]);
        pg_close($holder);
    });

    pg_send_query($holder, implode(";\n", [
        'begin',
        $lock,
        "do \$\$ begin for attempt in 1..1000 loop exit when exists ({$waiting} and not granted); perform pg_sleep(0.01); end loop; end \$\$",
        'set local session_replication_role = replica',
        DB::getQueryGrammar()->substituteBindingsIntoRawSql($insert, array_values($row)),
        'commit',
    ]));

    $deadline = now()->addSeconds(10);

    while (DB::selectOne("select exists ({$waiting} and granted and pid <> pg_backend_pid()) as held")->held === false && now()->lessThan($deadline)) {
        Sleep::for(10)->milliseconds();
    }

    $changed = resolve(UnlinkEmailFromRecord::class)->execute($this->owner, $email, $this->company);

    while (($result = pg_get_result($holder)) !== false) {
        expect(pg_result_error($result))->toBe('');
    }

    expect($changed)->toEqualCanonicalizing([$email->getKey() => 'auto', $late->getKey() => null])
        ->and(EmailLinkSuppression::query()->where('email_id', $late->getKey())->exists())->toBeTrue();
});

it('takes every conversation lock of a batch in key order before it changes a link', function (): void {
    $later = linkingEmail($this->owner, ['conversation_key' => 'stripe-renewal', 'rfc_message_id' => '<stripe@stripe.test>']);
    $earlier = linkingEmail($this->owner);
    $locked = [];
    DB::listen(function (QueryExecuted $query) use (&$locked): void {
        if (str_contains($query->sql, 'pg_advisory_xact_lock(?, hashtext(?))')) {
            $locked[] = $query->bindings[1];
        }
    });

    resolve(ApplyEmailLinkChanges::class)->execute($this->owner, [
        ['email' => $later, 'record' => $this->company],
        ['email' => $earlier, 'record' => $this->company],
    ], resolve(LinkEmailToRecord::class));

    expect(array_slice($locked, 0, 2))->toBe([$this->workspace->getKey().':airbnb-renewal', $this->workspace->getKey().':stripe-renewal']);
});

it('sorts a batch by the key each email holds now, after a merge moved one', function (): void {
    $merged = linkingEmail($this->owner, ['conversation_key' => 'zulu-old', 'rfc_message_id' => '<zulu@airbnb.test>']);
    $other = linkingEmail($this->owner, ['conversation_key' => 'mike-renewal', 'rfc_message_id' => '<mike@airbnb.test>']);
    Email::query()->withoutGlobalScopes()->whereKey($merged->getKey())->update(['conversation_key' => 'alpha-merged']);
    $locked = [];
    DB::listen(function (QueryExecuted $query) use (&$locked): void {
        if (str_contains($query->sql, 'pg_advisory_xact_lock(?, hashtext(?))')) {
            $locked[] = $query->bindings[1];
        }
    });

    resolve(ApplyEmailLinkChanges::class)->execute($this->owner, [
        ['email' => $other, 'record' => $this->company],
        ['email' => $merged, 'record' => $this->company],
    ], resolve(LinkEmailToRecord::class));

    expect(array_slice($locked, 0, 2))->toBe([$this->workspace->getKey().':alpha-merged', $this->workspace->getKey().':mike-renewal']);
});

it('links the conversation a merge moved the email into after it was loaded', function (): void {
    $email = linkingEmail($this->owner, ['conversation_key' => 'airbnb-old']);
    $reply = linkingEmail($this->owner, ['rfc_message_id' => '<renewal-reply@airbnb.test>']);
    Email::query()->withoutGlobalScopes()->whereKey($email->getKey())->update(['conversation_key' => 'airbnb-renewal']);

    resolve(LinkEmailToRecord::class)->execute($this->owner, $email, $this->company);

    expect(linkSource($email, $this->company))->toBe('manual')
        ->and(linkSource($reply, $this->company))->toBe('manual');
});

it('waits for the conversation lock before it changes links', function (Closure $change): void {
    $email = linkingEmail($this->owner);
    config(['database.connections.conversation_lock' => config('database.connections.'.config('database.default'))]);
    $holder = DB::connection('conversation_lock');
    $holder->beginTransaction();
    $holder->select('select pg_advisory_xact_lock(?, hashtext(?))', [Email::CONVERSATION_LOCK_CLASS, $this->workspace->getKey().':airbnb-renewal']);
    DB::statement("set local lock_timeout = '200ms'");

    try {
        expect(fn (): mixed => $change($this->owner, $email, $this->company))->toThrow(QueryException::class, 'lock timeout');
    } finally {
        $holder->rollBack();
        DB::purge('conversation_lock');
    }

    expect(linkSource($email, $this->company))->toBeNull();
})->with([
    'link' => fn (User $user, Email $email, Company $company): mixed => resolve(LinkEmailToRecord::class)->execute($user, $email, $company),
    'unlink' => fn (User $user, Email $email, Company $company): mixed => resolve(UnlinkEmailFromRecord::class)->execute($user, $email, $company),
    'sync linking' => fn (User $user, Email $email): mixed => resolve(LinkEmailAction::class)->reapply($email),
]);

it('changes the counts by only what an unlink took off', function (Closure $retire): void {
    $latest = linkingEmail($this->owner);
    $secondMailbox = ConnectedAccount::withoutEvents(fn (): ConnectedAccount => ConnectedAccount::factory()->create([
        'workspace_id' => $this->workspace->getKey(),
        'user_id' => $this->owner->getKey(),
        'status' => 'active',
    ]));
    $earlier = linkingEmail($this->owner, [
        'connected_account_id' => $secondMailbox->getKey(),
        'conversation_key' => 'airbnb-kickoff',
        'rfc_message_id' => '<kickoff@airbnb.test>',
    ]);
    $link = resolve(LinkEmailToRecord::class);
    $link->execute($this->owner, $earlier, $this->company);
    $link->execute($this->owner, $latest, $this->company);
    $retire($earlier);

    resolve(UnlinkEmailFromRecord::class)->execute($this->owner, $latest, $this->company);

    expect($this->company->fresh()->email_count)->toBe(1);
})->with([
    'deleted email' => fn (Email $email): mixed => $email->delete(),
    'disconnected mailbox' => fn (Email $email): mixed => ConnectedAccount::query()->whereKey($email->connected_account_id)->sole()->delete(),
]);

it('reads the named record once while it counts every view of the record', function (): void {
    resolve(LinkEmailToRecord::class)->execute($this->owner, linkingEmail($this->owner), $this->company);
    $reads = 0;
    DB::listen(function (QueryExecuted $query) use (&$reads): void {
        $reads += (int) str_contains($query->sql, 'from "companies"');
    });

    resolve(ConversationsQuery::class)->counts($this->owner, ConversationFilters::forRecord($this->company));

    expect($reads)->toBe(1);
});

it('recounts in its own workspace after a real round trip through the queue payload', function (): void {
    $email = linkingEmail($this->owner);
    $email->companies()->attach($this->company->getKey(), ['link_source' => 'manual']);
    $job = unserialize(serialize(new RecomputeRecordEmailMetricsJob($this->company)));
    $currentWorkspace = resolve(CurrentWorkspace::class);
    $currentWorkspace->forget();
    $boundDuringRecount = null;
    DB::listen(function (QueryExecuted $query) use (&$boundDuringRecount, $currentWorkspace): void {
        if (str_starts_with($query->sql, 'update companies')) {
            $boundDuringRecount = $currentWorkspace->get()?->getKey();
        }
    });

    app()->call($job->handle(...));

    expect($boundDuringRecount)->toBe($this->workspace->getKey())
        ->and($currentWorkspace->get())->toBeNull()
        ->and($this->company->fresh()->email_count)->toBe(1);
});

it('keeps the owner\'s linked copy on the record when a teammate unlinks their own copy', function (): void {
    $owners = linkingEmail($this->owner);
    $teammates = linkingEmail($this->teammate, ['rfc_message_id' => '<renewal-cc@airbnb.test>']);
    $owners->companies()->attach($this->company->getKey(), ['link_source' => 'auto']);
    $teammates->companies()->attach($this->company->getKey(), ['link_source' => 'auto']);
    resolve(RefreshConversationSummary::class)->execute($this->workspace->getKey(), 'airbnb-renewal');

    resolve(UnlinkEmailFromRecord::class)->execute($this->teammate, $teammates, $this->company);
    resolve(LinkEmailAction::class)->reapply($owners->fresh());

    expect(resolve(ConversationsQuery::class)->paginate($this->owner, ConversationView::All, ConversationFilters::forRecord($this->company))->rows)->toHaveCount(1)
        ->and(resolve(EmailVisibilityService::class)->visibleEmailCountBadge($this->company, $this->owner))->toBe('1')
        ->and(linkSource($owners, $this->company))->toBe('auto');
});

it('shows a pinned copy on the record whatever a teammate suppressed on theirs', function (): void {
    $domains = CustomField::query()->withoutGlobalScopes()->where('tenant_id', $this->workspace->getKey())->where('entity_type', 'company')->where('code', 'domains')->firstOrFail();
    $this->company->saveCustomFieldValue($domains, 'https://airbnb.test', $this->workspace);
    $owners = linkingEmail($this->owner);
    $teammates = linkingEmail($this->teammate, ['rfc_message_id' => '<renewal-cc@airbnb.test>']);
    EmailParticipant::factory()->from()->create(['email_id' => $teammates->getKey(), 'email_address' => 'brian@airbnb.test']);
    $owners->companies()->attach($this->company->getKey(), ['link_source' => 'auto']);
    $teammates->companies()->attach($this->company->getKey(), ['link_source' => 'auto']);

    resolve(UnlinkEmailFromRecord::class)->execute($this->teammate, $teammates, $this->company);
    resolve(UnlinkEmailFromRecord::class)->execute($this->owner, $owners, $this->company);
    resolve(LinkEmailToRecord::class)->execute($this->owner, $owners, $this->company);
    resolve(LinkEmailAction::class)->reapply($teammates->fresh());

    expect(Email::query()->onRecord($this->company)->pluck('id')->all())->toBe([$owners->getKey()])
        ->and(resolve(EmailVisibilityService::class)->visibleEmailCountBadge($this->company, $this->owner))->toBe('1')
        ->and(linkSource($teammates, $this->company))->toBeNull();
});

it('keeps the user\'s later replies off a record they unlinked when a teammate pins it on their own copy', function (): void {
    $domains = CustomField::query()->withoutGlobalScopes()->where('tenant_id', $this->workspace->getKey())->where('entity_type', 'company')->where('code', 'domains')->firstOrFail();
    $this->company->saveCustomFieldValue($domains, 'https://airbnb.test', $this->workspace);
    $owners = linkingEmail($this->owner);
    $teammates = linkingEmail($this->teammate, ['rfc_message_id' => '<renewal-cc@airbnb.test>']);
    $owners->companies()->attach($this->company->getKey(), ['link_source' => 'auto']);

    resolve(UnlinkEmailFromRecord::class)->execute($this->owner, $owners, $this->company);
    resolve(LinkEmailToRecord::class)->execute($this->teammate, $teammates, $this->company);

    $synced = linkingEmail($this->owner, ['rfc_message_id' => '<renewal-reply@airbnb.test>', 'linked_at' => null]);
    EmailParticipant::factory()->from()->create(['email_id' => $synced->getKey(), 'email_address' => 'brian@airbnb.test']);
    resolve(LinkEmailAction::class)->execute($synced);
    $stored = linkingEmail($this->owner, ['rfc_message_id' => '<renewal-second@airbnb.test>']);
    $stored->companies()->attach($this->company->getKey(), ['link_source' => 'auto']);

    expect(linkSource($synced, $this->company))->toBeNull()
        ->and(Email::query()->onRecord($this->company)->pluck('id')->all())->toBe([$teammates->getKey()]);
});

it('keeps the record tab right when many members suppressed many copies of its conversations', function (): void {
    $suppress = fn (Email $email, User $by): EmailLinkSuppression => EmailLinkSuppression::factory()->create([
        'workspace_id' => $this->workspace->getKey(),
        'email_id' => $email->getKey(),
        'emailable_type' => $this->company->getMorphClass(),
        'emailable_id' => $this->company->getKey(),
        'user_id' => $by->getKey(),
    ]);
    $shown = [];

    foreach (range(0, 39) as $index) {
        $key = "renewal-{$index}";
        $owners = linkingEmail($this->owner, ['conversation_key' => $key, 'rfc_message_id' => "<m{$index}@airbnb.test>"]);
        $owners->companies()->attach($this->company->getKey(), ['link_source' => 'auto']);

        if ($index < 30) {
            $teammates = linkingEmail($this->teammate, ['conversation_key' => $key, 'rfc_message_id' => "<m{$index}@airbnb.test>"]);
            $teammates->companies()->attach($this->company->getKey(), ['link_source' => 'auto']);
            $shown[] = $teammates->getKey();
        }

        match (true) {
            $index < 10, $index >= 30 => $suppress($owners, $this->owner),
            $index < 20 => $suppress(linkingEmail($this->owner, ['conversation_key' => $key, 'rfc_message_id' => "<m{$index}-reply@airbnb.test>"]), $this->owner),
            default => $suppress(linkingEmail($this->teammate, ['conversation_key' => $key, 'rfc_message_id' => "<m{$index}-cc@airbnb.test>"]), $this->teammate),
        };

        if ($index >= 20 && $index < 30) {
            $shown = [...array_diff($shown, [$teammates->getKey()]), $owners->getKey()];
        }

        resolve(RefreshConversationSummary::class)->execute($this->workspace->getKey(), $key);
    }

    expect(EmailLinkSuppression::query()->distinct()->count('user_id'))->toBe(2)
        ->and(EmailLinkSuppression::query()->count())->toBe(40)
        ->and(Email::query()->onRecord($this->company)->pluck('id')->all())->toEqualCanonicalizing($shown)
        ->and(resolve(ConversationsQuery::class)->paginate($this->owner, ConversationView::All, ConversationFilters::forRecord($this->company))->rows)->toHaveCount(30)
        ->and(resolve(EmailVisibilityService::class)->visibleEmailCountBadge($this->company, $this->owner))->toBe('30');
});

it('leaves a copy off the opportunity tab once its company link is suppressed on that copy', function (): void {
    $opportunity = Opportunity::factory()->for($this->workspace)->create(['name' => 'Airbnb renewal', 'company_id' => $this->company->getKey()]);
    $email = linkingEmail($this->owner);
    $email->companies()->attach($this->company->getKey(), ['link_source' => 'auto']);

    expect(Email::query()->onRecord($opportunity)->pluck('id')->all())->toBe([$email->getKey()]);

    EmailLinkSuppression::factory()->create([
        'workspace_id' => $this->workspace->getKey(),
        'email_id' => $email->getKey(),
        'emailable_type' => $this->company->getMorphClass(),
        'emailable_id' => $this->company->getKey(),
    ]);

    expect(Email::query()->onRecord($opportunity)->pluck('id')->all())->toBe([]);
});

it('restores only pairs the user reached and the unlink suppressed', function (): void {
    $own = linkingEmail($this->owner);
    $unsuppressed = linkingEmail($this->owner, ['rfc_message_id' => '<renewal-reply@airbnb.test>']);
    $teammates = linkingEmail($this->teammate, ['rfc_message_id' => '<renewal-cc@airbnb.test>', 'privacy_tier' => EmailPrivacyTier::FULL, 'privacy_tier_customized' => true]);
    EmailLinkSuppression::factory()->create([
        'workspace_id' => $this->workspace->getKey(),
        'email_id' => $teammates->getKey(),
        'emailable_type' => $this->company->getMorphClass(),
        'emailable_id' => $this->company->getKey(),
    ]);

    resolve(RestoreEmailRecordLinks::class)->execute($this->owner, $own, $this->company, [
        $unsuppressed->getKey() => 'manual',
        $teammates->getKey() => 'manual',
    ]);

    expect(linkSource($unsuppressed, $this->company))->toBeNull()
        ->and(linkSource($teammates, $this->company))->toBeNull()
        ->and(EmailLinkSuppression::query()->count())->toBe(1);
});

it('restores a reached suppressed pair with its source and leaves a standing auto link auto', function (): void {
    $email = linkingEmail($this->owner);
    $standing = linkingEmail($this->owner, ['rfc_message_id' => '<renewal-reply@airbnb.test>']);
    $email->companies()->attach($this->company->getKey(), ['link_source' => 'auto']);
    $changed = resolve(UnlinkEmailFromRecord::class)->execute($this->owner, $email, $this->company);
    $standing->companies()->attach($this->company->getKey(), ['link_source' => 'auto']);

    $restored = resolve(RestoreEmailRecordLinks::class)->execute($this->owner, $email, $this->company, [...$changed, $standing->getKey() => 'manual']);

    expect($restored)->toBeTrue()
        ->and(linkSource($email, $this->company))->toBe('auto')
        ->and(linkSource($standing, $this->company))->toBe('auto')
        ->and(EmailLinkSuppression::query()->count())->toBe(0);
});

it('links a reply synced between the unlink and its undo once the undo lifts the unlink', function (): void {
    $domains = CustomField::query()->withoutGlobalScopes()->where('tenant_id', $this->workspace->getKey())->where('entity_type', 'company')->where('code', 'domains')->firstOrFail();
    $this->company->saveCustomFieldValue($domains, 'https://airbnb.test', $this->workspace);
    $email = linkingEmail($this->owner);
    $email->companies()->attach($this->company->getKey(), ['link_source' => 'auto']);
    $changed = resolve(UnlinkEmailFromRecord::class)->execute($this->owner, $email, $this->company);
    $reply = linkingEmail($this->owner, ['rfc_message_id' => '<renewal-reply@airbnb.test>', 'linked_at' => null]);
    EmailParticipant::factory()->from()->create(['email_id' => $reply->getKey(), 'email_address' => 'brian@airbnb.test']);
    resolve(LinkEmailAction::class)->execute($reply);

    expect(linkSource($reply, $this->company))->toBeNull();

    resolve(RestoreEmailRecordLinks::class)->execute($this->owner, $email, $this->company, $changed);

    expect(linkSource($email, $this->company))->toBe('auto')
        ->and(linkSource($reply, $this->company))->toBe('auto');
});

it('says the undo put nothing back when the unlink it names left nothing to restore', function (): void {
    $email = linkingEmail($this->owner);
    linkingPage($email);
    $this->actingAs($this->owner);

    livewire(EmailAccessNotificationHandler::class)
        ->dispatch('relink-email-record', emailId: $email->getKey(), targetType: 'company', targetId: $this->company->getKey(), links: [$email->getKey() => 'auto'])
        ->assertNotified('Nothing to put back on Airbnb. It may have changed since.');

    expect(linkSource($email, $this->company))->toBeNull();
});

it('reads an opportunity\'s close date field once for its tab and once for its badge', function (): void {
    $opportunity = Opportunity::factory()->for($this->workspace)->create(['name' => 'Airbnb renewal', 'company_id' => $this->company->getKey()]);
    linkingEmail($this->owner)->companies()->attach($this->company->getKey(), ['link_source' => 'auto']);
    $reads = 0;
    DB::listen(function (QueryExecuted $query) use (&$reads): void {
        $reads += (int) (str_contains($query->sql, 'from "custom_fields"') && in_array('close_date', $query->bindings, true));
    });

    $conversations = resolve(ConversationsQuery::class);
    $conversations->counts($this->owner, ConversationFilters::forRecord($opportunity));
    $conversations->paginate($this->owner, ConversationView::All, ConversationFilters::forRecord($opportunity));
    resolve(EmailVisibilityService::class)->visibleEmailCountBadge($opportunity, $this->owner);
    resolve(EmailVisibilityService::class)->visibleEmailCountBadge($opportunity, $this->owner);

    expect($reads)->toBe(2);
});

function linkingPage(Email $email): mixed
{
    config(['relaticle.features.email_conversations' => [$email->workspace_id]]);
    resolve(RefreshConversationSummary::class)->execute($email->workspace_id, (string) $email->conversation_key);
    Filament::setCurrentPanel(Filament::getPanel('app'));

    return livewire(EmailInboxPage::class)->call('selectEmail', $email->getKey());
}

it('links the open conversation from the drawer picker as a pin', function (): void {
    $email = linkingEmail($this->owner);

    linkingPage($email)
        ->assertSeeHtml('data-reader-action="link"')
        ->mountAction('linkRecord', arguments: ['emailId' => $email->getKey()])
        ->setActionData(['target_type' => 'company', 'target_id' => $this->company->getKey()])
        ->callMountedAction()
        ->assertHasNoActionErrors()
        ->assertNotified('Linked to Airbnb')
        ->assertSeeHtml('data-linked-record="company:'.$this->company->getKey().'"');

    expect(linkSource($email, $this->company))->toBe('manual');
});

it('shows a chip for every record the open conversation is on, past the three a list row reads', function (): void {
    $email = linkingEmail($this->owner);
    $records = [
        $this->company,
        Company::factory()->for($this->workspace)->create(['name' => 'Stripe']),
        Opportunity::factory()->for($this->workspace)->create(['name' => 'Airbnb renewal']),
        Opportunity::factory()->for($this->workspace)->create(['name' => 'Stripe expansion']),
    ];

    foreach ($records as $record) {
        resolve(LinkEmailToRecord::class)->execute($this->owner, $email, $record);
    }

    $page = linkingPage($email)->assertSeeHtml('aria-label="+3 more linked records"');

    foreach ($records as $record) {
        $page->assertSeeHtml('data-linked-record="'.$record->getMorphClass().':'.$record->getKey().'"');
    }

    preg_match_all('/data-unlink-record="([a-z]+:[^"]+)"/', $page->html(), $overflow);

    expect($overflow[1])->toHaveCount(3)
        ->and(array_diff($overflow[1], array_map(fn (Company|Opportunity $record): string => $record->getMorphClass().':'.$record->getKey(), $records)))->toBe([]);
});

it('searches only the records of the bound workspace and stops at twenty', function (): void {
    $email = linkingEmail($this->owner);
    Company::factory()->count(22)->for($this->workspace)->sequence(fn ($sequence): array => ['name' => 'Airline '.str_pad((string) $sequence->index, 2, '0', STR_PAD_LEFT)])->create();
    Company::factory()->for(Workspace::factory())->create(['name' => 'Airbnb Elsewhere']);
    Opportunity::factory()->for($this->workspace)->create(['name' => 'Airbnb renewal']);

    $page = linkingPage($email)
        ->mountAction('linkRecord', arguments: ['emailId' => $email->getKey()])
        ->setActionData(['target_type' => 'company']);
    $search = fn (string $term): array => array_column($page->instance()->callSchemaComponentMethod('mountedActionSchema0.target_id', 'getSearchResultsForJs', ['search' => $term]), 'label');

    expect($search('airb'))->toBe(['Airbnb'])
        ->and($search('Air'))->toHaveCount(20)
        ->and($search('renewal'))->toBe([]);

    $page->setActionData(['target_type' => 'opportunity']);

    expect($search('renewal'))->toBe(['Airbnb renewal']);
});

it('answers a forged record from another workspace with not found and writes nothing', function (): void {
    $email = linkingEmail($this->owner);
    $foreign = Company::factory()->for(Workspace::factory())->create(['name' => 'Elsewhere']);

    linkingPage($email)
        ->mountAction('linkRecord', arguments: ['emailId' => $email->getKey()])
        ->setActionData(['target_type' => 'company', 'target_id' => $foreign->getKey()])
        ->callMountedAction()
        ->assertHasActionErrors(['target_id']);

    expect(DB::table('emailables')->where('email_id', $email->getKey())->count())->toBe(0);
});

it('tells a teammate who reached no copy that the drawer changed nothing', function (): void {
    $email = linkingEmail($this->owner);
    $email->companies()->attach($this->company->getKey(), ['link_source' => 'auto']);
    $this->actingAs($this->teammate);

    linkingPage($email)
        ->mountAction('unlinkRecord', arguments: ['emailId' => $email->getKey(), 'targetType' => 'company', 'targetId' => $this->company->getKey()])
        ->callMountedAction()
        ->assertNotified('Only people who hold this conversation, or who were given full access to it, can change its records.')
        ->assertNotDispatched('email:links-changed');

    expect(linkSource($email, $this->company))->toBe('auto');
});

it('offers no unlink in the drawer to a teammate who reached no copy', function (): void {
    $email = linkingEmail($this->owner);
    $email->companies()->attach($this->company->getKey(), ['link_source' => 'auto']);
    $this->actingAs($this->teammate);

    linkingPage($email)
        ->assertSeeHtml('data-linked-record="company:'.$this->company->getKey().'"')
        ->assertDontSeeHtml('data-unlink-record');
});

it('tells the unlinker when a teammate\'s copy keeps the conversation on the record', function (): void {
    $owners = linkingEmail($this->owner);
    $teammates = linkingEmail($this->teammate, ['rfc_message_id' => '<renewal-cc@airbnb.test>']);
    $owners->companies()->attach($this->company->getKey(), ['link_source' => 'auto']);
    $teammates->companies()->attach($this->company->getKey(), ['link_source' => 'auto']);
    $this->actingAs($this->teammate);

    linkingPage($teammates)
        ->assertSeeHtml('data-unlink-record')
        ->mountAction('unlinkRecord', arguments: ['emailId' => $teammates->getKey(), 'targetType' => 'company', 'targetId' => $this->company->getKey()])
        ->callMountedAction()
        ->assertNotified('Removed your copies. A teammate\'s copy keeps it on Airbnb.');

    expect(linkSource($teammates, $this->company))->toBeNull()
        ->and(linkSource($owners, $this->company))->toBe('auto');
});

it('unlinks a chip from the drawer and its undo puts back exactly what it took off', function (): void {
    $email = linkingEmail($this->owner);
    $reply = linkingEmail($this->owner, ['rfc_message_id' => '<renewal-reply@airbnb.test>', 'sent_at' => now()->subHour()]);
    $email->companies()->attach($this->company->getKey(), ['link_source' => 'auto']);
    $chip = 'data-linked-record="company:'.$this->company->getKey().'"';

    linkingPage($email)
        ->assertSeeHtml($chip)
        ->mountAction('unlinkRecord', arguments: ['emailId' => $email->getKey(), 'targetType' => 'company', 'targetId' => $this->company->getKey()])
        ->assertActionMounted('unlinkRecord')
        ->assertMountedActionModalSee('Remove this conversation from Airbnb?')
        ->callMountedAction()
        ->assertDispatched('email:links-changed')
        ->call('refreshEmailLinks')
        ->assertDontSeeHtml($chip);

    app(NotificationMailbox::class)->flush();
    $sent = collect(app(NotificationMailbox::class)->pull())->firstWhere('title', 'Removed from Airbnb');
    $undo = $sent['actions'][0]['eventData'];

    expect(linkSource($email, $this->company))->toBeNull()
        ->and(EmailLinkSuppression::query()->count())->toBe(2)
        ->and($undo['links'])->toEqualCanonicalizing([$email->getKey() => 'auto', $reply->getKey() => null]);

    livewire(EmailAccessNotificationHandler::class)
        ->dispatch('relink-email-record', ...$undo)
        ->assertNotified('Linked to Airbnb');

    expect(linkSource($email, $this->company))->toBe('auto')
        ->and(linkSource($reply, $this->company))->toBeNull()
        ->and(EmailLinkSuppression::query()->count())->toBe(0)
        ->and($this->company->fresh()->email_count)->toBe(1);
});
