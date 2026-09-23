<?php

namespace Database\Factories;

use App\Enums\AssetCondition;
use App\Enums\AssetStatus;
use App\Models\Asset;
use App\Models\AssetCategory;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Asset>
 */
class AssetFactory extends Factory
{
    protected $model = Asset::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tag' => 'TGM-'.fake()->unique()->numerify('#####'),
            'name' => fake()->words(2, true),
            'asset_category_id' => AssetCategory::factory(),
            'status' => AssetStatus::Available,
            'condition' => AssetCondition::Good,
        ];
    }
}
