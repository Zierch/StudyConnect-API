<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ChecksClassAccess;
use App\Models\Announcement;
use App\Models\Classes;
use Illuminate\Http\Request;
use Storage;
use Validator;

class AnnouncementController extends Controller
{
    use ChecksClassAccess;

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request, $class_id)
    {
        $user = auth()->user();
        if ($user->role !== 'teacher') {
            return response()->json(['message' => 'Hanya guru yang dapat membuat pengumuman'], 403);
        }

        $this->assertTeacherOwnsClass(Classes::findOrFail($class_id));

        $validator = Validator::make($request->all(), [
            'content' => 'required|string',
            'attachment' => 'nullable|file|mimes:jpeg,png,jpg,pdf,docx|max:8192',
            'scheduled_at' => 'nullable|date_format:Y-m-d H:i',
        ]);

        if ($validator->fails()) {
            return response()->json($validator->errors(), 422);
        }

        $attachmentPath = null;
        $attachment = $request->file('attachment');
        if ($attachment) {
            $attachmentName = time() . '_' . $attachment->getClientOriginalName();
            $attachmentPath = $attachment->storeAs('attachments', $attachmentName, 'public');
        }

        // Menentukan status berdasarkan keberadaan scheduled_at
        $status = $request->scheduled_at ? 'scheduled' : 'published';

        $announcement = Announcement::create([
            'content' => $request->content,
            'class_id' => $class_id,
            'teacher_id' => $user->id,
            'attachment_path' => $attachmentPath,
            'scheduled_at' => $request->scheduled_at,
            'status' => $status, 
        ]);

        return response()->json($announcement, 201);
    }

    /**
     * Display the specified resource.
     */
    public function show($class_id)
    {
        $this->assertCanViewClass(Classes::findOrFail($class_id));

        $announcement = Announcement::where('class_id', $class_id)->latest()->get();
        if (!$announcement) {
            return response()->json(['message' => 'Pengumuman tidak ditemukan'], 404);
        }
        return response()->json($announcement);
    }
    
    /**
     * Display the specified resource.
     */
    public function getById($id)
    {
        $announcement = Announcement::find($id);
        if (!$announcement) {
            return response()->json(['message' => 'Pengumuman tidak ditemukan'], 404);
        }

        // Detail boleh dibaca guru pemilik maupun siswa anggota kelas.
        $this->assertCanViewClass(Classes::findOrFail($announcement->class_id));
        return response()->json($announcement);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, $id)
    {
        $user = auth()->user();
        if ($user->role !== 'teacher') {
            return response()->json(['message' => 'Hanya guru yang dapat mengedit pengumuman'], 403);
        }

        $announcement = Announcement::find($id);
        if (!$announcement) {
            return response()->json(['message' => 'Pengumuman tidak ditemukan'], 404);
        }

        $this->assertTeacherOwnsClass(Classes::findOrFail($announcement->class_id));

        $validator = Validator::make($request->all(), [
            'content' => 'required|string',
            'attachment' => 'nullable|file|mimes:jpeg,png,jpg,pdf,docx|max:8192',
        ]);

        if ($validator->fails()) {
            return response()->json($validator->errors(), 422);
        }

        if ($request->hasFile('attachment')) {
            // Hapus attachment yang lama
            if ($announcement->attachment_path) {
                Storage::disk('public')->delete($announcement->attachment_path);

            }

            $attachment = $request->file('attachment');
            $attachmentName = time() . '_' . $attachment->getClientOriginalName();
            $attachmentPath = $attachment->storeAs('attachments', $attachmentName, 'public');
            $announcement->attachment_path = $attachmentPath;

        }

        $announcement->content = $request->content;
        $announcement->save();

        return response()->json($announcement);
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy($id)
    {
        $user = auth()->user();
        if ($user->role !== 'teacher') {
            return response()->json(['message' => 'Hanya guru yang dapat menghapus pengumuman'], 403);
        }

        $announcement = Announcement::find($id);
        if (!$announcement) {
            return response()->json(['message' => 'Pengumuman tidak ditemukan'], 404);
        }

        $this->assertTeacherOwnsClass(Classes::findOrFail($announcement->class_id));

        // Hapus file yang terupload
        if ($announcement->attachment_path) {
            Storage::disk('public')->delete($announcement->attachment_path);
        }

        $announcement->delete();
        return response()->json(['message' => 'Pengumuman berhasil dihapus']);
    }
}