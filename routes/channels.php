<?php
use App\Models\Document;
use Illuminate\Support\Facades\Broadcast;

Broadcast::channel('document.{documentId}', function ($user, $documentId) {
    $document = Document::find($documentId);

    if (!$document) {
        return false;
    }

    if ($document->isAccessibleBy($user)) {
        return [
            'id'    => $user->id,
            'name'  => $user->name,
            'email' => $user->email,
            'color' => '#' . substr(md5($user->id . $user->email), 0, 6),
        ];
    }

    return false;
});
