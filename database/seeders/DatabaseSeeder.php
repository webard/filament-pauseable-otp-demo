<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        if (! User::where('email', 'test@example.com')->exists()) {
            User::factory()->create([
                'name' => 'Test User',
                'email' => 'test@example.com',
            ]);
        }

        // A handful of extra users, so the demo table has rows to act on.
        foreach (range(1, 120) as $i) {
            if (User::where('email', $email = "user{$i}@example.com")->exists()) {
                continue;
            }

            $createdAt = now()->subDays(fake()->numberBetween(0, 365))->subHours(fake()->numberBetween(0, 23));

            User::factory()->create([
                'email' => $email,
                'email_verified_at' => $i % 3 === 0
                    ? null
                    : $createdAt->copy()->addHours(fake()->numberBetween(1, 72)),
                'created_at' => $createdAt,
                'updated_at' => $createdAt,
            ]);
        }
    }
}
