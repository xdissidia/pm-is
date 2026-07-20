<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Http\Requests\ProjectTag\StoreProjectTagRequest;
use App\Http\Requests\ProjectTag\UpdateProjectTagRequest;
use App\Http\Resources\ProjectTag\ProjectTagResource;
use App\Models\ProjectTag;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ProjectTagController extends Controller
{
    public function __construct()
    {
        $this->authorizeResource(ProjectTag::class, 'project_tag');
    }

    public function index(Request $request): Response
    {
        return Inertia::render('Settings/ProjectTags/Index', [
            'items' => ProjectTagResource::collection(
                ProjectTag::searchByQueryString()
                    ->sortByQueryString()
                    ->when($request->has('archived'), fn ($query) => $query->onlyArchived())
                    ->paginate(12)
            ),
        ]);
    }

    public function create()
    {
        return Inertia::render('Settings/ProjectTags/Create');
    }

    public function store(StoreProjectTagRequest $request)
    {
        ProjectTag::create($request->validated());

        return redirect()->route('settings.project-tags.index')->success('Project tag created', 'A new project tag was successfully created.');
    }

    public function edit(ProjectTag $projectTag)
    {
        return Inertia::render('Settings/ProjectTags/Edit', ['item' => new ProjectTagResource($projectTag)]);
    }

    public function update(ProjectTag $projectTag, UpdateProjectTagRequest $request)
    {
        $projectTag->update($request->validated());

        return redirect()->route('settings.project-tags.index')->success('Project tag updated', 'The project tag was successfully updated.');
    }

    public function destroy(ProjectTag $projectTag)
    {
        $projectTag->archive();

        return redirect()->back()->success('Project tag archived', 'The project tag was successfully archived.');
    }

    public function restore(int $projectTagId)
    {
        $projectTag = ProjectTag::withArchived()->findOrFail($projectTagId);

        $this->authorize('restore', $projectTag);

        $projectTag->unArchive();

        return redirect()->back()->success('Project tag restored', 'The restoring of the project tag was completed successfully.');
    }
}
