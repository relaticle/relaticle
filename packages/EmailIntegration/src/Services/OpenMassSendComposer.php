<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\Services;

use App\Models\Company;
use App\Models\People;
use Filament\Notifications\Notification;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Livewire\Component;
use Relaticle\EmailIntegration\Support\PersonRecipientFormatter;

final readonly class OpenMassSendComposer
{
    public function __construct(
        private MassSendRecipientResolver $resolver,
    ) {}

    /**
     * @param  Collection<int, Model>  $records
     */
    public function execute(Component $page, Collection $records, bool $forCompanies): void
    {
        $result = $forCompanies
            ? $this->resolver->resolveFromCompanies($this->companyRecords($records))
            : $this->resolver->resolveFromPeople($this->peopleRecords($records));

        if ($result->recipients === []) {
            Notification::make()
                ->title(__('filament/actions/mass-send.notifications.no_recipients.title'))
                ->body(__('filament/actions/mass-send.notifications.no_recipients.body'))
                ->warning()
                ->send();

            return;
        }

        if ($result->skipped > 0) {
            Notification::make()
                ->title(__('filament/actions/mass-send.notifications.skipped.title'))
                ->body(__('filament/actions/mass-send.notifications.skipped.body', [
                    'skipped' => $result->skipped,
                ]))
                ->warning()
                ->send();
        }

        $linkRecordType = null;
        $linkRecordId = null;

        if ($forCompanies && $records->count() === 1) {
            $company = $records->first();

            if ($company instanceof Company) {
                $linkRecordType = Company::class;
                $linkRecordId = (string) $company->getKey();
            }
        }

        /** @var list<array{personId: string, email: string, name: string}> $recipientPayload */
        $recipientPayload = [];

        foreach ($result->recipients as $recipient) {
            $recipientPayload[] = [
                'personId' => (string) $recipient['person']->getKey(),
                'email' => $recipient['email'],
                'name' => PersonRecipientFormatter::displayName($recipient['person'], $recipient['email']),
            ];
        }

        $page->dispatch('composer:open', payload: [
            'massSend' => true,
            'recipients' => $recipientPayload,
            'linkRecordType' => $linkRecordType,
            'linkRecordId' => $linkRecordId,
        ]);
    }

    /**
     * @param  Collection<int, Model>  $records
     * @return Collection<int, Company>
     */
    private function companyRecords(Collection $records): Collection
    {
        /** @var Collection<int, Company> $companies */
        $companies = $records
            ->filter(fn (Model $record): bool => $record instanceof Company)
            ->values();

        return $companies;
    }

    /**
     * @param  Collection<int, Model>  $records
     * @return Collection<int, People>
     */
    private function peopleRecords(Collection $records): Collection
    {
        /** @var Collection<int, People> $people */
        $people = $records
            ->filter(fn (Model $record): bool => $record instanceof People)
            ->values();

        return $people;
    }
}
