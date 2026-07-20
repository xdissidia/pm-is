<?php

namespace Database\Seeders;

use App\Models\ProjectTag;
use Illuminate\Database\Seeder;

class ProjectTagSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        ProjectTag::insert([
            ['name' => 'Internal', 'color' => '#3B5BDB'],
            ['name' => 'Client', 'color' => '#2B9267'],
            ['name' => 'Maintenance', 'color' => '#F08C00'],
            ['name' => 'Priority', 'color' => '#E03231'],
            ['name' => 'On hold', 'color' => '#868E96'],
        ]);
    }
}
