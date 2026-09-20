<?php

namespace Database\Factories;

use App\Models\BenchRow;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<BenchRow>
 */
class BenchRowFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'token' => fake()->uuid(),
            'value' => fake()->numberBetween(1, 10_000),
            // Roughly a realistic row width, so hydration is not measured on an empty record.
            'payload' => fake()->paragraph(),
        ];
    }
}
