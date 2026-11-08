<?php

declare(strict_types=1);

namespace Relaticle\Chat\Services;

use App\Enums\CreationSource;
use App\Enums\OnboardingDataSource;
use App\Enums\WorkspaceCapability;
use App\Models\User;
use App\Models\Workspace;
use App\Queries\Onboarding\ArrivedRecordsQuery;
use Relaticle\Chat\Data\SetupOption;
use Relaticle\Chat\Support\DestinationResolver;

final readonly class SetupOptions
{
    public function __construct(
        private ArrivedRecordsQuery $arrived,
        private DestinationResolver $destinations,
    ) {}

    /**
     * @return list<SetupOption>
     */
    public function for(User $user, Workspace $workspace): array
    {
        $answer = $workspace->onboarding_data_source;

        if (! $answer instanceof OnboardingDataSource) {
            return $this->answers();
        }

        $source = $workspace->effectiveDataSource();

        if ($this->companiesArrivedToday($user, $workspace, $source)) {
            return [
                $this->prompt('pick_first', 'lucide-flag'),
                $this->prompt('later', 'lucide-clock'),
            ];
        }

        $options = match ($source) {
            OnboardingDataSource::Memory => $answer === OnboardingDataSource::Inbox ? [
                $this->link($user, $workspace, 'connect_mailbox', 'email_accounts', 'lucide-mail'),
                $this->prompt('add_client', 'lucide-building-2'),
            ] : [
                $this->prompt('add_client', 'lucide-building-2'),
                $this->prompt('add_deal', 'lucide-briefcase'),
            ],
            OnboardingDataSource::Inbox => [
                $this->prompt('show_synced', 'lucide-mail'),
                $this->prompt('add_outside_email', 'lucide-user-plus'),
            ],
            OnboardingDataSource::Assistant => [
                $this->prompt('copy_prompt', 'lucide-clipboard'),
                $this->link($user, $workspace, 'connect_steps', 'connect_assistant', 'lucide-external-link'),
            ],
            OnboardingDataSource::Spreadsheet, OnboardingDataSource::OtherCrm => [
                $this->link($user, $workspace, 'upload_file', 'import_people', 'lucide-upload'),
            ],
        };

        return array_values(array_filter($options));
    }

    /**
     * @return list<SetupOption>
     */
    private function answers(): array
    {
        return array_map(
            fn (OnboardingDataSource $source): SetupOption => new SetupOption(
                id: $source->buttonId(),
                kind: SetupOption::KIND_PROMPT,
                label: $source->getLabel(),
                icon: $this->answerIcon($source),
                prompt: $source->getLabel(),
            ),
            OnboardingDataSource::offered(),
        );
    }

    private function answerIcon(OnboardingDataSource $source): string
    {
        return match ($source) {
            OnboardingDataSource::Spreadsheet => 'lucide-file-spreadsheet',
            OnboardingDataSource::OtherCrm => 'lucide-database',
            OnboardingDataSource::Inbox => 'lucide-mail',
            OnboardingDataSource::Memory => 'lucide-brain',
            OnboardingDataSource::Assistant => 'lucide-sparkles',
        };
    }

    private function companiesArrivedToday(User $user, Workspace $workspace, OnboardingDataSource $source): bool
    {
        $arrived = $this->arrived->today($user, $workspace);

        return array_any(
            $source->arrivalSources(),
            fn (CreationSource $creationSource): bool => ($arrived[$creationSource->value]['companies'] ?? 0) > 0,
        );
    }

    private function prompt(string $id, string $icon): SetupOption
    {
        return new SetupOption(
            id: $id,
            kind: SetupOption::KIND_PROMPT,
            label: __("onboarding/setup.buttons.{$id}.label"),
            icon: $icon,
            prompt: __("onboarding/setup.buttons.{$id}.prompt"),
        );
    }

    private function link(User $user, Workspace $workspace, string $id, string $destination, string $icon): ?SetupOption
    {
        $capability = $this->destinations->requiredCapability($destination);

        if ($capability instanceof WorkspaceCapability && ! $user->hasWorkspaceCapability($workspace->getKey(), $capability)) {
            return null;
        }

        $url = $this->destinations->resolve($destination, $workspace);

        if ($url === null) {
            return null;
        }

        return new SetupOption(
            id: $id,
            kind: SetupOption::KIND_LINK,
            label: __("onboarding/setup.buttons.{$id}.label"),
            icon: $icon,
            url: $url,
        );
    }
}
