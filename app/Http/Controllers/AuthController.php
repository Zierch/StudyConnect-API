<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;

class AuthController extends Controller
{
    public function register(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:50',
            'email' => 'required|string|email|max:50|unique:users',
            'role' => 'required|string|in:teacher,student',
            'password' => 'required|string|min:6',
        ]);

        if ($validator->fails()) {
            return response()->json($validator->errors(), 422);
        }

        $user = User::create([
            'name' => $request->name,
            'email' => $request->email,
            'role' => $request->role,
            'password' => Hash::make($request->password),
        ]);

        return response()->json($user, 201);
    }

    public function login(Request $request)
    {
        $credentials = $request->only('email', 'password');

        if (auth()->attempt($credentials)) {
            $user = auth()->user();
            $token = 'Bearer ' . $user->createToken('StudyConnect')->plainTextToken;

            return response()->json(['token' => $token, 'user_id' => $user->id, 'role' => $user->role], 200);
        }

        return response()->json(['message' => 'Invalid credentials'], 401);
    }
    
    public function logout(Request $request)
    {
        $request->user()->tokens()->delete();
        return response()->json(['message' => 'Logged out successfully'], 200);
    }

    public function getUserById($id)
    {
        $user = User::find($id);
        if (!$user) {
            return response()->json(['message' => 'User not found'], 404);
        }

        $requester = auth()->user();

        // Profil sendiri selalu boleh; selain itu hanya untuk orang di kelas yang sama.
        if ((int) $requester->id !== (int) $user->id && !$this->sharesAClass($requester, $user)) {
            return response()->json(['message' => 'Anda tidak memiliki izin untuk melihat pengguna ini'], 403);
        }

        return response()->json($user, 200);
    }

    /** Benar bila kedua pengguna berada di kelas yang sama (guru pengampu atau siswa anggota). */
    private function sharesAClass(User $a, User $b): bool
    {
        $classIds = function (User $user) {
            $taught = \App\Models\Classes::where('teacher_id', $user->id)->pluck('id');
            $joined = $user->classes()->pluck('classes.id');

            return $taught->merge($joined)->unique();
        };

        return $classIds($a)->intersect($classIds($b))->isNotEmpty();
    }
}