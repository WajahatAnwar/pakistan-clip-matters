<?php

namespace Tests\Feature;

use App\Jobs\ProcessVideoJob;
use App\Models\User;
use App\Models\Video;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class VideoManualTagsCompletionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['scout.driver' => 'null']);
    }

    public function test_manual_tags_mark_a_failed_video_as_completed(): void
    {
        $admin = $this->admin();
        $video = $this->video($admin, [
            'processing_status' => 'failed',
            'processing_error' => 'No spoken audio detected',
            'has_audio' => false,
            'approval_status' => 'approved',
        ]);

        $response = $this->actingAs($admin)->postJson(
            route('admin.video.approval.tags.save', $video),
            ['tags' => ['Drone footage', 'Underpass']]
        );

        $response->assertOk()
            ->assertJson([
                'processing_status' => 'completed',
                'completed_from_manual_tags' => true,
                'tags' => ['Drone footage', 'Underpass'],
            ]);

        $video->refresh();

        $this->assertSame('completed', $video->processing_status);
        $this->assertNull($video->processing_error);
        $this->assertNotNull($video->processing_completed_at);
        $this->assertDatabaseHas('video_tags', [
            'video_id' => $video->id,
            'normalized_tag' => 'drone footage',
        ]);
    }

    public function test_empty_tags_do_not_complete_a_failed_video(): void
    {
        $admin = $this->admin();
        $video = $this->video($admin, [
            'processing_status' => 'failed',
            'processing_error' => 'No spoken audio detected',
            'has_audio' => false,
        ]);

        $response = $this->actingAs($admin)->postJson(
            route('admin.video.approval.tags.save', $video),
            ['tags' => []]
        );

        $response->assertOk()
            ->assertJson([
                'processing_status' => 'failed',
                'completed_from_manual_tags' => false,
                'tags' => [],
            ]);

        $this->assertSame('failed', $video->fresh()->processing_status);
    }

    public function test_manual_tags_do_not_complete_a_pending_video(): void
    {
        $admin = $this->admin();
        $video = $this->video($admin, ['processing_status' => 'pending']);

        $response = $this->actingAs($admin)->postJson(
            route('admin.video.approval.tags.save', $video),
            ['tags' => ['Needs review']]
        );

        $response->assertOk()
            ->assertJson([
                'processing_status' => 'pending',
                'completed_from_manual_tags' => false,
            ]);

        $this->assertSame('pending', $video->fresh()->processing_status);
    }

    public function test_stale_processing_retry_does_not_reopen_a_manually_completed_video(): void
    {
        $user = User::factory()->create(['status' => true]);
        $video = $this->video($user, [
            'processing_status' => 'completed',
            'processing_error' => null,
            'processing_completed_at' => now(),
            'has_audio' => false,
        ]);

        $job = new ProcessVideoJob(
            $user->id,
            $video->id,
            $video->dropbox_path,
            $video->title,
            $video->description,
        );

        $job->handle();

        $this->assertSame('completed', $video->fresh()->processing_status);
    }

    private function admin(): User
    {
        $admin = User::factory()->create(['status' => true]);
        Role::findOrCreate('admin', 'web');
        $admin->assignRole('admin');

        return $admin;
    }

    private function video(User $user, array $attributes = []): Video
    {
        return Video::create(array_merge([
            'user_id' => $user->id,
            'dropbox_path' => '/test/video.mp4',
            'filename' => 'video.mp4',
            'title' => 'Test video',
            'processing_status' => 'pending',
        ], $attributes));
    }
}
