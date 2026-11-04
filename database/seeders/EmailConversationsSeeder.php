<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\WorkspaceRole;
use App\Models\Company;
use App\Models\Scopes\WorkspaceScope;
use App\Models\User;
use App\Models\Workspace;
use Carbon\CarbonImmutable;
use Database\Seeders\Personas\PersonaCatalog;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;
use Relaticle\EmailIntegration\Actions\RefreshConversationSummary;
use Relaticle\EmailIntegration\Enums\AutomatedReason;
use Relaticle\EmailIntegration\Enums\EmailCreationSource;
use Relaticle\EmailIntegration\Enums\EmailDirection;
use Relaticle\EmailIntegration\Enums\EmailFolder;
use Relaticle\EmailIntegration\Enums\EmailPrivacyTier;
use Relaticle\EmailIntegration\Enums\EmailStatus;
use Relaticle\EmailIntegration\Models\ConnectedAccount;
use Relaticle\EmailIntegration\Models\Email;
use Relaticle\EmailIntegration\Models\EmailAttachment;
use Relaticle\EmailIntegration\Models\EmailBody;
use Relaticle\EmailIntegration\Models\EmailParticipant;
use Relaticle\EmailIntegration\Models\EmailRead;

// Rebuilt on every run against today's clock, so Awaiting reply stays three business days old.
final class EmailConversationsSeeder extends Seeder
{
    private const string MARKER = '@seed.acme-sales.test>';

    /** @var array<string, ConnectedAccount> */
    private array $mailboxes = [];

    private Workspace $workspace;

    public function run(): void
    {
        $owner = User::query()->where('email', 'owner@'.PersonaCatalog::DOMAIN)->first();
        $workspace = $owner?->ownedWorkspaces()->where('name', 'Acme Sales')->first();

        if (! $owner instanceof User || ! $workspace instanceof Workspace) {
            $this->command?->warn('Skipping email conversations: the Acme Sales persona is not seeded.');

            return;
        }

        $this->workspace = $workspace;
        $this->mailboxes = [
            'owner' => $this->mailbox($owner, 'owner@acme-sales.com', 'Olivia Owner'),
            'mate' => $this->mailbox($this->member('admin@'.PersonaCatalog::DOMAIN, 'Adam Admin'), 'mate@acme-sales.com', 'Adam Admin'),
        ];
        $this->mailbox($this->member('riley@'.PersonaCatalog::DOMAIN, 'Riley Rep'), 'riley@acme-sales.com', 'Riley Rep');

        $keys = $this->removeSeededMail();

        foreach ($this->conversations() as $label => $conversation) {
            $keys[] = $this->seedConversation($label, $conversation);
        }

        foreach (array_unique($keys) as $key) {
            resolve(RefreshConversationSummary::class)->execute($workspace->getKey(), $key);
        }

        $this->command?->info('Seeded '.count($this->conversations()).' email conversations for Acme Sales.');
    }

    private function member(string $email, string $name): User
    {
        $user = User::query()->firstOrNew(['email' => $email]);

        if (! $user->exists) {
            $user->forceFill(User::factory()->raw(['email' => $email]))->save();
        }

        $user->forceFill([
            'name' => $name,
            'password' => bcrypt(PersonaCatalog::PASSWORD),
            'email_verified_at' => now(),
            'current_workspace_id' => $user->current_workspace_id ?? $this->workspace->getKey(),
        ])->save();

        $this->workspace->users()->syncWithoutDetaching([$user->getKey() => ['role' => WorkspaceRole::Member->value]]);

        return $user;
    }

    private function mailbox(User $user, string $address, string $name): ConnectedAccount
    {
        $existing = ConnectedAccount::query()
            ->where('workspace_id', $this->workspace->getKey())
            ->where('user_id', $user->getKey())
            ->first();

        return $existing ?? ConnectedAccount::withoutEvents(fn (): ConnectedAccount => ConnectedAccount::factory()->create([
            'workspace_id' => $this->workspace->getKey(),
            'user_id' => $user->getKey(),
            'email_address' => $address,
            'display_name' => $name,
            'status' => 'active',
        ]));
    }

    /** @return list<string> */
    private function removeSeededMail(): array
    {
        $seeded = Email::query()
            ->withoutGlobalScopes()
            ->withTrashed()
            ->where('workspace_id', $this->workspace->getKey())
            ->where('rfc_message_id', 'like', '%'.self::MARKER);

        $keys = array_values(array_filter($seeded->clone()->distinct()->pluck('conversation_key')->all(), is_string(...)));

        $seeded->forceDelete();

        return $keys;
    }

    /**
     * @param  array{subject: string, company?: string, tier?: EmailPrivacyTier, automated?: AutomatedReason, attachment?: string, read?: bool, messages: list<array{0: string, 1: string, 2: string, 3: string}>}  $conversation
     */
    private function seedConversation(string $label, array $conversation): string
    {
        // A holder list of `owner,mate` gives both mailboxes a copy of the same message.
        $key = hash('sha256', 'seed:'.$label);
        $company = isset($conversation['company']) ? $this->company($conversation['company']) : null;

        foreach ($conversation['messages'] as $index => [$holders, $from, $at, $body]) {
            $rfcMessageId = "<{$label}-{$index}".self::MARKER;
            $subject = $index === 0 ? $conversation['subject'] : 'Re: '.$conversation['subject'];

            foreach (explode(',', $holders) as $holder) {
                $email = $this->message($this->mailboxes[$holder], $key, $rfcMessageId, $subject, $from, now()->modify($at), $body, $conversation);
                $company?->emails()->attach($email->getKey());
            }
        }

        return $key;
    }

    /**
     * @param  array{tier?: EmailPrivacyTier, automated?: AutomatedReason, attachment?: string, read?: bool}  $conversation
     */
    private function message(ConnectedAccount $mailbox, string $key, string $rfcMessageId, string $subject, string $from, CarbonImmutable $sentAt, string $body, array $conversation): Email
    {
        $outbound = $from === $mailbox->email_address;
        $email = Email::factory()->create([
            'workspace_id' => $this->workspace->getKey(),
            'user_id' => $mailbox->user_id,
            'connected_account_id' => $mailbox->getKey(),
            'conversation_key' => $key,
            'rfc_message_id' => $rfcMessageId,
            'thread_id' => 'seed-'.$key,
            'subject' => $subject,
            'snippet' => Str::limit($body, 120),
            'sent_at' => $sentAt,
            'direction' => $outbound ? EmailDirection::OUTBOUND : EmailDirection::INBOUND,
            'folder' => $outbound ? EmailFolder::Sent : EmailFolder::Inbox,
            'status' => EmailStatus::SYNCED,
            'creation_source' => EmailCreationSource::SYNC,
            'privacy_tier' => $mailbox->getKey() === $this->mailboxes['owner']->getKey() ? EmailPrivacyTier::FULL : ($conversation['tier'] ?? EmailPrivacyTier::FULL),
            'is_internal' => false,
            'has_attachments' => isset($conversation['attachment']),
            'automated_reason' => $conversation['automated'] ?? null,
        ]);

        $this->participants($email, $mailbox, $from, $outbound);
        EmailBody::factory()->create(['email_id' => $email->getKey(), 'body_text' => $body, 'body_html' => '<p>'.e($body).'</p>']);

        if (isset($conversation['attachment'])) {
            EmailAttachment::factory()->create(['email_id' => $email->getKey(), 'filename' => $conversation['attachment']]);
        }

        if ($outbound || ($conversation['read'] ?? true)) {
            EmailRead::factory()->create(['email_id' => $email->getKey(), 'user_id' => $mailbox->user_id, 'read_at' => $sentAt]);
        }

        return $email;
    }

    private function participants(Email $email, ConnectedAccount $mailbox, string $from, bool $outbound): void
    {
        $counterpart = $outbound ? $this->counterpart($email) : $from;

        EmailParticipant::factory()->from()->create([
            'email_id' => $email->getKey(),
            'email_address' => $from,
            'name' => $outbound ? $this->mailboxName($mailbox) : $this->nameFor($from),
        ]);
        EmailParticipant::factory()->to()->create([
            'email_id' => $email->getKey(),
            'email_address' => $outbound ? $counterpart : $mailbox->email_address,
            'name' => $outbound ? $this->nameFor($counterpart) : $this->mailboxName($mailbox),
        ]);
    }

    private function counterpart(Email $email): string
    {
        foreach ($this->conversations() as $label => $conversation) {
            if (hash('sha256', 'seed:'.$label) === $email->conversation_key) {
                return $conversation['contact'];
            }
        }

        return 'hello@example.com';
    }

    private function mailboxName(ConnectedAccount $mailbox): string
    {
        return $mailbox->display_name ?? $mailbox->user?->name ?? $this->nameFor($mailbox->email_address);
    }

    private function nameFor(string $address): string
    {
        return Str::headline(str_replace('.', ' ', Str::before($address, '@')));
    }

    private function company(string $name): ?Company
    {
        return Company::query()
            ->withoutGlobalScope(WorkspaceScope::class)
            ->where('workspace_id', $this->workspace->getKey())
            ->where('name', $name)
            ->first();
    }

    /**
     * @return array<string, array{subject: string, contact: string, company?: string, tier?: EmailPrivacyTier, automated?: AutomatedReason, attachment?: string, read?: bool, messages: list<array{0: string, 1: string, 2: string, 3: string}>}>
     */
    private function conversations(): array
    {
        return [
            ...$this->needingReply(),
            ...$this->awaitingReply(),
            ...$this->teammateMail(),
            ...$this->unlinked(),
            ...$this->automated(),
            ...$this->answered(),
        ];
    }

    /** @return array<string, array<string, mixed>> */
    private function needingReply(): array
    {
        $o = 'owner@acme-sales.com';

        return [
            'airbnb-renewal' => ['subject' => 'Renewal pricing for next year', 'contact' => 'brian.chen@airbnb.com', 'company' => 'Airbnb', 'read' => false, 'attachment' => 'Airbnb-renewal-quote.pdf', 'messages' => [
                ['owner', $o, '-3 days', 'Hi Brian, attached is the renewal quote for 120 seats. Happy to walk through it this week.'],
                ['owner', 'brian.chen@airbnb.com', '-2 hours', 'Thanks Olivia. Finance wants to know if the 12% multi-year discount still applies if we sign before the 15th.'],
            ]],
            'figma-security' => ['subject' => 'Security questionnaire before procurement', 'contact' => 'nina.patel@figma.com', 'company' => 'Figma', 'read' => false, 'messages' => [
                ['owner', 'nina.patel@figma.com', '-5 hours', 'Our procurement team needs the SOC 2 report and the completed questionnaire before Friday. Can you send both?'],
            ]],
            'notion-pilot' => ['subject' => 'Pilot results and next steps', 'contact' => 'sam.ortiz@notion.so', 'company' => 'Notion', 'messages' => [
                ['owner', $o, '-4 days', 'Sam, here is the summary of the 30-day pilot. Adoption reached 82% of the sales team.'],
                ['owner', 'sam.ortiz@notion.so', '-1 day', 'Great numbers. Could we get a proposal for the full team of 300 by end of month?'],
            ]],
            'apple-intro' => ['subject' => 'Intro from Marcus at the SaaStr dinner', 'contact' => 'lena.wu@apple.com', 'company' => 'Apple', 'read' => false, 'messages' => [
                ['owner', 'lena.wu@apple.com', '-1 day', 'Marcus mentioned you help field sales teams keep their pipeline clean. Do you have 20 minutes next Tuesday?'],
            ]],
            'airbnb-legal' => ['subject' => 'DPA redlines from legal', 'contact' => 'kate.morris@airbnb.com', 'company' => 'Airbnb', 'attachment' => 'DPA-redlines-v3.docx', 'messages' => [
                ['owner', 'kate.morris@airbnb.com', '-2 days', 'Attached are our redlines on the DPA. Clause 7.2 on sub-processors is the only open point.'],
            ]],
            'figma-onboarding' => ['subject' => 'Onboarding session for the EMEA team', 'contact' => 'tom.becker@figma.com', 'company' => 'Figma', 'messages' => [
                ['owner', 'tom.becker@figma.com', '-6 hours', 'Can we book the onboarding session for our Berlin and London reps? Mornings CET work best.'],
            ]],
        ];
    }

    /** @return array<string, array<string, mixed>> */
    private function awaitingReply(): array
    {
        $o = 'owner@acme-sales.com';

        return [
            'apple-proposal' => ['subject' => 'Proposal for the retail partnerships team', 'contact' => 'james.hill@apple.com', 'company' => 'Apple', 'attachment' => 'Acme-proposal-Apple.pdf', 'messages' => [
                ['owner', 'james.hill@apple.com', '-12 days', 'Could you send over a proposal for the retail partnerships team? About 45 seats.'],
                ['owner', $o, '-8 days', 'James, the proposal is attached. It includes the onboarding package we discussed.'],
            ]],
            'notion-followup' => ['subject' => 'Following up on the security review', 'contact' => 'priya.nair@notion.so', 'company' => 'Notion', 'messages' => [
                ['owner', $o, '-6 days', 'Hi Priya, checking whether your security team had any questions on the pen test summary.'],
            ]],
            'prospect-gong' => ['subject' => 'Quick question about your pipeline reviews', 'contact' => 'derek.lane@brightwave.io', 'messages' => [
                ['owner', $o, '-9 days', 'Derek, saw your post about weekly pipeline reviews. Would a shared view of every open deal help your managers?'],
            ]],
        ];
    }

    /** @return array<string, array<string, mixed>> */
    private function teammateMail(): array
    {
        return [
            'airbnb-exec-sync' => ['subject' => 'Exec sponsor sync agenda', 'contact' => 'olivia.grant@airbnb.com', 'company' => 'Airbnb', 'tier' => EmailPrivacyTier::METADATA_ONLY, 'messages' => [
                ['mate', 'olivia.grant@airbnb.com', '-3 hours', 'Adam, here is the agenda for Thursday. I would like to cover expansion into the host support team.'],
            ]],
            'airbnb-budget' => ['subject' => 'Q1 budget approval timeline', 'contact' => 'raj.mehta@airbnb.com', 'company' => 'Airbnb', 'tier' => EmailPrivacyTier::SUBJECT, 'messages' => [
                ['mate', 'raj.mehta@airbnb.com', '-1 day', 'Budget committee meets on the 20th. I will know by the 22nd whether the expansion is approved.'],
            ]],
            'figma-shared' => ['subject' => 'Contract countersigned', 'contact' => 'nina.patel@figma.com', 'company' => 'Figma', 'messages' => [
                ['owner,mate', 'nina.patel@figma.com', '-4 hours', 'Both of you, the contract is countersigned. Welcome aboard, and thanks for the patience on legal.'],
            ]],
        ];
    }

    /** @return array<string, array<string, mixed>> */
    private function unlinked(): array
    {
        $o = 'owner@acme-sales.com';

        return [
            'conference' => ['subject' => 'Speaker slot at RevOps Summit', 'contact' => 'events@revopssummit.com', 'read' => false, 'messages' => [
                ['owner', 'events@revopssummit.com', '-7 hours', 'We would love to have you on the closing panel in March. Are you available on the 14th?'],
            ]],
            'recruiter' => ['subject' => 'Senior AE candidates for your team', 'contact' => 'mia.santos@talentbridge.co', 'messages' => [
                ['owner', 'mia.santos@talentbridge.co', '-2 days', 'I have three senior AE candidates with mid-market SaaS experience. Want to see their profiles?'],
            ]],
            'partner' => ['subject' => 'Referral partnership', 'contact' => 'leo.fischer@northwind.dev', 'messages' => [
                ['owner', 'leo.fischer@northwind.dev', '-3 days', 'We send a lot of agencies your way. Could we set up a referral agreement?'],
                ['owner', $o, '-2 days', 'Leo, happy to. I will send our partner terms after our board meeting next week.'],
            ]],
            'advisor' => ['subject' => 'Coffee next week?', 'contact' => 'hannah.ross@gmail.com', 'messages' => [
                ['owner', 'hannah.ross@gmail.com', '-1 day', 'Back in town next week. Coffee on Wednesday to catch up on the fundraise?'],
            ]],
        ];
    }

    /** @return array<string, array<string, mixed>> */
    private function automated(): array
    {
        return [
            'newsletter-saastr' => ['subject' => 'This week in SaaS: pricing pages that convert', 'contact' => 'newsletter@saastr.com', 'automated' => AutomatedReason::List, 'messages' => [
                ['owner', 'newsletter@saastr.com', '-5 hours', 'Five pricing page experiments that lifted conversion, and what B2B teams can learn from them.'],
            ]],
            'receipt-stripe' => ['subject' => 'Your receipt from Linear #2041', 'contact' => 'receipts@stripe.com', 'automated' => AutomatedReason::RoleSender, 'attachment' => 'receipt-2041.pdf', 'messages' => [
                ['owner', 'receipts@stripe.com', '-1 day', 'Receipt for your Linear subscription. Amount paid: $96.00.'],
            ]],
            'receipt-zoom' => ['subject' => 'Your Zoom invoice for October', 'contact' => 'billing@zoom.us', 'automated' => AutomatedReason::RoleSender, 'messages' => [
                ['owner', 'billing@zoom.us', '-3 days', 'Your invoice for October is ready. Total: $149.90.'],
            ]],
            'notion-digest' => ['subject' => 'Notion workspace digest', 'contact' => 'notify@mail.notion.so', 'company' => 'Notion', 'automated' => AutomatedReason::Bulk, 'messages' => [
                ['owner', 'notify@mail.notion.so', '-2 days', 'Sam Ortiz and 3 others edited pages in the Acme pilot workspace.'],
            ]],
            'calendar-reminder' => ['subject' => 'Reminder: Airbnb QBR tomorrow at 10:00', 'contact' => 'calendar-notification@google.com', 'company' => 'Airbnb', 'automated' => AutomatedReason::AutoSubmitted, 'messages' => [
                ['owner', 'calendar-notification@google.com', '-9 hours', 'Airbnb QBR, Thursday 10:00 to 11:00. Joining: Brian Chen, Kate Morris.'],
            ]],
        ];
    }

    /** @return array<string, array<string, mixed>> */
    private function answered(): array
    {
        $o = 'owner@acme-sales.com';
        $threads = [
            ['apple-demo', 'Demo recording', 'lena.wu@apple.com', 'Apple'],
            ['airbnb-seats', 'Adding 10 seats for support', 'brian.chen@airbnb.com', 'Airbnb'],
            ['figma-invoice', 'Invoice address change', 'ap@figma.com', 'Figma'],
            ['notion-sso', 'SSO setup question', 'sam.ortiz@notion.so', 'Notion'],
            ['apple-ref', 'Customer reference call', 'james.hill@apple.com', 'Apple'],
            ['airbnb-training', 'Admin training recording', 'kate.morris@airbnb.com', 'Airbnb'],
            ['figma-roadmap', 'Roadmap preview', 'tom.becker@figma.com', 'Figma'],
            ['notion-import', 'CSV import of old deals', 'priya.nair@notion.so', 'Notion'],
            ['apple-terms', 'Payment terms', 'ap@apple.com', 'Apple'],
            ['airbnb-api', 'API rate limits', 'dev@airbnb.com', 'Airbnb'],
            ['figma-feedback', 'Feedback from the design team', 'nina.patel@figma.com', 'Figma'],
            ['notion-usage', 'Usage report for October', 'sam.ortiz@notion.so', 'Notion'],
            ['apple-workshop', 'Pipeline workshop slides', 'lena.wu@apple.com', 'Apple'],
            ['airbnb-renewal-date', 'Renewal date confirmation', 'raj.mehta@airbnb.com', 'Airbnb'],
        ];

        $conversations = [];

        foreach ($threads as $index => [$label, $subject, $contact, $company]) {
            $conversations[$label] = ['subject' => $subject, 'contact' => $contact, 'company' => $company, 'messages' => [
                ['owner', $contact, '-'.($index * 3 + 5).' hours', "Quick question on {$subject}. Could you take a look when you have a moment?"],
                ['owner', $o, '-'.($index * 3 + 4).' hours', "Done. I have sorted out {$subject} and shared the details with your team."],
            ]];
        }

        return $conversations;
    }
}
