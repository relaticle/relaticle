<?php

declare(strict_types=1);

use App\Enums\CreationSource;
use App\Models\Company;
use App\Models\Note;
use App\Models\Opportunity;
use App\Models\People;
use App\Models\Task;
use App\Models\User;
use App\Models\Workspace;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Relaticle\Chat\Enums\AiCreditType;
use Relaticle\Chat\Enums\MessageOrigin;
use Relaticle\Chat\Models\AiCreditTransaction;
use Relaticle\Cloud\Billing\Filament\Billing;
use Relaticle\Cloud\Billing\Mail\ProTrialEndingSoonMail;

mutates(ProTrialEndingSoonMail::class);

function endingSoonMail(Workspace $workspace): ProTrialEndingSoonMail
{
    return new ProTrialEndingSoonMail($workspace, $workspace->trial_ends_at);
}

function trialWorkspace(string $timezone = 'UTC', array $attributes = []): Workspace
{
    $owner = User::factory()->create(['timezone' => $timezone]);

    return Workspace::factory()->create([
        'name' => 'Acme',
        'user_id' => $owner->id,
        'trial_ends_at' => '2026-09-09 12:30:00',
        'hosted_free_grandfathered_at' => null,
        ...$attributes,
    ]);
}

function askRela(Workspace $workspace, int $times, MessageOrigin $origin = MessageOrigin::Typed, string $role = 'user', ?CarbonImmutable $supersededAt = null): void
{
    $owner = $workspace->owner;
    $conversationId = (string) Str::uuid7();

    DB::table('agent_conversations')->insert([
        'id' => $conversationId,
        'participant_type' => $owner->getMorphClass(),
        'participant_id' => (string) $owner->getKey(),
        'workspace_id' => $workspace->getKey(),
        'title' => 'Question',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    foreach (range(1, $times) as $ignored) {
        DB::table('agent_conversation_messages')->insert([
            'id' => (string) Str::uuid7(),
            'conversation_id' => $conversationId,
            'participant_type' => $owner->getMorphClass(),
            'participant_id' => (string) $owner->getKey(),
            'agent' => 'test',
            'role' => $role,
            'content' => 'question',
            'attachments' => '[]',
            'steps' => '[]',
            'usage' => '[]',
            'meta' => '[]',
            'origin' => $origin->value,
            'superseded_at' => $supersededAt,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}

it('renders the trial end time in the owner timezone with the paused-access outcome', function (): void {
    $workspace = trialWorkspace('Europe/Berlin');

    $mail = endingSoonMail($workspace);

    $mail->assertHasSubject('Your Pro trial ends on Sep 9');
    $mail->assertSeeInHtml('Pro for Acme ends on Sep 9');
    $mail->assertSeeInHtml('Your Pro trial ends on Sep 9, 2026 at 2:30 PM (Berlin time).');
    $mail->assertSeeInHtml('After that, Acme pauses until you choose a plan.');
    $mail->assertSeeInHtml('If no one signs in for 60 days after that, we delete the workspace and its records.');
    $mail->assertSeeInHtml('Questions? Reply to this email.');
    $mail->assertSeeInHtml('Choose a plan');
    $mail->assertSeeInHtml('One flat price covers the whole workspace.');
    $mail->assertSeeInHtml('Acme pauses then unless you choose a plan. Your records stay safe.');
    $mail->assertDontSeeInHtml('returns to its Cloud Free plan then');
    $mail->assertDontSeeInHtml(__('mail.trial_ending.grandfathered', ['workspace' => 'Acme']));
    $mail->assertSeeInHtml(__('mail.footer.reason.owner', ['workspace' => 'Acme']));
    $mail->assertSeeInText('Choose a plan: '.Billing::getUrl(panel: 'app', tenant: $workspace));
});

it('states no 60-day rule to a workspace that bought a credit pack or owes a managed invoice', function (Closure $arrange): void {
    $workspace = trialWorkspace();
    $arrange($workspace);

    endingSoonMail($workspace)->assertDontSeeInHtml('60 days');
})->with([
    'credit pack' => fn (Workspace $workspace): Model => AiCreditTransaction::factory()->create(['workspace_id' => $workspace->getKey(), 'type' => AiCreditType::Purchase]),
    'managed invoice' => fn (Workspace $workspace): Model => $workspace->subscriptions()->create(['type' => 'managed_pending', 'stripe_id' => 'sub_managed', 'stripe_status' => 'incomplete', 'stripe_price' => 'price_pro_monthly_test', 'quantity' => 1]),
]);

it('names UTC when the owner has no city timezone', function (): void {
    $mail = endingSoonMail(trialWorkspace('UTC'));

    $mail->assertSeeInHtml('Your Pro trial ends on Sep 9, 2026 at 12:30 PM (UTC time).');
    $mail->assertDontSeeInHtml('( time)');
});

it('turns underscores in the city into spaces', function (): void {
    $mail = endingSoonMail(trialWorkspace('America/New_York'));

    $mail->assertSeeInHtml('(New York time)');
});

it('describes the grandfathered outcome for grandfathered workspaces', function (): void {
    $mail = endingSoonMail(trialWorkspace('UTC', ['hosted_free_grandfathered_at' => now()->subMonth()]));

    $mail->assertSeeInHtml(__('mail.trial_ending.grandfathered', ['workspace' => 'Acme']));
    $mail->assertDontSeeInHtml('After that, Acme pauses');
    $mail->assertDontSeeInHtml('60 days');
});

it('promises a return to Cloud Free in the preheader of a grandfathered workspace', function (): void {
    $mail = endingSoonMail(trialWorkspace('UTC', ['hosted_free_grandfathered_at' => now()->subMonth()]));

    $mail->assertSeeInHtml('Acme returns to its Cloud Free plan then. Your records stay safe.');
    $mail->assertDontSeeInHtml('pauses then unless');
});

it('names the end date and no fixed length on an extended trial', function (): void {
    $workspace = trialWorkspace('UTC', [
        'pro_trial_used_at' => '2026-10-01 09:00:00',
        'trial_ends_at' => '2026-11-15 09:00:00',
    ]);

    $mail = endingSoonMail($workspace);

    $mail->assertHasSubject('Your Pro trial ends on Nov 15');
    $mail->assertSeeInHtml('Your Pro trial ends on Nov 15, 2026 at 9:00 AM (UTC time).');
    $mail->assertDontSeeInHtml('14-day');
    $mail->assertDontSeeInHtml('3 days');
});

it('keeps the end date in the subject after the pause step clears trial_ends_at', function (): void {
    $workspace = trialWorkspace('UTC');
    $mail = endingSoonMail($workspace);

    $workspace->forceFill(['trial_ends_at' => null])->save();

    $mail->assertHasSubject('Your Pro trial ends on Sep 9');
    $mail->assertSeeInHtml('Your Pro trial ends on Sep 9, 2026 at 12:30 PM (UTC time).');
});

it('counts records the team added and questions asked in the workspace', function (): void {
    $workspace = trialWorkspace();
    $other = trialWorkspace();

    Company::factory()->count(2)->create(['workspace_id' => $workspace->id, 'creation_source' => CreationSource::WEB]);
    Company::factory()->create(['workspace_id' => $workspace->id, 'creation_source' => CreationSource::SAMPLE]);
    People::factory()->create(['workspace_id' => $workspace->id, 'creation_source' => CreationSource::MAILBOX]);
    Company::factory()->create(['workspace_id' => $other->id, 'creation_source' => CreationSource::WEB]);
    askRela($workspace, 3);
    askRela($other, 1);

    endingSoonMail($workspace)->assertSeeInHtml('So far your team has added 2 records and asked Rela 3 questions.');
});

it('leaves out the usage line when nothing was added or asked', function (): void {
    $workspace = trialWorkspace();
    Company::factory()->create(['workspace_id' => $workspace->id, 'creation_source' => CreationSource::SAMPLE]);

    endingSoonMail($workspace)->assertDontSeeInHtml('So far your team');
});

it('prints only the half that is not zero', function (): void {
    $records = trialWorkspace();
    Company::factory()->create(['workspace_id' => $records->id, 'creation_source' => CreationSource::WEB]);

    $questions = trialWorkspace();
    askRela($questions, 1);

    endingSoonMail($records)->assertSeeInHtml('So far your team has added 1 record.');
    endingSoonMail($questions)->assertSeeInHtml('So far your team has asked Rela 1 question.');
});

it('counts every record type and question kind that belongs to the trial, and nothing else', function (): void {
    $workspace = trialWorkspace();
    $attributes = fn (CreationSource $source): array => ['workspace_id' => $workspace->id, 'creation_source' => $source];

    foreach ([CreationSource::WEB, CreationSource::SAMPLE] as $source) {
        Company::factory()->create($attributes($source));
        People::factory()->create($attributes($source));
        Opportunity::factory()->create($attributes($source));
        Task::factory()->create($attributes($source));
        Note::factory()->create($attributes($source));
    }

    Company::factory()->create($attributes(CreationSource::WEB))->delete();
    askRela($workspace, 2);
    askRela($workspace, 1, MessageOrigin::Greeting);
    askRela($workspace, 1, role: 'assistant');

    endingSoonMail($workspace)->assertSeeInHtml('So far your team has added 5 records and asked Rela 2 questions.');
});

it('counts only what was created from the trial start', function (): void {
    $workspace = trialWorkspace('UTC', ['pro_trial_used_at' => '2026-09-01 00:00:00']);

    Company::factory()->create([
        'workspace_id' => $workspace->id,
        'creation_source' => CreationSource::WEB,
        'created_at' => '2026-08-31 23:59:59',
    ]);
    Company::factory()->create([
        'workspace_id' => $workspace->id,
        'creation_source' => CreationSource::WEB,
        'created_at' => '2026-09-01 00:00:00',
    ]);

    endingSoonMail($workspace)->assertSeeInHtml('So far your team has added 1 record.');
});

it('counts only questions asked from the trial start', function (): void {
    $workspace = trialWorkspace('UTC', ['pro_trial_used_at' => '2026-09-01 00:00:00']);

    $this->travelTo(CarbonImmutable::parse('2026-08-31 23:59:59'));
    askRela($workspace, 2);
    $this->travelTo(CarbonImmutable::parse('2026-09-01 00:00:00'));
    askRela($workspace, 1);

    endingSoonMail($workspace)->assertSeeInHtml('So far your team has asked Rela 1 question.');
});

it('ignores questions the user replaced by regenerating or editing', function (): void {
    $workspace = trialWorkspace();

    askRela($workspace, 1);
    askRela($workspace, 2, supersededAt: now());

    endingSoonMail($workspace)->assertSeeInHtml('So far your team has asked Rela 1 question.');
});

it('groups thousands in the record count', function (): void {
    $workspace = trialWorkspace();

    DB::insert(
        "insert into notes (id, workspace_id, title, creation_source, created_at, updated_at)
        select substr(md5(n::text), 1, 26), ?, 'Note', 'web', ?, ? from generate_series(1, 12500) as n",
        [$workspace->id, now(), now()],
    );

    endingSoonMail($workspace)->assertSeeInHtml('So far your team has added 12,500 records.');
});
