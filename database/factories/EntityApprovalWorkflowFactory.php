<?php

namespace Database\Factories;

use App\Models\EntityApprovalWorkflow;
use App\Models\ApprovalMatrix;
use Illuminate\Database\Eloquent\Factories\Factory;

class EntityApprovalWorkflowFactory extends Factory
{
    protected $model = EntityApprovalWorkflow::class;

    public function definition(): array
    {
        return [
            'entity_type' => $this->faker->randomElement(['store_order', 'wastage', 'interco_transfer']),
            'entity_id' => $this->faker->numberBetween(1, 1000),
            'initiated_by_type' => 'App\\Models\\User',
            'initiated_by_id' => $this->faker->numberBetween(1, 100),
            'approval_matrix_id' => ApprovalMatrix::factory(),
            'total_approval_required' => $this->faker->numberBetween(1, 5),
            'current_approval_level' => $this->faker->numberBetween(0, 5),
            'current_status' => $this->faker->randomElement(['pending', 'approved', 'rejected', 'cancelled', 'escalated']),
            'approval_workflow' => $this->generateWorkflowJson(),
            'initiated_at' => $this->faker->dateTimeBetween('-30 days', 'now'),
            'completed_at' => $this->faker->optional(0.6)->dateTimeBetween('-30 days', 'now'),
            'escalated_at' => $this->faker->optional(0.2)->dateTimeBetween('-30 days', 'now'),
            'is_active' => $this->faker->boolean(80),
            'rejection_reason' => $this->faker->optional(0.4)->sentence(10),
            'escalation_reason' => $this->faker->optional(0.2)->sentence(10),
        ];
    }

    private function generateWorkflowJson(): string
    {
        $workflow = [];
        $levels = $this->faker->numberBetween(1, 3);

        for ($i = 1; $i <= $levels; $i++) {
            $workflow[] = [
                'level' => $i,
                'status' => $this->faker->randomElement(['pending', 'approved', 'rejected']),
                'approver_id' => $this->faker->numberBetween(1, 100),
                'action_date' => $this->faker->optional()->dateTime->format('Y-m-d H:i:s'),
                'comments' => $this->faker->optional()->sentence(5),
            ];
        }

        return json_encode($workflow);
    }

    public function forEntityType(string $entityType): static
    {
        return $this->state(fn (array $attributes) => [
            'entity_type' => $entityType,
        ]);
    }

    public function forEntity(int $entityId, string $entityType = 'store_order'): static
    {
        return $this->state(fn (array $attributes) => [
            'entity_type' => $entityType,
            'entity_id' => $entityId,
        ]);
    }

    public function pending(): static
    {
        return $this->state(fn (array $attributes) => [
            'current_status' => 'pending',
            'current_approval_level' => $this->faker->numberBetween(0, 4),
            'completed_at' => null,
            'escalated_at' => null,
            'rejection_reason' => null,
            'escalation_reason' => null,
        ]);
    }

    public function approved(): static
    {
        return $this->state(fn (array $attributes) => [
            'current_status' => 'approved',
            'current_approval_level' => $attributes['total_approval_required'] ?? $this->faker->numberBetween(1, 5),
            'completed_at' => $this->faker->dateTimeBetween('-7 days', 'now'),
        ]);
    }

    public function rejected(string $reason = null): static
    {
        return $this->state(fn (array $attributes) => [
            'current_status' => 'rejected',
            'completed_at' => $this->faker->dateTimeBetween('-7 days', 'now'),
            'rejection_reason' => $reason ?? $this->faker->sentence(10),
        ]);
    }

    public function cancelled(string $reason = null): static
    {
        return $this->state(fn (array $attributes) => [
            'current_status' => 'cancelled',
            'completed_at' => $this->faker->dateTimeBetween('-7 days', 'now'),
            'cancellation_reason' => $reason ?? $this->faker->sentence(10),
        ]);
    }

    public function escalated(string $reason = null): static
    {
        return $this->state(fn (array $attributes) => [
            'current_status' => 'escalated',
            'escalated_at' => $this->faker->dateTimeBetween('-7 days', 'now'),
            'escalation_reason' => $reason ?? $this->faker->sentence(10),
        ]);
    }

    public function withLevels(int $levels): static
    {
        return $this->state(fn (array $attributes) => [
            'total_approval_required' => $levels,
        ]);
    }

    public function atLevel(int $level): static
    {
        return $this->state(fn (array $attributes) => [
            'current_approval_level' => $level,
        ]);
    }

    public function withMatrix(ApprovalMatrix $matrix): static
    {
        return $this->state(fn (array $attributes) => [
            'approval_matrix_id' => $matrix->id,
            'total_approval_required' => $matrix->approval_levels,
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

    public function recent(): static
    {
        return $this->state(fn (array $attributes) => [
            'initiated_at' => $this->faker->dateTimeBetween('-7 days', 'now'),
        ]);
    }

    public function overdue(): static
    {
        return $this->state(fn (array $attributes) => [
            'initiated_at' => $this->faker->dateTimeBetween('-30 days', '-10 days'),
            'current_status' => 'pending',
        ]);
    }

    public function highPriority(): static
    {
        return $this->state(fn (array $attributes) => [
            'initiated_at' => $this->faker->dateTimeBetween('-24 hours', 'now'),
            'total_approval_required' => 1,
        ]);
    }

    public function complex(): static
    {
        return $this->state(fn (array $attributes) => [
            'total_approval_required' => $this->faker->numberBetween(3, 5),
            'approval_workflow' => $this->generateComplexWorkflowJson(),
        ]);
    }

    private function generateComplexWorkflowJson(): string
    {
        $workflow = [];
        $levels = $this->faker->numberBetween(3, 5);

        for ($i = 1; $i <= $levels; $i++) {
            $workflow[] = [
                'level' => $i,
                'status' => $this->faker->randomElement(['pending', 'approved', 'rejected', 'delegated']),
                'approver_id' => $this->faker->numberBetween(1, 100),
                'delegated_to_id' => $this->faker->optional(0.3)->numberBetween(1, 100),
                'action_date' => $this->faker->optional()->dateTime->format('Y-m-d H:i:s'),
                'comments' => $this->faker->optional()->sentence(5),
                'additional_data' => $this->faker->optional()->randomElement([
                    ['urgency' => 'high', 'notes' => ' expedite processing'],
                    ['special_requirements' => 'manager review needed'],
                    ['budget_check' => true, 'amount' => $this->faker->numberBetween(1000, 50000)],
                ]),
            ];
        }

        return json_encode($workflow);
    }
}