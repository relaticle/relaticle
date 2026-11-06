<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\Actions;

use App\Models\Company;
use App\Models\Opportunity;
use App\Models\People;
use App\Models\Workspace;
use App\Support\CurrentWorkspace;
use Illuminate\Contracts\Database\Query\Builder;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphToMany;
use Illuminate\Support\Facades\DB;
use Relaticle\EmailIntegration\Enums\ContactCreationMode;
use Relaticle\EmailIntegration\Enums\EmailDirection;
use Relaticle\EmailIntegration\Jobs\RecomputeRecordEmailMetricsJob;
use Relaticle\EmailIntegration\Models\Email;
use Relaticle\EmailIntegration\Models\EmailLinkSuppression;
use Relaticle\EmailIntegration\Models\Scopes\ActiveAccountScope;
use Relaticle\EmailIntegration\Services\EmailVisibilityService;
use Relaticle\EmailIntegration\Services\RecordCommunicationMetrics;
use Relaticle\EmailIntegration\Support\AutomatedSenderMatcher;
use Relaticle\EmailIntegration\Support\CompanyDomainMatcher;
use Relaticle\EmailIntegration\Support\PersonEmailMatcher;

final readonly class LinkEmailAction
{
    public function __construct(
        private AutoCreateCompanyAction $autoCreateCompany,
        private AutoCreatePersonAction $autoCreatePerson,
        private CompanyDomainMatcher $domainMatcher,
        private PersonEmailMatcher $personEmailMatcher,
        private AutomatedSenderMatcher $automatedSender,
        private EmailVisibilityService $visibility,
        private RecordCommunicationMetrics $metrics,
    ) {}

    /**
     * Link an email to its CRM records exactly once.
     *
     * incrementEmailMetrics() is not idempotent, so a second pass over an email
     * would inflate email_count on every record it touches. The `linked_at` claim
     * and the linking itself share one transaction, taken under a row lock on the
     * email: a crash mid-link rolls the claim back so a retry re-links from
     * scratch, and a retry after a completed link is a no-op. Concurrent workers
     * on the same email serialise on the lock, and the second one finds the claim.
     */
    public function execute(Email $email): void
    {
        $this->claimAndLink($email, reapply: false);
    }

    /**
     * Re-run CRM linking under the current workspace policy.
     *
     * Mailbox history re-import must create people after a None → Selective (or All)
     * switch even though `linked_at` was set on the first pass. autoAttach() still
     * skips metric increments for records already on the email.
     */
    public function reapply(Email $email): void
    {
        $this->claimAndLink($email, reapply: true);
    }

    private function claimAndLink(Email $email, bool $reapply): void
    {
        resolve(CurrentWorkspace::class)->within($email->workspace_id, fn () => $this->claimAndLinkInTransaction($email, $reapply));
    }

    private function claimAndLinkInTransaction(Email $email, bool $reapply): void
    {
        DB::transaction(function () use ($email, $reapply): void {
            $email->lockConversation();

            /** @var Email|null $locked */
            $locked = Email::query()
                ->withoutGlobalScopes()
                ->whereKey($email->getKey())
                ->lockForUpdate()
                ->first();

            if ($locked === null) {
                return;
            }

            if (! $reapply && $locked->linked_at !== null) {
                return;
            }

            $applyMetricsForPrelinkedRecords = $locked->linked_at === null;

            $this->detachSuppressed($email, $this->suppressedPairs($email, onThisCopyOnly: true));
            $this->link($email, $applyMetricsForPrelinkedRecords, $this->suppressedPairs($email, onThisCopyOnly: false));

            if ($locked->linked_at === null) {
                $locked->updateQuietly(['linked_at' => now()]);
            }
        });
    }

    /** @param  array<string, true>  $suppressed */
    private function link(Email $email, bool $applyMetricsForPrelinkedRecords, array $suppressed): void
    {
        $participants = $email->participants()->with('contact', 'company')->get();
        $workspaceId = $email->workspace_id;
        $connectedAccount = $email->connectedAccount;
        $skippedDomains = $this->domainMatcher->skippedHosts($workspaceId);
        $workspace = $email->workspace;

        // A single email can resolve to the same company/person/opportunity through
        // multiple participants (e.g. two recipients at the same domain). Metrics
        // must be counted once per email, so track which records were already
        // incremented during this run.
        $countedCompanies = [];
        $countedPeople = [];
        $countedOpportunities = [];
        $counted = [];

        foreach ($participants as $participant) {
            // Machine-sent senders (no-reply@, notice@, bounce@) still link to existing
            // records but must never spawn a new Company/Person: there's no real
            // contact behind them.
            $isAutomatedSender = $this->automatedSender->matches($participant->email_address);
            $suppressCreate = $this->visibility->suppressesRecordCreation(
                $participant->email_address,
                $workspaceId,
                $email->connected_account_id,
            );

            $person = $this->personEmailMatcher->firstMatching($participant->email_address, $workspaceId);

            $wouldCreatePerson = ! $person
                && ! $email->is_internal
                && ! $isAutomatedSender
                && ! $suppressCreate
                && $connectedAccount
                && $workspace
                && $this->shouldCreatePerson($workspace, $participant->email_address, $email);

            // 2. Match or create Company by email host so a new person can be born already linked.
            $company = null;
            $rawDomain = $this->extractDomain($participant->email_address);
            $host = $rawDomain !== null ? $this->domainMatcher->host($rawDomain) : null;

            if ($host && $skippedDomains->doesntContain($host)) {
                $company = $this->domainMatcher->firstMatching($host, $workspaceId);

                // 3. Auto-create Company only when a new person would also be created.
                if (! $company && $wouldCreatePerson && $this->shouldCreateCompany($workspace, $participant->email_address, $email)) {
                    $company = $this->autoCreateCompany->execute($host, $workspaceId);
                }

                if ($company instanceof Company) {
                    $participant->update(['company_id' => $company->getKey()]);

                    if ($this->autoAttach($email->companies(), $company->getKey(), $suppressed) && ! isset($countedCompanies[$company->getKey()])) {
                        $countedCompanies[$company->getKey()] = true;
                        $counted[] = $company;
                    }
                }
            }

            // 4. Auto-create Person when no existing record found, passing resolved company_id.
            if ($wouldCreatePerson) {
                $person = $this->autoCreatePerson->execute(
                    $participant->name ?? '',
                    $participant->email_address,
                    $workspaceId,
                    $workspace,
                    $company?->getKey(),
                );
            }

            if ($person instanceof People) {
                $participant->update(['contact_id' => $person->getKey()]);

                if ($this->autoAttach($email->people(), $person->getKey(), $suppressed) && ! isset($countedPeople[$person->getKey()])) {
                    $countedPeople[$person->getKey()] = true;
                    $counted[] = $person;
                }

                $personCompany = $person->company;

                if ($personCompany instanceof Company
                    && $this->autoAttach($email->companies(), $personCompany->getKey(), $suppressed)
                    && ! isset($countedCompanies[$personCompany->getKey()])) {
                    $countedCompanies[$personCompany->getKey()] = true;
                    $counted[] = $personCompany;
                }

                $opportunities = Opportunity::query()->where('workspace_id', $workspaceId)
                    ->where('contact_id', $person->getKey())
                    ->get();

                foreach ($opportunities as $opportunity) {
                    if ($this->autoAttach($email->opportunities(), $opportunity->getKey(), $suppressed) && ! isset($countedOpportunities[$opportunity->getKey()])) {
                        $countedOpportunities[$opportunity->getKey()] = true;
                        $counted[] = $opportunity;
                    }
                }
            }
        }

        if ($applyMetricsForPrelinkedRecords) {
            $counted = [...$counted, ...$this->metrics->prelinkedRecords($email, $countedCompanies, $countedPeople, $countedOpportunities)];
        }

        $this->metrics->incrementEmailMetricsInLockOrder($counted, $email);
    }

    /**
     * Determine whether a new Person should be created for the given email address,
     * based on the workspace contact_creation_mode setting.
     *
     * - All:        always create when the address is unknown
     * - Selective:  create when any workspace mailbox has sent to this address
     * - None:       never create
     */
    private function shouldCreatePerson(Workspace $workspace, string $emailAddress, Email $email): bool
    {
        return match ($workspace->contact_creation_mode) {
            ContactCreationMode::All => true,
            ContactCreationMode::Selective => $email->direction === EmailDirection::OUTBOUND
                || $this->hasWorkspaceOutboundHistory($workspace, $emailAddress),
            ContactCreationMode::None => false,
        };
    }

    /**
     * A company is created only when a person would be created for this address
     * and the workspace company toggle is on. None never creates companies.
     * Selective creates them only for addresses the workspace has emailed (or
     * the outbound message being linked). All creates them for every eligible
     * address.
     */
    private function shouldCreateCompany(Workspace $workspace, string $emailAddress, Email $email): bool
    {
        return $workspace->auto_create_companies && $this->shouldCreatePerson($workspace, $emailAddress, $email);
    }

    /**
     * True when any connected mailbox on this workspace has an outbound email involving
     * the address. Includes mail stored under disconnected accounts so a reply
     * on an active mailbox still creates the person after account churn.
     */
    private function hasWorkspaceOutboundHistory(Workspace $workspace, string $emailAddress): bool
    {
        return Email::query()
            ->withoutGlobalScope(ActiveAccountScope::class)
            ->where('workspace_id', $workspace->getKey())
            ->where('direction', EmailDirection::OUTBOUND)
            ->whereHas(
                'participants',
                fn (Builder $participantQuery) => $participantQuery->where('email_address', $emailAddress),
            )
            ->exists();
    }

    private function extractDomain(string $email): ?string
    {
        $parts = explode('@', $email);

        return count($parts) === 2 ? strtolower($parts[1]) : null;
    }

    /**
     * A suppression on this copy always holds; one on the holder's other copies of the conversation holds until a pin overrides it.
     *
     * @return array<string, true> keyed "type:id"
     */
    private function suppressedPairs(Email $email, bool $onThisCopyOnly): array
    {
        return array_fill_keys(EmailLinkSuppression::query()
            ->when(
                $onThisCopyOnly,
                fn (EloquentBuilder $own): EloquentBuilder => $own->where('email_id', $email->getKey()),
                fn (EloquentBuilder $conversation): EloquentBuilder => $conversation
                    ->heldAlongside($email)
                    ->where(fn (EloquentBuilder $holding): EloquentBuilder => $holding->where('email_id', $email->getKey())->orWhere(fn (EloquentBuilder $elsewhere): EloquentBuilder => $elsewhere->inForce())),
            )
            ->get(['emailable_type', 'emailable_id'])
            ->map(fn (EmailLinkSuppression $suppression): string => "{$suppression->emailable_type}:{$suppression->emailable_id}")
            ->all(), true);
    }

    /** @param  array<string, true>  $suppressed */
    private function detachSuppressed(Email $email, array $suppressed): void
    {
        if ($suppressed === []) {
            return;
        }

        foreach ([$email->companies(), $email->people(), $email->opportunities()] as $relation) {
            foreach ($relation->get() as $record) {
                if (isset($suppressed["{$record->getMorphClass()}:{$record->getKey()}"])) {
                    $relation->detach($record->getKey());
                    dispatch(new RecomputeRecordEmailMetricsJob($record))->afterCommit();
                }
            }
        }
    }

    /**
     * Attaches a record to the email via the given morph relation if not already linked.
     * Returns true only when a new pivot row was created, so metric increments stay
     * one-shot across history re-import of the same message.
     *
     * @template TRelated of Model
     *
     * @param  MorphToMany<TRelated, Email>  $relation
     * @param  array<string, true>  $suppressed
     */
    private function autoAttach(MorphToMany $relation, string $relatedId, array $suppressed): bool
    {
        if (isset($suppressed["{$relation->getRelated()->getMorphClass()}:{$relatedId}"]) || $relation->whereKey($relatedId)->exists()) {
            return false;
        }

        $relation->attach($relatedId, ['link_source' => 'auto']);

        return true;
    }
}
