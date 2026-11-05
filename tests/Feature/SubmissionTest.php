<?php

namespace Tests\Feature;

use App\Models\Assignment;
use App\Models\Classes;
use App\Models\Submission;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Pengumpulan tugas dan penilaian.
 */
class SubmissionTest extends TestCase
{
    use RefreshDatabase;

    private User $teacher;
    private User $student;
    private Classes $class;
    private Assignment $assignment;

    protected function setUp(): void
    {
        parent::setUp();
        $this->teacher = User::factory()->create(['role' => 'teacher']);
        $this->student = User::factory()->create(['role' => 'student']);
        $this->class = Classes::create([
            'name' => 'Kelas',
            'teacher_id' => $this->teacher->id,
            'code' => 'subm0001',
        ]);
        $this->class->students()->attach($this->student->id);
        $this->assignment = Assignment::create([
            'title' => 'Latihan Soal',
            'class_id' => $this->class->id,
            'max_score' => 100,
            'type' => 'essay',
            'status' => 'published',
        ]);
    }

    public function test_student_submits_an_essay_answer(): void
    {
        Sanctum::actingAs($this->student);

        $this->postJson('/api/assignment/' . $this->assignment->id . '/submission', [
            'answer' => 'Jawaban saya.',
        ])->assertCreated()
            ->assertJsonPath('status', 'submitted')
            ->assertJsonPath('answer', 'Jawaban saya.');

        $this->assertDatabaseHas('submission', [
            'assignment_id' => $this->assignment->id,
            'student_id' => $this->student->id,
        ]);
    }

    public function test_teacher_cannot_submit(): void
    {
        Sanctum::actingAs($this->teacher);

        $this->postJson('/api/assignment/' . $this->assignment->id . '/submission', [
            'answer' => 'Bukan tugas saya',
        ])->assertForbidden();
    }

    public function test_answer_is_required_for_essay(): void
    {
        Sanctum::actingAs($this->student);

        $this->postJson('/api/assignment/' . $this->assignment->id . '/submission', [])
            ->assertStatus(422)
            ->assertJsonStructure(['answer']);
    }

    public function test_file_upload_assignment_requires_an_attachment(): void
    {
        $this->assignment->type = 'file_upload';
        $this->assignment->save();

        Sanctum::actingAs($this->student);

        $this->postJson('/api/assignment/' . $this->assignment->id . '/submission', [
            'answer' => 'teks tidak cukup',
        ])->assertStatus(422)->assertJsonStructure(['attachment']);
    }

    public function test_submitting_to_a_missing_assignment_returns_404(): void
    {
        Sanctum::actingAs($this->student);

        $this->postJson('/api/assignment/999/submission', ['answer' => 'x'])
            ->assertNotFound();
    }

    public function test_teacher_scores_a_submission(): void
    {
        $submission = Submission::create([
            'assignment_id' => $this->assignment->id,
            'student_id' => $this->student->id,
            'submitted_at' => now(),
            'answer' => 'Jawaban',
            'status' => 'submitted',
        ]);

        Sanctum::actingAs($this->teacher);

        $this->putJson('/api/submission/' . $submission->id, ['score' => 90])
            ->assertOk()
            ->assertJsonPath('score', 90)
            ->assertJsonPath('status', 'graded');
    }

    public function test_student_cannot_score(): void
    {
        $submission = Submission::create([
            'assignment_id' => $this->assignment->id,
            'student_id' => $this->student->id,
            'submitted_at' => now(),
            'answer' => 'Jawaban',
            'status' => 'submitted',
        ]);

        Sanctum::actingAs($this->student);

        $this->putJson('/api/submission/' . $submission->id, ['score' => 100])
            ->assertForbidden();

        $this->assertNull($submission->fresh()->score);
    }

    public function test_score_must_be_between_0_and_100(): void
    {
        $submission = Submission::create([
            'assignment_id' => $this->assignment->id,
            'student_id' => $this->student->id,
            'submitted_at' => now(),
            'answer' => 'Jawaban',
            'status' => 'submitted',
        ]);

        Sanctum::actingAs($this->teacher);

        $this->putJson('/api/submission/' . $submission->id, ['score' => 101])
            ->assertStatus(422)->assertJsonStructure(['score']);

        $this->putJson('/api/submission/' . $submission->id, ['score' => -5])
            ->assertStatus(422)->assertJsonStructure(['score']);
    }

    public function test_scoring_a_missing_submission_returns_404(): void
    {
        Sanctum::actingAs($this->teacher);

        $this->putJson('/api/submission/999', ['score' => 80])->assertNotFound();
    }

    public function test_submissions_are_listed_for_an_assignment(): void
    {
        Submission::create([
            'assignment_id' => $this->assignment->id,
            'student_id' => $this->student->id,
            'submitted_at' => now(),
            'answer' => 'Jawaban',
            'status' => 'submitted',
        ]);

        Sanctum::actingAs($this->teacher);

        $response = $this->getJson('/api/assignment/' . $this->assignment->id . '/submission')->assertOk();
        $this->assertCount(1, $response->json());
    }
}
