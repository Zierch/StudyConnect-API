<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ChecksClassAccess;
use App\Models\Classes;
use App\Models\Material;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Storage;
use Validator;

class MaterialController extends Controller
{
    use ChecksClassAccess;

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request, $class_id)
    {
        $user = auth()->user();
        if ($user->role !== 'teacher') {
            return response()->json(['message' => 'Hanya guru yang dapat membuat materi'], 403);
        }

        $this->assertTeacherOwnsClass(Classes::findOrFail($class_id));

        $validator = Validator::make($request->all(), [
            'title' => 'required|string|max:100',
            'description' => 'nullable|string',
            'attachment' => 'nullable|file|mimes:jpeg,png,jpg,pdf,docx|max:8192',
            'scheduled_at' => 'nullable|date_format:Y-m-d H:i',
            'link_url' => 'nullable|url',
        ]);

        if ($validator->fails()) {
            return response()->json($validator->errors(), 422);
        }

        $material = Material::create([
            'title' => $request->title,
            'description' => $request->description,
            'class_id' => $class_id,
            'teacher_id' => $user->id,
            'status' => $request->scheduled_at ? 'scheduled' : 'published',
            'link_url' => $request->link_url,
        ]);

        $attachment = $request->file('attachment');
        if ($attachment) {
            $attachmentName = time() . '_' . $attachment->getClientOriginalName();
            $attachmentPath = $attachment->storeAs('attachments', $attachmentName, 'public');
            $material->attachment_path = $attachmentPath;
            $material->save();
        }
        if ($request->scheduled_at) {
            $material->scheduled_at = Carbon::createFromFormat('Y-m-d H:i', $request->scheduled_at);
            $material->save();
        }

        return response()->json($material, 201);
    }

    /**
     * Display the specified resource.
     */
    public function show($class_id)
    {
        $this->assertCanViewClass(Classes::findOrFail($class_id));

        $materials = Material::where('class_id', $class_id)->latest()->get();
        return response()->json($materials);
    }

    /**
     * Get the specified resource by id.
     */
    public function getById($id)
    {
        $material = Material::find($id);
        if (!$material) {
            return response()->json(['message' => 'Materi tidak ditemukan'], 404);
        }

        $this->assertCanViewClass(Classes::findOrFail($material->class_id));

        return response()->json($material);
    }


    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, $id)
    {
        $user = auth()->user();
        if ($user->role !== 'teacher') {
            return response()->json(['message' => 'Hanya guru yang dapat mengedit materi'], 403);
        }

        $material = Material::find($id);
        if (!$material) {
            return response()->json(['message' => 'Materi tidak ditemukan'], 404);
        }

        $this->assertTeacherOwnsClass(Classes::findOrFail($material->class_id));

        $validator = Validator::make($request->all(), [
            'title' => 'required|string|max:100',
            'description' => 'nullable|string',
            'attachment' => 'nullable|file|mimes:jpeg,png,jpg,pdf,docx|max:8192',
            'link_url' => 'nullable|url',
        ]);

        if ($validator->fails()) {
            return response()->json($validator->errors(), 422);
        }

        if ($request->hasFile('attachment')) {
            // Hapus attachment yang lama
            if ($material->attachment_path) {
                Storage::disk('public')->delete($material->attachment_path);

            }

            $attachment = $request->file('attachment');
            $attachmentName = time() . '_' . $attachment->getClientOriginalName();
            $attachmentPath = $attachment->storeAs('attachments', $attachmentName, 'public');
            $material->attachment_path = $attachmentPath;
        }

        $material->title = $request->title;
        $material->description = $request->description;
        $material->link_url = $request->link_url;
        $material->save();

        return response()->json($material);
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy($id)
    {
        $user = auth()->user();
        if ($user->role !== 'teacher') {
            return response()->json(['message' => 'Hanya guru yang dapat menghapus materi'], 403);
        }

        $materi = Material::find($id);
        if (!$materi) {
            return response()->json(['message' => 'Materi tidak ditemukan'], 404);
        }

        $this->assertTeacherOwnsClass(Classes::findOrFail($materi->class_id));

        // Hapus file yang terupload
        if ($materi->attachment_path) {
            Storage::disk('public')->delete($materi->attachment_path);
        }

        $materi->delete();
        return response()->json(['message' => 'Materi berhasil dihapus']);
    }
}

