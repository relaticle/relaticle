<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\Console\Commands;

use App\Enums\CreationSource;
use App\Models\ActivityLog\Activity;
use App\Models\Company;
use App\Models\Opportunity;
use App\Models\People;
use App\Models\Workspace;
use App\Support\CurrentWorkspace;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Relaticle\EmailIntegration\Services\EmailVisibilityService;

#[Description('Delete untouched people and companies the mailbox sync created on a workspace\'s own domains')]
#[Signature('email:remove-own-domain-records {--force : Delete the records instead of reporting them}')]
final class RemoveOwnDomainRecordsCommand extends Command
{
    public function handle(EmailVisibilityService $visibility, CurrentWorkspace $currentWorkspace): int
    {
        $write = (bool) $this->option('force');
        $people = 0;
        $companies = 0;

        $workspaces = Workspace::query()
            ->where(fn (Builder $query): Builder => $query
                ->whereIn('id', People::query()->withoutGlobalScopes()->where('creation_source', CreationSource::MAILBOX)->select('workspace_id'))
                ->orWhereIn('id', Company::query()->withoutGlobalScopes()->where('creation_source', CreationSource::MAILBOX)->select('workspace_id')))
            ->lazyById();

        foreach ($workspaces as $workspace) {
            $ownDomains = $visibility->workspaceDomains($workspace);

            if ($ownDomains === []) {
                continue;
            }

            [$removablePeople, $removableCompanies] = $currentWorkspace->within(
                $workspace,
                fn (): array => $this->removableRecords($visibility, $ownDomains),
            );

            $removablePeople->merge($removableCompanies)->each(function (People|Company $record) use ($write, $currentWorkspace, $workspace): void {
                $this->info("{$record->getMorphClass()} `{$record->getKey()}` in workspace `{$workspace->getKey()}`...");

                if ($write) {
                    $currentWorkspace->within($workspace, fn (): ?bool => $record->delete());
                }
            });

            $people += $removablePeople->count();
            $companies += $removableCompanies->count();
        }

        $this->comment($write
            ? "{$people} person(s) and {$companies} company(ies) on own domains deleted."
            : "{$people} person(s) and {$companies} company(ies) on own domains. Re-run with --force to delete them.");

        return self::SUCCESS;
    }

    /**
     * @param  array<int, string>  $ownDomains
     * @return array{Collection<int, People>, Collection<int, Company>}
     */
    private function removableRecords(EmailVisibilityService $visibility, array $ownDomains): array
    {
        $people = People::query()
            ->where('creation_source', CreationSource::MAILBOX)
            ->get()
            ->filter(fn (People $person): bool => $this->allOnOwnDomains(
                array_map(fn (string $address): string => Str::afterLast(Str::lower($address), '@'), $visibility->recordIdentityAddresses($person)),
                $ownDomains,
            ))
            ->filter(fn (People $person): bool => $this->isUntouched($person)
                && Opportunity::query()->where('contact_id', $person->getKey())->doesntExist())
            ->values();

        $companies = Company::query()
            ->where('creation_source', CreationSource::MAILBOX)
            ->get()
            ->filter(fn (Company $company): bool => $this->allOnOwnDomains($visibility->recordIdentityDomains($company), $ownDomains))
            ->filter(fn (Company $company): bool => $this->isUntouched($company)
                && $company->opportunities()->doesntExist()
                && $company->people()->whereKeyNot($people->modelKeys())->doesntExist())
            ->values();

        return [$people, $companies];
    }

    /**
     * @param  array<int, string>  $domains
     * @param  array<int, string>  $ownDomains
     */
    private function allOnOwnDomains(array $domains, array $ownDomains): bool
    {
        return $domains !== [] && array_diff($domains, $ownDomains) === [];
    }

    private function isUntouched(People|Company $record): bool
    {
        return $record->notes()->doesntExist()
            && $record->tasks()->doesntExist()
            && Activity::query()
                ->forSubject($record)
                ->where(fn (Builder $query): Builder => $query
                    ->whereNull('properties->'.Activity::SOURCE_PROPERTY)
                    ->orWhere('properties->'.Activity::SOURCE_PROPERTY, '!=', CreationSource::MAILBOX->value))
                ->doesntExist();
    }
}
