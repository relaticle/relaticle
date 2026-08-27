<?php

declare(strict_types=1);

use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Date;
use Relaticle\SystemAdmin\Filament\Widgets\SignupTrendChartWidget;
use Relaticle\SystemAdmin\Models\SystemAdministrator;

mutates(SignupTrendChartWidget::class);

function actAsSignupTrendAdmin(?string $timezone = null): void
{
    $admin = SystemAdministrator::factory()->create(['timezone' => $timezone]);

    test()->actingAs($admin, 'sysadmin');
    Filament::setCurrentPanel('sysadmin');
}

/**
 * @return array<string, int>
 */
function signupTrendSeries(string $dataset = 'New Users', int $period = 30): array
{
    $component = livewire(SignupTrendChartWidget::class, ['pageFilters' => ['period' => (string) $period]])
        ->assertOk();

    $data = invade($component->instance())->getData();

    $counts = collect($data['datasets'])->firstWhere('label', $dataset)['data'];

    return array_combine($data['labels'], $counts);
}

it('ends the daily series on today rather than yesterday', function (): void {
    $this->travelTo(Date::parse('2026-08-27 10:31:00', 'UTC'));
    actAsSignupTrendAdmin();

    User::factory()->create(['created_at' => Date::parse('2026-08-27 09:00:00', 'UTC')]);

    $series = signupTrendSeries();

    expect(array_key_last($series))->toBe('Aug 27')
        ->and($series['Aug 27'])->toBe(1)
        ->and($series)->toHaveCount(30)
        ->and(array_key_first($series))->toBe('Jul 29');
});

it('buckets a signup by the administrator calendar day, not the server day', function (): void {
    $this->travelTo(Date::parse('2026-08-27 10:31:00', 'UTC'));
    actAsSignupTrendAdmin('Asia/Yerevan');

    // 01:30 on Aug 27 in Yerevan, still Aug 26 on the server.
    User::factory()->create(['created_at' => Date::parse('2026-08-26 21:30:00', 'UTC')]);

    $series = signupTrendSeries();

    expect($series['Aug 27'])->toBe(1)
        ->and($series['Aug 26'])->toBe(0);
});

it('keeps an administrator without a zone on server days', function (): void {
    $this->travelTo(Date::parse('2026-08-27 10:31:00', 'UTC'));
    actAsSignupTrendAdmin();

    User::factory()->create(['created_at' => Date::parse('2026-08-26 21:30:00', 'UTC')]);

    $series = signupTrendSeries();

    expect($series['Aug 26'])->toBe(1)
        ->and($series['Aug 27'])->toBe(0);
});

it('counts a signup made later today in the administrator zone', function (): void {
    // 02:00 on Aug 28 in Yerevan is still Aug 27 on the server, so the viewer's
    // "today" bucket has to reach past the server's midnight.
    $this->travelTo(Date::parse('2026-08-27 22:30:00', 'UTC'));
    actAsSignupTrendAdmin('Asia/Yerevan');

    User::factory()->create(['created_at' => Date::parse('2026-08-27 22:00:00', 'UTC')]);

    $series = signupTrendSeries();

    expect(array_key_last($series))->toBe('Aug 28')
        ->and($series['Aug 28'])->toBe(1);
});

it('starts the weekly series on a whole week and ends on the current one', function (): void {
    $this->travelTo(Date::parse('2026-08-27 10:31:00', 'UTC'));
    actAsSignupTrendAdmin('Asia/Yerevan');

    // Monday of the week holding 2026-05-30, the 90th day back from Aug 27.
    User::factory()->create(['created_at' => Date::parse('2026-05-25 03:00:00', 'UTC')]);
    User::factory()->create(['created_at' => Date::parse('2026-08-24 03:00:00', 'UTC')]);

    $series = signupTrendSeries(period: 90);

    expect(array_key_first($series))->toBe('May 25')
        ->and($series['May 25'])->toBe(1)
        ->and(array_key_last($series))->toBe('Aug 24')
        ->and($series['Aug 24'])->toBe(1);
});

it('counts only non-personal teams in the teams series', function (): void {
    $this->travelTo(Date::parse('2026-08-27 10:31:00', 'UTC'));
    actAsSignupTrendAdmin();

    $owner = User::factory()->withTeam()->create();
    $owner->currentTeam->forceFill(['created_at' => Date::parse('2026-08-27 09:00:00', 'UTC')])->save();

    expect(signupTrendSeries('New Teams')['Aug 27'])->toBe(1);
});

it('stamps the chart with the read time in the administrator zone', function (): void {
    $this->travelTo(Date::parse('2026-08-27 10:31:00', 'UTC'));
    actAsSignupTrendAdmin('Asia/Yerevan');

    livewire(SignupTrendChartWidget::class)
        ->assertOk()
        ->assertSee('Updated 14:31 +04');
});
