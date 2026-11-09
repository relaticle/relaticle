<?php

declare(strict_types=1);

use App\Models\Task;
use App\Models\User;
use App\Notifications\Channels\InboxChannel;
use App\Support\CurrentWorkspace;
use Filament\Notifications\Events\DatabaseNotificationsSent;
use Filament\Notifications\Notification as FilamentNotification;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Tests\Helpers\GroupedInboxNotification;

mutates(InboxChannel::class);

beforeEach(function (): void {
    $this->user = User::factory()->withPersonalWorkspace()->create();
    $this->task = Task::factory()->for($this->user->currentWorkspace)->create();
});

it('collapses a send with the same group key into the unread row', function (): void {
    $this->user->notifyNow(new GroupedInboxNotification($this->task, 'task:1', 'first'));
    $this->user->notifyNow(new GroupedInboxNotification($this->task, 'task:1', 'second'));

    expect($this->user->notifications()->sole()->data)->toBe(['labels' => ['first', 'second']]);
});

it('starts a new row once the earlier one with the group key is read', function (): void {
    $this->user->notifyNow(new GroupedInboxNotification($this->task, 'task:1', 'first'));
    $this->user->notifications()->sole()->markAsRead();
    $this->user->notifyNow(new GroupedInboxNotification($this->task, 'task:1', 'second'));

    expect($this->user->notifications()->count())->toBe(2);
});

it('collapses into a read row and unreads it when the notice replaces read rows', function (): void {
    $this->user->notifyNow(new GroupedInboxNotification($this->task, 'task:1', 'first', collapsesReadRows: true));
    $this->user->notifications()->sole()->markAsRead();
    $this->user->notifyNow(new GroupedInboxNotification($this->task, 'task:1', 'second', collapsesReadRows: true));

    $row = $this->user->notifications()->sole();

    expect($row->read_at)->toBeNull()
        ->and($row->data)->toBe(['labels' => ['first', 'second']]);
});

it('collapses into the newest read row that is not archived', function (): void {
    $this->user->notifyNow(new GroupedInboxNotification($this->task, 'task:1', 'first', collapsesReadRows: true));
    $this->user->notifications()->sole()->forceFill(['read_at' => now(), 'archived_at' => now()])->save();
    $this->user->notifyNow(new GroupedInboxNotification($this->task, 'task:1', 'second', collapsesReadRows: true));
    $this->user->notifications()->whereNull('archived_at')->sole()->markAsRead();
    $this->user->notifyNow(new GroupedInboxNotification($this->task, 'task:1', 'third', collapsesReadRows: true));

    expect($this->user->notifications()->count())->toBe(2)
        ->and($this->user->notifications()->whereNull('archived_at')->sole()->data)->toBe(['labels' => ['second', 'third']]);
});

it('collapses into the row a concurrent send inserted first', function (): void {
    $raced = false;

    DB::listen(function (QueryExecuted $query) use (&$raced): void {
        if ($raced || ! str_contains($query->sql, 'select * from "notifications"')) {
            return;
        }

        $raced = true;

        DB::table('notifications')->insert([
            'id' => (string) Str::uuid(),
            'type' => GroupedInboxNotification::class,
            'notifiable_type' => $this->user->getMorphClass(),
            'notifiable_id' => $this->user->getKey(),
            'data' => json_encode(['labels' => ['competitor']]),
            'group_key' => 'task:1',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    });

    $this->user->notifyNow(new GroupedInboxNotification($this->task, 'task:1', 'second'));

    expect($this->user->notifications()->sole()->data)->toBe(['labels' => ['competitor', 'second']]);
});

it('locks the unread row it collapses into', function (): void {
    $this->user->notifyNow(new GroupedInboxNotification($this->task, 'task:1', 'first'));

    $statements = [];
    DB::listen(function (QueryExecuted $query) use (&$statements): void {
        $statements[] = $query->sql;
    });

    $this->user->notifyNow(new GroupedInboxNotification($this->task, 'task:1', 'second'));

    $locked = collect($statements)->contains(fn (string $sql): bool => str_contains($sql, 'from "notifications"') && str_contains($sql, 'for update'));

    expect($locked)->toBeTrue();
});

it('rethrows a unique violation that no collapse explains', function (): void {
    $notification = new GroupedInboxNotification($this->task, 'task:1', 'first');
    $notification->id = (string) Str::uuid();

    DB::table('notifications')->insert([
        'id' => $notification->id,
        'type' => GroupedInboxNotification::class,
        'notifiable_type' => $this->user->getMorphClass(),
        'notifiable_id' => $this->user->getKey(),
        'data' => json_encode(['labels' => ['taken']]),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    expect(fn () => $this->user->notifyNow($notification))->toThrow(UniqueConstraintViolationException::class)
        ->and($this->user->notifications()->count())->toBe(1);
});

it('broadcasts only after the surrounding transaction commits', function (): void {
    Event::fake([DatabaseNotificationsSent::class]);

    DB::transaction(function (): void {
        $this->user->notifyNow(new GroupedInboxNotification($this->task, 'task:1', 'first'));

        Event::assertNotDispatched(DatabaseNotificationsSent::class);
    });

    Event::assertDispatchedTimes(DatabaseNotificationsSent::class, 1);
});

it('does not broadcast when the surrounding transaction rolls back', function (): void {
    Event::fake([DatabaseNotificationsSent::class]);

    try {
        DB::transaction(function (): void {
            $this->user->notifyNow(new GroupedInboxNotification($this->task, 'task:1', 'first'));

            throw new RuntimeException('rolled back');
        });
    } catch (RuntimeException) {
    }

    Event::assertNotDispatched(DatabaseNotificationsSent::class);
    expect($this->user->notifications()->count())->toBe(0);
});

it('stamps a Filament notice with the workspace bound when it is sent', function (): void {
    resolve(CurrentWorkspace::class)->within($this->user->currentWorkspace, function (): void {
        FilamentNotification::make()->title('Export ready')->sendToDatabase($this->user);
    });

    $row = $this->user->notifications()->sole();

    expect($row->workspace_id)->toBe($this->user->currentWorkspace->getKey())
        ->and($row->reason)->toBeNull()
        ->and($row->data['title'])->toBe('Export ready');
});

it('leaves a Filament notice account-level when no workspace is bound', function (): void {
    FilamentNotification::make()->title('Export ready')->sendToDatabase($this->user);

    expect($this->user->notifications()->sole()->workspace_id)->toBeNull();
});

it('stamps a Filament notice sent from a job with the workspace bound at dispatch', function (): void {
    $dehydrated = resolve(CurrentWorkspace::class)->within(
        $this->user->currentWorkspace,
        fn (): ?array => Context::dehydrate(),
    );
    Context::flush();
    app()->forgetScopedInstances();
    Context::hydrate($dehydrated);

    FilamentNotification::make()->title('Export ready')->sendToDatabase($this->user);

    expect($this->user->notifications()->sole()->workspace_id)->toBe($this->user->currentWorkspace->getKey());
});

it('broadcasts a Filament notice only after the surrounding transaction commits', function (): void {
    Event::fake([DatabaseNotificationsSent::class]);

    DB::transaction(function (): void {
        FilamentNotification::make()->title('Export ready')->sendToDatabase($this->user);

        Event::assertNotDispatched(DatabaseNotificationsSent::class);
    });

    Event::assertDispatchedTimes(DatabaseNotificationsSent::class, 1);
});

it('rejects a database notification that does not extend the inbox base', function (): void {
    $notification = new class extends Notification
    {
        /** @return list<string> */
        public function via(object $notifiable): array
        {
            return ['database'];
        }

        /** @return array<string, mixed> */
        public function toArray(object $notifiable): array
        {
            return ['title' => 'unowned'];
        }
    };

    expect(fn () => $this->user->notifyNow($notification))
        ->toThrow(LogicException::class, $notification::class)
        ->and($this->user->notifications()->count())->toBe(0);
});
