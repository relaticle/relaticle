<?php

declare(strict_types=1);

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

final class TaskAssignedMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(
        public string $taskTitle,
        public string $taskUrl,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: "You've been assigned a task: {$this->taskTitle}");
    }

    public function content(): Content
    {
        return new Content(markdown: 'mail.task-assigned');
    }
}
