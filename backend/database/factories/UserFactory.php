<?php

namespace Database\Factories;

use App\Modules\Identity\Domain\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    protected $model = User::class;

    protected static ?string $password;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake('id_ID')->name(),
            'email' => fake()->unique()->safeEmail(),
            'phone' => '62812'.fake()->unique()->numerify('#######'),
            'password' => static::$password ??= 'Rahasia123',
        ];
    }

    public function platformAdmin(): static
    {
        return $this->afterMaking(fn (User $user) => $user->forceFill(['is_platform_admin' => true]));
    }

    public function withRandomPassword(): static
    {
        return $this->state(fn () => ['password' => Str::password(16)]);
    }
}
