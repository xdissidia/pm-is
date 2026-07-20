<?php

namespace App\Policies;

use App\Models\ProjectTag;
use App\Models\User;

class ProjectTagPolicy
{
    /**
     * Determine whether the user can view any models.
     */
    public function viewAny(User $user): bool
    {
        return $user->hasPermissionTo('view project tags');
    }

    /**
     * Determine whether the user can create models.
     */
    public function create(User $user): bool
    {
        return $user->hasPermissionTo('create project tag');
    }

    /**
     * Determine whether the user can update the model.
     */
    public function update(User $user, ProjectTag $projectTag): bool
    {
        return $user->hasPermissionTo('edit project tag');
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User $user, ProjectTag $projectTag): bool
    {
        return $user->hasPermissionTo('archive project tag');
    }

    /**
     * Determine whether the user can restore the model.
     */
    public function restore(User $user, ProjectTag $projectTag): bool
    {
        return $user->hasPermissionTo('restore project tag');
    }
}
