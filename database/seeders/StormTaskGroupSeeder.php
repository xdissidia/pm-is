<?php

namespace Database\Seeders;

use App\Models\Project;
use App\Models\TaskGroup;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class StormTaskGroupSeeder extends Seeder
{
    /**
     * Name of the task group this seeder puts at the top of each board.
     */
    public const GROUP_NAME = 'STORM';

    /**
     * Projects to touch. Matched on the name with punctuation and spacing
     * stripped, because these are stored spaced out ("- N E T W O R K -")
     * and the ids differ per environment.
     */
    public const PROJECTS = ['NETWORK', 'SERVER', 'ISRDT'];

    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $projects = Project::get(['id', 'name'])
            ->keyBy(fn (Project $project) => $this->normalize($project->name));

        foreach (self::PROJECTS as $needle) {
            $project = $projects->get($needle);

            if (! $project) {
                $this->command?->warn("Skipped {$needle}: no project matches that name.");

                continue;
            }

            $this->seedProject($project, $needle);
        }
    }

    protected function seedProject(Project $project, string $label): void
    {
        $group = TaskGroup::firstWhere([
            'project_id' => $project->id,
            'name' => self::GROUP_NAME,
        ]);

        if ($group) {
            $this->command?->line("  {$label} (project {$project->id}): already has ".self::GROUP_NAME." — id {$group->id}");
        } else {
            // SortableTrait puts new groups last; the reorder below lifts it.
            $group = $project->taskGroups()->create(['name' => self::GROUP_NAME]);

            $this->command?->info("  {$label} (project {$project->id}): created ".self::GROUP_NAME." — id {$group->id}");
        }

        $this->moveToTop($project, $group);
    }

    /**
     * Renumber the project's live groups with this one first, the same way the
     * board's drag-to-reorder endpoint does it.
     */
    protected function moveToTop(Project $project, TaskGroup $group): void
    {
        $ids = TaskGroup::where('project_id', $project->id)
            ->pluck('id')
            ->reject(fn (int $id) => $id === $group->id)
            ->prepend($group->id)
            ->all();

        TaskGroup::setNewOrder($ids);
    }

    /**
     * "- N E T W O R K -" => "NETWORK"
     */
    protected function normalize(string $name): string
    {
        return Str::upper(preg_replace('/[^A-Za-z0-9]/', '', $name));
    }
}
