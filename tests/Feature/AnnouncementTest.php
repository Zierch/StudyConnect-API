<?php

namespace Tests\Feature;

use App\Models\Announcement;
use App\Models\Classes;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Pengumuman kelas: hanya guru yang boleh menulis, siswa boleh membaca.
 */
class AnnouncementTest extends TestCase
{
    use RefreshDatabase;

    private User $teacher;
    private User $student;
    private Classes $class;

    protected function setUp(): void
    {
        parent::setUp();
        $this->teacher = User::factory()->create(['role' => 'teacher']);
        $this->student = User::factory()->create(['role' => 'student']);
        $this->class = Classes::create([
            'name' => 'Kelas',
            'teacher_id' => $this->teacher->id,
            'code' => 'annc0001',
        ]);
        // Siswa hanya boleh membaca kelas yang dia ikuti.
        $this->class->students()->attach($this->student->id);
    }

    public function test_teacher_posts_an_announcement(): void
    {
        Sanctum::actingAs($this->teacher);

        $this->postJson('/api/announcement/' . $this->class->id, [
            'content' => 'Kuis bab 3 dilaksanakan hari Senin.',
        ])->assertCreated()
            ->assertJsonPath('status', 'published')
            ->assertJsonPath('content', 'Kuis bab 3 dilaksanakan hari Senin.');

        $this->assertDatabaseHas('announcement', [
            'class_id' => $this->class->id,
            'teacher_id' => $this->teacher->id,
        ]);
    }

    public function test_student_cannot_post_an_announcement(): void
    {
        Sanctum::actingAs($this->student);

        $this->postJson('/api/announcement/' . $this->class->id, ['content' => 'Halo'])
            ->assertForbidden();

        $this->assertDatabaseCount('announcement', 0);
    }

    public function test_content_is_required(): void
    {
        Sanctum::actingAs($this->teacher);

        $this->postJson('/api/announcement/' . $this->class->id, [])
            ->assertStatus(422)
            ->assertJsonStructure(['content']);
    }

    public function test_scheduled_announcement_is_created_as_scheduled(): void
    {
        Sanctum::actingAs($this->teacher);

        $this->postJson('/api/announcement/' . $this->class->id, [
            'content' => 'Besok libur',
            'scheduled_at' => now()->addDay()->format('Y-m-d H:i'),
        ])->assertCreated()->assertJsonPath('status', 'scheduled');
    }

    public function test_scheduled_at_rejects_a_bad_format(): void
    {
        Sanctum::actingAs($this->teacher);

        $this->postJson('/api/announcement/' . $this->class->id, [
            'content' => 'Besok libur',
            'scheduled_at' => 'besok pagi',
        ])->assertStatus(422)->assertJsonStructure(['scheduled_at']);
    }

    public function test_announcements_are_listed_newest_first(): void
    {
        $old = Announcement::create([
            'class_id' => $this->class->id,
            'teacher_id' => $this->teacher->id,
            'content' => 'Lama',
            'status' => 'published',
        ]);
        $old->created_at = now()->subDay();
        $old->save();

        Announcement::create([
            'class_id' => $this->class->id,
            'teacher_id' => $this->teacher->id,
            'content' => 'Baru',
            'status' => 'published',
        ]);

        Sanctum::actingAs($this->student);

        $response = $this->getJson('/api/announcement/' . $this->class->id)->assertOk();
        $this->assertSame(['Baru', 'Lama'], array_column($response->json(), 'content'));
    }

    public function test_teacher_updates_and_deletes_an_announcement(): void
    {
        $announcement = Announcement::create([
            'class_id' => $this->class->id,
            'teacher_id' => $this->teacher->id,
            'content' => 'Isi lama',
            'status' => 'published',
        ]);

        Sanctum::actingAs($this->teacher);

        $this->putJson('/api/announcement/' . $announcement->id, ['content' => 'Isi baru'])
            ->assertOk()
            ->assertJsonPath('content', 'Isi baru');

        $this->deleteJson('/api/announcement/' . $announcement->id)->assertOk();
        $this->assertDatabaseCount('announcement', 0);
    }

    public function test_get_by_id_returns_the_announcement(): void
    {
        $announcement = Announcement::create([
            'class_id' => $this->class->id,
            'teacher_id' => $this->teacher->id,
            'content' => 'Detail',
            'status' => 'published',
        ]);

        Sanctum::actingAs($this->student);

        $this->getJson('/api/announcement/' . $announcement->id . '/get')
            ->assertOk()
            ->assertJsonPath('content', 'Detail');
    }
}
