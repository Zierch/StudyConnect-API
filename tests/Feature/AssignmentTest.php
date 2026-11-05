<?php

namespace Tests\Feature;

use App\Models\Assignment;
use App\Models\Classes;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Tugas: validasi per tipe, penjadwalan, dan pemisahan tampilan guru/siswa.
 */
class AssignmentTest extends TestCase
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
            'name' => 'Matematika XI RPL 1',
            'teacher_id' => $this->teacher->id,
            'code' => 'asgn0001',
        ]);
        $this->class->students()->attach($this->student->id);
    }

    private function essayPayload(array $overrides = []): array
    {
        return array_merge([
            'title' => 'Latihan Soal Matriks',
            'description' => 'Halaman 45 nomor 1-10',
            'max_score' => 100,
            'type' => 'essay',
        ], $overrides);
    }

    public function test_teacher_creates_an_essay_assignment(): void
    {
        Sanctum::actingAs($this->teacher);

        $response = $this->postJson('/api/assignment/' . $this->class->id, $this->essayPayload());

        $response->assertCreated()
            ->assertJsonPath('title', 'Latihan Soal Matriks')
            ->assertJsonPath('type', 'essay')
            ->assertJsonPath('status', 'published');

        $this->assertDatabaseHas('assignment', [
            'class_id' => $this->class->id,
            'title' => 'Latihan Soal Matriks',
        ]);
    }

    public function test_student_cannot_create_an_assignment(): void
    {
        Sanctum::actingAs($this->student);

        $this->postJson('/api/assignment/' . $this->class->id, $this->essayPayload())
            ->assertForbidden();

        $this->assertDatabaseCount('assignment', 0);
    }

    public function test_assignment_requires_title_score_and_type(): void
    {
        Sanctum::actingAs($this->teacher);

        $this->postJson('/api/assignment/' . $this->class->id, [])
            ->assertStatus(422)
            ->assertJsonStructure(['title', 'max_score', 'type']);
    }

    public function test_multiple_choice_needs_two_to_four_options(): void
    {
        Sanctum::actingAs($this->teacher);

        $this->postJson('/api/assignment/' . $this->class->id, $this->essayPayload([
            'type' => 'multiple_choice',
            'options' => 'satu',
        ]))->assertStatus(422)->assertJsonStructure(['options']);

        $this->postJson('/api/assignment/' . $this->class->id, $this->essayPayload([
            'type' => 'multiple_choice',
            'options' => 'a,b,c,d,e',
        ]))->assertStatus(422)->assertJsonStructure(['options']);

        $this->postJson('/api/assignment/' . $this->class->id, $this->essayPayload([
            'type' => 'multiple_choice',
            'options' => 'a,b,c',
        ]))->assertCreated();
    }

    public function test_unknown_type_is_rejected(): void
    {
        Sanctum::actingAs($this->teacher);

        $this->postJson('/api/assignment/' . $this->class->id, $this->essayPayload([
            'type' => 'quiz',
        ]))->assertStatus(422)->assertJsonStructure(['type']);
    }

    public function test_max_score_must_be_between_0_and_100(): void
    {
        Sanctum::actingAs($this->teacher);

        $this->postJson('/api/assignment/' . $this->class->id, $this->essayPayload(['max_score' => 101]))
            ->assertStatus(422)->assertJsonStructure(['max_score']);

        $this->postJson('/api/assignment/' . $this->class->id, $this->essayPayload(['max_score' => -1]))
            ->assertStatus(422)->assertJsonStructure(['max_score']);
    }

    public function test_due_date_must_follow_the_expected_format(): void
    {
        Sanctum::actingAs($this->teacher);

        $this->postJson('/api/assignment/' . $this->class->id, $this->essayPayload([
            'due_date' => '2026-11-10',
        ]))->assertStatus(422)->assertJsonStructure(['due_date']);

        $this->postJson('/api/assignment/' . $this->class->id, $this->essayPayload([
            'due_date' => '2026-11-10 23:59',
        ]))->assertCreated();
    }

    public function test_scheduled_assignment_is_hidden_from_students_until_published(): void
    {
        Assignment::create([
            'title' => 'Terbit nanti',
            'class_id' => $this->class->id,
            'max_score' => 100,
            'type' => 'essay',
            'scheduled_at' => now()->addDay(),
            'status' => 'scheduled',
        ]);
        Assignment::create([
            'title' => 'Sudah terbit',
            'class_id' => $this->class->id,
            'max_score' => 100,
            'type' => 'essay',
            'status' => 'published',
        ]);

        Sanctum::actingAs($this->student);
        $studentView = $this->getJson('/api/assignment/' . $this->class->id)->assertOk();
        $this->assertSame(['Sudah terbit'], array_column($studentView->json(), 'title'));

        Sanctum::actingAs($this->teacher);
        $teacherView = $this->getJson('/api/assignment/' . $this->class->id)->assertOk();
        $this->assertCount(2, $teacherView->json());
    }

    public function test_scheduler_publishes_due_scheduled_assignments(): void
    {
        $due = Assignment::create([
            'title' => 'Jatuh tempo',
            'class_id' => $this->class->id,
            'max_score' => 100,
            'type' => 'essay',
            'scheduled_at' => now()->subMinute(),
            'status' => 'scheduled',
        ]);
        $later = Assignment::create([
            'title' => 'Masih nanti',
            'class_id' => $this->class->id,
            'max_score' => 100,
            'type' => 'essay',
            'scheduled_at' => now()->addDay(),
            'status' => 'scheduled',
        ]);

        $this->artisan('app:publish-scheduled-data')->assertSuccessful();

        $this->assertSame('published', $due->fresh()->status);
        $this->assertSame('scheduled', $later->fresh()->status);
    }

    public function test_teacher_updates_and_deletes_an_assignment(): void
    {
        $assignment = Assignment::create([
            'title' => 'Judul lama',
            'class_id' => $this->class->id,
            'max_score' => 100,
            'type' => 'essay',
        ]);

        Sanctum::actingAs($this->teacher);

        $this->putJson('/api/assignment/' . $assignment->id, $this->essayPayload([
            'title' => 'Judul baru',
            'max_score' => 50,
        ]))->assertOk()->assertJsonPath('title', 'Judul baru');

        $this->assertSame(50, $assignment->fresh()->max_score);

        $this->deleteJson('/api/assignment/' . $assignment->id)->assertOk();
        $this->assertDatabaseCount('assignment', 0);
    }

    public function test_updating_a_missing_assignment_returns_404(): void
    {
        Sanctum::actingAs($this->teacher);

        $this->putJson('/api/assignment/999', $this->essayPayload())->assertNotFound();
    }
}
