<?php

declare(strict_types=1);

namespace App\Filament\RelationManagers;

use App\Models\Note;
use App\Models\User;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Table;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Relaticle\EmailIntegration\Models\Email;
use Relaticle\EmailIntegration\Models\Scopes\VisibleEmailScope;

abstract class BaseActivityTimelineRelationManager extends RelationManager
{
    protected static string $relationship = 'emails';

    protected static string|\BackedEnum|null $icon = 'heroicon-o-clock';

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return 'Activity';
    }

    public function render(): View
    {
        $this->getOwnerRecord();
        $activities = $this->loadActivities();

        return view('filament.relation-managers.activity-timeline', [
            'activities' => $activities,
            'authUser' => $this->authUser(),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table;
    }

    /**
     * @return Collection<int, array{type: string, date: Carbon|null, record: Email|Note}>
     */
    private function loadActivities(): Collection
    {
        $record = $this->getOwnerRecord();
        $user = $this->authUser();

        $emails = collect();

        if (method_exists($record, 'emails')) {
            $emails = $record->emails()
                ->with(['from', 'labels', 'participants'])
                ->withGlobalScope('visible', new VisibleEmailScope($user))
                ->latest('sent_at')
                ->limit(100)
                ->get()
                ->map(fn (Email $email): array => [
                    'type' => 'email',
                    'date' => $email->sent_at ?? $email->created_at,
                    'record' => $email,
                ]);
        }

        $notes = collect();

        if (method_exists($record, 'notes')) {
            $notes = $record->notes()
                ->with('creator')
                ->latest()
                ->limit(100)
                ->get()
                ->map(fn (Note $note): array => [
                    'type' => 'note',
                    'date' => $note->created_at,
                    'record' => $note,
                ]);
        }

        return $emails->merge($notes)
            ->sortByDesc('date')
            ->values();
    }

    private function authUser(): User
    {
        /** @var User */
        return auth()->user();
    }
}
