<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Relaticle\EmailIntegration\Controllers\ComposerInlineImageController;
use Relaticle\EmailIntegration\Models\EmailAttachment;
use Relaticle\EmailIntegration\Support\ComposerInlineImage;

mutates(ComposerInlineImage::class, ComposerInlineImageController::class);

it('serves a compose image that belongs to the current workspace', function (): void {
    Storage::fake(EmailAttachment::DISK);

    $user = User::factory()->withWorkspace()->create();
    $bytes = UploadedFile::fake()->image('logo.jpg')->getContent();
    $path = EmailAttachment::composeImagesDirectory((string) $user->current_workspace_id).'/logo.jpg';
    Storage::disk(EmailAttachment::DISK)->put($path, $bytes);

    $response = $this->actingAs($user)
        ->get(route('email-compose-images.show', ['path' => $path]));

    $response->assertSuccessful()
        ->assertHeader('content-type', 'image/jpeg');

    expect($response->getContent())->toBe($bytes);
});

it('redirects guests away from compose images', function (): void {
    $this->get(route('email-compose-images.show', ['path' => 'email-attachments/1/logo.jpg']))
        ->assertRedirect(route('login'));
});

it('does not serve a compose image from another workspace', function (): void {
    Storage::fake(EmailAttachment::DISK);

    $owner = User::factory()->withWorkspace()->create();
    $other = User::factory()->withWorkspace()->create();
    $path = EmailAttachment::composeImagesDirectory((string) $owner->current_workspace_id).'/logo.jpg';
    Storage::disk(EmailAttachment::DISK)->put($path, UploadedFile::fake()->image('logo.jpg')->getContent());

    $this->actingAs($other)
        ->get(route('email-compose-images.show', ['path' => $path]))
        ->assertNotFound();
});

it('does not serve a file outside the workspace compose directory', function (): void {
    Storage::fake(EmailAttachment::DISK);

    $user = User::factory()->withWorkspace()->create();
    $directory = EmailAttachment::composeImagesDirectory((string) $user->current_workspace_id);
    Storage::disk(EmailAttachment::DISK)->put('email-attachments/secret.jpg', UploadedFile::fake()->image('secret.jpg')->getContent());

    $this->actingAs($user)
        ->get(route('email-compose-images.show', ['path' => $directory.'/../../secret.jpg']))
        ->assertNotFound();

    $this->get(route('email-compose-images.show', ['path' => 'email-attachments/secret.jpg']))
        ->assertNotFound();
});

it('does not serve a non-image from the compose directory', function (): void {
    Storage::fake(EmailAttachment::DISK);

    $user = User::factory()->withWorkspace()->create();
    $path = EmailAttachment::composeImagesDirectory((string) $user->current_workspace_id).'/notes.csv';
    Storage::disk(EmailAttachment::DISK)->put($path, 'secret,tenant,data');

    $this->actingAs($user)
        ->get(route('email-compose-images.show', ['path' => $path]))
        ->assertNotFound();
});
