<?php

declare(strict_types=1);

namespace App\Mail;

use App\Data\DigestPayload;
use App\Filament\Pages\NotificationPreferences;
use App\Filament\Resources\TaskResource;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

final class TaskDigestMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(
        public User $user,
        public DigestPayload $payload,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Your tasks for today');
    }

    public function content(): Content
    {
        $tenant = $this->user->currentTeam ?? $this->user->allTeams()->first();

        return new Content(
            markdown: 'mail.task-digest',
            with: [
                'greetingName' => explode(' ', $this->user->name)[0],
                'payload' => $this->payload,
                'tasksUrl' => TaskResource::getUrl(
                    name: 'index',
                    parameters: [
                        'tableFilters' => ['assigned_to_me' => ['isActive' => true]],
                        'tenant' => $tenant,
                    ],
                    panel: 'app',
                ),
                'manageSettingsUrl' => NotificationPreferences::getUrl(
                    panel: 'app',
                    tenant: $tenant,
                ),
                'companyName' => (string) config('relaticle.company.name'),
                'companyAddress' => (string) config('relaticle.company.address'),
            ],
        );
    }
}
