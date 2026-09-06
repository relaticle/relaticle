<?php

declare(strict_types=1);

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Str;

final class TaskAssignedMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(
        public string $taskTitle,
        public string $taskUrl,
        public ?string $teamName = null,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: __('mail.task_assigned.subject', ['title' => Str::limit($this->taskTitle, 45)]));
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'mail.task-assigned',
            with: [
                'teamName' => $this->teamName,
                'preheader' => $this->teamName === null
                    ? __('mail.task_assigned.preheader_without_team')
                    : __('mail.task_assigned.preheader', ['team' => $this->teamName]),
                'rows' => array_filter([
                    ['label' => $this->taskTitle, 'url' => $this->taskUrl],
                    $this->teamName === null ? null : ['label' => __('mail.task_assigned.team_label'), 'value' => $this->teamName],
                ]),
            ],
        );
    }
}
