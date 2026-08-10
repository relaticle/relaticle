<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\Actions;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Relaticle\EmailIntegration\Enums\EmailStatus;
use Relaticle\EmailIntegration\Models\Email;

final readonly class DeleteEmailDraftAction
{
    public function execute(User $user, string $draftId): void
    {
        $draft = Email::query()
            ->where('user_id', $user->getKey())
            ->where('status', EmailStatus::DRAFT)
            ->whereKey($draftId)
            ->first();

        abort_if($draft === null, 403);

        DB::transaction(function () use ($draft): void {
            $draft->body()->delete();
            $draft->participants()->delete();
            $draft->delete();
        });
    }
}
