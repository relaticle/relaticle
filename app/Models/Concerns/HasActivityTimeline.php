<?php

declare(strict_types=1);

namespace App\Models\Concerns;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\Relation;
use Relaticle\ActivityLog\Concerns\InteractsWithTimeline;
use Relaticle\ActivityLog\Timeline\Sources\RelatedModelSource;
use Relaticle\ActivityLog\Timeline\TimelineBuilder;
use Relaticle\EmailIntegration\Models\Scopes\VisibleEmailScope;

trait HasActivityTimeline
{
    use InteractsWithTimeline;

    public function timeline(): TimelineBuilder
    {
        $viewer = auth()->user();

        return TimelineBuilder::make($this)
            ->fromActivityLog()
            ->fromRelation('emails', function (RelatedModelSource $source) use ($viewer): void {
                $source
                    ->event('sent_at', 'email_sent')
                    ->event('created_at', 'email_received', when: fn ($email): bool => $email->sent_at === null)
                    ->with(['from', 'labels', 'participants'])
                    ->title(fn ($email): string => $email->subject ?? 'Email')
                    ->description(fn ($email): ?string => $email->from->first()?->email_address)
                    ->causer(fn ($email) => $email->from->first());

                if ($viewer instanceof User) {
                    $source->using(fn (Builder|Relation $query) => $query->withGlobalScope(
                        'visible',
                        new VisibleEmailScope($viewer),
                    ));
                }
            })
            ->fromRelation('notes', fn (RelatedModelSource $source): RelatedModelSource => $source
                ->event('created_at', 'note_created')
                ->with(['creator'])
                ->title(fn ($note): string => $note->title ?? 'Note')
                ->causer('creator'))
            ->fromRelation('tasks', fn (RelatedModelSource $source): RelatedModelSource => $source
                ->event('created_at', 'task_created')
                ->with(['creator'])
                ->title(fn ($task): string => $task->title ?? 'Task')
                ->causer('creator'));
    }
}
