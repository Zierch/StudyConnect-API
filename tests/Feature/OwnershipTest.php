<?php

namespace Tests\Feature;

use App\Models\Announcement;
use App\Models\Assignment;
use App\Models\Classes;
use App\Models\Material;
use App\Models\Submission;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Batas kepemilikan lintas kelas.
 *
 * <p>Sebelum ini setiap controller hanya memeriksa peran, sehingga guru mana pun bisa
 * menulis, mengubah, menghapus, bahkan menilai di kelas guru lain, dan siswa bisa
 * membaca kelas yang tidak dia ikuti. Test di berkas ini mengunci perbaikannya.
 */
class OwnershipTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;
    private User $intruder;
    private User $student;
    private User $outsider;
    private Classes $class;

    protected function setUp(): void
    {
        parent::setUp();
        $this->owner = User::factory()->create(['role' => 'teacher']);
        $this->intruder = User::factory()->create(['role' => 'teacher']);
        $this->student = User::factory()->create(['role' => 'student']);
        $this->outsider = User::factory()->create(['role' => 'student']);

        $this->class = Classes::create([
            'name' => 'Matematika XI RPL 1',
            'teacher_id' => $this->owner->id,
            'code' => 'own00001',
        ]);
        $this->class->students()->attach($this->student->id);
    }

    public function test_other_teacher_cannot_create_assignments_materials_or_announcements(): void
    {
        Sanctum::actingAs($this->intruder);

        $this->postJson('/api/assignment/' . $this->class->id, [
            'title' => 'Disusupkan',
            'max_score' => 100,
            'type' => 'essay',
        ])->assertForbidden();

        $this->postJson('/api/material/' . $this->class->id, ['title' => 'Disusupkan'])
            ->assertForbidden();

        $this->postJson('/api/announcement/' . $this->class->id, ['content' => 'Disusupkan'])
            ->assertForbidden();

        $this->assertDatabaseCount('assignment', 0);
        $this->assertDatabaseCount('material', 0);
        $this->assertDatabaseCount('announcement', 0);
    }

    public function test_other_teacher_cannot_delete_foreign_records(): void
    {
        $assignment = Assignment::create([
            'title' => 'Punya orang',
            'class_id' => $this->class->id,
            'max_score' => 100,
            'type' => 'essay',
        ]);
        $material = Material::create([
            'title' => 'Punya orang',
            'class_id' => $this->class->id,
            'teacher_id' => $this->owner->id,
            'status' => 'published',
        ]);
        $announcement = Announcement::create([
            'class_id' => $this->class->id,
            'teacher_id' => $this->owner->id,
            'content' => 'Punya orang',
            'status' => 'published',
        ]);

        Sanctum::actingAs($this->intruder);

        $this->deleteJson('/api/assignment/' . $assignment->id)->assertForbidden();
        $this->deleteJson('/api/material/' . $material->id)->assertForbidden();
        $this->deleteJson('/api/announcement/' . $announcement->id)->assertForbidden();

        $this->assertDatabaseCount('assignment', 1);
        $this->assertDatabaseCount('material', 1);
        $this->assertDatabaseCount('announcement', 1);
    }

    public function test_other_teacher_cannot_update_foreign_records(): void
    {
        $assignment = Assignment::create([
            'title' => 'Judul asli',
            'class_id' => $this->class->id,
            'max_score' => 100,
            'type' => 'essay',
        ]);
        $material = Material::create([
            'title' => 'Judul asli',
            'class_id' => $this->class->id,
            'teacher_id' => $this->owner->id,
            'status' => 'published',
        ]);
        $announcement = Announcement::create([
            'class_id' => $this->class->id,
            'teacher_id' => $this->owner->id,
            'content' => 'Isi asli',
            'status' => 'published',
        ]);

        Sanctum::actingAs($this->intruder);

        $this->putJson('/api/assignment/' . $assignment->id, [
            'title' => 'Dibajak',
            'max_score' => 100,
            'type' => 'essay',
        ])->assertForbidden();
        $this->putJson('/api/material/' . $material->id, ['title' => 'Dibajak'])->assertForbidden();
        $this->putJson('/api/announcement/' . $announcement->id, ['content' => 'Dibajak'])
            ->assertForbidden();

        $this->assertSame('Judul asli', $assignment->fresh()->title);
        $this->assertSame('Judul asli', $material->fresh()->title);
        $this->assertSame('Isi asli', $announcement->fresh()->content);
    }

    public function test_other_teacher_cannot_score_a_submission(): void
    {
        $assignment = Assignment::create([
            'title' => 'Tugas',
            'class_id' => $this->class->id,
            'max_score' => 100,
            'type' => 'essay',
        ]);
        $submission = Submission::create([
            'assignment_id' => $assignment->id,
            'student_id' => $this->student->id,
            'submitted_at' => now(),
            'answer' => 'Jawaban',
            'status' => 'submitted',
        ]);

        Sanctum::actingAs($this->intruder);

        $this->putJson('/api/submission/' . $submission->id, ['score' => 10])->assertForbidden();

        $this->assertNull($submission->fresh()->score);
        $this->assertSame('submitted', $submission->fresh()->status);
    }

    public function test_owning_teacher_can_still_do_all_of_it(): void
    {
        $assignment = Assignment::create([
            'title' => 'Tugas',
            'class_id' => $this->class->id,
            'max_score' => 100,
            'type' => 'essay',
        ]);
        $submission = Submission::create([
            'assignment_id' => $assignment->id,
            'student_id' => $this->student->id,
            'submitted_at' => now(),
            'answer' => 'Jawaban',
            'status' => 'submitted',
        ]);

        Sanctum::actingAs($this->owner);

        $this->postJson('/api/assignment/' . $this->class->id, [
            'title' => 'Tugas baru',
            'max_score' => 100,
            'type' => 'essay',
        ])->assertCreated();
        $this->postJson('/api/material/' . $this->class->id, ['title' => 'Materi'])->assertCreated();
        $this->postJson('/api/announcement/' . $this->class->id, ['content' => 'Info'])
            ->assertCreated();
        $this->putJson('/api/submission/' . $submission->id, ['score' => 95])->assertOk();
    }

    public function test_student_cannot_read_a_class_they_did_not_join(): void
    {
        Assignment::create([
            'title' => 'Rahasia',
            'class_id' => $this->class->id,
            'max_score' => 100,
            'type' => 'essay',
            'status' => 'published',
        ]);
        Announcement::create([
            'class_id' => $this->class->id,
            'teacher_id' => $this->owner->id,
            'content' => 'Rahasia',
            'status' => 'published',
        ]);
        Material::create([
            'title' => 'Rahasia',
            'class_id' => $this->class->id,
            'teacher_id' => $this->owner->id,
            'status' => 'published',
        ]);

        Sanctum::actingAs($this->outsider);

        $this->getJson('/api/assignment/' . $this->class->id)->assertForbidden();
        $this->getJson('/api/announcement/' . $this->class->id)->assertForbidden();
        $this->getJson('/api/material/' . $this->class->id)->assertForbidden();
    }

    public function test_joined_student_can_read_the_class(): void
    {
        Assignment::create([
            'title' => 'Terbuka',
            'class_id' => $this->class->id,
            'max_score' => 100,
            'type' => 'essay',
            'status' => 'published',
        ]);
        Announcement::create([
            'class_id' => $this->class->id,
            'teacher_id' => $this->owner->id,
            'content' => 'Terbuka',
            'status' => 'published',
        ]);

        Sanctum::actingAs($this->student);

        $this->getJson('/api/assignment/' . $this->class->id)->assertOk();
        $this->getJson('/api/announcement/' . $this->class->id)->assertOk();
    }

    public function test_student_only_sees_their_own_submission(): void
    {
        $assignment = Assignment::create([
            'title' => 'Tugas',
            'class_id' => $this->class->id,
            'max_score' => 100,
            'type' => 'essay',
        ]);
        $this->class->students()->attach($this->outsider->id);
        Submission::create([
            'assignment_id' => $assignment->id,
            'student_id' => $this->student->id,
            'submitted_at' => now(),
            'answer' => 'Punya teman',
            'status' => 'submitted',
        ]);
        Submission::create([
            'assignment_id' => $assignment->id,
            'student_id' => $this->outsider->id,
            'submitted_at' => now(),
            'answer' => 'Punyaku',
            'status' => 'submitted',
        ]);

        Sanctum::actingAs($this->outsider);

        $response = $this->getJson('/api/assignment/' . $assignment->id . '/submission')->assertOk();
        $this->assertSame(['Punyaku'], array_column($response->json(), 'answer'));

        Sanctum::actingAs($this->owner);
        $this->assertCount(
            2,
            $this->getJson('/api/assignment/' . $assignment->id . '/submission')->json()
        );
    }

    public function test_student_cannot_read_another_students_submission(): void
    {
        $assignment = Assignment::create([
            'title' => 'Tugas',
            'class_id' => $this->class->id,
            'max_score' => 100,
            'type' => 'essay',
        ]);
        $submission = Submission::create([
            'assignment_id' => $assignment->id,
            'student_id' => $this->student->id,
            'submitted_at' => now(),
            'answer' => 'Punya teman',
            'status' => 'submitted',
        ]);
        $this->class->students()->attach($this->outsider->id);

        Sanctum::actingAs($this->outsider);

        $this->getJson('/api/submission/' . $submission->id . '/get')->assertForbidden();
    }

    public function test_student_cannot_submit_to_a_class_they_did_not_join(): void
    {
        $assignment = Assignment::create([
            'title' => 'Tugas',
            'class_id' => $this->class->id,
            'max_score' => 100,
            'type' => 'essay',
            'status' => 'published',
        ]);

        Sanctum::actingAs($this->outsider);

        $this->postJson('/api/assignment/' . $assignment->id . '/submission', [
            'answer' => 'Menyusup',
        ])->assertForbidden();

        $this->assertDatabaseCount('submission', 0);
    }

    public function test_other_teacher_cannot_update_or_delete_a_class(): void
    {
        Sanctum::actingAs($this->intruder);

        $this->putJson('/api/classes/' . $this->class->id, ['name' => 'Dibajak'])
            ->assertForbidden();
        $this->deleteJson('/api/classes/' . $this->class->id)->assertForbidden();

        $this->assertSame('Matematika XI RPL 1', $this->class->fresh()->name);
    }

    public function test_class_update_cannot_change_owner_code_or_status(): void
    {
        Sanctum::actingAs($this->owner);

        $this->putJson('/api/classes/' . $this->class->id, [
            'name' => 'Nama Baru',
            'teacher_id' => $this->intruder->id,
            'code' => 'hacked01',
            'status' => 'archived',
        ])->assertOk();

        $fresh = $this->class->fresh();
        $this->assertSame('Nama Baru', $fresh->name);
        $this->assertSame($this->owner->id, (int) $fresh->teacher_id);
        $this->assertSame('own00001', $fresh->code);
        $this->assertSame('active', $fresh->status);
    }

    public function test_outsider_cannot_read_class_metadata_or_join_code(): void
    {
        Sanctum::actingAs($this->outsider);

        $this->getJson('/api/classes/' . $this->class->id)->assertForbidden();
    }

    public function test_joined_student_can_read_class_metadata(): void
    {
        Sanctum::actingAs($this->student);

        $this->getJson('/api/classes/' . $this->class->id)
            ->assertOk()
            ->assertJsonPath('name', 'Matematika XI RPL 1');
    }

    public function test_user_profile_is_only_visible_to_self_or_classmates(): void
    {
        Sanctum::actingAs($this->outsider);
        $this->getJson('/api/user/' . $this->student->id)->assertForbidden();

        Sanctum::actingAs($this->student);
        $this->getJson('/api/user/' . $this->student->id)->assertOk();

        // Guru pemilik kelas satu kelas dengan siswanya.
        Sanctum::actingAs($this->owner);
        $this->getJson('/api/user/' . $this->student->id)->assertOk();

        Sanctum::actingAs($this->intruder);
        $this->getJson('/api/user/' . $this->student->id)->assertForbidden();
    }

    public function test_outsider_cannot_read_a_material_detail(): void
    {
        $material = Material::create([
            'title' => 'Rahasia',
            'class_id' => $this->class->id,
            'teacher_id' => $this->owner->id,
            'status' => 'published',
        ]);

        Sanctum::actingAs($this->outsider);
        $this->getJson('/api/material/' . $material->id . '/get')->assertForbidden();

        Sanctum::actingAs($this->student);
        $this->getJson('/api/material/' . $material->id . '/get')->assertOk();
    }

    public function test_joined_student_can_open_assignment_detail(): void
    {
        $assignment = Assignment::create([
            'title' => 'Detail',
            'class_id' => $this->class->id,
            'max_score' => 100,
            'type' => 'essay',
            'status' => 'published',
        ]);

        Sanctum::actingAs($this->student);
        $this->getJson('/api/assignment/' . $assignment->id . '/get')
            ->assertOk()
            ->assertJsonPath('title', 'Detail');

        Sanctum::actingAs($this->outsider);
        $this->getJson('/api/assignment/' . $assignment->id . '/get')->assertForbidden();
    }
}
