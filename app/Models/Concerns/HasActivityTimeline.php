<?php

declare(strict_types=1);

namespace App\Models\Concerns;

use App\ActivityLog\AppEventPalette;
use App\Features\EmailIntegration;
use Laravel\Pennant\Feature;
use Relaticle\ActivityLog\Concerns\InteractsWithTimeline;
use Relaticle\ActivityLog\Timeline\Sources\RelatedModelSource;
use Relaticle\ActivityLog\Timeline\TimelineBuilder;
use Relaticle\EmailIntegration\ActivityLog\EmailTimelineSource;

trait HasActivityTimeline
{
    use InteractsWithTimeline;

    public function timeline(): TimelineBuilder
    {
        $timeline = TimelineBuilder::make($this)
            ->fromActivityLog(mergedRenderer: 'merged-activity')
            ->fromRelation('notes', fn (RelatedModelSource $source): RelatedModelSource => $source
                ->event('created_at', 'note_created')
                ->with(['creator'])
                ->title(fn ($note): string => $note->title ?? AppEventPalette::NoteCreated->getLabel())
                ->causer('creator'))
            ->fromRelation('tasks', fn (RelatedModelSource $source): RelatedModelSource => $source
                ->event('created_at', 'task_created')
                ->with(['creator'])
                ->title(fn ($task): string => $task->title ?? AppEventPalette::TaskCreated->getLabel())
                ->causer('creator'));

        if (Feature::active(EmailIntegration::class)) {
            $timeline->fromRelation('emails', (new EmailTimelineSource(auth()->user()))(...));
        }

        return $timeline;
    }
}
