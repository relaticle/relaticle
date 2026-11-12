<?php

declare(strict_types=1);

namespace App\Actions\Views;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;

final readonly class PlaceBoardCard
{
    public function execute(User $user, Model $card, float $position): void
    {
        abort_unless($user->can('update', $card), 403);

        $card->setAttribute('order_column', $position);
        $card->saveQuietly();
    }
}
