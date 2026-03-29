<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Actions\Task\CreateTask;
use App\Actions\Task\DeleteTask;
use App\Actions\Task\ListTasks;
use App\Actions\Task\UpdateTask;
use App\Enums\CreationSource;
use App\Http\Requests\Api\V1\IndexRequest;
use App\Http\Requests\Api\V1\StoreTaskRequest;
use App\Http\Requests\Api\V1\UpdateTaskRequest;
use App\Http\Resources\V1\TaskResource;
use App\Models\Task;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response as HttpResponse;
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
    #[ResponseFromApiResource(TaskResource::class, Task::class, collection: true, paginate: 15)]
    public function index(IndexRequest $request, ListTasks $action, #[CurrentUser] User $user): AnonymousResourceCollection
    {
        return TaskResource::collection($action->execute(
            user: $user,
            perPage: $request->safe()->integer('per_page', 15),
            useCursor: $request->safe()->has('cursor'),
            request: $request,
        ));
    }

    #[ResponseFromApiResource(TaskResource::class, Task::class, status: 201)]
    public function store(StoreTaskRequest $request, CreateTask $action, #[CurrentUser] User $user): JsonResponse
    {
        $task = $action->execute($user, $request->validated(), CreationSource::API);

        return new TaskResource($task)
            ->response()
            ->setStatusCode(201);
    }

    #[ResponseFromApiResource(TaskResource::class, Task::class)]
    public function show(Task $task): TaskResource
    {
        Gate::authorize('view', $task);

        $task->loadMissing('customFieldValues.customField.options');

        return new TaskResource($task);
    }

    #[ResponseFromApiResource(TaskResource::class, Task::class)]
    public function update(UpdateTaskRequest $request, Task $task, UpdateTask $action, #[CurrentUser] User $user): TaskResource
    {
        $task = $action->execute($user, $task, $request->validated());

        return new TaskResource($task);
    }

    #[Response(status: 204)]
    public function destroy(Task $task, DeleteTask $action, #[CurrentUser] User $user): HttpResponse
    {
        $action->execute($user, $task);

        return response()->noContent();
    }
}
