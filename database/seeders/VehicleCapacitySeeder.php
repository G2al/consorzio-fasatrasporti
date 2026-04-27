<?php

namespace Database\Seeders;

use App\Models\VehicleCapacity;
use Illuminate\Database\Seeder;

class VehicleCapacitySeeder extends Seeder
{
    public function run(): void
    {
        foreach (VehicleCapacity::VALUES as $seats) {
            VehicleCapacity::query()->updateOrCreate(
                ['seats' => $seats],
                ['seats' => $seats],
            );
        }
    }
}
