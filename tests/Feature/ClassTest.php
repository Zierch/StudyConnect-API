<?php

namespace Tests\Feature;

use App\Models\Classes;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * CRUD kelas, keanggotaan, dan batas izin guru vs siswa.
 */
class ClassTest extends TestCase
{
    use RefreshDatabase;

    private function teacher(): User
    {
        return User::factory()->create(['role' => 'teacher']);
    }

    private function student(): User
    {
        return User::factory()->create(['role' => 'student']);
    }

    public function test_teacher_can_create_a_class_with_a_generated_code(): void
    {
        Sanctum::actingAs($this->teacher());

        $response = $this->postJson('/api/classes', [
            'name' => 'Matematika XI RPL 1',
            'subject' => 'Matematika',
            'description' => 'Kelas semester ganjil',
        ]);

        $response->assertCreated()
            ->assertJsonPath('name', 'Matematika XI RPL 1');

        $this->assertSame(8, strlen($response->json('code')));
        // status diisi default kolom, jadi tidak muncul di respons create.
        $this->assertDatabaseHas('classes', ['name' => 'Matematika XI RPL 1', 'status' => 'active']);
    }

    public function test_student_cannot_create_a_class(): void
    {
        Sanctum::actingAs($this->student());

        $this->postJson('/api/classes', ['name' => 'Kelas Palsu'])
            ->assertForbidden();

        $this->assertDatabaseCount('classes', 0);
    }

    public function test_class_requires_a_name(): void
    {
        Sanctum::actingAs($this->teacher());

        $this->postJson('/api/classes', ['subject' => 'Matematika'])
            ->assertStatus(422)
            ->assertJsonStructure(['name']);
    }

    public function test_teacher_only_sees_their_own_active_classes(): void
    {
        $teacher = $this->teacher();
        $other = $this->teacher();
        Classes::create(['name' => 'Punyaku', 'teacher_id' => $teacher->id, 'code' => 'aaaa1111']);
        Classes::create(['name' => 'Punya Orang', 'teacher_id' => $other->id, 'code' => 'bbbb2222']);
        // status tidak mass-assignable, jadi diset langsung.
        $archived = Classes::create(['name' => 'Arsip', 'teacher_id' => $teacher->id, 'code' => 'cccc3333']);
        $archived->status = 'archived';
        $archived->save();

        Sanctum::actingAs($teacher);

        $response = $this->getJson('/api/classes')->assertOk();
        $this->assertSame(['Punyaku'], array_column($response->json(), 'name'));
    }

    public function test_status_cannot_be_mass_assigned_on_create(): void
    {
        $teacher = $this->teacher();

        // Kolom status punya default 'active'; mengirimkannya lewat create() tidak berpengaruh.
        $class = Classes::create([
            'name' => 'Coba',
            'teacher_id' => $teacher->id,
            'code' => 'zzzz0000',
            'status' => 'archived',
        ]);

        $this->assertSame('active', $class->fresh()->status);
    }

    public function test_student_sees_only_joined_classes(): void
    {
        $teacher = $this->teacher();
        $student = $this->student();
        $joined = Classes::create(['name' => 'Diikuti', 'teacher_id' => $teacher->id, 'code' => 'dddd4444']);
        Classes::create(['name' => 'Tidak Diikuti', 'teacher_id' => $teacher->id, 'code' => 'eeee5555']);
        $student->classes()->attach($joined->id);

        Sanctum::actingAs($student);

        $response = $this->getJson('/api/classes')->assertOk();
        $this->assertSame(['Diikuti'], array_column($response->json(), 'name'));
    }

    public function test_student_joins_and_leaves_by_code(): void
    {
        $teacher = $this->teacher();
        $student = $this->student();
        $class = Classes::create(['name' => 'Kelas', 'teacher_id' => $teacher->id, 'code' => 'ffff6666']);

        Sanctum::actingAs($student);

        $this->postJson('/api/classes/ffff6666/join')->assertOk();
        $this->assertDatabaseHas('class_student', ['class_id' => $class->id, 'student_id' => $student->id]);

        $this->postJson('/api/classes/ffff6666/join')->assertStatus(400);

        $this->postJson('/api/classes/ffff6666/leave')->assertOk();
        $this->assertDatabaseMissing('class_student', ['class_id' => $class->id, 'student_id' => $student->id]);
    }

    public function test_joining_with_unknown_code_fails(): void
    {
        Sanctum::actingAs($this->student());

        $this->postJson('/api/classes/tidakada/join')->assertNotFound();
    }

    public function test_teacher_cannot_join_a_class(): void
    {
        $teacher = $this->teacher();
        Classes::create(['name' => 'Kelas', 'teacher_id' => $teacher->id, 'code' => 'gggg7777']);

        Sanctum::actingAs($teacher);

        $this->postJson('/api/classes/gggg7777/join')->assertForbidden();
    }

    public function test_only_the_owning_teacher_can_list_students(): void
    {
        $owner = $this->teacher();
        $other = $this->teacher();
        $student = $this->student();
        $class = Classes::create(['name' => 'Kelas', 'teacher_id' => $owner->id, 'code' => 'hhhh8888']);
        $class->students()->attach($student->id);

        Sanctum::actingAs($owner);
        $response = $this->getJson('/api/classes/' . $class->id . '/students')->assertOk();
        $this->assertSame([$student->id], array_column($response->json(), 'id'));

        Sanctum::actingAs($other);
        $this->getJson('/api/classes/' . $class->id . '/students')->assertForbidden();

        Sanctum::actingAs($student);
        $this->getJson('/api/classes/' . $class->id . '/students')->assertForbidden();
    }

    public function test_archive_and_restore_move_the_class_between_lists(): void
    {
        $teacher = $this->teacher();
        $class = Classes::create(['name' => 'Kelas', 'teacher_id' => $teacher->id, 'code' => 'iiii9999']);
        Sanctum::actingAs($teacher);

        $this->postJson('/api/archive/' . $class->id)->assertOk();
        $this->assertSame('archived', $class->fresh()->status);
        $this->assertCount(0, $this->getJson('/api/classes')->json());
        $this->assertCount(1, $this->getJson('/api/archived-classes')->json());

        $this->postJson('/api/restore/' . $class->id)->assertOk();
        $this->assertSame('active', $class->fresh()->status);
        $this->assertCount(1, $this->getJson('/api/classes')->json());
    }

    public function test_teacher_cannot_archive_someone_elses_class(): void
    {
        $owner = $this->teacher();
        $class = Classes::create(['name' => 'Kelas', 'teacher_id' => $owner->id, 'code' => 'jjjj0000']);

        Sanctum::actingAs($this->teacher());

        $this->postJson('/api/archive/' . $class->id)->assertForbidden();
        $this->assertSame('active', $class->fresh()->status);
    }

    public function test_student_cannot_archive_a_class(): void
    {
        $teacher = $this->teacher();
        $class = Classes::create(['name' => 'Kelas', 'teacher_id' => $teacher->id, 'code' => 'kkkk1111']);

        Sanctum::actingAs($this->student());

        $this->postJson('/api/archive/' . $class->id)->assertForbidden();
    }

    public function test_only_the_owning_teacher_can_delete(): void
    {
        $owner = $this->teacher();
        $class = Classes::create(['name' => 'Kelas', 'teacher_id' => $owner->id, 'code' => 'llll2222']);

        Sanctum::actingAs($this->student());
        $this->deleteJson('/api/classes/' . $class->id)->assertForbidden();

        Sanctum::actingAs($owner);
        $this->deleteJson('/api/classes/' . $class->id)->assertOk();
        $this->assertDatabaseCount('classes', 0);
    }

    public function test_deleting_a_class_removes_its_memberships(): void
    {
        $teacher = $this->teacher();
        $student = $this->student();
        $class = Classes::create(['name' => 'Kelas', 'teacher_id' => $teacher->id, 'code' => 'mmmm3333']);
        $class->students()->attach($student->id);

        Sanctum::actingAs($teacher);
        $this->deleteJson('/api/classes/' . $class->id)->assertOk();

        $this->assertDatabaseCount('class_student', 0);
    }
}
