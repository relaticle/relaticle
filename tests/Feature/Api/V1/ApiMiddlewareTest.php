<?php

declare(strict_types=1);

use App\Models\Company;
use App\Models\User;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->user = User::factory()->withPersonalTeam()->create();
    $this->team = $this->user->personalTeam();
});

describe('ForceJsonResponse', function (): void {
    it('returns JSON even without Accept header', function (): void {
        $token = $this->user->createToken('test', ['*'])->plainTextToken;

        $response = $this->withToken($token)
            ->get('/api/v1/companies');

        $response->assertOk();
        expect($response->headers->get('Content-Type'))->toContain('json');
    });

    it('returns JSON validation error without Accept header', function (): void {
        $token = $this->user->createToken('test', ['*'])->plainTextToken;

        $response = $this->withToken($token)
            ->post('/api/v1/companies', []);

        $response->assertUnprocessable();
        expect($response->headers->get('Content-Type'))->toContain('json');
    });
});

describe('rate limiting', function (): void {
    it('returns 429 after exceeding threshold', function (): void {
        RateLimiter::for('api', fn () => Limit::perMinute(3)->by($this->user->id));

        $token = $this->user->createToken('test', ['*'])->plainTextToken;

        for ($i = 0; $i < 3; $i++) {
            $this->withToken($token)
                ->getJson('/api/v1/companies')
                ->assertOk();
        }

        $this->withToken($token)
            ->getJson('/api/v1/companies')
            ->assertTooManyRequests();
    });
});

describe('real-token middleware chain', function (): void {
    it('authenticates and scopes via real bearer token through full middleware stack', function (): void {
        $companies = Company::factory()->for($this->team)->count(2)->create();

        $raw = Str::random(40);
        $token = $this->user->tokens()->create([
            'name' => 'full-stack-test',
            'token' => hash('sha256', $raw),
            'abilities' => ['*'],
            'team_id' => $this->team->id,
        ]);

        $plainToken = "{$token->id}|{$raw}";

        $response = $this->withToken($plainToken)
            ->getJson('/api/v1/companies');

        $response->assertOk();
        $ids = collect($response->json('data'))->pluck('id');
        expect($ids)->toContain($companies[0]->id);
        expect($ids)->toContain($companies[1]->id);
        expect($response->headers->get('Content-Type'))->toContain('json');
    });

    it('rejects request with invalid bearer token', function (): void {
        $this->withToken('invalid-token')
            ->getJson('/api/v1/companies')
            ->assertUnauthorized();
    });
});
