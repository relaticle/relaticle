<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Actions\Task\CreateTask;
use App\Actions\Task\DeleteTask;
use App\Actions\Task\ListTasks;
use App\Actions\Task\UpdateTask;
use App\Enums\CreationSource;
use App\Http\Requests\Api\V1\StoreTaskRequest;
use App\Http\Requests\Api\V1\UpdateTaskRequest;
use App\Http\Resources\V1\TaskResource;
use App\Models\Task;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;
use Knuckles\Scribe\Attributes\Response;
use Knuckles\Scribe\Attributes\ResponseFromApiResource;

/**
 * @group Tasks
 *
 * Manage tasks in your CRM workspace.
 */
final readonly class TasksController
{
    public function index(Request $request, ListTasks $action): AnonymousResourceCollection
    {
        /** @var User $user */
        $user = $request->user();

        return TaskResource::collection($action->execute($user));
    }

    #[ResponseFromApiResource(TaskResource::class, Task::class, status: 201)]
    public function store(StoreTaskRequest $request, CreateTask $action): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $task = $action->execute($user, $request->validated(), CreationSource::API);

        return new TaskResource($task->load('customFieldValues.customField'))
            ->response()
            ->setStatusCode(201);
    }

    #[ResponseFromApiResource(TaskResource::class, Task::class)]
    public function show(Task $task): TaskResource
    {
        Gate::authorize('view', $task);

        $task->loadMissing('customFieldValues.customField');

        return new TaskResource($task);
    }

    #[ResponseFromApiResource(TaskResource::class, Task::class)]
    public function update(UpdateTaskRequest $request, Task $task, UpdateTask $action): TaskResource
    {
        /** @var User $user */
        $user = $request->user();

        $task = $action->execute($user, $task, $request->validated());

        return new TaskResource($task->load('customFieldValues.customField'));
    }

    #[Response(status: 204)]
    public function destroy(Request $request, Task $task, DeleteTask $action): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $action->execute($user, $task);

        return response()->json(null, 204);
    }
}
