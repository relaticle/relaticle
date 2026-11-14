<?php

declare(strict_types=1);

use App\Data\DigestRecord;
use App\Data\DigestTaskItem;
use App\Data\TaskDigest;
use App\Enums\CrmEntity;
use App\Filament\Resources\TaskResource;
use App\Filament\Resources\TaskResource\Pages\ManageTasks;
use App\Mail\TaskDigestMail;
use App\Models\Task;
use App\Models\User;
use App\Models\Workspace;
use Filament\Facades\Filament;
use Illuminate\Support\Str;
use Livewire\Livewire;

function digestWorkspace(User $user, string $name = 'Acme'): Workspace
{
    $workspace = $user->currentWorkspace;
    $workspace->update(['name' => $name]);

    return $workspace;
}

it('renders the digest with subject, preheader, summary, footer, and settings link', function (): void {
    config(['relaticle.company.address' => '123 Test St, Testville']);

    $user = User::factory()->withPersonalWorkspace()->create(['name' => 'Ada Lovelace', 'timezone' => 'UTC']);

    $digest = new TaskDigest(
        overdue: [new DigestTaskItem('Call client', now()->subDay(), 'https://app.test/tasks?a')],
        upcoming: [new DigestTaskItem('Send proposal', now(), 'https://app.test/tasks?b')],
    );

    $mail = new TaskDigestMail($user, digestWorkspace($user), $digest);

    $mail->assertHasSubject('[Acme] 1 overdue, 1 due today, '.now('UTC')->format('M j'));
    $mail->assertSeeInHtml('Call client, Send proposal');
    $mail->assertSeeInHtml(__('mail.task_digest.heading', ['name' => 'Ada']), escape: false);
    $mail->assertSeeInText('You have 1 overdue task and 1 task due today in Acme.');
    $mail->assertSeeInHtml('123 Test St');
    $mail->assertSeeInHtml(__('mail.footer.settings'));
    $mail->assertSeeInHtml(__('mail.footer.reason.digest'));
    $mail->assertSeeInText("Call client: https://app.test/tasks?a\n".now('UTC')->subDay()->format('M j, Y').' (Overdue)');
    $mail->assertSeeInText("Send proposal: https://app.test/tasks?b\nDue today");
    $mail->assertDontSeeInHtml('Due Today');
    $mail->assertDontSeeInHtml('align="left"');
    $mail->assertSeeInText(__('mail.task_digest.cta').': ');
    $mail->assertDontSeeInText('Call client, Send proposal');
    $mail->assertSeeInHtml(__('mail.task_digest.open_hint', ['workspace' => 'Acme']));
});

it('links the whole card and its chevron to the task', function (): void {
    $user = User::factory()->withPersonalWorkspace()->create();

    $mail = new TaskDigestMail($user, digestWorkspace($user), new TaskDigest([], [new DigestTaskItem('Send proposal', now(), 'https://app.test/tasks?task=abc')]));

    expect(substr_count($mail->render(), 'href="https://app.test/tasks?task=abc"'))->toBe(2);
});

it('points the button and settings link at the digest workspace, not the current one', function (): void {
    $user = User::factory()->withPersonalWorkspace()->create();
    $other = Workspace::factory()->create(['name' => 'Globex']);
    $other->users()->attach($user, ['role' => 'member']);

    $mail = new TaskDigestMail($user, $other, new TaskDigest([], [new DigestTaskItem('Send proposal', now(), 'https://app.test/tasks?b')]));

    $mail->assertHasSubject('[Globex] 1 task due today, '.now($user->effectiveTimezone())->format('M j'));
    $mail->assertSeeInHtml(TaskResource::getUrl('index', ['tenant' => $other], panel: 'app'));
    $mail->assertDontSeeInHtml(TaskResource::getUrl('index', ['tenant' => $user->currentWorkspace], panel: 'app'));
});

it('shows priority with the board badge colors and one linked record plus a count', function (): void {
    $user = User::factory()->withPersonalWorkspace()->create();

    $digest = new TaskDigest(
        overdue: [new DigestTaskItem('Send proposal', now()->subDays(3), 'https://app.test/tasks?a', [
            new DigestRecord(CrmEntity::Company, 'Northwind'),
            new DigestRecord(CrmEntity::People, 'Ana Reyes'),
            new DigestRecord(CrmEntity::Opportunity, 'Fleet renewal'),
        ], 'High', '#dc2626')],
        upcoming: [new DigestTaskItem('Call client', now(), 'https://app.test/tasks?b', [new DigestRecord(CrmEntity::Company, 'International Business Machines Corporation Holdings')], 'Odd', 'not-a-color')],
    );

    $mail = new TaskDigestMail($user, digestWorkspace($user), $digest);

    $mail->assertSeeInHtml('#ad3c34');
    $mail->assertSeeInHtml('Northwind');
    $mail->assertDontSeeInHtml('Ana Reyes');
    $mail->assertSeeInHtml('+2');
    $mail->assertSeeInText("Call client: https://app.test/tasks?b\nOdd · Due today · International Business M...");
    $mail->assertSeeInText('High · '.now()->subDays(3)->format('M j, Y').' (Overdue) · Northwind +2');
    $mail->assertSeeInHtml(asset('brand/email-icons/flag.png'));
    $mail->assertDontSeeInHtml('not-a-color');
    expect(substr_count($mail->render(), 'class="task-chip-dot"'))->toBe(1);
    $mail->assertSeeInHtml(asset('brand/email-icons/building.png'));
    $mail->assertSeeInHtml(asset('brand/email-icons/calendar.png'));
});

it('ships an icon file for the due date and every record type a task links to', function (): void {
    $icons = ['lucide-calendar', 'lucide-flag', ...array_map(fn (CrmEntity $entity): string => $entity->icon(), CrmEntity::linkable())];

    foreach ($icons as $icon) {
        expect(public_path('brand/email-icons/'.Str::after($icon, 'lucide-').'.png'))->toBeFile();
    }
});

it('shows five cards per column, keeps the full count and links the rest to the task list', function (): void {
    $user = User::factory()->withPersonalWorkspace()->create();
    $workspace = digestWorkspace($user);
    $item = fn (string $title): DigestTaskItem => new DigestTaskItem($title, now()->subDay(), "https://app.test/tasks?task={$title}");

    $mail = new TaskDigestMail($user, $workspace, new TaskDigest(
        overdue: array_map($item, ['o1', 'o2', 'o3', 'o4', 'o5', 'o6', 'o7']),
        upcoming: array_map($item, ['t1', 't2']),
    ));
    $tasksUrl = TaskResource::assignedToMeUrl($workspace);

    expect(substr_count($mail->render(), 'class="task-card"'))->toBe(7);
    $mail->assertDontSeeInHtml('task=o6');
    $mail->assertSeeInHtml('View 2 more tasks');
    $mail->assertDontSeeInHtml('View 0 more');
    $mail->assertSeeInText('Overdue (7)');
    $mail->assertSeeInText('View 2 more tasks: '.$tasksUrl);
    $mail->assertSeeInText('Due today (2)');
});

it('caps the preheader at three task titles', function (): void {
    $user = User::factory()->withPersonalWorkspace()->create();

    $digest = new TaskDigest([], array_map(
        fn (string $title): DigestTaskItem => new DigestTaskItem($title, now(), 'https://app.test/tasks'),
        ['One', 'Two', 'Three', 'Four', 'Five'],
    ));

    (new TaskDigestMail($user, digestWorkspace($user), $digest))->assertSeeInHtml('One, Two, Three and 2 more');
});

it('renders a workspace name as text, never as markdown or html', function (): void {
    $user = User::factory()->withPersonalWorkspace()->create();
    $workspace = digestWorkspace($user, '[Verify your account](https://phish.example) *now* <a href="https://phish.test">here</a>');

    $html = (new TaskDigestMail($user, $workspace, new TaskDigest([], [new DigestTaskItem('Send proposal', now(), 'https://app.test/tasks?b')])))->render();

    expect($html)->not->toContain('href="https://phish.example"')
        ->and($html)->not->toContain('<a href="https://phish.test"')
        ->and($html)->not->toContain('<em')
        ->and($html)->toContain('[Verify your account](https://phish.example) *now* &lt;a href="https://phish.test"&gt;here&lt;/a&gt;');
});

it('keeps a blank line in a name, title or label from breaking out into markdown', function (): void {
    $user = User::factory()->withPersonalWorkspace()->create();
    $workspace = digestWorkspace($user, "Acme\n\n[Verify](https://phish.example)");

    $digest = new TaskDigest([new DigestTaskItem(
        "Send proposal\n\n[Click](https://phish.example)",
        now()->subDay(),
        'https://app.test/tasks?a',
        [new DigestRecord(CrmEntity::Company, "Northwind\n\n[Open](https://phish.example)")],
        "High\n\n[Fix](https://phish.example)",
        '#dc2626',
    )], []);

    $html = (new TaskDigestMail($user, $workspace, $digest))->render();

    expect($html)->not->toContain('href="https://phish.example"')
        ->and($html)->toContain('Send proposal [Click](https://phish.example)');
});

it('never leaks a lang key into the rendered digest', function (): void {
    $user = User::factory()->withPersonalWorkspace()->create();

    $digest = new TaskDigest([], [new DigestTaskItem('Send proposal', now(), 'https://app.test/tasks?b')]);

    expect((new TaskDigestMail($user, digestWorkspace($user), $digest))->render())->not->toContain('mail.');
});

it('carries rfc 8058 one-click unsubscribe headers pointing at the app host', function (): void {
    $user = User::factory()->withPersonalWorkspace()->create();
    $digest = new TaskDigest([], [new DigestTaskItem('Send proposal', now(), 'https://app.test/tasks?b')]);

    $mail = new TaskDigestMail($user, digestWorkspace($user), $digest);
    $headers = $mail->headers()->text;

    expect($headers['List-Unsubscribe-Post'])->toBe('List-Unsubscribe=One-Click')
        ->and($headers['List-Unsubscribe'])->toStartWith('<'.rtrim((string) config('app.url'), '/').'/mail/unsubscribe/'.$user->id.'/task_digest?')
        ->and($headers['List-Unsubscribe'])->toEndWith('>')
        ->and($mail->render())->toContain(trim($headers['List-Unsubscribe'], '<>'))
        ->and($mail->render())->toContain(__('mail.footer.unsubscribe'));
});

it('names the workspace and leads the subject with the overdue and due-today counts', function (int $overdue, int $due, string $subject, string $timezone = 'UTC'): void {
    $this->travelTo('2026-11-05 12:00:00');

    $user = User::factory()->withPersonalWorkspace()->create(['timezone' => $timezone]);

    $digest = new TaskDigest(
        overdue: array_fill(0, $overdue, new DigestTaskItem('Late', now()->subDay(), 'https://app.test/tasks?a')),
        upcoming: array_fill(0, $due, new DigestTaskItem('Due', now(), 'https://app.test/tasks?b')),
    );

    (new TaskDigestMail($user, digestWorkspace($user), $digest))->assertHasSubject($subject);
})->with([
    'both' => [2, 1, '[Acme] 2 overdue, 1 due today, Nov 5'],
    'overdue plural' => [2, 0, '[Acme] 2 overdue tasks, Nov 5'],
    'overdue singular' => [1, 0, '[Acme] 1 overdue task, Nov 5'],
    'due singular' => [0, 1, '[Acme] 1 task due today, Nov 5'],
    'due plural' => [0, 3, '[Acme] 3 tasks due today, Nov 5'],
    'owner timezone date' => [1, 1, '[Acme] 1 overdue, 1 due today, Nov 6', 'Pacific/Auckland'],
]);

it('links the digest to the task list narrowed to the tasks assigned to the member', function (): void {
    $user = User::factory()->withPersonalWorkspace()->create();
    $workspace = $user->currentWorkspace;
    $mine = Task::factory()->for($workspace)->create(['title' => 'Mine']);
    $mine->assignees()->attach($user);
    $theirs = Task::factory()->for($workspace)->create(['title' => 'Theirs']);
    $digest = new TaskDigest([], [new DigestTaskItem('Send proposal', now(), 'https://app.test/tasks?b')]);

    $link = (new TaskDigestMail($user, $workspace, $digest))->content()->with['tasksUrl'];
    parse_str((string) parse_url($link, PHP_URL_QUERY), $query);

    $this->actingAs($user);
    Filament::setTenant($workspace);
    Filament::setCurrentPanel(Filament::getPanel('app'));
    Filament::bootCurrentPanel();

    expect($query)->toHaveKey('filters')->not->toHaveKey('tableFilters');

    Livewire::withQueryParams($query)->test(ManageTasks::class)
        ->assertCanSeeTableRecords([$mine])
        ->assertCanNotSeeTableRecords([$theirs]);
});
