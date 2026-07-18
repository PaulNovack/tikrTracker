<?php

namespace App\Policies;

use App\Models\User;

class AssetInfoPolicy
{
    /**
     * Determine whether the user can create assets.
     */
    public function create(User $user): bool
    {
        return $user->isAdmin();
    }
}
