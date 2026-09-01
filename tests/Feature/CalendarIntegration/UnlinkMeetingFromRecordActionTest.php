<?php

declare(strict_types=1);

use App\Models\People;
use Relaticle\EmailIntegration\Actions\LinkMeetingToRecordAction;
use Relaticle\EmailIntegration\Actions\UnlinkMeetingFromRecordAction;
use Relaticle\EmailIntegration\Models\ConnectedAccount;
use Relaticle\EmailIntegration\Models\Meeting;

mutates(UnlinkMeetingFromRecordAction::class);

it('removes a link regardless of source', function (): void {
    $account = ConnectedAccount::withoutEvents(fn () => ConnectedAccount::factory()->create());
    $meeting = Meeting::factory()->create([
        'team_id' => $account->team_id,
        'connected_account_id' => $account->getKey(),
    ]);
    $person = People::factory()->for($meeting->team)->create();
    (app(LinkMeetingToRecordAction::class))->execute($meeting, $person);

    (app(UnlinkMeetingFromRecordAction::class))->execute($meeting, $person);

    expect($meeting->people()->count())->toBe(0);
});
