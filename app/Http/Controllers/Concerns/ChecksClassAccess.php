<?php

namespace App\Http\Controllers\Concerns;

use App\Models\Classes;
use Illuminate\Http\Exceptions\HttpResponseException;

/**
 * Aturan akses per kelas.
 *
 * <p>Guru hanya boleh mengubah kelas yang dia ampu; siswa hanya boleh membaca kelas
 * yang dia ikuti. Sebelumnya setiap controller hanya memeriksa peran, sehingga guru
 * mana pun bisa menulis ke kelas guru lain.
 */
trait ChecksClassAccess
{
    /** Guru pemilik kelas; selain itu 403. */
    protected function assertTeacherOwnsClass(Classes $class): void
    {
        $user = auth()->user();

        if (!$user || $user->role !== 'teacher' || (int) $class->teacher_id !== (int) $user->id) {
            $this->deny('Anda tidak memiliki izin untuk mengubah kelas ini');
        }
    }

    /** Guru pemilik atau siswa anggota; selain itu 403. */
    protected function assertCanViewClass(Classes $class): void
    {
        $user = auth()->user();

        if (!$user) {
            $this->deny('Anda tidak memiliki izin untuk melihat kelas ini');
        }

        if ($user->role === 'teacher' && (int) $class->teacher_id === (int) $user->id) {
            return;
        }

        if ($user->role === 'student' && $class->students()->where('users.id', $user->id)->exists()) {
            return;
        }

        $this->deny('Anda tidak memiliki izin untuk melihat kelas ini');
    }

    private function deny(string $message): void
    {
        throw new HttpResponseException(response()->json(['message' => $message], 403));
    }
}
