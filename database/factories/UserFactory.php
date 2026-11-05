<?php

namespace Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    /**
     * The current password being used by the factory.
     */
    protected static ?string $password;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'email_verified_at' => now(),
            'password' => static::$password ??= Hash::make('password'),
            'remember_token' => Str::random(10),
        ];
    }

    /**
     * Multi-cuenta: todo el dashboard era TaDa y todos los usuarios
     * existentes quedaron con TaDa (migración usuario_cuenta) -- el usuario
     * de prueba también. Para probar sin acceso: $user->cuentas()->detach().
     */
    public function configure(): static
    {
        return $this->afterCreating(function (User $user) {
            if (Schema::hasTable('usuario_cuenta')) {
                $tada = DB::table('cuentas')->where('slug', 'tada')->value('id');
                $tada && $user->cuentas()->syncWithoutDetaching([$tada]);
            }
        });
    }

    /**
     * Indicate that the model's email address should be unverified.
     */
    public function unverified(): static
    {
        return $this->state(fn (array $attributes) => [
            'email_verified_at' => null,
        ]);
    }
}
