<?php

namespace App\Policies;

use App\Models\TaskPriority;
use App\Models\User;

class TaskPriorityPolicy
{
    /**
     * Determine whether the user can view any models.
     */
    public function viewAny(User $user): bool
    {
        return $user->hasPermissionTo('view task priority');
    }

    /**
     * Determine whether the user can create models.
     */
    public function create(User $user): bool
    {
        return $user->hasPermissionTo('create task priority');
    }

    /**
     * Determine whether the user can update the model.
     */
    public function update(User $user, TaskPriority $taskPriority): bool
    {
        return $user->hasPermissionTo('edit task priority');
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User $user, TaskPriority $taskPriority): bool
    {
        return $user->hasPermissionTo('delete task priority');
    }

    /**
     * Determine whether the user can restore the model.
     */
    public function restore(User $user, TaskPriority $taskPriority): bool
    {
        return $user->hasPermissionTo('restore task priority');
    }
}
