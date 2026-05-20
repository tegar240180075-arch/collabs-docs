<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ $document->title }} - CollabDocs</title>
    <link rel="stylesheet" href="{{ asset('css/editor.css') }}">
    <meta name="csrf-token" content="{{ csrf_token() }}">
</head>
<body>

<header class="editor-header">
    <a href="{{ route('documents.index') }}" class="btn-back">&#8592; Kembali</a>
    <input type="text" class="doc-title-input" id="docTitle"
           value="{{ $document->title }}" placeholder="Judul dokumen..."
           @if(!$canEdit) readonly @endif>
    <div class="header-right">
        @if(!$isOwner)
            <span class="permission-badge {{ $canEdit ? 'editor-perm' : 'viewer' }}">
                {{ $canEdit ? 'Editor' : 'Viewer' }}
            </span>
        @endif
        @if($isOwner)
            <button class="btn-share" onclick="openShareModal()">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 12v8a2 2 0 002 2h12a2 2 0 002-2v-8"/><polyline points="16 6 12 2 8 6"/><line x1="12" y1="2" x2="12" y2="15"/></svg>
                Bagikan
            </button>
        @endif
        <div class="active-users" id="activeUsers">
            <div class="user-dot" style="background:#{{ substr(md5(Auth::id()), 0, 6) }}" title="{{ Auth::user()->name }}">
                <span class="tooltip">{{ Auth::user()->name }} (kamu)</span>
                {{ strtoupper(substr(Auth::user()->name, 0, 1)) }}
            </div>
        </div>
        <div class="save-status" id="saveStatus">
            <div class="save-dot"></div>
            <span>Tersimpan</span>
        </div>
    </div>
</header>

@if($canEdit)
<div class="editor-toolbar">
    <button class="toolbar-btn" onclick="execCmd('bold')" title="Bold"><b>B</b></button>
    <button class="toolbar-btn" onclick="execCmd('italic')" title="Italic"><i>I</i></button>
    <button class="toolbar-btn" onclick="execCmd('underline')" title="Underline"><u>U</u></button>
    <button class="toolbar-btn" onclick="execCmd('strikeThrough')" title="Strikethrough"><s>S</s></button>
    <div class="toolbar-divider"></div>
    <select class="toolbar-select" onchange="execCmd('fontSize', this.value)" title="Font Size">
        <option value="3">Normal</option>
        <option value="1">Kecil</option>
        <option value="5">Besar</option>
        <option value="7">Sangat Besar</option>
    </select>
    <div class="toolbar-divider"></div>
    <button class="toolbar-btn" onclick="execCmd('justifyLeft')" title="Rata Kiri">&#9776;</button>
    <button class="toolbar-btn" onclick="execCmd('justifyCenter')" title="Tengah">&#8801;</button>
    <button class="toolbar-btn" onclick="execCmd('justifyRight')" title="Rata Kanan">&#9783;</button>
    <div class="toolbar-divider"></div>
    <button class="toolbar-btn" onclick="execCmd('insertUnorderedList')" title="Bullet List">&#8226;&#8801;</button>
    <button class="toolbar-btn" onclick="execCmd('insertOrderedList')" title="Numbered List">1&#8801;</button>
    <div class="toolbar-divider"></div>
    <button class="toolbar-btn" onclick="execCmd('undo')" title="Undo">&#8617;</button>
    <button class="toolbar-btn" onclick="execCmd('redo')" title="Redo">&#8618;</button>
</div>
@endif

<div class="editor-body">
    <main class="editor-main">
        <div class="editor-paper">
            <div class="editor-content" id="editorContent"
                 contenteditable="{{ $canEdit ? 'true' : 'false' }}"
                 spellcheck="false">{!! $document->content !!}</div>
            <div id="cursorsContainer"></div>
        </div>
        <div class="typing-indicator" id="typingIndicator" style="opacity:0">
            <div class="typing-dots">
                <span></span><span></span><span></span>
            </div>
            <span class="typing-text"></span>
        </div>
    </main>
    <aside class="editor-sidebar">
        <div class="sidebar-tabs">
            <button class="sidebar-tab active" onclick="switchTab('versions', this)">Versi</button>
            <button class="sidebar-tab" onclick="switchTab('users', this)" id="tabOnline">
                Online <span class="online-count-badge" id="onlineCountBadge">1</span>
            </button>
        </div>
        <div class="sidebar-panel active" id="panel-versions">
            <div class="save-version-form">
                <input type="text" id="versionLabel" placeholder="Label versi...">
                <button class="btn-save-ver" onclick="saveVersion()">Simpan</button>
            </div>
            <div id="versionsList">
                @forelse($versions as $v)
                    <div class="version-item" id="version-{{ $v->id }}">
                        <div class="version-label">{{ $v->snapshot_label ?? 'Auto-save' }}</div>
                        <div class="version-meta">{{ $v->user->name }} &#8226; {{ $v->created_at->format('d M H:i') }}</div>
                        <button class="btn-restore" onclick="restoreVersion({{ $v->id }})">&#8617; Restore</button>
                    </div>
                @empty
                    <p style="color:#999;font-size:13px;text-align:center;padding:24px 0">Belum ada versi tersimpan</p>
                @endforelse
            </div>
        </div>
        <div class="sidebar-panel" id="panel-users">
            <div id="onlineUsersList">
                <div class="online-user-item">
                    <div class="online-avatar" style="background:#{{ substr(md5(Auth::id()), 0, 6) }}">{{ strtoupper(substr(Auth::user()->name, 0, 1)) }}</div>
                    <div class="online-info">
                        <div class="online-name">{{ Auth::user()->name }} (Kamu)</div>
                        <div class="online-badge">Online</div>
                    </div>
                </div>
            </div>

            <div class="activity-log-title">LOG AKTIVITAS</div>
            <div id="activityLog" class="activity-log">
                <div class="activity-log-item join">
                    <span class="activity-dot"></span>
                    <span class="activity-text">Kamu bergabung</span>
                    <span class="activity-time" id="selfJoinTime"></span>
                </div>
            </div>
        </div>
    </aside>
</div>

<div class="modal-overlay" id="shareModal">
    <div class="share-modal">
        <div class="modal-header">
            <h2>Bagikan Dokumen</h2>
            <button class="modal-close" onclick="closeShareModal()">X</button>
        </div>
        <div class="modal-body">
            <div class="share-form">
                <input type="email" id="shareEmail" placeholder="Masukkan email user...">
                <select id="sharePermission">
                    <option value="editor">Editor</option>
                    <option value="viewer">Viewer</option>
                </select>
                <button class="btn-share-submit" id="btnShareSubmit" onclick="shareDocument()">Kirim</button>
            </div>
            <div class="share-error" id="shareError" style="display:none"></div>
            <div class="share-link-section">
                <input type="text" id="shareLinkInput" value="{{ url('/documents/' . $document->id . '/editor') }}" readonly>
                <button class="btn-copy-link" onclick="copyShareLink()">Salin Link</button>
            </div>
            <div class="shared-users-title">YANG MEMILIKI AKSES</div>
            <div id="sharedUsersList">
                <div class="shared-user-item">
                    <div class="shared-user-avatar">{{ strtoupper(substr(Auth::user()->name, 0, 1)) }}</div>
                    <div class="shared-user-info">
                        <div class="shared-user-name">{{ Auth::user()->name }}</div>
                        <div class="shared-user-email">{{ Auth::user()->email }}</div>
                    </div>
                    <span class="shared-user-perm owner">Pemilik</span>
                </div>
            </div>
        </div>
    </div>
</div>

<script src="https://js.pusher.com/8.2.0/pusher.min.js"></script>
<script src="{{ asset('js/collab.js') }}"></script>
<script>
    initCollab({
        documentId: {{ $document->id }},
        currentUser: {
            id: {{ Auth::id() }},
            name: "{{ Auth::user()->name }}",
            color: "#{{ substr(md5(Auth::id()), 0, 6) }}"
        },
        csrfToken: "{{ csrf_token() }}",
        reverbKey: "{{ config('reverb.apps.0.key', env('REVERB_APP_KEY')) }}",
        reverbHost: "{{ env('REVERB_HOST', 'localhost') }}",
        reverbPort: {{ env('REVERB_PORT', 8080) }},
        canEdit: {{ $canEdit ? 'true' : 'false' }},
        isOwner: {{ $isOwner ? 'true' : 'false' }}
    });
</script>
</body>
</html>
