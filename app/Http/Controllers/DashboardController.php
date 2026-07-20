<?php

namespace App\Http\Controllers;

use App\Models\Comment;
use App\Models\Project;
use App\Models\ProjectTag;
use App\Models\Task;
use App\Services\PermissionService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function index(Request $request): Response
    {
        $projectIds = PermissionService::projectsThatUserCanAccess(auth()->user())->pluck('id');

        $selectedTags = (array) $request->input('tags', []);

        if (! empty($selectedTags)) {
            $projectIds = Project::whereIn('id', $projectIds)
                ->whereHas('tags', fn ($query) => $query->whereIn('project_tags.id', $selectedTags))
                ->pluck('id');
        }

        return Inertia::render('Dashboard/Index', [
            'tags' => ProjectTag::orderBy('order')->get(['id', 'name', 'color']),
            'projects' => Project::whereIn('id', $projectIds)
                ->with([
                    'clientCompany:id,name',
                    'tags:id,name,color',
                ])
                ->withCount([
                    'tasks AS all_tasks_count',
                    'tasks AS completed_tasks_count' => fn ($query) => $query->whereNotNull('completed_at'),
                    'tasks AS overdue_tasks_count' => fn ($query) => $query->whereNull('completed_at')->whereDate('due_on', '<', now()),
                ])
                ->withExists('favoritedByAuthUser AS favorite')
                ->orderBy('favorite', 'desc')
                ->orderBy('name', 'asc')
                ->get(['id', 'name']),
            'overdueTasks' => Task::whereIn('project_id', $projectIds)
                ->whereNull('completed_at')
                ->whereDate('due_on', '<', now())
                ->whereHas('assignees', function ($query) {
                    $query->where('user_id', auth()->id());
                })
                ->with('project:id,name')
                ->with('taskGroup:id,name')
                ->orderBy('due_on')
                ->get(['id', 'name', 'due_on', 'group_id', 'project_id']),
            'recentlyAssignedTasks' => Task::whereIn('project_id', $projectIds)
                ->whereNull('completed_at')
                ->whereHas('assignees', function ($query) {
                    $query->where('user_id', auth()->id());
                })
                ->with('project:id,name')
                ->with([
                    'assignees' => function ($query) {
                        $query->where('user_id', auth()->id());
                    },
                ])
                ->with(relations: 'taskGroup:id,name')
                ->limit(10)
                ->get(['id', 'name', 'assigned_at', 'group_id', 'project_id'])
                ->sortByDesc(fn ($t) => data_get($t, 'assignees.0.pivot.created_at'))->values(),
            'recentComments' => Comment::query()
                ->whereHas('task', function ($query) use ($projectIds) {
                    $query->whereIn('project_id', $projectIds)
                        ->whereHas('assignees', function ($query) {
                            $query->where('user_id', auth()->id());
                        });
                })
                ->with([
                    'task:id,name,project_id',
                    'task.project:id,name',
                    'user:id,name',
                ])
                ->latest()
                ->get(),
        ]);
    }
}
