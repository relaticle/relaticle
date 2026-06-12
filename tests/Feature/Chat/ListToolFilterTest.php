<?php

declare(strict_types=1);

use App\Models\Company;
use App\Models\User;
use Laravel\Ai\Tools\Request;
use Relaticle\Chat\Tools\Company\ListCompaniesTool;

it('applies a filter when searching for the literal term "0" instead of returning all', function (): void {
    $user = User::factory()->withPersonalTeam()->create();
    $this->actingAs($user);
    $team = $user->currentTeam;

    Company::factory()->for($team)->create(['name' => '0']);
    Company::factory()->for($team)->create(['name' => 'Acme']);
    Company::factory()->for($team)->create(['name' => 'Globex']);

    $tool = new ListCompaniesTool;
    $json = $tool->handle(new Request(['search' => '0']));
    $data = json_decode($json, true);

    $rows = $data['data'] ?? $data;
    expect($rows)->toHaveCount(1);
});
