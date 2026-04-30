<?php

declare(strict_types=1);

namespace App\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Prism\Prism\Enums\Provider;
use Prism\Prism\Facades\Prism;
use Relaticle\EmailIntegration\Enums\EmailPrivacyTier;
use Relaticle\EmailIntegration\Models\Email;
use Relaticle\EmailIntegration\Models\EmailLabel;

final class ClassifyEmailJob implements ShouldQueue
{
    use Queueable;

    /** @var list<string> */
    public const array CATEGORIES = [
        'Scheduling',
        'Marketing',
        'Invoice',
        'Support',
        'Sales',
        'Personal',
        'Other',
    ];

    public function __construct(public readonly string $emailId)
    {
        $this->onQueue('emails-sync');
    }

    public function handle(): void
    {
        /** @var Email|null $email */
        $email = Email::with(['body', 'participants'])->find($this->emailId);

        if ($email === null) {
            return;
        }

        // Idempotent: skip if already classified
        if ($email->labels()->where('source', 'ai')->exists()) {
            return;
        }

        $category = $this->classify($email);

        EmailLabel::query()->create([
            'email_id' => $email->getKey(),
            'label' => $category,
            'source' => 'ai',
            'created_at' => now(),
        ]);
    }

    private function classify(Email $email): string
    {
        $subject = $email->subject ?? '(no subject)';
        $snippet = $email->snippet ?? '';
        $from = $email->participants->where('role', 'from')->first()->email_address ?? '';

        $body = '';
        if ($email->privacy_tier === EmailPrivacyTier::FULL) {
            $body = mb_substr($email->body->body_text ?? '', 0, 800);
        }

        $prompt = <<<TEXT
Classify this email into exactly one category from the list below.
Respond with only the category name — nothing else.

Categories: Scheduling, Marketing, Invoice, Support, Sales, Personal, Other

Subject: {$subject}
From: {$from}
Snippet: {$snippet}
Body excerpt: {$body}
TEXT;

        $response = Prism::text()
            ->using(Provider::Anthropic, config('services.anthropic.summary_model'))
            ->withPrompt($prompt)
            ->generate();

        $result = trim($response->text);

        return in_array($result, self::CATEGORIES, true) ? $result : 'Other';
    }
}
