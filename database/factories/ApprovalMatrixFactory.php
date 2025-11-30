<?php

namespace Database\Factories;

use App\Models\ApprovalMatrix;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class ApprovalMatrixFactory extends Factory
{
    protected $model = ApprovalMatrix::class;

    public function definition(): array
    {
        return [
            'matrix_name' => $this->faker->unique()->company() . ' Approval Matrix',
            'module_name' => $this->faker->randomElement(['store_orders', 'wastages', 'interco_transfers']),
            'entity_type' => $this->faker->randomElement(['regular', 'mass_order', 'interco', 'wastage']),
            'approval_levels' => $this->faker->numberBetween(1, 5),
            'approval_type' => $this->faker->randomElement(['sequential', 'parallel']),
            'basis_column' => $this->faker->randomElement([
                'supplier_id', 'total_amount', 'store_branch_id', 'supplier.supplier_code'
            ]),
            'basis_operator' => $this->faker->randomElement([
                'equals', 'in', 'greater_than', 'between', 'less_than', 'not_equals', 'not_in'
            ]),
            'basis_value' => $this->generateBasisValue(),
            'minimum_amount' => $this->faker->optional(0.7)->randomFloat(2, 100, 10000),
            'maximum_amount' => $this->faker->optional(0.7)->randomFloat(2, 10000, 100000),
            'is_active' => $this->faker->boolean(80), // 80% chance of being active
            'effective_date' => $this->faker->optional(0.8)->dateTimeBetween('-6 months', 'now'),
            'expiry_date' => $this->faker->optional(0.6)->dateTimeBetween('now', '+2 years'),
            'priority' => $this->faker->numberBetween(0, 100),
            'description' => $this->faker->optional(0.7)->sentence(10),
            'created_by' => User::factory(),
            'updated_by' => $this->faker->optional(0.5)->User::factory(),
        ];
    }

    private function generateBasisValue(): array|string
    {
        $operators = [
            'equals' => [$this->faker->numberBetween(1, 100)],
            'in' => $this->faker->randomElements(range(1, 50), $this->faker->numberBetween(1, 5)),
            'greater_than' => [$this->faker->numberBetween(1000, 10000)],
            'less_than' => [$this->faker->numberBetween(5000, 50000)],
            'between' => [
                $this->faker->numberBetween(1000, 5000),
                $this->faker->numberBetween(10000, 20000)
            ],
            'not_equals' => [$this->faker->numberBetween(1, 100)],
            'not_in' => $this->faker->randomElements(range(1, 50), $this->faker->numberBetween(1, 5)),
        ];

        return json_encode($this->faker->randomElement($operators));
    }

    public function pulOSupplier(): static
    {
        return $this->state(fn (array $attributes) => [
            'matrix_name' => 'PUL-O Supplier Orders',
            'module_name' => 'store_orders',
            'entity_type' => 'regular',
            'approval_levels' => 1,
            'basis_column' => 'supplier_id',
            'basis_operator' => 'equals',
            'basis_value' => json_encode([4]),
            'priority' => 10,
        ]);
    }

    public function amountBased(array $amounts = [1000, 5000, 10000]): static
    {
        return $this->state(fn (array $attributes) => [
            'matrix_name' => 'Amount-Based Approval Matrix',
            'basis_column' => 'total_amount',
            'basis_operator' => 'greater_than',
            'basis_value' => json_encode([min($amounts)]),
            'minimum_amount' => min($amounts),
            'maximum_amount' => max($amounts),
            'approval_levels' => count($amounts),
        ]);
    }

    public function branchBased(string $region = 'north'): static
    {
        return $this->state(fn (array $attributes) => [
            'matrix_name' => ucfirst($region) . ' Regional Orders',
            'basis_column' => 'store_branch.region',
            'basis_operator' => 'in',
            'basis_value' => json_encode([$region, 'central']),
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

    public function expired(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_active' => true,
            'effective_date' => now()->subMonths(2),
            'expiry_date' => now()->subDays(1),
        ]);
    }

    public function future(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_active' => true,
            'effective_date' => now()->addDays(1),
            'expiry_date' => now()->addMonths(1),
        ]);
    }

    public function forModule(string $module): static
    {
        return $this->state(fn (array $attributes) => [
            'module_name' => $module,
        ]);
    }

    public function forEntityType(string $entityType): static
    {
        return $this->state(fn (array $attributes) => [
            'entity_type' => $entityType,
        ]);
    }

    public function withLevels(int $levels): static
    {
        return $this->state(fn (array $attributes) => [
            'approval_levels' => $levels,
        ]);
    }

    public function sequential(): static
    {
        return $this->state(fn (array $attributes) => [
            'approval_type' => 'sequential',
        ]);
    }

    public function parallel(): static
    {
        return $this->state(fn (array $attributes) => [
            'approval_type' => 'parallel',
        ]);
    }

    public function highPriority(): static
    {
        return $this->state(fn (array $attributes) => [
            'priority' => $this->faker->numberBetween(80, 100),
        ]);
    }

    public function lowPriority(): static
    {
        return $this->state(fn (array $attributes) => [
            'priority' => $this->faker->numberBetween(0, 20),
        ]);
    }
}