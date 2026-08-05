<?php

use App\Http\Controllers\Api\V1\TaskController;
use App\Http\Controllers\Api\V1\UserController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Here is where you can register API routes for your application. These
| routes are loaded by the RouteServiceProvider and all of them will
| be assigned to the "api" middleware group. Make something great!
|
*/

Route::middleware('auth:sanctum')->get('/user', function (Request $request) {
    return $request->user();
});

Route::middleware('auth:sanctum')->prefix('v1')->name('api.v1.')->group(function () {
    Route::post('users/lookup', [UserController::class, 'lookup'])
        ->name('users.lookup');

    // Create and update take no {project}: it is inferred from the task group
    // or the task itself. An optional task_group_id in the body decides where
    // a new task lands — without one it is stored unfiled, with no project,
    // group or number, until a later update moves it into a group.
    Route::post('tasks', [TaskController::class, 'store'])
        ->name('tasks.store');

    Route::patch('tasks/{task}', [TaskController::class, 'update'])
        ->name('tasks.update');

    Route::put('projects/{project}/tasks/{task}/group', [TaskController::class, 'move'])
        ->name('tasks.move');

    Route::put('projects/{project}/tasks/{task}/complete', [TaskController::class, 'complete'])
        ->name('tasks.complete');
});
