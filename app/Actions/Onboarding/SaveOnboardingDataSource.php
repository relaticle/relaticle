<?php

declare(strict_types=1);

namespace App\Actions\Onboarding;

use App\Enums\OnboardingDataSource;
use App\Enums\WorkspaceSetupStep;
use App\Models\User;
use App\Models\Workspace;
use App\Onboarding\MailboxStep;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

final readonly class SaveOnboardingDataSource
{
    public function __construct(private MoveWorkspaceSetup $moveSetup) {}

    /**
     * @param  array<string, mixed>  $input
     */
    public function execute(User $user, Workspace $workspace, array $input): bool
    {
        abort_unless($workspace->user_id === $user->getKey(), 403);

        $validated = Validator::make($input, [
            'onboarding_data_source' => ['required', 'string', Rule::in(array_column(OnboardingDataSource::offered(), 'value'))],
            'onboarding_data_source_detail' => ['nullable', 'string', 'max:120'],
        ])->validate();

        $source = OnboardingDataSource::from($validated['onboarding_data_source']);
        $detail = trim((string) ($validated['onboarding_data_source_detail'] ?? ''));

        return DB::transaction(function () use ($user, $workspace, $source, $detail): bool {
            if (! $this->moveSetup->execute($user, $workspace, WorkspaceSetupStep::Source, $this->nextStep($source))) {
                return false;
            }

            $workspace->update([
                'onboarding_data_source' => $source,
                'onboarding_data_source_detail' => $source === OnboardingDataSource::OtherCrm && $detail !== '' ? $detail : null,
            ]);

            return true;
        });
    }

    private function nextStep(OnboardingDataSource $source): WorkspaceSetupStep
    {
        return $source === OnboardingDataSource::Inbox && MailboxStep::canRun()
            ? WorkspaceSetupStep::Email
            : WorkspaceSetupStep::UseCase;
    }
}
