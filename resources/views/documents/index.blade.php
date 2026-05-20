<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dokumen Saya — CollabDocs</title>
    <link rel="stylesheet" href="{{ asset('css/dashboard.css') }}">
</head>
<body>

<nav class="navbar">
    <a href="{{ route('documents.index') }}" class="navbar-brand">
        <div class="brand-icon"></div>
        <span>CollabDocs</span>
    </a>
    <div class="navbar-right">
        <div class="user-chip">
            <div class="user-avatar">{{ strtoupper(substr(Auth::user()->name, 0, 1)) }}</div>
            <span>{{ Auth::user()->name }}</span>
        </div>
        <form method="POST" action="{{ route('logout') }}">
            @csrf
            <button type="submit" class="btn-logout">Keluar</button>
        </form>
    </div>
</nav>

<div class="main-content">
    <div class="page-header">
        <div>
            <h1>Dokumen Saya</h1>
            <p>{{ $myDocuments->count() }} dokumen milikmu</p>
        </div>
        <form method="POST" action="{{ route('documents.create') }}">
            @csrf
            <button type="submit" class="btn-new-doc">
                <span></span> Dokumen Baru
            </button>
        </form>
    </div>

    <div class="docs-grid">
        @forelse($myDocuments as $doc)
            <div class="doc-card">
                <div class="doc-icon"></div>
                <div class="doc-title" title="{{ $doc->title }}">{{ $doc->title }}</div>
                <div class="doc-meta">
                    <span>{{ $doc->updated_at->diffForHumans() }}</span>
                </div>
                @if($doc->shares->count() > 0)
                    <div class="doc-shared-badge">
                        <span class="shared-count"> Dibagikan ke {{ $doc->shares->count() }} user</span>
                    </div>
                @endif
                <div class="doc-actions">
                    <a href="{{ route('documents.editor', $doc->id) }}" class="btn-open">Buka Editor</a>
                    <form method="POST" action="{{ route('documents.destroy', $doc->id) }}"
                          onsubmit="return confirm('Hapus dokumen ini?')">
                        @csrf @method('DELETE')
                        <button type="submit" class="btn-delete"></button>
                    </form>
                </div>
            </div>
        @empty
            <div class="empty-state">
                <div class="empty-icon"></div>
                <h3>Belum ada dokumen</h3>
                <p>Klik "Dokumen Baru" untuk memulai berkolaborasi</p>
            </div>
        @endforelse
    </div>

    @if($sharedDocuments->count() > 0)
        <div class="section-divider"></div>
        <div class="page-header">
            <div>
                <h1> Dibagikan ke Saya</h1>
                <p>{{ $sharedDocuments->count() }} dokumen dari user lain</p>
            </div>
        </div>

        <div class="docs-grid">
            @foreach($sharedDocuments as $doc)
                <div class="doc-card shared-card">
                    <div class="doc-icon shared-icon"></div>
                    <div class="doc-title" title="{{ $doc->title }}">{{ $doc->title }}</div>
                    <div class="doc-meta">
                        <span> {{ $doc->owner->name }}</span>
                        <span> {{ $doc->updated_at->diffForHumans() }}</span>
                    </div>
                    <div class="doc-actions">
                        <a href="{{ route('documents.editor', $doc->id) }}" class="btn-open">Buka Editor</a>
                        <form method="POST" action="{{ route('documents.leaveShare', $doc->id) }}"
                              onsubmit="return confirm('Hapus dokumen ini dari daftar Anda?')">
                            @csrf @method('DELETE')
                            <button type="submit" class="btn-delete"></button>
                        </form>
                    </div>
                </div>
            @endforeach
        </div>
    @endif
</div>

</body>
</html>