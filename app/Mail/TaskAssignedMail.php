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
        public ?string $workspaceName = null,
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
                'workspaceName' => $this->workspaceName,
                'preheader' => $this->workspaceName === null
                    ? __('mail.task_assigned.preheader_without_workspace')
                    : __('mail.task_assigned.preheader', ['workspace' => $this->workspaceName]),
                'rows' => array_filter([
                    ['label' => $this->taskTitle, 'url' => $this->taskUrl],
                    $this->workspaceName === null ? null : ['label' => __('mail.task_assigned.workspace_label'), 'value' => $this->workspaceName],
                ]),
            ],
        );
    }
}
