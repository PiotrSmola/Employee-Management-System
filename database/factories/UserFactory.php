<?php

namespace Database\Factories;

use App\Enums\Role;
use App\Models\Department;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Application accounts (password: "password"), viewer by default:
 *
 *   User::factory()->admin()->create();
 *   User::factory()->manager($department)->create();   // or ->manager('d005')
 *   User::factory()->viewer()->inactive()->create();
 *
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    protected $model = User::class;

    /**
     * The current password being used by the factory.
     */
    protected static ?string $password;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'password' => static::$password ??= Hash::make('password'),
            'role' => Role::Viewer,
            'dept_no' => null,
            'is_active' => true,
            'remember_token' => Str::random(10),
        ];
    }

    public function role(Role $role): static
    {
        return $this->state(fn () => ['role' => $role, 'dept_no' => null]);
    }

    public function admin(): static
    {
        return $this->role(Role::Admin);
    }

    public function hr(): static
    {
        return $this->role(Role::Hr);
    }

    public function viewer(): static
    {
        return $this->role(Role::Viewer);
    }

    /**
     * Manager of $department (a Department or a dept_no; created when omitted).
     */
    public function manager(Department|string|null $department = null): static
    {
        return $this->state(fn () => [
            'role' => Role::Manager,
            'dept_no' => match (true) {
                $department instanceof Department => $department->dept_no,
                is_string($department) => $department,
                default => Department::factory()->create()->dept_no,
            },
        ]);
    }

    public function inactive(): static
    {
        return $this->state(fn () => ['is_active' => false]);
    }
}
