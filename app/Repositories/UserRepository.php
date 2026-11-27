<?php

namespace App\Repositories;

use App\Models\User;

/**
 * Persistence access for users.
 *
 * Keeps queries out of services so business rules can be tested against a
 * repository double.
 */
final class UserRepository
{
    /**
     * Find an active (not soft deleted) user by exact email address.
     */
    public function findByEmail(string $email): ?User
    {
        return User::query()->where('email', $email)->first();
    }

    /**
     * Find an active (not soft deleted) user by primary key.
     */
    public function findById(string $id): ?User
    {
        return User::query()->find($id);
    }
}
