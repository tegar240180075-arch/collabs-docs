<?php
namespace App\Http\Controllers;

use App\Events\CursorMoved;
use App\Events\DocumentUpdated;
use App\Models\Document;
use App\Models\DocumentShare;
use App\Models\DocumentVersion;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class DocumentController extends Controller
{
    public function index()
    {
        $user = Auth::user();

        // Dokumen milik sendiri
        $myDocuments = Document::with('owner', 'shares')
            ->where('user_id', $user->id)
            ->latest()
            ->get();

        // Dokumen yang dibagikan ke saya
        $sharedDocuments = Document::with('owner')
            ->whereHas('shares', function ($q) use ($user) {
                $q->where('user_id', $user->id);
            })
            ->latest()
            ->get();

        return view('documents.index', compact('myDocuments', 'sharedDocuments'));
    }

    public function create()
    {
        $doc = Document::create([
            'title'   => 'Untitled Document',
            'content' => '',
            'user_id' => Auth::id(),
        ]);

        return redirect()->route('documents.editor', $doc->id);
    }

    public function editor(Document $document)
    {
        $user = Auth::user();

        // Cek akses
        if (!$document->isAccessibleBy($user)) {
            abort(403, 'Anda tidak memiliki akses ke dokumen ini.');
        }

        $versions = $document->versions()->with('user')->take(20)->get();
        $canEdit  = $document->canEdit($user);
        $isOwner  = $document->user_id === $user->id;
        $sharedUsers = $document->shares()->with('user')->get();

        return view('documents.editor', compact('document', 'versions', 'canEdit', 'isOwner', 'sharedUsers'));
    }

    public function update(Request $request, Document $document)
    {
        $user = Auth::user();

        // Cek permission edit
        if (!$document->canEdit($user)) {
            return response()->json(['error' => 'Tidak memiliki izin edit'], 403);
        }

        $request->validate([
            'content' => 'nullable|string',
            'title'   => 'nullable|string|max:255',
        ]);

        // Simpan versi setiap 10 update (bisa di-improve)
        $versionCount = $document->versions()->count();
        if ($versionCount % 10 === 0) {
            DocumentVersion::create([
                'document_id'    => $document->id,
                'user_id'        => Auth::id(),
                'content'        => $document->content ?? '',
                'snapshot_label' => now()->format('d M Y H:i'),
            ]);
        }

        $document->update([
            'content' => $request->content,
            'title'   => $request->title ?? $document->title,
        ]);

        // Broadcast ke semua user di channel ini
        broadcast(new DocumentUpdated(
            documentId: $document->id,
            content:    $request->content ?? '',
            title:      $request->title ?? $document->title,
            userId:     Auth::id(),
            userName:   Auth::user()->name,
        ))->toOthers();

        return response()->json(['status' => 'ok']);
    }

    public function cursor(Request $request, Document $document)
    {
        $request->validate([
            'position' => 'required|integer',
            'color'    => 'required|string|max:20',
        ]);

        broadcast(new CursorMoved(
            documentId: $document->id,
            userId:     Auth::id(),
            userName:   Auth::user()->name,
            position:   $request->position,
            color:      $request->color,
        ))->toOthers();

        return response()->json(['status' => 'ok']);
    }

    public function versions(Document $document)
    {
        $versions = $document->versions()->with('user')->get();
        return response()->json($versions);
    }

    public function restoreVersion(Document $document, DocumentVersion $version)
    {
        $document->update(['content' => $version->content]);

        broadcast(new DocumentUpdated(
            documentId: $document->id,
            content:    $version->content,
            title:      $document->title,
            userId:     Auth::id(),
            userName:   Auth::user()->name,
        ))->toOthers();

        return response()->json(['status' => 'restored', 'content' => $version->content]);
    }

    public function saveVersion(Request $request, Document $document)
    {
        $request->validate(['label' => 'required|string|max:100']);

        DocumentVersion::create([
            'document_id'    => $document->id,
            'user_id'        => Auth::id(),
            'content'        => $document->content ?? '',
            'snapshot_label' => $request->label,
        ]);

        return response()->json(['status' => 'saved']);
    }

    public function destroy(Document $document)
    {
        $document->delete();
        return redirect()->route('documents.index');
    }

    // ========== SHARE METHODS ==========

    /**
     * Bagikan dokumen ke user lain
     */
    public function share(Request $request, Document $document)
    {
        $user = Auth::user();

        // Hanya owner yang bisa share
        if ($document->user_id !== $user->id) {
            return response()->json(['error' => 'Hanya pemilik yang bisa membagikan dokumen.'], 403);
        }

        $request->validate([
            'email'      => 'required|email|exists:users,email',
            'permission' => 'required|in:viewer,editor',
        ]);

        $targetUser = User::where('email', $request->email)->first();

        // Jangan share ke diri sendiri
        if ($targetUser->id === $user->id) {
            return response()->json(['error' => 'Tidak bisa membagikan ke diri sendiri.'], 422);
        }

        // Upsert share
        DocumentShare::updateOrCreate(
            ['document_id' => $document->id, 'user_id' => $targetUser->id],
            ['permission' => $request->permission]
        );

        return response()->json([
            'status' => 'shared',
            'user'   => [
                'id'    => $targetUser->id,
                'name'  => $targetUser->name,
                'email' => $targetUser->email,
            ],
            'permission' => $request->permission,
        ]);
    }

    /**
     * Hapus akses share user
     */
    public function removeShare(Document $document, User $user)
    {
        $currentUser = Auth::user();

        if ($document->user_id !== $currentUser->id) {
            return response()->json(['error' => 'Hanya pemilik yang bisa menghapus akses.'], 403);
        }

        DocumentShare::where('document_id', $document->id)
            ->where('user_id', $user->id)
            ->delete();

        return response()->json(['status' => 'removed']);
    }

    /**
     * Daftar user yang memiliki akses
     */
    public function sharedUsers(Document $document)
    {
        $shares = $document->shares()->with('user')->get()->map(function ($share) {
            return [
                'id'         => $share->user->id,
                'name'       => $share->user->name,
                'email'      => $share->user->email,
                'permission' => $share->permission,
            ];
        });

        return response()->json($shares);
    }
}