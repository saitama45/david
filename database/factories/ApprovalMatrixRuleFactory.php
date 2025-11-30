<?php

namespace Database\Factories;

use App\Models\ApprovalMatrix;
use App\Models\ApprovalMatrixRule;
use Illuminate\Database\Eloquent\Factories\Factory;

class ApprovalMatrixRuleFactory extends Factory
{
    protected $model = ApprovalMatrixRule::class;

    public function definition(): array
    {
        return [
            'approval_matrix_id' => ApprovalMatrix::factory(),
            'condition_group' => $this->faker->numberBetween(1, 5),
            'condition_logic' => $this->faker->randomElement(['AND', 'OR']),
            'condition_column' => $this->faker->randomElement([
                'total_amount',
                'supplier_id',
                'supplier.supplier_code',
                'store_branch.region',
                'store_branch.province',
                'department.name',
                'urgency_level',
                'item_count',
                'delivery_date',
                'budget_remaining',
            ]),
            'condition_operator' => $this->faker->randomElement([
                'equals', 'not_equals', 'in', 'not_in', 'greater_than', 'less_than',
                'between', 'not_between', 'like', 'not_like', 'is_null', 'is_not_null'
            ]),
            'condition_value' => $this->generateConditionValue(),
            'is_active' => $this->faker->boolean(80),
        ];
    }

    private function generateConditionValue(): ?string
    {
        $operators = [
            'equals' => $this->faker->randomElement([
                $this->faker->numberBetween(1, 100),
                $this->faker->word,
                $this->faker->company,
            ]),
            'not_equals' => $this->faker->randomElement([
                $this->faker->numberBetween(1, 100),
                $this->faker->word,
            ]),
            'in' => $this->faker->randomElements([
                'north', 'central', 'south', 'east', 'west',
                'high', 'medium', 'low',
                $this->faker->numberBetween(1, 50),
            ], $this->faker->numberBetween(2, 4)),
            'not_in' => $this->faker->randomElements([
                'cancelled', 'archived', 'inactive',
                $this->faker->numberBetween(1, 50),
            ], $this->faker->numberBetween(2, 4)),
            'greater_than' => [$this->faker->numberBetween(1000, 10000)],
            'less_than' => [$this->faker->numberBetween(5000, 50000)],
            'between' => [
                $this->faker->numberBetween(1000, 5000),
                $this->faker->numberBetween(10000, 20000)
            ],
            'not_between' => [
                $this->faker->numberBetween(1000, 5000),
                $this->faker->numberBetween(10000, 20000)
            ],
            'like' => $this->faker->word . '%',
            'not_like' => '%test%',
            'is_null' => null,
            'is_not_null' => null,
        ];

        $value = $this->faker->randomElement($operators);
        return is_array($value) ? json_encode($value) : (is_null($value) ? null : $value);
    }

    public function forMatrix(ApprovalMatrix $matrix): static
    {
        return $this->state(fn (array $attributes) => [
            'approval_matrix_id' => $matrix->id,
        ]);
    }

    public function forGroup(int $group): static
    {
        return $this->state(fn (array $attributes) => [
            'condition_group' => $group,
        ]);
    }

    public function andLogic(): static
    {
        return $this->state(fn (array $attributes) => [
            'condition_logic' => 'AND',
        ]);
    }

    public function orLogic(): static
    {
        return $this->state(fn (array $attributes) => [
            'condition_logic' => 'OR',
        ]);
    }

    public function forAmount(): static
    {
        return $this->state(fn (array $attributes) => [
            'condition_column' => 'total_amount',
            'condition_operator' => $this->faker->randomElement(['greater_than', 'less_than', 'between']),
            'condition_value' => json_encode([
                $this->faker->randomElement([
                    $this->faker->numberBetween(1000, 5000),
                    [$this->faker->numberBetween(1000, 3000), $this->faker->numberBetween(8000, 12000)]
                ])
            ]),
        ]);
    }

    public function forSupplier(): static
    {
        return $this->state(fn (array $attributes) => [
            'condition_column' => 'supplier.supplier_code',
            'condition_operator' => $this->faker->randomElement(['equals', 'in']),
            'condition_value' => json_encode(
                $this->faker->randomElement([
                    'PUL-O',
                    ['SUP-A', 'SUP-B', 'SUP-C'],
                    'NORTH-SUPPLIER',
                ])
            ),
        ]);
    }

    public function forRegion(): static
    {
        return $this->state(fn (array $attributes) => [
            'condition_column' => 'store_branch.region',
            'condition_operator' => $this->faker->randomElement(['in', 'equals']),
            'condition_value' => json_encode(
                $this->faker->randomElement([
                    'north',
                    ['north', 'central'],
                    'south',
                ])
            ),
        ]);
    }

    public function forUrgency(): static
    {
        return $this->state(fn (array $attributes) => [
            'condition_column' => 'urgency_level',
            'condition_operator' => $this->faker->randomElement(['equals', 'in']),
            'condition_value' => json_encode(
                $this->faker->randomElement([
                    'high',
                    ['high', 'urgent'],
                    'medium',
                ])
            ),
        ]);
    }

    public function forDepartment(): static
    {
        return $this->state(fn (array $attributes) => [
            'condition_column' => 'department.name',
            'condition_operator' => $this->faker->randomElement(['equals', 'in']),
            'condition_value' => json_encode(
                $this->faker->randomElement([
                    'IT',
                    ['Finance', 'Accounting'],
                    'Operations',
                ])
            ),
        ]);
    }

    public function forBudget(): static
    {
        return $this->state(fn (array $attributes) => [
            'condition_column' => 'budget_remaining',
            'condition_operator' => $this->faker->randomElement(['greater_than', 'less_than', 'between']),
            'condition_value' => json_encode(
                $this->faker->randomElement([
                    [$this->faker->numberBetween(10000, 50000)],
                    [$this->faker->numberBetween(5000, 15000), $this->faker->numberBetween(25000, 50000)]
                ])
            ),
        ]);
    }

    public function forDeliveryDate(): static
    {
        return $this->state(fn (array $attributes) => [
            'condition_column' => 'delivery_date',
            'condition_operator' => $this->faker->randomElement(['greater_than', 'less_than', 'between']),
            'condition_value' => json_encode(
                $this->faker->randomElement([
                    now()->addDays($this->faker->numberBetween(1, 7))->format('Y-m-d'),
                    [
                        now()->addDays(1)->format('Y-m-d'),
                        now()->addDays(3)->format('Y-m-d')
                    ]
                ])
            ),
        ]);
    }

    public function forItemCount(): static
    {
        return $this->state(fn (array $attributes) => [
            'condition_column' => 'item_count',
            'condition_operator' => $this->faker->randomElement(['greater_than', 'less_than', 'between']),
            'condition_value' => json_encode(
                $this->faker->randomElement([
                    [$this->faker->numberBetween(50, 100)],
                    [$this->faker->numberBetween(10, 25), $this->faker->numberBetween(75, 150)]
                ])
            ),
        ]);
    }

    public function nullCondition(): static
    {
        return $this->state(fn (array $attributes) => [
            'condition_operator' => $this->faker->randomElement(['is_null', 'is_not_null']),
            'condition_value' => null,
        ]);
    }

    public function textSearch(): static
    {
        return $this->state(fn (array $attributes) => [
            'condition_column' => $this->faker->randomElement(['description', 'notes', 'comments']),
            'condition_operator' => $this->faker->randomElement(['like', 'not_like']),
            'condition_value' => $this->faker->randomElement(['%urgent%', '%critical%', '%budget%']),
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

    public function highPriority(): static
    {
        return $this->state(fn (array $attributes) => [
            'condition_group' => 1,
            'condition_logic' => 'AND',
        ]);
    }

    public function alternativePath(): static
    {
        return $this->state(fn (array $attributes) => [
            'condition_group' => 2,
            'condition_logic' => 'OR',
        ]);
    }

    public function complex(): static
    {
        return $this->state(fn (array $attributes) => [
            'condition_column' => $this->faker->randomElement([
                'total_amount', 'store_branch.region', 'supplier.supplier_code'
            ]),
            'condition_operator' => $this->faker->randomElement(['between', 'not_between', 'in', 'not_in']),
            'condition_value' => json_encode(
                $this->faker->randomElement([
                    [$this->faker->numberBetween(10000, 20000), $this->faker->numberBetween(50000, 80000)],
                    $this->faker->randomElements(['north', 'central', 'south'], 2),
                ])
            ),
        ]);
    }

    public function simple(): static
    {
        return $this->state(fn (array $attributes) => [
            'condition_operator' => 'equals',
            'condition_value' => $this->faker->numberBetween(1, 100),
        ]);
    }
}