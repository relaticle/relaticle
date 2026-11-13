<?php

declare(strict_types=1);

namespace App\Mail;

use App\Data\DigestRecord;
use App\Data\DigestTaskItem;
use App\Data\TaskDigest;
use App\Enums\Notifications\NotificationType;
use App\Filament\Pages\NotificationPreferences;
use App\Filament\Resources\TaskResource;
use App\Models\User;
use App\Models\Workspace;
use Filament\Support\Colors\Color;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Mail\Mailables\Headers;
use Illuminate\Queue\Attributes\Backoff;
use Illuminate\Queue\Attributes\Tries;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Str;

#[Tries(3)]
#[Backoff(60, 300)]
final class TaskDigestMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    private const int PREHEADER_TITLES = 3;

    // Each card is about 6 KB once the theme is inlined; five per column keeps a digest under Gmail's 102 KB clip.
    private const int CARDS_PER_COLUMN = 5;

    private const int RECORDS_SHOWN = 1;

    private const int RECORD_NAME_LIMIT = 24;

    public function __construct(
        public User $user,
        public Workspace $workspace,
        public TaskDigest $digest,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->digestSubject());
    }

    private function digestSubject(): string
    {
        $overdue = $this->digest->overdueCount();
        $due = $this->digest->upcomingCount();
        $replace = ['workspace' => $this->workspace->name, 'date' => now($this->user->effectiveTimezone())->format('M j')];

        if ($overdue > 0 && $due > 0) {
            return __('mail.task_digest.subject_both', [...$replace, 'overdue' => $overdue, 'due' => $due]);
        }

        if ($overdue > 0) {
            return trans_choice('mail.task_digest.subject_overdue', $overdue, $replace);
        }

        return trans_choice('mail.task_digest.subject_due', $due, $replace);
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
        $timezone = $this->user->effectiveTimezone();

        return new Content(
            markdown: 'mail.task-digest',
            with: [
                'greetingName' => explode(' ', Str::squish($this->user->name))[0],
                'preheader' => $this->preheader(),
                'summary' => $this->summary(),
                'overdue' => $this->cards($this->digest->overdue, fn (DigestTaskItem $item): string => __('mail.task_digest.due_overdue', [
                    'date' => $item->dueAt->setTimezone($timezone)->format('M j, Y'),
                ])),
                'upcoming' => $this->cards($this->digest->upcoming, fn (): string => __('mail.task_digest.due_today_chip')),
                'tasksUrl' => TaskResource::getUrl(
                    name: 'index',
                    parameters: [
                        'tableFilters' => ['assigned_to_me' => ['isActive' => true]],
                        'tenant' => $this->workspace,
                    ],
                    panel: 'app',
                ),
                'settingsUrl' => NotificationPreferences::getUrl(panel: 'app', tenant: $this->workspace),
                'unsubscribeUrl' => $this->unsubscribeUrl(),
                'workspaceName' => $this->workspaceName(),
            ],
        );
    }

    private function summary(): HtmlString
    {
        $overdue = $this->digest->overdueCount();
        $due = $this->digest->upcomingCount();
        $overdueText = '<strong>'.e(trans_choice('mail.task_digest.summary_overdue', $overdue)).'</strong>';
        $dueText = '<strong>'.e(trans_choice('mail.task_digest.summary_due', $due)).'</strong>';
        $workspace = e($this->workspaceName());

        if ($overdue > 0 && $due > 0) {
            return new HtmlString(__('mail.task_digest.summary_both', ['overdue' => $overdueText, 'due' => $dueText, 'workspace' => $workspace]));
        }

        return new HtmlString(__('mail.task_digest.summary', ['tasks' => $overdue > 0 ? $overdueText : $dueText, 'workspace' => $workspace]));
    }

    private function preheader(): string
    {
        $titles = array_map(
            fn (DigestTaskItem $item): string => Str::squish($item->title),
            [...$this->digest->overdue, ...$this->digest->upcoming],
        );

        $shown = implode(', ', array_slice($titles, 0, self::PREHEADER_TITLES));
        $hidden = count($titles) - self::PREHEADER_TITLES;

        return $hidden > 0 ? __('mail.task_digest.preheader_more', ['tasks' => $shown, 'count' => $hidden]) : $shown;
    }

    /**
     * @param  list<DigestTaskItem>  $items
     * @param  callable(DigestTaskItem): string  $dueLabel
     * @return list<array{title: string, url: string, due: string, dueIcon: string, priority: ?array{label: string, icon: string, dot: ?string, text: ?string, background: ?string, border: ?string}, records: list<array{label: string, icon: ?string}>}>
     */
    private function cards(array $items, callable $dueLabel): array
    {
        $dueIcon = $this->iconUrl('lucide-calendar');
        $priorityIcon = $this->iconUrl('lucide-flag');

        return array_map(fn (DigestTaskItem $item): array => [
            'title' => Str::squish($item->title),
            'url' => $item->editUrl,
            'due' => $dueLabel($item),
            'dueIcon' => $dueIcon,
            'priority' => $item->priority === null ? null : ['label' => Str::squish($item->priority), 'icon' => $priorityIcon, ...$this->priorityColors($item->priorityColor)],
            'records' => $this->recordChips($item->records),
        ], array_slice($items, 0, self::CARDS_PER_COLUMN));
    }

    /**
     * @return array{dot: ?string, text: ?string, background: ?string, border: ?string}
     */
    private function priorityColors(?string $color): array
    {
        // The shades Filament's colored badge uses, so the chip matches the board's priority badge.
        if ($color === null || preg_match('/^#[0-9a-f]{6}$/i', $color) !== 1) {
            return ['dot' => null, 'text' => null, 'background' => null, 'border' => null];
        }

        $palette = Color::hex($color);

        return [
            'dot' => Color::convertToHex($palette[500]),
            'text' => Color::convertToHex($palette[700]),
            'background' => Color::convertToHex($palette[50]),
            'border' => Color::convertToHex($palette[100]),
        ];
    }

    /**
     * @param  list<DigestRecord>  $records
     * @return list<array{label: string, icon: ?string}>
     */
    private function recordChips(array $records): array
    {
        $chips = array_map(fn (DigestRecord $record): array => [
            'label' => Str::limit(Str::squish($record->name), self::RECORD_NAME_LIMIT),
            'icon' => $this->iconUrl($record->entity->icon()),
        ], array_slice($records, 0, self::RECORDS_SHOWN));
        $hidden = count($records) - self::RECORDS_SHOWN;

        if ($hidden > 0) {
            $chips[] = ['label' => __('mail.task_digest.more_records', ['count' => $hidden]), 'icon' => null];
        }

        return $chips;
    }

    // The view is Markdown: a blank line in a user-supplied name ends the HTML block and its text renders as Markdown.
    private function workspaceName(): string
    {
        return Str::squish($this->workspace->name);
    }

    private function iconUrl(string $lucideIcon): string
    {
        return asset('brand/email-icons/'.Str::after($lucideIcon, 'lucide-').'.png');
    }
}
