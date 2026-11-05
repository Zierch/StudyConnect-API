<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ChecksClassAccess;
use App\Models\Assignment;
use App\Models\Classes;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Storage;
use Validator;

class AssignmentController extends Controller
{
    use ChecksClassAccess;

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request, $class_id)
    {
        $user = auth()->user();
        if ($user->role !== 'teacher') {
            return response()->json(['message' => 'Hanya guru yang dapat membuat tugas'], 403);
        }

        $this->assertTeacherOwnsClass(Classes::findOrFail($class_id));

        // Validasi awal
        $validator = Validator::make($request->all(), [
            'title' => 'required|string|max:100',
            'description' => 'nullable|string',
            'attachment' => 'nullable|file|mimes:jpeg,png,jpg,pdf,docx|max:8192',
            'due_date' => 'nullable|date_format:Y-m-d H:i',
            'max_score' => 'required|integer|min:0|max:100',
            'scheduled_at' => 'nullable|date_format:Y-m-d H:i',
            'type' => 'required|in:essay,multiple_choice,file_upload',
            'options' => 'nullable|string',
            'link_url' => 'nullable|url',
        ]);

        // Tambahkan validasi kondisional
        $validator->after(function ($validator) use ($request) {

            // Jika type adalah 'multiple_choice', options wajib diisi
            if ($request->type === 'multiple_choice' && (!$request->options || count(explode(',', $request->options)) < 2 || count(explode(',', $request->options)) > 4)) {
                $validator->errors()->add('options', 'Options harus memiliki 2-4 pilihan untuk tipe multiple_choice.');
            }
        });

        // Jika validasi gagal, kembalikan respon error
        if ($validator->fails()) {
            return response()->json($validator->errors(), 422);
        }

        // Simpan attachment jika ada
        $attachmentPath = null;
        if ($request->hasFile('attachment')) {
            $attachment = $request->file('attachment');
            $attachmentName = time() . '_' . $attachment->getClientOriginalName();
            $attachmentPath = $attachment->storeAs('attachments', $attachmentName, 'public');
        }

        // Simpan data tugas
        $assignment = Assignment::create([
            'title' => $request->title,
            'description' => $request->description,
            'attachment_path' => $attachmentPath,
            'due_date' => $request->due_date,
            'max_score' => $request->max_score,
            'class_id' => $class_id,
            'teacher_id' => $user->id,
            'status' => $request->scheduled_at ? 'scheduled' : 'published',
            'type' => $request->type,
            'link_url' => $request->link_url,
        ]);

        if ($request->type === 'multiple_choice') {
            // Ubah string options menjadi array
            $optionsArray = array_map('trim', explode(',', $request->options));

            // Simpan sebagai JSON di database
            $assignment->options = json_encode($optionsArray);
            $assignment->save();
        } else {
            $assignment->options = null;
            $assignment->save();
        }

        // Atur jadwal jika ada
        if ($request->scheduled_at) {
            $assignment->status = 'scheduled';
            $assignment->scheduled_at = Carbon::createFromFormat('Y-m-d H:i', $request->scheduled_at);
            $assignment->save();
        }

        return response()->json($assignment, 201);
    }

    /**
     * Display the specified resource.
     */
    public function show($class_id)
    {
        $user = auth()->user();
        $this->assertCanViewClass(Classes::findOrFail($class_id));

        if ($user->role === 'student') {
            $assignments = Assignment::where('class_id', $class_id)
                ->where('status', 'published')
                ->get();
        } else {
            $assignments = Assignment::where('class_id', $class_id)->latest()->get();
        }
        return response()->json($assignments);
    }

    
    /**
     * Display the specified resource.
     */
    public function getById($id)
    {
        $user = auth()->user();
        $assignment = Assignment::find($id);
        if (!$assignment) {
            return response()->json(['message' => 'Tugas tidak ditemukan'], 404);
        }

        // Guru pemilik dan siswa anggota sama-sama boleh membuka detail tugas.
        $this->assertCanViewClass(Classes::findOrFail($assignment->class_id));

        return response()->json($assignment);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, $id)
    {
        $user = auth()->user();
        if ($user->role !== 'teacher') {
            return response()->json(['message' => 'Hanya guru yang dapat mengedit tugas'], 403);
        }

        $assignment = Assignment::find($id);
        if (!$assignment) {
            return response()->json(['message' => 'Tugas tidak ditemukan'], 404);
        }

        $this->assertTeacherOwnsClass(Classes::findOrFail($assignment->class_id));

        // Validasi awal
        $validator = Validator::make($request->all(), [
            'title' => 'required|string|max:100',
            'description' => 'nullable|string',
            'attachment' => 'nullable|file|mimes:jpeg,png,jpg,pdf,docx|max:8192',
            'due_date' => 'nullable|date_format:Y-m-d H:i',
            'max_score' => 'required|integer|min:0|max:100',
            'type' => 'required|in:essay,multiple_choice,file_upload',
            'options' => 'nullable|string',
            'link_url' => 'nullable|url'
        ]);

        // Tambahkan validasi kondisional
        $validator->after(function ($validator) use ($request) {

            // Jika type adalah 'multiple_choice', options wajib diisi
            if ($request->type === 'multiple_choice' && (!$request->options || count(explode(',', $request->options)) < 2 || count(explode(',', $request->options)) > 4)) {
                $validator->errors()->add('options', 'Options harus memiliki 2-4 pilihan untuk tipe multiple_choice.');
            }
        });

        // Jika validasi gagal, kembalikan respon error
        if ($validator->fails()) {
            return response()->json($validator->errors(), 422);
        }

        // Hapus attachment lama jika ada file baru diunggah
        if ($request->hasFile('attachment')) {
            if ($assignment->attachment_path) {
                Storage::disk('public')->delete($assignment->attachment_path);
            }

            $attachment = $request->file('attachment');
            $attachmentName = time() . '_' . $attachment->getClientOriginalName();
            $attachmentPath = $attachment->storeAs('attachments', $attachmentName, 'public');
            $assignment->attachment_path = $attachmentPath;
        }

        if ($request->type === 'multiple_choice') {
            // Ubah string options menjadi array
            $optionsArray = array_map('trim', explode(',', $request->options));

            // Simpan sebagai JSON di database
            $assignment->options = json_encode($optionsArray);
        } else {
            $assignment->options = null;
        }

        // Update data tugas
        $assignment->title = $request->title;
        $assignment->description = $request->description;
        $assignment->due_date = $request->due_date;
        $assignment->max_score = $request->max_score;
        $assignment->type = $request->type;
        $assignment->link_url = $request->link_url;
        $assignment->save();

        return response()->json($assignment);
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy($id)
    {
        $user = auth()->user();
        if ($user->role !== 'teacher') {
            return response()->json(['message' => 'Hanya guru yang dapat menghapus tugas'], 403);
        }

        $assignment = Assignment::find($id);
        if (!$assignment) {
            return response()->json(['message' => 'Tugas tidak ditemukan'], 404);
        }

        $this->assertTeacherOwnsClass(Classes::findOrFail($assignment->class_id));

        // Hapus file yang terupload
        if ($assignment->attachment_path) {
            Storage::disk('public')->delete($assignment->attachment_path);
        }

        $assignment->delete();
        return response()->json(['message' => 'Tugas berhasil dihapus']);
    }
}
