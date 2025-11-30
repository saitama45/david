<?php

namespace Database\Factories;

use App\Models\ApprovalWorkflowStep;
use App\Models\EntityApprovalWorkflow;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class ApprovalWorkflowStepFactory extends Factory
{
    protected $model = ApprovalWorkflowStep::class;

    public function definition(): array
    {
        return [
            'entity_approval_workflow_id' => EntityApprovalWorkflow::factory(),
            'approval_level' => $this->faker->numberBetween(1, 5),
            'approver_user_id' => $this->faker->numberBetween(1, 100),
            'delegated_to_user_id' => $this->faker->optional(0.3)->numberBetween(1, 100),
            'action' => $this->faker->randomElement(['pending', 'approved', 'rejected', 'delegated', 'skipped']),
            'action_reason' => $this->faker->optional(0.7)->sentence(10),
            'action_data' => $this->generateActionDataJson(),
            'assigned_at' => $this->faker->dateTimeBetween('-30 days', 'now'),
            'action_taken_at' => $this->faker->optional(0.6)->dateTimeBetween('-30 days', 'now'),
            'deadline_at' => $this->faker->optional(0.8)->dateTimeBetween('now', '+7 days'),
            'is_active' => $this->faker->boolean(80),
        ];
    }

    private function generateActionDataJson(): ?string
    {
        if ($this->faker->boolean(60)) {
            return null;
        }

        $data = $this->faker->randomElements([
            'notes' => $this->faker->sentence(5),
            'urgency' => $this->faker->randomElement(['low', 'medium', 'high']),
            'budget_check' => $this->faker->boolean(),
            'additional_documents' => $this->faker->words(3),
            'special_requirements' => $this->faker->sentence(3),
            'amount_verified' => $this->faker->boolean(90),
            'verification_method' => $this->faker->randomElement(['manual', 'automated', 'hybrid']),
            'approval_comments' => $this->faker->optional()->sentence(8),
            'next_steps' => $this->faker->optional()->randomElements(['notify_manager', 'update_inventory', 'process_payment'], 2),
        ], $this->faker->numberBetween(1, 4));

        return json_encode(array_combine(array_keys($data), array_values($data)));
    }

    public function forWorkflow(EntityApprovalWorkflow $workflow): static
    {
        return $this->state(fn (array $attributes) => [
            'entity_approval_workflow_id' => $workflow->id,
        ]);
    }

    public function forLevel(int $level): static
    {
        return $this->state(fn (array $attributes) => [
            'approval_level' => $level,
        ]);
    }

    public function forApprover(User $approver): static
    {
        return $this->state(fn (array $attributes) => [
            'approver_user_id' => $approver->id,
        ]);
    }

    public function delegatedTo(User $delegate): static
    {
        return $this->state(fn (array $attributes) => [
            'delegated_to_user_id' => $delegate->id,
        ]);
    }

    public function pending(): static
    {
        return $this->state(fn (array $attributes) => [
            'action' => 'pending',
            'action_taken_at' => null,
            'deadline_at' => $this->faker->dateTimeBetween('now', '+7 days'),
        ]);
    }

    public function approved(string $reason = null): static
    {
        return $this->state(fn (array $attributes) => [
            'action' => 'approved',
            'action_reason' => $reason ?? $this->faker->sentence(10),
            'action_taken_at' => $this->faker->dateTimeBetween('-2 days', 'now'),
        ]);
    }

    public function rejected(string $reason = null): static
    {
        return $this->state(fn (array $attributes) => [
            'action' => 'rejected',
            'action_reason' => $reason ?? $this->faker->sentence(10),
            'action_taken_at' => $this->faker->dateTimeBetween('-2 days', 'now'),
        ]);
    }

    public function delegated(string $reason = null): static
    {
        return $this->state(fn (array $attributes) => [
            'action' => 'delegated',
            'action_reason' => $reason ?? $this->faker->sentence(10),
            'action_taken_at' => $this->faker->dateTimeBetween('-2 days', 'now'),
            'delegated_to_user_id' => $this->faker->numberBetween(1, 100),
        ]);
    }

    public function skipped(string $reason = null): static
    {
        return $this->state(fn (array $attributes) => [
            'action' => 'skipped',
            'action_reason' => $reason ?? $this->faker->sentence(10),
            'action_taken_at' => $this->faker->dateTimeBetween('-2 days', 'now'),
        ]);
    }

    public function active(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_active' => true,
        ]);
    }

    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_active' => false,
        ]);
    }

    public function withDeadline(int $hoursFromNow): static
    {
        return $this->state(fn (array $attributes) => [
            'deadline_at' => now()->addHours($hoursFromNow),
        ]);
    }

    public function overdue(): static
    {
        return $this->state(fn (array $attributes) => [
            'deadline_at' => $this->faker->dateTimeBetween('-30 days', '-1 hour'),
            'action' => 'pending',
        ]);
    }

    public function urgent(): static
    {
        return $this->state(fn (array $attributes) => [
            'deadline_at' => now()->addHours($this->faker->numberBetween(1, 4)),
            'action_data' => json_encode(['urgency' => 'high']),
        ]);
    }

    public function standard(): static
    {
        return $this->state(fn (array $attributes) => [
            'deadline_at' => now()->addHours($this->faker->numberBetween(24, 72)),
            'action_data' => json_encode(['urgency' => 'medium']),
        ]);
    }

    public function routine(): static
    {
        return $this->state(fn (array $attributes) => [
            'deadline_at' => now()->addHours($this->faker->numberBetween(72, 168)),
            'action_data' => json_encode(['urgency' => 'low']),
        ]);
    }

    public function withNotes(string $notes): static
    {
        return $this->state(fn (array $attributes) => [
            'action_data' => json_encode(['notes' => $notes]),
        ]);
    }

    public function withBudgetCheck(bool $verified = true): static
    {
        return $this->state(fn (array $attributes) => [
            'action_data' => json_encode(['budget_check' => $verified]),
        ]);
    }

    public function withSpecialRequirements(array $requirements): static
    {
        return $this->state(fn (array $attributes) => [
            'action_data' => json_encode(['special_requirements' => $requirements]),
        ]);
    }

    public function recentlyAssigned(): static
    {
        return $this->state(fn (array $attributes) => [
            'assigned_at' => $this->faker->dateTimeBetween('-24 hours', 'now'),
        ]);
    }

    public function longPending(): static
    {
        return $this->state(fn (array $attributes) => [
            'assigned_at' => $this->faker->dateTimeBetween('-7 days', '-3 days'),
            'action' => 'pending',
            'deadline_at' => $this->faker->dateTimeBetween('-1 day', '-1 hour'),
        ]);
    }

    public function processed(): static
    {
        return $this->state(fn (array $attributes) => [
            'action' => $this->faker->randomElement(['approved', 'rejected', 'delegated']),
            'action_taken_at' => $this->faker->dateTimeBetween('-5 days', '-1 hour'),
        ]);
    }
}