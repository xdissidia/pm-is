<?php

namespace Database\Seeders;

use App\Models\ProjectTag;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class ProjectTagSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * Keyed by name via updateOrCreate so re-running updates existing tags
     * (e.g. their color) instead of inserting duplicates. Every color is
     * distinct and taken from the tag color-picker swatch palette.
     */
    public function run(): void
    {
        // Clear existing tags (and their project associations) before seeding.
        // FK checks are disabled so the pivot-referenced table can be truncated.
        Schema::withoutForeignKeyConstraints(function () {
            DB::table('project_project_tag')->truncate();
            ProjectTag::truncate();
        });

        $tags = [
            // Provided tags (duplicate #868E96 colors reassigned to distinct swatches)
            ['name' => 'ICT', 'color' => '#3B5BDB'],
            ['name' => 'InfoSys', 'color' => '#2B9267'],
            ['name' => 'Network', 'color' => '#F08C00'],
            ['name' => 'Server', 'color' => '#E03231'],
            ['name' => 'Tech Support', 'color' => '#868E96'],
            ['name' => 'DEVELOPMENT', 'color' => '#6741D9'],
            ['name' => 'PROJECT', 'color' => '#2771C2'],
            ['name' => 'TOR', 'color' => '#9C36B5'],
            ['name' => 'Completed', 'color' => '#309E44'],

            // Suggested additional tags
            // ['name' => 'Infrastructure', 'color' => '#343A40'],
            // ['name' => 'Security', 'color' => '#C2255C'],
            // ['name' => 'Database', 'color' => '#2A8599'],
            // ['name' => 'Website', 'color' => '#66A810'],
            // ['name' => 'Hardware', 'color' => '#E7590D'],
            // ['name' => 'On Hold', 'color' => '#FAB005'],
            // ['name' => 'Urgent', 'color' => '#E64980'],
        ];

        foreach ($tags as $index => $tag) {
            ProjectTag::updateOrCreate(
                ['name' => $tag['name']],
                ['color' => $tag['color'], 'order' => $index + 1],
            );
        }
    }
}
