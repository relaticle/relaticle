<?php

declare(strict_types=1);

namespace App\Policies\Blog;

use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;
use ManukMinasyan\FilamentBlog\Models\Post;

final readonly class PostPolicy
{
    use HandlesAuthorization;

    public function viewAny(User $user): bool
    {
        return $user->hasVerifiedEmail();
    }

    public function view(User $user, Post $post): bool
    {
        return $user->hasVerifiedEmail();
    }

    public function create(User $user): bool
    {
        return $user->hasVerifiedEmail();
    }

    public function update(User $user, Post $post): bool
    {
        return $user->hasVerifiedEmail();
    }

    public function delete(User $user, Post $post): bool
    {
        return $user->hasVerifiedEmail();
    }

    public function deleteAny(User $user): bool
    {
        return $user->hasVerifiedEmail();
    }

    public function restore(User $user, Post $post): bool
    {
        return $user->hasVerifiedEmail();
    }

    public function restoreAny(User $user): bool
    {
        return $user->hasVerifiedEmail();
    }

    public function forceDelete(User $user, Post $post): bool
    {
        return $user->hasVerifiedEmail();
    }

    public function forceDeleteAny(User $user): bool
    {
        return $user->hasVerifiedEmail();
    }
}
