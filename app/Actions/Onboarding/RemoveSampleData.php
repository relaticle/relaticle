<?php

declare(strict_types=1);

namespace App\Actions\Onboarding;

use App\Enums\CreationSource;
use App\Models\Company;
use App\Models\Note;
use App\Models\Opportunity;
use App\Models\People;
use App\Models\Task;
use App\Models\User;
use App\Models\Workspace;
use App\Services\WorkspaceActivationFacts;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

final readonly class RemoveSampleData
{
    /**
     * Tasks and notes go first: they hang off the records deleted after them.
     *
     * @var list<class-string<Model>>
     */
    private const array MODELS = [Task::class, Note::class, Opportunity::class, People::class, Company::class];

    public function __construct(private WorkspaceActivationFacts $facts) {}

    public function execute(User $user, Workspace $workspace): int
    {
        abort_unless($user->ownsWorkspace($workspace), 403);
        abort_unless($this->facts->hasOwnRecord($workspace), 422);

        $removed = DB::transaction(function () use ($workspace): int {
            $removed = 0;

            foreach (self::MODELS as $model) {
                $records = $model::query()
                    ->where('workspace_id', $workspace->getKey())
                    ->where('creation_source', CreationSource::SYSTEM)
                    ->get();

                $records->each(function (Model $record) use (&$removed): void {
                    $record->delete();
                    $removed++;
                });
            }

            return $removed;
        });

        $this->facts->forget($workspace);

        return $removed;
    }
}
