<?php

namespace App\Policies;

use App\Models\Manuscript;
use App\Models\User;

class ManuscriptPolicy
{
    public function view(User $user, Manuscript $manuscript): bool
    {
        return $user->isEditor() || $manuscript->user_id === $user->id;
    }

    public function updateStatus(User $user, Manuscript $manuscript): bool
    {
        return $user->isEditor();
    }
}
