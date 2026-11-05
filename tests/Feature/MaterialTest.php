<?php

namespace Tests\Feature;

use App\Models\Classes;
use App\Models\Material;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Materi kelas: hanya guru yang menulis, jadwal terbit lewat scheduler.
 */
class MaterialTest extends TestCase
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
            'code' => 'matr0001',
        ]);
        // Siswa hanya boleh membaca kelas yang dia ikuti.
        $this->class->students()->attach($this->student->id);
    }

    public function test_teacher_uploads_a_material(): void
    {
        Sanctum::actingAs($this->teacher);

        $this->postJson('/api/material/' . $this->class->id, [
            'title' => 'Bab 3 Matriks',
            'description' => 'Pengantar matriks',
        ])->assertCreated()
            ->assertJsonPath('title', 'Bab 3 Matriks')
            ->assertJsonPath('status', 'published');
    }

    public function test_student_cannot_upload_a_material(): void
    {
        Sanctum::actingAs($this->student);

        $this->postJson('/api/material/' . $this->class->id, ['title' => 'Palsu'])
            ->assertForbidden();
    }

    public function test_title_is_required_and_limited(): void
    {
        Sanctum::actingAs($this->teacher);

        $this->postJson('/api/material/' . $this->class->id, [])
            ->assertStatus(422)->assertJsonStructure(['title']);

        $this->postJson('/api/material/' . $this->class->id, ['title' => str_repeat('a', 101)])
            ->assertStatus(422)->assertJsonStructure(['title']);
    }

    public function test_link_url_must_be_valid(): void
    {
        Sanctum::actingAs($this->teacher);

        $this->postJson('/api/material/' . $this->class->id, [
            'title' => 'Materi',
            'link_url' => 'bukan-url',
        ])->assertStatus(422)->assertJsonStructure(['link_url']);

        $this->postJson('/api/material/' . $this->class->id, [
            'title' => 'Materi',
            'link_url' => 'https://contoh.test/materi',
        ])->assertCreated();
    }

    public function test_scheduled_material_is_published_by_the_scheduler(): void
    {
        $due = Material::create([
            'title' => 'Terbit sekarang',
            'class_id' => $this->class->id,
            'teacher_id' => $this->teacher->id,
            'scheduled_at' => now()->subMinute(),
            'status' => 'scheduled',
        ]);
        $later = Material::create([
            'title' => 'Terbit besok',
            'class_id' => $this->class->id,
            'teacher_id' => $this->teacher->id,
            'scheduled_at' => now()->addDay(),
            'status' => 'scheduled',
        ]);

        $this->artisan('app:publish-scheduled-data')->assertSuccessful();

        $this->assertSame('published', $due->fresh()->status);
        $this->assertSame('scheduled', $later->fresh()->status);
    }

    public function test_teacher_updates_and_deletes_a_material(): void
    {
        $material = Material::create([
            'title' => 'Judul lama',
            'class_id' => $this->class->id,
            'teacher_id' => $this->teacher->id,
            'status' => 'published',
        ]);

        Sanctum::actingAs($this->teacher);

        $this->putJson('/api/material/' . $material->id, ['title' => 'Judul baru'])
            ->assertOk()
            ->assertJsonPath('title', 'Judul baru');

        $this->deleteJson('/api/material/' . $material->id)->assertOk();
        $this->assertDatabaseCount('material', 0);
    }

    public function test_materials_are_listed_per_class(): void
    {
        $other = Classes::create([
            'name' => 'Kelas lain',
            'teacher_id' => $this->teacher->id,
            'code' => 'matr0002',
        ]);
        Material::create([
            'title' => 'Punya kelas ini',
            'class_id' => $this->class->id,
            'teacher_id' => $this->teacher->id,
            'status' => 'published',
        ]);
        Material::create([
            'title' => 'Punya kelas lain',
            'class_id' => $other->id,
            'teacher_id' => $this->teacher->id,
            'status' => 'published',
        ]);

        Sanctum::actingAs($this->student);

        $response = $this->getJson('/api/material/' . $this->class->id)->assertOk();
        $this->assertSame(['Punya kelas ini'], array_column($response->json(), 'title'));
    }
}
