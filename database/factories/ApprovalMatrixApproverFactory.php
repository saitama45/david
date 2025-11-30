<?php

namespace Database\Factories;

use App\Models\ApprovalMatrix;
use App\Models\ApprovalMatrixApprover;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class ApprovalMatrixApproverFactory extends Factory
{
    protected $model = ApprovalMatrixApprover::class;

    public function definition(): array
    {
        return [
            'approval_matrix_id' => ApprovalMatrix::factory(),
            'user_id' => User::factory(),
            'approval_level' => $this->faker->numberBetween(1, 5),
            'is_primary' => $this->faker->boolean(30), // 30% chance of being primary
            'is_backup' => $this->faker->boolean(20), // 20% chance of being backup
            'can_delegate' => $this->faker->boolean(80), // 80% chance of being able to delegate
            'approval_limit_amount' => $this->faker->optional(0.6)->randomFloat(2, 1000, 100000),
            'approval_limit_percentage' => $this->faker->optional(0.6)->randomFloat(2, 10, 100),
            'approval_deadline_hours' => $this->faker->numberBetween(1, 168), // 1 hour to 1 week
            'business_hours_only' => $this->faker->boolean(70), // 70% chance of business hours only
            'is_active' => $this->faker->boolean(90), // 90% chance of being active
            'effective_date' => $this->faker->optional(0.8)->dateTimeBetween('-6 months', 'now'),
            'expiry_date' => $this->faker->optional(0.6)->dateTimeBetween('now', '+2 years'),
        ];
    }

    public function primary(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_primary' => true,
            'is_backup' => false,
        ]);
    }

    public function backup(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_primary' => false,
            'is_backup' => true,
        ]);
    }

    public function forLevel(int $level): static
    {
        return $this->state(fn (array $attributes) => [
            'approval_level' => $level,
        ]);
    }

    public function withDeadline(int $hours): static
    {
        return $this->state(fn (array $attributes) => [
            'approval_deadline_hours' => $hours,
        ]);
    }

    public function withLimitAmount(float $amount): static
    {
        return $this->state(fn (array $attributes) => [
            'approval_limit_amount' => $amount,
        ]);
    }

    public function withLimitPercentage(float $percentage): static
    {
        return $this->state(fn (array $attributes) => [
            'approval_limit_percentage' => $percentage,
        ]);
    }

    public function canDelegate(bool $canDelegate = true): static
    {
        return $this->state(fn (array $attributes) => [
            'can_delegate' => $canDelegate,
        ]);
    }

    public function businessHoursOnly(bool $businessHoursOnly = true): static
    {
        return $this->state(fn (array $attributes) => [
            'business_hours_only' => $businessHoursOnly,
        ]);
    }

    public function active(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_active' => true,
            'effective_date' => now()->subDays(1),
            'expiry_date' => now()->addYear(),
        ]);
    }

    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_active' => false,
        ]);
    }

    public function forMatrix(ApprovalMatrix $matrix): static
    {
        return $this->state(fn (array $attributes) => [
            'approval_matrix_id' => $matrix->id,
        ]);
    }

    public function forUser(User $user): static
    {
        return $this->state(fn (array $attributes) => [
            'user_id' => $user->id,
        ]);
    }

    public function quickApproval(): static
    {
        return $this->state(fn (array $attributes) => [
            'approval_deadline_hours' => $this->faker->numberBetween(1, 4),
            'business_hours_only' => false,
        ]);
    }

    public function standardApproval(): static
    {
        return $this->state(fn (array $attributes) => [
            'approval_deadline_hours' => $this->faker->numberBetween(24, 72),
            'business_hours_only' => true,
        ]);
    }

    public function extendedApproval(): static
    {
        return $this->state(fn (array $attributes) => [
            'approval_deadline_hours' => $this->faker->numberBetween(120, 168),
            'business_hours_only' => true,
        ]);
    }
}