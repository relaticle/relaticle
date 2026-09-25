<?php

declare(strict_types=1);

use App\Models\Company;
use App\Models\People;
use App\Models\Task;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\RateLimiter;
use Relaticle\Chat\Http\Controllers\ChatController;

mutates(ChatController::class);

beforeEach(function (): void {
    $this->user = User::factory()->withPersonalWorkspace()->create();
    $this->workspace = $this->user->currentWorkspace;
    $this->actingAs($this->user);
    Filament::setTenant($this->workspace);
    RateLimiter::clear('60|'.request()->ip());
});

it('orders prefix matches before substring matches within the same type', function (): void {
    Company::factory()->for($this->workspace)->create(['name' => 'Macao Inc']);     // substring match for "Ac"
    Company::factory()->for($this->workspace)->create(['name' => 'Acme Corp']);     // prefix match
    Company::factory()->for($this->workspace)->create(['name' => 'AcmeAir']);       // prefix match (shorter)

    $response = $this->getJson(route('chat.mentions', ['q' => 'Ac']))->assertOk();

    $names = collect($response->json('data'))->where('type', 'company')->pluck('name')->all();

    // Prefix matches first, ordered by length ascending. Macao Inc (substring) comes after.
    expect(array_slice($names, 0, 2))->toBe(['AcmeAir', 'Acme Corp']);
});

it('orders prefix matches before substring matches for tasks (title column)', function (): void {
    Task::factory()->for($this->workspace)->create(['title' => 'Recall everyone about Friday']);  // substring
    Task::factory()->for($this->workspace)->create(['title' => 'Friday standup']);              // prefix (14 chars)
    Task::factory()->for($this->workspace)->create(['title' => 'Fri-only routine']);            // prefix (16 chars)

    $response = $this->getJson(route('chat.mentions', ['q' => 'Fri']))->assertOk();

    $titles = collect($response->json('data'))->where('type', 'task')->pluck('name')->all();

    // Prefix matches first, ordered by length ascending (shorter first). Substring match comes after.
    expect(array_slice($titles, 0, 2))->toBe(['Friday standup', 'Fri-only routine']);
});

it('returns people before companies for ambiguous queries', function (): void {
    Company::factory()->for($this->workspace)->create(['name' => 'Acme Tim Co']);
    People::factory()->for($this->workspace)->create(['name' => 'Tim Cook']);

    $response = $this->getJson(route('chat.mentions', ['q' => 'Tim']))->assertOk();

    $data = $response->json('data');
    expect($data[0]['type'])->toBe('people');
    expect($data[0]['name'])->toBe('Tim Cook');
});
