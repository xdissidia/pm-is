<?php

namespace App\Http\Controllers\Account;

use App\Actions\User\UpdateAuthUser;
use App\Http\Controllers\Controller;
use App\Http\Requests\User\UpdateAuthUserRequest;
use App\Http\Resources\User\AuthUserResource;
use App\Models\ProjectTag;
use Illuminate\Http\Request;
use Inertia\Inertia;

class ProfileController extends Controller
{
    public function edit()
    {
        return Inertia::render('Account/Profile/Edit', [
            'user' => new AuthUserResource(auth()->user()),
            'projectTags' => ProjectTag::orderBy('order')->get(['id', 'name', 'color']),
        ]);
    }

    public function update(UpdateAuthUserRequest $request)
    {
        (new UpdateAuthUser)->update($request->user(), $request->validated());

        return redirect()->back()->success('User updated', 'The user was successfully updated.');
    }

    public function updateDefaultProjectTags(Request $request)
    {
        $validated = $request->validate([
            'tags' => 'array',
            'tags.*' => 'integer|exists:project_tags,id',
        ]);

        $request->user()->update([
            'default_project_tag_ids' => array_map('intval', $validated['tags'] ?? []),
        ]);

        return redirect()->back()->success('Default filter saved', 'Your default project tag filter was updated.');
    }
}
