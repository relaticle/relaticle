<?php

declare(strict_types=1);

namespace App\Mail;

use App\Data\DigestPayload;
use App\Data\DigestTaskItem;
use App\Data\DigestTeamSection;
use App\Enums\Notifications\NotificationType;
use App\Filament\Pages\NotificationPreferences;
use App\Filament\Resources\TaskResource;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Mail\Mailables\Headers;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\URL;

final class TaskDigestMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(
        public User $user,
        public DigestPayload $payload,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: __('mail.task_digest.subject', [
            'date' => now($this->user->effectiveTimezone())->format('M j'),
        ]));
    }

    public function headers(): Headers
    {
        return new Headers(text: [
            'List-Unsubscribe' => "<{$this->unsubscribeUrl()}>",
            'List-Unsubscribe-Post' => 'List-Unsubscribe=One-Click',
        ]);
    }

    public function unsubscribeUrl(): string
    {
        return URL::signedRoute('mail.unsubscribe', [
            'user' => $this->user->id,
            'type' => NotificationType::TaskDigest->value,
        ]);
    }

    public function content(): Content
    {
        $tenant = $this->user->currentTeam ?? $this->user->allTeams()->first();
        $timezone = $this->user->effectiveTimezone();

        return new Content(
            markdown: 'mail.task-digest',
            with: [
                'greetingName' => explode(' ', $this->user->name)[0],
                'preheader' => __('mail.task_digest.preheader', [
                    'overdue' => $this->payload->overdueCount(),
                    'due' => $this->payload->upcomingCount(),
                ]),
                'sections' => array_map(fn (DigestTeamSection $team): array => [
                    'name' => $team->teamName,
                    'overdue' => $this->rows($team->overdue, $timezone),
                    'upcoming' => $this->rows($team->upcoming, $timezone),
                ], $this->payload->teams),
                'tasksUrl' => TaskResource::getUrl(
                    name: 'index',
                    parameters: [
                        'tableFilters' => ['assigned_to_me' => ['isActive' => true]],
                        'tenant' => $tenant,
                    ],
                    panel: 'app',
                ),
                'settingsUrl' => NotificationPreferences::getUrl(panel: 'app', tenant: $tenant),
                'unsubscribeUrl' => $this->unsubscribeUrl(),
            ],
        );
    }

    /**
     * @param  list<DigestTaskItem>  $items
     * @return list<array{label: string, url: string, meta: string}>
     */
    private function rows(array $items, string $timezone): array
    {
        return array_map(fn (DigestTaskItem $item): array => [
            'label' => $item->title,
            'url' => $item->editUrl,
            'meta' => __('mail.task_digest.due', ['date' => $item->dueAt->copy()->setTimezone($timezone)->format('M j, Y')]),
        ], $items);
    }
}
