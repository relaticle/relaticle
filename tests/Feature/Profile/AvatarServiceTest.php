<?php

declare(strict_types=1);

use App\Services\AvatarService;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;

mutates(AvatarService::class);

beforeEach(function () {
    $this->avatarService = new AvatarService(
        cache: new Repository(new ArrayStore),
        cacheTtl: 3600,
        defaultTextColor: '#FFFFFF',
        defaultBgColor: '#3182CE',
        backgroundColors: ['#FF5733', '#33FF57', '#3357FF']
    );
});

function extractInitialsFromSvg(string $dataUrl): string
{
    $base64 = str_replace('data:image/svg+xml;base64,', '', $dataUrl);
    $svg = base64_decode($base64);
    preg_match('/<text[^>]*>\s*(\S+)\s*<\/text>/s', $svg, $matches);

    return trim($matches[1] ?? '');
}

it('handles edge cases in name analysis via generateAuto output', function () {
    $emptyAvatar = $this->avatarService->generateAuto('');
    expect($emptyAvatar)->toStartWith('data:image/svg+xml;base64,');

    $singleCharAvatar = $this->avatarService->generateAuto('A');
    expect($singleCharAvatar)->toStartWith('data:image/svg+xml;base64,');

    expect($emptyAvatar)->not->toBe($singleCharAvatar);
});

it('produces visually distinct avatars for different name types', function () {
    $vowelHeavy = $this->avatarService->generateAuto('Aoi Ueo');
    $consonantHeavy = $this->avatarService->generateAuto('Brynhildr Skjeggestad');

    expect($vowelHeavy)->not->toBe($consonantHeavy);

    $withRareLetters = $this->avatarService->generateAuto('Xavi Quintanilla Jimenez');
    $ordinary = $this->avatarService->generateAuto('Daniel Anderson');

    expect($withRareLetters)->not->toBe($ordinary);
});

it('generates different background colors for different names', function () {
    $avatar1 = $this->avatarService->generateAuto('John Smith');
    $avatar2 = $this->avatarService->generateAuto('Jane Doe');

    expect($avatar1)->not->toBe($avatar2);

    $avatar3 = $this->avatarService->generateAuto('John Smith');
    expect($avatar1)->toBe($avatar3);
});

it('correctly extracts initials from names', function () {
    expect(extractInitialsFromSvg($this->avatarService->generate('John Smith')))->toBe('JS');
    expect(extractInitialsFromSvg($this->avatarService->generate('John Smith', initialCount: 1)))->toBe('J');
    expect(extractInitialsFromSvg($this->avatarService->generate('John')))->toBe('JO');
    expect(extractInitialsFromSvg($this->avatarService->generate('O\'Connor')))->toBe('OC');
    expect(extractInitialsFromSvg($this->avatarService->generate('Jean-Claude')))->toBe('JC');
});

it('correctly handles names with special characters and symbols', function () {
    expect(extractInitialsFromSvg($this->avatarService->generate('Jo.hn Smith')))->toBe('JS');
    expect(extractInitialsFromSvg($this->avatarService->generate('Dr. John Smith')))->toBe('DS');

    expect(extractInitialsFromSvg($this->avatarService->generate('John_Smith')))->toBe('JS');
    expect(extractInitialsFromSvg($this->avatarService->generate('John_Do_e')))->toBe('JE');

    expect(extractInitialsFromSvg($this->avatarService->generate('Jo.hn d_oe')))->toBe('JO');
    expect(extractInitialsFromSvg($this->avatarService->generate('Jo.hn.d_oe')))->toBe('JO');

    expect(extractInitialsFromSvg($this->avatarService->generate('John+Smith')))->toBe('JO');
    expect(extractInitialsFromSvg($this->avatarService->generate('John@Smith')))->toBe('JO');

    expect(extractInitialsFromSvg($this->avatarService->generate('J.o-h_n S.m-i_t.h')))->toBe('JI');
    expect(extractInitialsFromSvg($this->avatarService->generate('...John...Smith...')))->toBe('JS');
});

it('validates colors properly', function () {
    expect($this->avatarService->validateColor('#123456'))->toBeTrue();
    expect($this->avatarService->validateColor('#abc'))->toBeTrue();
    expect($this->avatarService->validateColor('rgb(10, 20, 30)'))->toBeTrue();
    expect($this->avatarService->validateColor('rgba(10, 20, 30, 0.5)'))->toBeTrue();
    expect($this->avatarService->validateColor('invalid'))->toBeFalse();
    expect($this->avatarService->validateColor(null))->toBeFalse();
});
