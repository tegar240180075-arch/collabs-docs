
let CONFIG = {};
let saveTimer = null;
let contentBroadcastTimer = null;
let onlineUsers = {};
let isReceiving = false;
let cursorElements = {};
let selectionElements = {};
let typingUsers = {};
let editor, pusher, channel;
let lastBroadcastContent = '';

function initCollab(config) {
    CONFIG = config;
    editor = document.getElementById('editorContent');
    lastBroadcastContent = editor.innerHTML;

    pusher = new Pusher(config.reverbKey, {
        wsHost: config.reverbHost,
        wsPort: config.reverbPort,
        wssPort: config.reverbPort,
        forceTLS: false,
        enabledTransports: ['ws', 'wss'],
        cluster: 'mt1',
        authEndpoint: '/broadcasting/auth',
        auth: { headers: { 'X-CSRF-TOKEN': config.csrfToken } }
    });

    channel = pusher.subscribe('presence-document.' + config.documentId);

    const selfTimeEl = document.getElementById('selfJoinTime');
    if (selfTimeEl) selfTimeEl.textContent = formatTime(new Date());

    channel.bind('pusher:subscription_succeeded', (members) => {
        members.each(m => {
            if (m.id !== config.currentUser.id) {
                addOnlineUser(m.id, m.info);
                addActivityLog(m.info.name, 'join', 'Sudah online');
            }
        });
        updateOnlineBadge();
    });

    channel.bind('pusher:member_added', (m) => {
        addOnlineUser(m.id, m.info);
        addActivityLog(m.info.name, 'join');
        updateOnlineBadge();
        showToast(`${m.info.name} bergabung`);
    });

    channel.bind('pusher:member_removed', (m) => {
        removeOnlineUser(m.id);
        removeCursor(m.id);
        removeSelection(m.id);
        removeTypingIndicator(m.id);
        addActivityLog(m.info.name, 'leave');
        updateOnlineBadge();
        showToast(`${m.info.name} keluar`);
    });

    channel.bind('client-content.changed', (data) => {
        if (data.user_id === config.currentUser.id) return;
        applyRemoteContent(data.content, data.title);
    });

    channel.bind('client-user.typing', (data) => {
        if (data.user_id === config.currentUser.id) return;
        showTypingIndicator(data.user_id, data.user_name, data.color);
    });

    channel.bind('document.updated', (data) => {
        if (data.user_id === config.currentUser.id) return;
        if (editor.innerHTML !== data.content) {
            applyRemoteContent(data.content, data.title);
        }
        setSaveStatus('saved');
    });

    channel.bind('client-cursor.moved', (data) => {
        if (data.user_id === config.currentUser.id) return;
        showRemoteCursor(data.user_id, data.user_name, data.offset, data.color);
        if (data.selStart !== undefined && data.selEnd !== undefined && data.selStart !== data.selEnd) {
            showRemoteSelection(data.user_id, data.user_name, data.selStart, data.selEnd, data.color);
        } else {
            removeSelection(data.user_id);
        }
    });

    channel.bind('cursor.moved', (data) => {
        if (data.user_id === config.currentUser.id) return;
        showRemoteCursor(data.user_id, data.user_name, data.position, data.color);
    });

    if (config.canEdit) {
        editor.addEventListener('input', () => {
            if (isReceiving) return;
            broadcastContentThrottled();
            broadcastTyping();
            setSaveStatus('saving');
            clearTimeout(saveTimer);
            saveTimer = setTimeout(saveDocument, 1500);
            sendCursorPosition();
        });

        editor.addEventListener('keyup', sendCursorPosition);
        editor.addEventListener('click', sendCursorPosition);
        editor.addEventListener('mouseup', sendCursorPosition);
        document.addEventListener('selectionchange', () => {
            if (document.activeElement === editor) sendCursorPosition();
        });

        document.getElementById('docTitle').addEventListener('input', () => {
            broadcastContentNow();
            clearTimeout(saveTimer);
            saveTimer = setTimeout(saveDocument, 1500);
        });
    }

    if (config.isOwner) loadSharedUsers();
}

function applyRemoteContent(content, title) {
    isReceiving = true;
    const sel = window.getSelection();
    const hadFocus = document.activeElement === editor;
    let savedOffset = 0;

    if (hadFocus && sel.rangeCount) {
        savedOffset = getCharOffset(sel.getRangeAt(0).startContainer, sel.getRangeAt(0).startOffset);
    }

    editor.innerHTML = content;
    lastBroadcastContent = content;

    if (hadFocus) {
        try { restoreCursorPosition(savedOffset); } catch(e) { editor.focus(); }
    }

    if (title) {
        const titleInput = document.getElementById('docTitle');
        if (document.activeElement !== titleInput) titleInput.value = title;
    }

    isReceiving = false;
}

function broadcastContentThrottled() {
    clearTimeout(contentBroadcastTimer);
    contentBroadcastTimer = setTimeout(broadcastContentNow, 80);
}

function broadcastContentNow() {
    const content = editor.innerHTML;
    const title = document.getElementById('docTitle').value;
    if (content === lastBroadcastContent) return;
    lastBroadcastContent = content;

    channel.trigger('client-content.changed', {
        user_id: CONFIG.currentUser.id,
        user_name: CONFIG.currentUser.name,
        content: content,
        title: title
    });
}

let typingTimer = null;
function broadcastTyping() {
    clearTimeout(typingTimer);
    channel.trigger('client-user.typing', {
        user_id: CONFIG.currentUser.id,
        user_name: CONFIG.currentUser.name,
        color: CONFIG.currentUser.color
    });
}

function showTypingIndicator(userId, userName, color) {
    const el = document.getElementById('typingIndicator');
    if (!el) return;
    typingUsers[userId] = { name: userName, color: color };
    renderTypingIndicator();
    clearTimeout(typingUsers[userId]._timer);
    typingUsers[userId]._timer = setTimeout(() => removeTypingIndicator(userId), 2000);
}

function removeTypingIndicator(userId) {
    if (typingUsers[userId]) {
        clearTimeout(typingUsers[userId]._timer);
        delete typingUsers[userId];
    }
    renderTypingIndicator();
}

function renderTypingIndicator() {
    const el = document.getElementById('typingIndicator');
    if (!el) return;
    const names = Object.values(typingUsers).map(u => u.name);
    if (names.length === 0) { el.style.opacity = '0'; return; }
    let text = names.length === 1 ? `${names[0]} sedang mengetik`
             : names.length === 2 ? `${names[0]} dan ${names[1]} sedang mengetik`
             : `${names.length} orang sedang mengetik`;
    el.querySelector('.typing-text').textContent = text;
    el.style.opacity = '1';
}

async function saveDocument() {
    const content = editor.innerHTML;
    const title = document.getElementById('docTitle').value;
    try {
        const res = await fetch(`/documents/${CONFIG.documentId}`, {
            method: 'PUT',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': CONFIG.csrfToken },
            body: JSON.stringify({ content, title }),
        });
        setSaveStatus(res.ok ? 'saved' : 'error');
    } catch (e) {
        setSaveStatus('error');
    }
}

function getCharOffset(node, offset) {
    const range = document.createRange();
    range.selectNodeContents(editor);
    range.setEnd(node, offset);
    return range.toString().length;
}

function nodeAndOffsetFromChar(charOffset) {
    const walker = document.createTreeWalker(editor, NodeFilter.SHOW_TEXT, null, false);
    let current = 0;
    while (walker.nextNode()) {
        const node = walker.currentNode;
        const len = node.textContent.length;
        if (current + len >= charOffset) {
            return { node, offset: Math.min(charOffset - current, len) };
        }
        current += len;
    }
    return null;
}

function sendCursorPosition() {
    const sel = window.getSelection();
    if (!sel.rangeCount) return;

    const range = sel.getRangeAt(0);
    const offset = getCharOffset(range.startContainer, range.startOffset);
    const selStart = offset;
    const selEnd = range.collapsed ? offset : getCharOffset(range.endContainer, range.endOffset);

    channel.trigger('client-cursor.moved', {
        user_id: CONFIG.currentUser.id,
        user_name: CONFIG.currentUser.name,
        offset: offset,
        selStart: selStart,
        selEnd: selEnd,
        color: CONFIG.currentUser.color
    });
}

function showRemoteCursor(userId, userName, charOffset, color) {
    const container = document.getElementById('cursorsContainer');
    if (!container) return;

    if (!cursorElements[userId]) {
        const wrapper = document.createElement('div');
        wrapper.className = 'remote-cursor';
        wrapper.id = `cursor-${userId}`;

        const line = document.createElement('div');
        line.className = 'remote-cursor-line';
        line.style.background = color;
        line.style.boxShadow = `0 0 6px ${color}40`;

        const flag = document.createElement('div');
        flag.className = 'remote-cursor-flag';
        flag.style.background = color;
        flag.textContent = userName;

        wrapper.appendChild(flag);
        wrapper.appendChild(line);
        container.appendChild(wrapper);
        cursorElements[userId] = wrapper;
    }

    positionCursorAt(userId, charOffset);

    clearTimeout(cursorElements[userId]._hideTimer);
    cursorElements[userId].style.opacity = '1';
    cursorElements[userId]._hideTimer = setTimeout(() => {
        if (cursorElements[userId]) {
            cursorElements[userId].style.opacity = '0';
        }
    }, 15000);
}

function positionCursorAt(userId, charOffset) {
    if (!cursorElements[userId]) return;
    const coords = getPixelCoordsForOffset(charOffset);
    if (coords) {
        cursorElements[userId].style.top = `${coords.top}px`;
        cursorElements[userId].style.left = `${coords.left}px`;
    }
}

function getPixelCoordsForOffset(charOffset) {
    try {
        const result = nodeAndOffsetFromChar(charOffset);
        const range = document.createRange();

        if (result) {
            range.setStart(result.node, result.offset);
            range.setEnd(result.node, result.offset);
        } else {
            range.selectNodeContents(editor);
            range.collapse(false);
        }

        const paperRect = document.querySelector('.editor-paper').getBoundingClientRect();
        const rects = range.getClientRects();

        if (rects.length > 0) {
            return {
                top: rects[0].top - paperRect.top,
                left: rects[0].left - paperRect.left
            };
        }

        const span = document.createElement('span');
        span.textContent = '\u200b';
        range.insertNode(span);
        const spanRect = span.getBoundingClientRect();
        const coords = {
            top: spanRect.top - paperRect.top,
            left: spanRect.left - paperRect.left
        };
        span.remove();
        return coords;
    } catch (e) {
        return { top: 0, left: 0 };
    }
}

function removeCursor(userId) {
    if (cursorElements[userId]) {
        clearTimeout(cursorElements[userId]._hideTimer);
        cursorElements[userId].remove();
        delete cursorElements[userId];
    }
}

function showRemoteSelection(userId, userName, selStart, selEnd, color) {
    removeSelection(userId);

    try {
        const startResult = nodeAndOffsetFromChar(selStart);
        const endResult = nodeAndOffsetFromChar(selEnd);
        if (!startResult || !endResult) return;

        const range = document.createRange();
        range.setStart(startResult.node, startResult.offset);
        range.setEnd(endResult.node, endResult.offset);

        const rects = range.getClientRects();
        const paperRect = document.querySelector('.editor-paper').getBoundingClientRect();

        const container = document.getElementById('cursorsContainer');
        const selGroup = document.createElement('div');
        selGroup.className = 'remote-selection-group';
        selGroup.id = `selection-${userId}`;

        for (let i = 0; i < rects.length; i++) {
            const rect = rects[i];
            const highlight = document.createElement('div');
            highlight.className = 'remote-selection-rect';
            highlight.style.background = color + '25';
            highlight.style.borderBottom = `2px solid ${color}60`;
            highlight.style.top = (rect.top - paperRect.top) + 'px';
            highlight.style.left = (rect.left - paperRect.left) + 'px';
            highlight.style.width = rect.width + 'px';
            highlight.style.height = rect.height + 'px';
            selGroup.appendChild(highlight);
        }

        container.appendChild(selGroup);
        selectionElements[userId] = selGroup;
    } catch (e) {}
}

function removeSelection(userId) {
    if (selectionElements[userId]) {
        selectionElements[userId].remove();
        delete selectionElements[userId];
    }
}

function restoreCursorPosition(charOffset) {
    const result = nodeAndOffsetFromChar(charOffset);
    const sel = window.getSelection();
    const range = document.createRange();

    if (result) {
        range.setStart(result.node, result.offset);
    } else {
        range.selectNodeContents(editor);
        range.collapse(false);
    }
    range.collapse(true);
    sel.removeAllRanges();
    sel.addRange(range);
    editor.focus();
}

function addOnlineUser(userId, info) {
    onlineUsers[userId] = info;
    renderOnlineUsers();
    renderActiveAvatars();
}

function removeOnlineUser(userId) {
    delete onlineUsers[userId];
    renderOnlineUsers();
    renderActiveAvatars();
}

function renderOnlineUsers() {
    const list = document.getElementById('onlineUsersList');
    let html = `<div class="online-user-item">
        <div class="online-avatar" style="background:${CONFIG.currentUser.color}">${CONFIG.currentUser.name.charAt(0).toUpperCase()}</div>
        <div class="online-info"><div class="online-name">${CONFIG.currentUser.name} (Kamu)</div><div class="online-badge">Online</div></div>
    </div>`;

    Object.values(onlineUsers).forEach(u => {
        const c = u.color || '#4f8ef7';
        html += `<div class="online-user-item">
            <div class="online-avatar" style="background:${c}">${u.name.charAt(0).toUpperCase()}</div>
            <div class="online-info"><div class="online-name">${u.name}</div><div class="online-badge">Online</div></div>
        </div>`;
    });

    list.innerHTML = html;
}

function renderActiveAvatars() {
    const container = document.getElementById('activeUsers');
    let html = `<div class="user-dot" style="background:${CONFIG.currentUser.color}" title="${CONFIG.currentUser.name}">
        <span class="tooltip">${CONFIG.currentUser.name} (kamu)</span>${CONFIG.currentUser.name.charAt(0).toUpperCase()}
    </div>`;

    Object.values(onlineUsers).forEach(u => {
        const c = u.color || '#4f8ef7';
        html += `<div class="user-dot" style="background:${c}"><span class="tooltip">${u.name}</span>${u.name.charAt(0).toUpperCase()}</div>`;
    });

    container.innerHTML = html;
}

async function saveVersion() {
    const label = document.getElementById('versionLabel').value.trim();
    if (!label) { alert('Masukkan label versi!'); return; }

    await fetch(`/documents/${CONFIG.documentId}/versions/save`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': CONFIG.csrfToken },
        body: JSON.stringify({ label }),
    });

    document.getElementById('versionLabel').value = '';
    await loadVersions();
    showToast('Versi tersimpan');
}

async function loadVersions() {
    const res = await fetch(`/documents/${CONFIG.documentId}/versions`);
    const data = await res.json();
    const list = document.getElementById('versionsList');

    if (!data.length) {
        list.innerHTML = '<p style="color:#64748b;font-size:13px;text-align:center;padding:24px 0">Belum ada versi tersimpan</p>';
        return;
    }

    list.innerHTML = data.map(v => `
        <div class="version-item">
            <div class="version-label">${v.snapshot_label || 'Auto-save'}</div>
            <div class="version-meta">${v.user?.name || 'User'} â€¢ ${new Date(v.created_at).toLocaleString('id-ID')}</div>
            <button class="btn-restore" onclick="restoreVersion(${v.id})">â†© Restore</button>
        </div>`).join('');
}

async function restoreVersion(versionId) {
    if (!confirm('Restore ke versi ini?')) return;
    const res = await fetch(`/documents/${CONFIG.documentId}/versions/${versionId}/restore`, {
        method: 'POST',
        headers: { 'X-CSRF-TOKEN': CONFIG.csrfToken },
    });
    const data = await res.json();
    if (data.content !== undefined) {
        editor.innerHTML = data.content;
        lastBroadcastContent = data.content;
        broadcastContentNow();
        showToast('Versi berhasil direstore');
    }
}

function execCmd(cmd, value = null) {
    document.execCommand(cmd, false, value);
    editor.focus();
    broadcastContentNow();
    clearTimeout(saveTimer);
    saveTimer = setTimeout(saveDocument, 1000);
}

function switchTab(name, btn) {
    document.querySelectorAll('.sidebar-tab').forEach(t => t.classList.remove('active'));
    document.querySelectorAll('.sidebar-panel').forEach(p => p.classList.remove('active'));
    btn.classList.add('active');
    document.getElementById('panel-' + name).classList.add('active');
    if (name === 'versions') loadVersions();
}

function setSaveStatus(status) {
    const el = document.getElementById('saveStatus');
    const labels = { saving: 'Menyimpan...', saved: 'Tersimpan', error: 'Gagal simpan' };
    el.className = 'save-status ' + status;
    el.querySelector('span').textContent = labels[status] || '';
}

function openShareModal() {
    document.getElementById('shareModal').classList.add('active');
    loadSharedUsers();
}

function closeShareModal() {
    document.getElementById('shareModal').classList.remove('active');
    document.getElementById('shareError').style.display = 'none';
}

document.addEventListener('click', (e) => {
    if (e.target.id === 'shareModal') closeShareModal();
});

async function shareDocument() {
    const email = document.getElementById('shareEmail').value.trim();
    const permission = document.getElementById('sharePermission').value;
    const errEl = document.getElementById('shareError');
    const btn = document.getElementById('btnShareSubmit');

    if (!email) { errEl.textContent = 'Masukkan email!'; errEl.style.display = 'block'; return; }

    btn.disabled = true;
    btn.textContent = '...';
    errEl.style.display = 'none';

    try {
        const res = await fetch(`/documents/${CONFIG.documentId}/share`, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'Accept': 'application/json',
                'X-CSRF-TOKEN': CONFIG.csrfToken
            },
            body: JSON.stringify({ email, permission }),
        });

        const text = await res.text();
        let data;
        try { data = JSON.parse(text); } catch(e) { data = null; }

        if (!res.ok) {
            if (data && data.errors && data.errors.email) {
                errEl.textContent = data.errors.email[0];
            } else if (data && (data.error || data.message)) {
                errEl.textContent = data.error || data.message;
            } else {
                errEl.textContent = 'Gagal membagikan (Error ' + res.status + ')';
            }
            errEl.style.display = 'block';
        } else if (data) {
            document.getElementById('shareEmail').value = '';
            showToast(`Dibagikan ke ${data.user.name}`);
            loadSharedUsers();
        }
    } catch (e) {
        errEl.textContent = 'Terjadi kesalahan jaringan: ' + e.message;
        errEl.style.display = 'block';
    }

    btn.disabled = false;
    btn.textContent = 'Kirim';
}

async function loadSharedUsers() {
    const listEl = document.getElementById('sharedUsersList');
    if (!listEl) return;

    try {
        const res = await fetch(`/documents/${CONFIG.documentId}/shared-users`);
        const users = await res.json();

        let html = `<div class="shared-user-item">
            <div class="shared-user-avatar">${CONFIG.currentUser.name.charAt(0).toUpperCase()}</div>
            <div class="shared-user-info">
                <div class="shared-user-name">${CONFIG.currentUser.name}</div>
                <div class="shared-user-email">Pemilik dokumen</div>
            </div>
            <span class="shared-user-perm owner">Pemilik</span>
        </div>`;

        users.forEach(u => {
            const avatarColor = '#' + Math.abs(hashCode(u.email)).toString(16).slice(0, 6).padEnd(6, 'a');
            html += `<div class="shared-user-item">
                <div class="shared-user-avatar" style="background:${avatarColor}">${u.name.charAt(0).toUpperCase()}</div>
                <div class="shared-user-info">
                    <div class="shared-user-name">${u.name}</div>
                    <div class="shared-user-email">${u.email}</div>
                </div>
                <span class="shared-user-perm ${u.permission}">${u.permission === 'editor' ? 'Editor' : 'Viewer'}</span>
                <button class="btn-remove-share" onclick="removeShare(${u.id})" title="Hapus akses">âœ•</button>
            </div>`;
        });

        if (!users.length) html += '<div class="share-empty">Belum dibagikan ke siapapun</div>';
        listEl.innerHTML = html;
    } catch(e) {}
}

function hashCode(str) {
    let hash = 0;
    for (let i = 0; i < str.length; i++) {
        hash = ((hash << 5) - hash) + str.charCodeAt(i);
        hash |= 0;
    }
    return hash;
}

async function removeShare(userId) {
    if (!confirm('Hapus akses user ini?')) return;
    await fetch(`/documents/${CONFIG.documentId}/share/${userId}`, {
        method: 'DELETE',
        headers: { 'X-CSRF-TOKEN': CONFIG.csrfToken },
    });
    showToast('Akses dihapus');
    loadSharedUsers();
}

function copyShareLink() {
    const input = document.getElementById('shareLinkInput');
    input.select();
    navigator.clipboard.writeText(input.value).then(() => showToast('Link disalin'));
}

function addActivityLog(userName, type, customText) {
    const log = document.getElementById('activityLog');
    if (!log) return;
    const text = customText || (type === 'join' ? `${userName} bergabung` : `${userName} keluar`);
    const item = document.createElement('div');
    item.className = `activity-log-item ${type}`;
    item.innerHTML = `
        <span class="activity-dot"></span>
        <span class="activity-text">${text}</span>
        <span class="activity-time">${formatTime(new Date())}</span>
    `;
    log.insertBefore(item, log.firstChild);
    while (log.children.length > 30) log.removeChild(log.lastChild);
}

function updateOnlineBadge() {
    const badge = document.getElementById('onlineCountBadge');
    if (!badge) return;
    const count = 1 + Object.keys(onlineUsers).length;
    badge.textContent = count;
    badge.classList.remove('pulse');
    void badge.offsetWidth;
    badge.classList.add('pulse');
}

function formatTime(date) {
    return date.toLocaleTimeString('id-ID', { hour: '2-digit', minute: '2-digit', second: '2-digit' });
}

function showToast(msg) {
    const t = document.createElement('div');
    t.textContent = msg;
    Object.assign(t.style, {
        position: 'fixed', bottom: '24px', left: '50%', transform: 'translateX(-50%)',
        background: '#1e293b', border: '1px solid rgba(255,255,255,0.1)',
        color: '#e2e8f0', padding: '10px 20px', borderRadius: '10px', fontSize: '13px',
        boxShadow: '0 8px 24px rgba(0,0,0,0.4)', zIndex: '9999', transition: 'opacity 0.3s',
    });
    document.body.appendChild(t);
    setTimeout(() => { t.style.opacity = '0'; }, 2500);
    setTimeout(() => t.remove(), 2800);
}
