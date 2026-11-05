<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ChecksClassAccess;
use App\Models\Classes;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class ClassController extends Controller
{
    use ChecksClassAccess;

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:50',
            'subject' => 'nullable|string|max:100',
            'description' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return response()->json($validator->errors(), 422);
        }

        $user = auth()->user();
        if ($user->role !== 'teacher') {
            return response()->json(['message' => 'Hanya guru yang dapat membuat kelas'], 403);
        }

        $class = Classes::create([
            'name' => $request->name,
            'subject' => $request->subject,
            'description' => $request->description,
            'teacher_id' => $user->id,
            'code' => substr(str_shuffle($characters = 'abcdefghijklmnopqrstuvwxyz0123456789'), 0, 8),
        ]);

        return response()->json($class, 201);
    }

    /**
     * Display the specified resource.
     */
    public function show()
    {
        $user = auth()->user();
        if ($user->role == 'teacher') {
            $classes = Classes::where('teacher_id', $user->id)
                ->where('status', 'active')
                ->latest()
                ->get();
        } elseif ($user->role == 'student') {
            $classes = $user->classes()
                ->where('status', 'active')
                ->latest()
                ->get();
        } else {
            return response()->json(['message' => 'Invalid user role'], 403);
        }
        return response()->json($classes);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, string $id)
    {
        $user = auth()->user();
        if ($user->role !== 'teacher') {
            return response()->json(['message' => 'Hanya guru yang dapat mengedit kelas'], 403);
        }

        $class = Classes::findOrFail($id);
        $this->assertTeacherOwnsClass($class);

        $validator = Validator::make($request->all(), [
            'name' => 'sometimes|required|string|max:50',
            'subject' => 'nullable|string|max:100',
            'description' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return response()->json($validator->errors(), 422);
        }

        // Hanya kolom ini yang boleh diubah; teacher_id, code, dan status tidak lewat sini.
        $class->fill($request->only(['name', 'subject', 'description']));
        $class->save();

        return response()->json($class);
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(string $id)
    {
        $user = auth()->user();
        if ($user->role !== 'teacher') {
            return response()->json(['message' => 'Hanya guru yang dapat menghapus kelas'], 403);
        }

        $class = Classes::findOrFail($id);
        $this->assertTeacherOwnsClass($class);
        $class->delete();
        return response()->json(['message' => 'Class deleted successfully']);
    }

    /**
     * Join a class using the class code
     * .
     */
    public function join(Request $request, $code)
    {
        $class = Classes::where('code', $code)->first();
        if (!$class) {
            return response()->json(['message' => 'Kelas tidak ditemukan'], 404);
        }

        $user = auth()->user();
        if ($user->role !== 'student') {
            return response()->json(['message' => 'Hanya siswa yang dapat bergabung dengan kelas'], 403);
        }

        if ($user->classes()->where('class_id', $class->id)->exists()) {
            return response()->json(['message' => 'Anda sudah bergabung dengan kelas ini'], 400);
        }

        $user->classes()->attach($class->id);
        return response()->json(['message' => 'Anda telah bergabung dengan kelas ini']);
    }

    /**
     * Leave a class using the class code.
     * .
     */
    public function leave(Request $request, $code)
    {
        $class = Classes::where('code', $code)->first();
        if (!$class) {
            return response()->json(['message' => 'Kelas tidak ditemukan'], 404);
        }

        $user = auth()->user();
        if ($user->role !== 'student') {
            return response()->json(['message' => 'Hanya siswa yang dapat keluar dari kelas'], 403);
        }

        if (!$user->classes()->where('class_id', $class->id)->exists()) {
            return response()->json(['message' => 'Anda tidak bergabung dengan kelas ini'], 400);
        }

        $user->classes()->detach($class->id);
        return response()->json(['message' => 'Anda telah keluar dari kelas ini']);
    }

    /**
     * Get all students in a class.
     */
    public function getStudents($id)
    {
        $class = Classes::findOrFail($id);

        // Pastikan hanya guru dari kelas ini yang bisa mengakses daftar siswa
        $user = auth()->user();
        if ($user->role !== 'teacher' || $class->teacher_id !== $user->id) {
            return response()->json(['message' => 'Anda tidak memiliki izin untuk melihat siswa di kelas ini'], 403);
        }

        // Dapatkan semua siswa yang bergabung
        $students = $class->students()->get();

        return response()->json($students);
    }

    /**
     * Get a class by id.
     */
    public function getById($id)
    {
        $class = Classes::findOrFail($id);
        $this->assertCanViewClass($class);

        return response()->json($class);
    }

    /**
     * Get archived classes for the authenticated teacher.
     */
    public function getArchivedClasses()
    {
        $user = auth()->user();
        if ($user->role !== 'teacher') {
            return response()->json(['message' => 'Hanya guru yang dapat melihat kelas yang diarsipkan'], 403);
        }

        $archivedClasses = Classes::where('teacher_id', $user->id)
            ->where('status', 'archived')
            ->get();

        return response()->json($archivedClasses);
    }

    /**
     * Archive the specified class.
     */
    public function archive($id)
    {
        $user = auth()->user();
        if ($user->role !== 'teacher') {
            return response()->json(['message' => 'Hanya guru yang dapat mengarsipkan kelas'], 403);
        }

        $class = Classes::findOrFail($id);
        if ($class->teacher_id !== $user->id) {
            return response()->json(['message' => 'Anda tidak memiliki izin untuk mengarsipkan kelas ini'], 403);
        }

        $class->status = 'archived';
        $class->save();

        return response()->json(['message' => 'Kelas telah diarsipkan']);
    }

    /**
     * Restore the specified archived class.
     */
    public function restore($id)
    {
        $user = auth()->user();
        if ($user->role !== 'teacher') {
            return response()->json(['message' => 'Hanya guru yang dapat memulihkan kelas'], 403);
        }

        $class = Classes::findOrFail($id);
        if ($class->teacher_id !== $user->id) {
            return response()->json(['message' => 'Anda tidak memiliki izin untuk memulihkan kelas ini'], 403);
        }

        $class->status = 'active';
        $class->save();

        return response()->json(['message' => 'Kelas telah dipulihkan']);
    }

}
