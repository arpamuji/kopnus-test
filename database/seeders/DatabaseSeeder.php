<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Number of demo users seeded.
     */
    private const int USER_COUNT = 10;

    /**
     * Starting balance for each seeded account, in whole rupiah.
     */
    private const int INITIAL_BALANCE = 1_000_000;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        User::factory()
            ->count(self::USER_COUNT)
            ->create()
            ->each(function (User $user): void {
                $user->account()->create(['balance' => self::INITIAL_BALANCE]);
            });
    }
}
