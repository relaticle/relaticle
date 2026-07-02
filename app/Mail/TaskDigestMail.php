<?php

declare(strict_types=1);

namespace App\Mail;

use App\Data\DigestPayload;
use App\Enums\Notifications\DigestCadence;
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
        public DigestCadence $cadence,
    ) {}

    public function envelope(): Envelope
    {
        $subject = $this->cadence === DigestCadence::Weekly
            ? 'Your tasks for the week ahead'
            : 'Your tasks for today';

        return new Envelope(subject: $subject);
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'mail.task-digest',
            with: [
                'greetingName' => explode(' ', $this->user->name)[0],
                'payload' => $this->payload,
                'tasksUrl' => TaskResource::getUrl(
                    name: 'index',
                    parameters: [
                        'tableFilters' => ['assigned_to_me' => ['isActive' => true]],
                        'tenant' => $this->user->currentTeam,
                    ],
                    panel: 'app',
                ),
                'companyName' => (string) config('relaticle.company.name'),
                'companyAddress' => (string) config('relaticle.company.address'),
            ],
        );
    }
}
