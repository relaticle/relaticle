<?php

declare(strict_types=1);

namespace App\Actions\Views;

use App\Models\CustomField;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Relaticle\CustomFields\Models\Contracts\HasCustomFields;

final readonly class MoveBoardCard
{
    public function execute(User $user, Model&HasCustomFields $card, string $positionAttribute, string $position, CustomField $columnField, ?string $columnValue): void
    {
        abort_unless($user->can('update', $card), 403);

        $card->update([$positionAttribute => $position]);
        $card->saveCustomFieldValue($columnField, $columnValue);
    }
}
