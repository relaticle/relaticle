<?php

declare(strict_types=1);

namespace Relaticle\Chat\Services;

use App\Enums\WorkspaceCapability;
use App\Models\User;
use App\Models\Workspace;
use App\Queries\Onboarding\ArrivedRecordsQuery;
use App\Services\WorkspaceActivationFacts;
use Relaticle\Chat\Data\SetupOption;
use Relaticle\Chat\Support\DestinationResolver;

final readonly class SetupOptions
{
    public function __construct(
        private ArrivedRecordsQuery $arrived,
        private DestinationResolver $destinations,
        private WorkspaceActivationFacts $facts,
    ) {}

    /**
     * @return list<SetupOption>
     */
    public function for(User $user, Workspace $workspace): array
    {
        if ($this->companiesArrivedToday($user, $workspace)) {
            return [
                $this->prompt('pick_first', 'lucide-flag'),
                $this->prompt('later', 'lucide-clock'),
            ];
        }

        if ($this->facts->hasConnectedMailbox($user, $workspace)) {
            return [
                $this->prompt('show_synced', 'lucide-mail'),
                $this->prompt('add_outside_email', 'lucide-user-plus'),
            ];
        }

        return array_values(array_filter([
            $this->link($user, $workspace, 'upload_file', 'import_people', 'lucide-upload'),
            $this->prompt('add_client', 'lucide-building-2'),
            $this->prompt('connect_assistant', 'lucide-plug'),
        ]));
    }

    private function companiesArrivedToday(User $user, Workspace $workspace): bool
    {
        return array_any(
            $this->arrived->today($user, $workspace),
            fn (array $counts): bool => $counts['companies'] > 0,
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
