<?php

namespace App\Http\Controllers;

use App\Models\Assignment;
use App\Http\Controllers\Concerns\ChecksClassAccess;
use App\Models\Classes;
use App\Models\Submission;
use Illuminate\Http\Request;
use Validator;

class SubmissionController extends Controller
{
    use ChecksClassAccess;

    /**
     * Create a new submission.
     */
    public function store(Request $request, $assignment_id)
    {
        $user = auth()->user();
        if ($user->role !== 'student') {
            return response()->json(['message' => 'Hanya siswa yang dapat mengumpulkan submission'], 403);
        }

        // Ambil tipe tugas dari assignment
        $assignment = Assignment::find($assignment_id);
        if (!$assignment) {
            return response()->json(['message' => 'Tugas tidak ditemukan'], 404);
        }

        // Siswa hanya boleh mengumpulkan tugas dari kelas yang dia ikuti.
        $this->assertCanViewClass(Classes::findOrFail($assignment->class_id));

        // Tentukan validasi berdasarkan tipe tugas
        $validatorRules = [];

        switch ($assignment->type) {
            case 'file_upload':
                // Validasi untuk tugas dengan tipe file upload
                $validatorRules = [
                    'attachment' => 'required|file|mimes:jpeg,png,jpg,pdf,docx|max:8192',
                ];
                break;

            case 'essay':
            case 'multiple_choice':
                // Validasi untuk tugas dengan tipe essay atau multiple choice
                $validatorRules = [
                    'answer' => 'required|string',
                ];
                break;

            default:
                return response()->json(['message' => 'Tipe tugas tidak valid'], 400);
        }

        // Validasi data
        $validator = Validator::make($request->all(), $validatorRules);

        if ($validator->fails()) {
            return response()->json($validator->errors(), 422);
        }

        // Simpan file submission jika ada
        $attachmentPath = null;
        if ($request->hasFile('attachment')) {
            $attachment = $request->file('attachment');
            $attachmentName = time() . '_' . $attachment->getClientOriginalName();
            $attachmentPath = $attachment->storeAs('submissions', $attachmentName, 'public');
        }

        // Simpan submission
        $submission = Submission::create([
            'assignment_id' => $assignment_id,
            'student_id' => $user->id,
            'attachment_path' => $attachmentPath,
            'answer' => $request->answer,
            'link_url' => $request->link_url,
            'submitted_at' => now(),
            'status' => 'submitted',
        ]);

        return response()->json($submission, 201);
    }

    /**
     * Retrieve submissions for an assignment.
     */
    public function show($assignment_id)
    {
        $assignment = Assignment::find($assignment_id);
        if (!$assignment) {
            return response()->json(['message' => 'Tugas tidak ditemukan'], 404);
        }

        $user = auth()->user();
        $class = Classes::findOrFail($assignment->class_id);

        // Guru pemilik kelas melihat semua; siswa hanya pengumpulannya sendiri.
        if ($user->role === 'teacher') {
            $this->assertTeacherOwnsClass($class);
            return response()->json(
                Submission::where('assignment_id', $assignment_id)->latest()->get()
            );
        }

        $this->assertCanViewClass($class);

        return response()->json(
            Submission::where('assignment_id', $assignment_id)
                ->where('student_id', $user->id)
                ->latest()
                ->get()
        );
    }

    
    /**
     * Retrieve a submission by its ID.
     */
    public function getById($submission_id)
    {
        $submission = Submission::find($submission_id);

        if (!$submission) {
            return response()->json(['message' => 'Submission tidak ditemukan'], 404);
        }

        $user = auth()->user();
        $assignment = $submission->assignment;
        if (!$assignment) {
            return response()->json(['message' => 'Tugas tidak ditemukan'], 404);
        }

        if ($user->role === 'teacher') {
            $this->assertTeacherOwnsClass(Classes::findOrFail($assignment->class_id));
        } elseif ((int) $submission->student_id !== (int) $user->id) {
            $this->deny('Anda tidak memiliki izin untuk melihat pengumpulan ini');
        }

        return response()->json($submission);
    }

    /**
     * Update the submission's score and status.
     */
    public function score(Request $request, $submission_id)
    {
        $user = auth()->user();
        if ($user->role !== 'teacher') {
            return response()->json(['message' => 'Hanya guru yang dapat memberikan nilai'], 403);
        }

        $submission = Submission::find($submission_id);
        if (!$submission) {
            return response()->json(['message' => 'Submission tidak ditemukan'], 404);
        }

        $assignment = $submission->assignment;
        if (!$assignment) {
            return response()->json(['message' => 'Tugas tidak ditemukan'], 404);
        }
        $this->assertTeacherOwnsClass(Classes::findOrFail($assignment->class_id));

        // Validasi nilai
        $validator = Validator::make($request->all(), [
            'score' => 'required|integer|min:0|max:100',
        ]);

        if ($validator->fails()) {
            return response()->json($validator->errors(), 422);
        }

        // Update nilai dan status
        $submission->score = $request->score;
        $submission->status = 'graded'; // Ganti status ke 'graded'
        $submission->save();

        return response()->json($submission);
    }
}
