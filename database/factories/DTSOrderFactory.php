<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\StoreOrder>
 */
class DTSOrderFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'encoder_id' => 1,
            'supplier_id' => 5,
            'order_number' => 'TEST-' . fake()->numberBetween(00000, 11111),
            'order_date' => fake()->date(),
            
        ];
    }
}
