<?php

namespace Tests\Feature;

use App\Models\Classes;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Perilaku autentikasi dan aturan peran pada endpoint /api.
 */
class AuthTest extends TestCase
{
    use RefreshDatabase;

    public function test_teacher_can_register(): void
    {
        $response = $this->postJson('/api/register', [
            'name' => 'Bu Sari',
            'email' => 'sari@sekolah.test',
            'role' => 'teacher',
            'password' => 'rahasia123',
        ]);

        $response->assertCreated()
            ->assertJsonPath('name', 'Bu Sari')
            ->assertJsonPath('role', 'teacher');

        $this->assertDatabaseHas('users', [
            'email' => 'sari@sekolah.test',
            'role' => 'teacher',
        ]);
    }

    public function test_register_rejects_invalid_role_and_duplicate_email(): void
    {
        $this->postJson('/api/register', [
            'name' => 'X',
            'email' => 'x@sekolah.test',
            'role' => 'admin',
            'password' => 'rahasia123',
        ])->assertStatus(422)->assertJsonStructure(['role']);

        User::factory()->create(['email' => 'ada@sekolah.test']);

        $this->postJson('/api/register', [
            'name' => 'Y',
            'email' => 'ada@sekolah.test',
            'role' => 'student',
            'password' => 'rahasia123',
        ])->assertStatus(422)->assertJsonStructure(['email']);
    }

    public function test_password_is_hashed_and_never_returned(): void
    {
        $this->postJson('/api/register', [
            'name' => 'Bu Sari',
            'email' => 'sari@sekolah.test',
            'role' => 'teacher',
            'password' => 'rahasia123',
        ])->assertCreated()->assertJsonMissingPath('password');

        $user = User::where('email', 'sari@sekolah.test')->firstOrFail();

        $this->assertNotSame('rahasia123', $user->password);
        $this->assertTrue(password_verify('rahasia123', $user->password));
    }

    public function test_login_returns_token_and_role(): void
    {
        User::factory()->create([
            'email' => 'sari@sekolah.test',
            'password' => bcrypt('rahasia123'),
            'role' => 'teacher',
        ]);

        $response = $this->postJson('/api/login', [
            'email' => 'sari@sekolah.test',
            'password' => 'rahasia123',
        ]);

        $response->assertOk()
            ->assertJsonPath('role', 'teacher')
            ->assertJsonStructure(['token', 'user_id', 'role']);

        $this->assertStringContainsString('Bearer', $response->json('token'));
    }

    public function test_login_rejects_wrong_password(): void
    {
        User::factory()->create([
            'email' => 'sari@sekolah.test',
            'password' => bcrypt('rahasia123'),
        ]);

        $this->postJson('/api/login', [
            'email' => 'sari@sekolah.test',
            'password' => 'salah',
        ])->assertUnauthorized();
    }

    public function test_protected_routes_require_a_token(): void
    {
        $this->getJson('/api/classes')->assertUnauthorized();
        $this->postJson('/api/classes', ['name' => 'Kelas'])->assertUnauthorized();
        $this->getJson('/api/archived-classes')->assertUnauthorized();
    }

    public function test_logout_revokes_the_token(): void
    {
        $user = User::factory()->create(['role' => 'teacher']);
        $token = $user->createToken('StudyConnect')->plainTextToken;

        $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson('/api/logout')
            ->assertOk();

        $this->assertSame(0, $user->tokens()->count());

        // Guard menyimpan user yang sudah terautentikasi antar request dalam satu test.
        $this->app['auth']->forgetGuards();

        $this->withHeader('Authorization', 'Bearer ' . $token)
            ->getJson('/api/classes')
            ->assertUnauthorized();
    }

    public function test_get_user_by_id_returns_the_profile(): void
    {
        $user = User::factory()->create(['name' => 'Bu Sari', 'role' => 'teacher']);
        Sanctum::actingAs($user);

        $this->getJson('/api/user/' . $user->id)
            ->assertOk()
            ->assertJsonPath('name', 'Bu Sari')
            ->assertJsonPath('role', 'teacher');
    }
}
