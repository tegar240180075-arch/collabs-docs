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

        $myDocuments = Document::with('owner', 'shares')
            ->where('user_id', $user->id)
            ->latest()
            ->get();

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

        if (!$document->canEdit($user)) {
            return response()->json(['error' => 'Tidak memiliki izin edit'], 403);
        }

        $request->validate([
            'content' => 'nullable|string',
            'title'   => 'nullable|string|max:255',
        ]);

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


    public function share(Request $request, Document $document)
    {
        try {
            $user = Auth::user();

            if ((int) $document->user_id !== (int) $user->id) {
                return response()->json(['error' => 'Hanya pemilik yang bisa membagikan dokumen.'], 403);
            }

            $email = $request->input('email', '');
            $permission = $request->input('permission', 'viewer');

            if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                return response()->json(['error' => 'Email tidak valid.'], 422);
            }

            if (!in_array($permission, ['viewer', 'editor'])) {
                return response()->json(['error' => 'Permission harus viewer atau editor.'], 422);
            }

            $targetUser = User::where('email', $email)->first();

            if (!$targetUser) {
                return response()->json(['error' => 'User dengan email tersebut tidak ditemukan.'], 422);
            }

            if ((int) $targetUser->id === (int) $user->id) {
                return response()->json(['error' => 'Tidak bisa membagikan ke diri sendiri.'], 422);
            }

            DocumentShare::updateOrCreate(
                ['document_id' => $document->id, 'user_id' => $targetUser->id],
                ['permission' => $permission]
            );

            return response()->json([
                'status' => 'shared',
                'user'   => [
                    'id'    => $targetUser->id,
                    'name'  => $targetUser->name,
                    'email' => $targetUser->email,
                ],
                'permission' => $permission,
            ]);
        } catch (\Exception $e) {
            return response()->json(['error' => 'Server error: ' . $e->getMessage()], 500);
        }
    }

    public function removeShare(Document $document, User $user)
    {
        $currentUser = Auth::user();

        if ((int) $document->user_id !== (int) $currentUser->id) {
            return response()->json(['error' => 'Hanya pemilik yang bisa menghapus akses.'], 403);
        }

        DocumentShare::where('document_id', $document->id)
            ->where('user_id', $user->id)
            ->delete();

        return response()->json(['status' => 'removed']);
    }

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

    public function leaveShare(Document $document)
    {
        $user = Auth::user();

        DocumentShare::where('document_id', $document->id)
            ->where('user_id', $user->id)
            ->delete();

        return redirect()->route('documents.index');
    }
}
