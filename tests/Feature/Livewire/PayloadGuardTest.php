<?php

declare(strict_types=1);

use App\Models\User;
use Livewire\Livewire;

it('answers an oversized livewire request with a message the panel can show', function (): void {
    $this->actingAs(User::factory()->withTeam()->create());

    $response = $this->withHeaders(['X-Livewire' => 'true'])
        ->postJson(Livewire::getUpdateUri(), ['components' => [['snapshot' => str_repeat('a', 1024 * 1024 + 1)]]]);

    $response->assertStatus(413)
        ->assertExactJson(['message' => __('filament/panel.payload_too_large')]);
});
