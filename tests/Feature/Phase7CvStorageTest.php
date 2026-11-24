<?php

namespace Tests\Feature;

use App\Models\Profile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class Phase7CvStorageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
    }

    protected function using(string $token): static
    {
        $this->app['auth']->forgetGuards();

        return $this->withToken($token);
    }

    private function member(array $attributes = []): User
    {
        return User::factory()->create(array_merge([
            'status' => 'active',
            'email_verified_at' => now(),
        ], $attributes));
    }

    private function profile(User $user, array $attributes = []): Profile
    {
        return $user->profiles()->create(array_merge([
            'slug' => 'cv-' . $user->id . '-' . uniqid(),
            'title' => 'Member',
            'visibility' => 'public',
            'privacy_confirmed_at' => now(),
            'is_live' => true,
            'is_published' => true,
            'is_searchable' => true,
        ], $attributes));
    }

    private function uploadCv(Profile $profile, bool $visible): \Spatie\MediaLibrary\MediaCollections\Models\Media
    {
        return $profile->addMedia(UploadedFile::fake()->create(
            ($visible ? 'public' : 'private') . '-cv.pdf',
            60,
            'application/pdf'
        ))->withCustomProperties(['visible' => $visible])->toMediaCollection('cv');
    }

    public function test_hidden_cv_is_owner_and_admin_only(): void
    {
        $owner = $this->member();
        $admin = $this->member(['role' => 'admin']);
        $other = $this->member();
        $profile = $this->profile($owner);
        $media = $this->uploadCv($profile, false);

        $this->using($owner->createToken('test')->plainTextToken)
            ->get("/api/profiles/{$profile->id}/cv/{$media->id}")
            ->assertOk()
            ->assertHeader('Content-Disposition');

        $this->using($admin->createToken('test')->plainTextToken)
            ->get("/api/profiles/{$profile->id}/cv/{$media->id}")
            ->assertOk();

        $this->using($other->createToken('test')->plainTextToken)
            ->get("/api/profiles/{$profile->id}/cv/{$media->id}")
            ->assertStatus(403);

        $this->app['auth']->forgetGuards();
        $this->get("/api/profiles/{$profile->id}/cv/{$media->id}")
            ->assertStatus(403);
    }

    public function test_visible_cv_is_downloadable_through_authorized_route_but_not_storage_path(): void
    {
        $owner = $this->member();
        $profile = $this->profile($owner);
        $media = $this->uploadCv($profile, true);

        $response = $this->getJson('/api/public/' . $profile->slug . '?_metadata=1')->assertOk();
        $url = $response->json('cv.url');

        $this->assertStringContainsString("/api/profiles/{$profile->id}/cv/{$media->id}", $url);
        $this->assertStringNotContainsString('/storage/', $url);
        $this->assertStringNotContainsString('storage/app', $url);

        $this->get($url)->assertOk();
    }

    public function test_hidden_cv_is_absent_from_public_profile_and_missing_file_returns_404(): void
    {
        $owner = $this->member();
        $profile = $this->profile($owner);
        $hidden = $this->uploadCv($profile, false);

        $this->getJson('/api/public/' . $profile->slug . '?_metadata=1')
            ->assertOk()
            ->assertJsonPath('cv', null);

        Storage::disk('local')->delete($hidden->getPathRelativeToRoot());

        $this->using($owner->createToken('test')->plainTextToken)
            ->get("/api/profiles/{$profile->id}/cv/{$hidden->id}")
            ->assertStatus(404);
    }

    public function test_private_cv_migration_command_is_dry_run_safe_and_verifies_copy(): void
    {
        Storage::fake('public');
        Storage::fake('local');

        $owner = $this->member();
        $profile = $this->profile($owner);
        $media = $this->uploadCv($profile, true);
        $path = $media->getPathRelativeToRoot();
        $contents = Storage::disk('local')->get($path);

        // Simulate a legacy CV row still on the public disk.
        Storage::disk('public')->put($path, $contents);
        Storage::disk('local')->delete($path);
        $media->forceFill(['disk' => 'public'])->save();

        $this->artisan('media:migrate-private-cvs', ['--dry-run' => true])->assertSuccessful();
        $this->assertSame('public', $media->fresh()->disk);
        $this->assertFalse(Storage::disk('local')->exists($path));
        $this->assertTrue(Storage::disk('public')->exists($path));

        $this->artisan('media:migrate-private-cvs')->assertSuccessful();

        $media->refresh();
        $this->assertSame('local', $media->disk);
        $this->assertTrue(Storage::disk('local')->exists($path));
        $this->assertSame($contents, Storage::disk('local')->get($path));
        // The original is retained by design.
        $this->assertTrue(Storage::disk('public')->exists($path));
    }

    public function test_private_cv_migration_reports_missing_source_files(): void
    {
        Storage::fake('public');
        Storage::fake('local');

        $owner = $this->member();
        $profile = $this->profile($owner);
        $media = $this->uploadCv($profile, false);
        $path = $media->getPathRelativeToRoot();

        Storage::disk('local')->delete($path);
        $media->forceFill(['disk' => 'public'])->save();

        $this->artisan('media:migrate-private-cvs')
            ->expectsOutputToContain('source file missing')
            ->assertFailed();

        $this->assertSame('public', $media->fresh()->disk);
    }

    public function test_new_uploads_use_private_disk_and_upload_replace_delete_flow_remains_available(): void
    {
        $owner = $this->member();
        $profile = $this->profile($owner);
        $token = $owner->createToken('test')->plainTextToken;

        $first = $this->using($token)->post('/api/profile/cv', [
            'cv' => UploadedFile::fake()->create('first.pdf', 40, 'application/pdf'),
            'visible' => false,
        ])->assertOk()->assertJsonPath('cv.visible', false);

        $firstId = $first->json('cv.id');
        $this->assertNotNull($firstId);
        $this->assertArrayNotHasKey('url', $first->json('cv'));
        $this->assertSame('local', $profile->fresh()->getMedia('cv')->first()->disk);

        $second = $this->using($token)->post('/api/profile/cv', [
            'cv' => UploadedFile::fake()->create('second.pdf', 40, 'application/pdf'),
            'visible' => true,
        ])->assertOk()->assertJsonPath('cv.visible', true);

        $this->assertSame(2, $profile->fresh()->getMedia('cv')->count());

        $this->using($token)
            ->deleteJson("/api/profile/cv/{$second->json('cv.id')}")
            ->assertOk();

        $this->assertSame(1, $profile->fresh()->getMedia('cv')->count());
        $this->assertNotNull($profile->fresh()->getMedia('cv')->first());
    }
}
