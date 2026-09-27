@include('aibusinessmanager::partials.assistant_markdown_js')

<div id="aibm-float-root" class="no-print aibm-float">
    <style>
        .aibm-float.aibm-float-root-hidden { display: none !important; }
        .aibm-float.aibm-float-root-visible { display: block !important; }

        .aibm-float-wrap {
            position: fixed;
            bottom: 22px;
            right: 22px;
            z-index: 9998;
            display: flex;
            flex-direction: column;
            align-items: flex-end;
            gap: 12px;
            font-family: inherit;
            --aibm-float-hex: {{ $aibm_floating_accent_hex ?? '#3c8dbc' }};
            --aibm-float-rgb: {{ $aibm_floating_accent_rgb ?? '60, 141, 188' }};
        }

        .aibm-float-panel {
            display: none;
            width: min(400px, calc(100vw - 32px));
            max-width: 400px;
        }

        .aibm-float-panel-inner {
            border-radius: 16px;
            border: 1px solid rgba(var(--aibm-float-rgb), 0.22);
            background: #fff;
            box-shadow:
                0 4px 6px -1px rgba(0, 0, 0, 0.07),
                0 20px 40px -12px rgba(0, 0, 0, 0.18);
            overflow: hidden;
        }

        .aibm-float-header {
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            gap: 10px;
            padding: 14px 16px;
            border-bottom: 1px solid rgba(var(--aibm-float-rgb), 0.12);
            background: linear-gradient(180deg, rgba(var(--aibm-float-rgb), 0.08) 0%, rgba(var(--aibm-float-rgb), 0.02) 100%);
        }

        .aibm-float-brand {
            min-width: 0;
            flex: 1;
            line-height: 1.35;
        }

        .aibm-float-brand-eli {
            color: var(--aibm-float-hex);
            font-weight: 800;
            font-size: 15px;
            letter-spacing: -0.02em;
        }

        .aibm-float-brand-sep {
            color: #94a3b8;
            font-weight: 500;
            font-size: 14px;
        }

        .aibm-float-brand-meaning {
            color: #475569;
            font-weight: 600;
            font-size: 13px;
            letter-spacing: -0.01em;
        }

        .aibm-float-close {
            flex-shrink: 0;
            width: 32px;
            height: 32px;
            margin: 0;
            padding: 0;
            border: none;
            border-radius: 10px;
            background: rgba(var(--aibm-float-rgb), 0.1);
            color: #475569;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: background 0.15s ease, color 0.15s ease;
        }
        .aibm-float-close:hover {
            background: rgba(var(--aibm-float-rgb), 0.18);
            color: #1e293b;
        }
        .aibm-float-close .fa-times { font-size: 14px; }

        .aibm-float-messages {
            height: 300px;
            overflow-y: auto;
            padding: 14px 16px;
            background: #f8fafc;
            font-size: 13px;
            line-height: 1.5;
            -webkit-overflow-scrolling: touch;
        }

        .aibm-float-history-loading {
            display: flex;
            align-items: center;
            justify-content: center;
            min-height: 120px;
            padding: 16px;
            text-align: center;
            font-size: 12px;
            color: #64748b;
        }

        .aibm-float-load-older-wrap {
            overflow: hidden;
            max-height: 0;
            opacity: 0;
            margin: 0;
            transition:
                max-height 0.28s cubic-bezier(0.22, 1, 0.36, 1),
                opacity 0.22s ease,
                margin-bottom 0.22s ease;
        }

        .aibm-float-load-older-wrap.is-revealed {
            max-height: 72px;
            opacity: 1;
            margin-bottom: 12px;
        }

        .aibm-float-load-older-btn {
            width: 100%;
            box-sizing: border-box;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            padding: 11px 16px;
            font-size: 13px;
            font-weight: 600;
            letter-spacing: -0.01em;
            color: var(--aibm-float-hex);
            background: rgba(var(--aibm-float-rgb), 0.14);
            border: 1px solid rgba(var(--aibm-float-rgb), 0.38);
            border-radius: 12px;
            cursor: pointer;
            transition: background 0.15s ease, border-color 0.15s ease, transform 0.12s ease;
        }

        .aibm-float-load-older-btn:hover:not(:disabled) {
            background: rgba(var(--aibm-float-rgb), 0.22);
            border-color: rgba(var(--aibm-float-rgb), 0.48);
        }

        .aibm-float-load-older-btn:active:not(:disabled) {
            transform: scale(0.99);
        }

        .aibm-float-load-older-btn:disabled {
            opacity: 0.65;
            cursor: wait;
        }

        .aibm-float-load-older-btn .fa-history {
            font-size: 14px;
            opacity: 0.9;
        }

        .aibm-float-bubble-user {
            margin-left: 12%;
            background: rgba(var(--aibm-float-rgb), 0.12);
            border: 1px solid rgba(var(--aibm-float-rgb), 0.22);
            border-radius: 14px 14px 4px 14px;
            padding: 10px 12px;
            margin-bottom: 12px;
            white-space: pre-wrap;
            word-break: break-word;
            color: #1e293b;
        }

        .aibm-float-bubble-ai {
            margin-right: 8%;
            background: #fff;
            border: 1px solid #e2e8f0;
            border-radius: 14px 14px 14px 4px;
            padding: 10px 12px;
            margin-bottom: 12px;
            white-space: pre-wrap;
            word-break: break-word;
            color: #1e293b;
            box-shadow: 0 1px 2px rgba(0, 0, 0, 0.04);
        }

        .aibm-float-meta {
            font-size: 11px;
            font-weight: 600;
            color: #64748b;
            margin-bottom: 6px;
            text-transform: uppercase;
            letter-spacing: 0.04em;
        }

        .aibm-float-input-row {
            display: flex;
            gap: 10px;
            padding: 12px 16px;
            border-top: 1px solid #e2e8f0;
            background: #fff;
            align-items: center;
        }

        .aibm-float-input {
            flex: 1;
            border-radius: 12px;
            border: 1px solid #e2e8f0;
            padding: 10px 14px;
            font-size: 13px;
            outline: none;
            transition: border-color 0.15s ease, box-shadow 0.15s ease;
        }
        .aibm-float-input:focus {
            border-color: var(--aibm-float-hex);
            box-shadow: 0 0 0 3px rgba(var(--aibm-float-rgb), 0.15);
        }
        .aibm-float-input::placeholder { color: #94a3b8; }

        .aibm-float-send {
            width: 42px;
            height: 42px;
            border-radius: 12px;
            border: none;
            cursor: pointer;
            background: var(--aibm-float-hex);
            color: #fff;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: opacity 0.15s ease, transform 0.1s ease;
            flex-shrink: 0;
        }
        .aibm-float-send:hover:not(:disabled) { transform: scale(1.03); }
        .aibm-float-send:disabled { opacity: 0.45; cursor: not-allowed; }
        .aibm-float-send .fa-paper-plane { font-size: 14px; }

        .aibm-float-fab {
            width: 56px;
            height: 56px;
            border-radius: 50%;
            border: 2px solid rgba(var(--aibm-float-rgb), 0.45);
            cursor: pointer;
            box-shadow:
                0 4px 12px rgba(var(--aibm-float-rgb), 0.28),
                0 10px 24px rgba(0, 0, 0, 0.12);
            background: #fff;
            color: var(--aibm-float-hex);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 22px;
            transition: transform 0.15s ease, box-shadow 0.15s ease;
            overflow: hidden;
            padding: 0;
        }
        .aibm-float-fab:hover {
            transform: scale(1.05);
            box-shadow:
                0 6px 16px rgba(var(--aibm-float-rgb), 0.38),
                0 12px 28px rgba(0, 0, 0, 0.14);
        }

        .aibm-float-fab .aibm-float-fab-img {
            width: 48px;
            height: 48px;
            object-fit: contain;
            display: block;
            pointer-events: none;
        }

        .aibm-float-fab .aibm-float-fab-icon.is-hidden {
            display: none;
        }

        .aibm-float-thinking .aibm-float-dot {
            display: inline-block;
            width: 6px;
            height: 6px;
            border-radius: 50%;
            background: rgba(var(--aibm-float-rgb), 0.5);
            margin: 0 3px;
            animation: aibm-float-bounce 0.85s ease-in-out infinite;
        }
        .aibm-float-thinking .aibm-float-dot:nth-child(2) { animation-delay: 0.12s; }
        .aibm-float-thinking .aibm-float-dot:nth-child(3) { animation-delay: 0.24s; }
        @keyframes aibm-float-bounce {
            0%, 80%, 100% { transform: translateY(0); opacity: 0.35; }
            40% { transform: translateY(-4px); opacity: 1; }
        }

        .aibm-report-teaser {
            position: fixed;
            bottom: 94px;
            right: 22px;
            z-index: 9997;
            width: min(360px, calc(100vw - 36px));
            border-radius: 14px;
            border: 1px solid rgba(var(--aibm-teaser-rgb), 0.35);
            background: #fff;
            box-shadow:
                0 4px 6px -1px rgba(0, 0, 0, 0.06),
                0 16px 36px -12px rgba(0, 0, 0, 0.18);
            overflow: hidden;
        }

        .aibm-report-teaser-inner {
            padding: 12px 14px;
            display: flex;
            flex-direction: column;
            gap: 10px;
        }

        .aibm-report-teaser-badge {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            font-size: 11px;
            font-weight: 700;
            letter-spacing: 0.06em;
            text-transform: uppercase;
            color: var(--aibm-teaser-accent);
        }

        .aibm-report-teaser-text {
            margin: 0;
            font-size: 13px;
            line-height: 1.45;
            color: #334155;
        }

        .aibm-report-teaser-text strong {
            color: #0f172a;
            font-weight: 600;
        }

        .aibm-report-teaser-actions {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
            align-items: center;
        }

        .aibm-report-teaser-btn-primary {
            flex: 1;
            min-width: 120px;
            padding: 9px 12px;
            border-radius: 10px;
            border: none;
            cursor: pointer;
            font-size: 13px;
            font-weight: 600;
            background: var(--aibm-teaser-accent);
            color: #fff;
            transition: opacity 0.15s ease, transform 0.1s ease;
        }

        .aibm-report-teaser-btn-primary:hover {
            opacity: 0.93;
        }

        .aibm-report-teaser-btn-primary:active {
            transform: scale(0.99);
        }

        .aibm-report-teaser-btn-quiet {
            padding: 9px 12px;
            border-radius: 10px;
            border: none;
            cursor: pointer;
            font-size: 12px;
            font-weight: 600;
            color: #64748b;
            background: transparent;
        }

        .aibm-report-teaser-btn-quiet:hover {
            color: #1e293b;
            background: #f1f5f9;
        }
    </style>

    @if (! empty($aibm_report_context))
        <div
            id="aibm-report-teaser"
            class="aibm-report-teaser no-print"
            role="region"
            aria-label="@lang('aibusinessmanager::lang.report_teaser_title')"
            style="--aibm-teaser-accent: {{ $aibm_floating_accent_hex ?? '#3c8dbc' }}; --aibm-teaser-rgb: {{ $aibm_floating_accent_rgb ?? '60, 141, 188' }};"
        >
            <div class="aibm-report-teaser-inner">
                <div class="aibm-report-teaser-copy">
                    <span class="aibm-report-teaser-badge">{{ __('aibusinessmanager::lang.eli_name') }}</span>
                    <p class="aibm-report-teaser-text">
                        {{ __('aibusinessmanager::lang.report_teaser_body', ['report' => $aibm_report_context['title']]) }}
                    </p>
                </div>
                <div class="aibm-report-teaser-actions">
                    <button type="button" id="aibm-report-teaser-open" class="aibm-report-teaser-btn-primary">
                        @lang('aibusinessmanager::lang.report_teaser_open_eli')
                    </button>
                    <button type="button" id="aibm-report-teaser-dismiss" class="aibm-report-teaser-btn-quiet">
                        @lang('aibusinessmanager::lang.report_teaser_dismiss')
                    </button>
                </div>
            </div>
        </div>
    @endif

    <div id="aibm-float-wrap" class="aibm-float-wrap">
        <div id="aibm-float-panel" class="aibm-float-panel" role="dialog" aria-label="@lang('aibusinessmanager::lang.floating_panel_aria')">
            <div id="aibm-float-panel-inner" class="aibm-float-panel-inner">
                <header class="aibm-float-header">
                    <div class="aibm-float-brand">
                        <span class="aibm-float-brand-eli">@lang('aibusinessmanager::lang.eli_name')</span><span class="aibm-float-brand-sep"> — </span><span class="aibm-float-brand-meaning">@lang('aibusinessmanager::lang.eli_meaning')</span>
                    </div>
                    <button type="button" id="aibm-float-close" class="aibm-float-close" aria-label="@lang('aibusinessmanager::lang.floating_close')">
                        <i class="fas fa-times" aria-hidden="true"></i>
                    </button>
                </header>
                <div id="aibm-float-messages" class="aibm-float-messages" aria-live="polite"></div>
                <div class="aibm-float-input-row">
                    <input type="text" id="aibm-float-input" class="aibm-float-input" maxlength="8000" autocomplete="off"
                           placeholder="@lang('aibusinessmanager::lang.floating_placeholder')">
                    <button type="button" id="aibm-float-send" class="aibm-float-send" title="@lang('aibusinessmanager::lang.send')" aria-label="@lang('aibusinessmanager::lang.send')">
                        <i class="fas fa-paper-plane" aria-hidden="true"></i>
                    </button>
                </div>
            </div>
        </div>

        <button type="button" id="aibm-float-fab" class="aibm-float-fab" aria-expanded="false" aria-controls="aibm-float-panel"
                title="@lang('aibusinessmanager::lang.floating_toggle')"
                aria-label="@lang('aibusinessmanager::lang.floating_aria_toggle')">
            @if (! empty($aibm_eli_icon_url))
                <img src="{{ $aibm_eli_icon_url }}" alt="" class="aibm-float-fab-img aibm-float-fab-icon" id="aibm-float-fab-icon-open" width="48" height="48" decoding="async">
            @else
                <i class="fas fa-comments aibm-float-fab-icon" id="aibm-float-fab-icon-open" aria-hidden="true"></i>
            @endif
            <i class="fas fa-times aibm-float-fab-icon is-hidden" id="aibm-float-fab-icon-close" aria-hidden="true"></i>
        </button>
    </div>
</div>

<script type="text/javascript">
(function () {
    var root = document.getElementById('aibm-float-root');
    if (!root) return;
    root.classList.remove('aibm-float-root-hidden');
    root.classList.add('aibm-float-root-visible');

    var chatUrl = @json($aibm_chat_post_url ?? '');
    var messagesUrl = @json($aibm_chat_messages_url ?? '');
    var reportPageContext = @json($aibm_report_context ?? null);
    var tokenEl = document.querySelector('meta[name="csrf-token"]');
    var metaCsrf = tokenEl && tokenEl.getAttribute('content') ? tokenEl.getAttribute('content').trim() : '';
    var token = metaCsrf || @json($aibm_csrf_token ?? '');

    var panel = document.getElementById('aibm-float-panel');
    var fab = document.getElementById('aibm-float-fab');
    var fabOpen = document.getElementById('aibm-float-fab-icon-open');
    var fabClose = document.getElementById('aibm-float-fab-icon-close');
    var messagesEl = document.getElementById('aibm-float-messages');
    var inputEl = document.getElementById('aibm-float-input');
    var sendBtn = document.getElementById('aibm-float-send');
    var btnClose = document.getElementById('aibm-float-close');

    var youLabel = @json(__('aibusinessmanager::lang.floating_you'));
    var aiLabel = @json(__('aibusinessmanager::lang.floating_ai_label'));
    var welcomeText = @json(__('aibusinessmanager::lang.chat_welcome_eli'));
    var loadingHistoryText = @json(__('aibusinessmanager::lang.floating_loading_history'));
    var loadOlderLabel = @json(__('aibusinessmanager::lang.floating_load_older_conversations'));
    var loadingOlderLabel = @json(__('aibusinessmanager::lang.floating_loading_older'));

    var historyReady = false;
    var oldestMsgId = null;
    var hasMoreOlder = false;
    var loadingOlder = false;

    function setInputsEnabled(on) {
        sendBtn.disabled = !on;
        inputEl.disabled = !on;
    }

    function buildMessageBlock(role, text) {
        var wrap = document.createElement('div');
        if (role === 'assistant') {
            wrap.innerHTML = '<div class="aibm-float-meta">' + window.aibmEscapeHtml(aiLabel) + '</div>' +
                '<div class="aibm-float-bubble-ai">' + window.aibmFormatAssistantText(text || '') + '</div>';
        } else if (role === 'user') {
            wrap.innerHTML = '<div class="aibm-float-meta">' + window.aibmEscapeHtml(youLabel) + '</div>' +
                '<div class="aibm-float-bubble-user">' + window.aibmEscapeHtml(text || '') + '</div>';
        }
        return wrap;
    }

    function appendAssistantFormatted(text) {
        var node = buildMessageBlock('assistant', text);
        messagesEl.appendChild(node);
        if (window.aibmFinalizeRichContent) {
            window.aibmFinalizeRichContent(node, function () {
                scrollLog();
            });
        }
    }

    function appendWelcome() {
        appendAssistantFormatted(welcomeText);
    }

    /** Snap to newest messages (bottom). Retries fix hidden-panel layout + scroll anchoring races. */
    function scrollLog() {
        function snap() {
            messagesEl.scrollTop = messagesEl.scrollHeight;
        }
        snap();
        window.requestAnimationFrame(function () {
            snap();
            window.requestAnimationFrame(snap);
        });
        window.setTimeout(snap, 0);
        window.setTimeout(snap, 80);
    }

    function removeLoadOlderWrap() {
        var w = document.getElementById('aibm-float-load-older-wrap');
        if (w) {
            w.remove();
        }
    }

    function ensureLoadOlderWrap() {
        if (!hasMoreOlder || oldestMsgId == null) {
            removeLoadOlderWrap();

            return;
        }
        if (document.getElementById('aibm-float-load-older-wrap')) {
            return;
        }
        var wrap = document.createElement('div');
        wrap.id = 'aibm-float-load-older-wrap';
        wrap.className = 'aibm-float-load-older-wrap';
        wrap.innerHTML =
            '<button type="button" id="aibm-float-load-older-btn" class="aibm-float-load-older-btn" aria-label="' +
            window.aibmEscapeHtml(loadOlderLabel) + '">' +
            '<i class="fas fa-history" aria-hidden="true"></i>' +
            '<span>' + window.aibmEscapeHtml(loadOlderLabel) + '</span></button>';
        messagesEl.insertBefore(wrap, messagesEl.firstChild);
        document.getElementById('aibm-float-load-older-btn').addEventListener('click', loadOlderChunk);
    }

    function syncLoadOlderReveal() {
        var wrap = document.getElementById('aibm-float-load-older-wrap');
        if (!wrap) {
            return;
        }
        var nearTop = messagesEl.scrollTop <= 52;
        wrap.classList.toggle('is-revealed', nearTop);
    }

    messagesEl.addEventListener('scroll', syncLoadOlderReveal, { passive: true });

    function prependHistoryChunk(list) {
        var anchor = document.getElementById('aibm-float-load-older-wrap');
        var ref = anchor ? anchor.nextSibling : messagesEl.firstChild;
        var frag = document.createDocumentFragment();
        list.forEach(function (m) {
            if (m.role !== 'user' && m.role !== 'assistant') {
                return;
            }
            frag.appendChild(buildMessageBlock(m.role, m.content || ''));
        });
        messagesEl.insertBefore(frag, ref);
        if (window.aibmFinalizeRichContent) {
            window.aibmFinalizeRichContent(messagesEl, function () {});
        }
    }

    function restoreLoadOlderButton() {
        var btn = document.getElementById('aibm-float-load-older-btn');
        if (!btn) {
            return;
        }
        btn.disabled = false;
        btn.innerHTML =
            '<i class="fas fa-history" aria-hidden="true"></i>' +
            '<span>' + window.aibmEscapeHtml(loadOlderLabel) + '</span>';
    }

    function loadOlderChunk() {
        if (loadingOlder || !hasMoreOlder || oldestMsgId == null || !messagesUrl) {
            return;
        }
        var btn = document.getElementById('aibm-float-load-older-btn');
        loadingOlder = true;
        if (btn) {
            btn.disabled = true;
            btn.innerHTML = '<span>' + window.aibmEscapeHtml(loadingOlderLabel) + '</span>';
        }

        var sep = messagesUrl.indexOf('?') >= 0 ? '&' : '?';
        var url =
            messagesUrl +
            sep +
            'before_id=' +
            encodeURIComponent(String(oldestMsgId)) +
            '&limit=100';

        var prevScrollHeight = messagesEl.scrollHeight;
        var prevScrollTop = messagesEl.scrollTop;

        fetch(url, {
            method: 'GET',
            headers: {
                'Accept': 'application/json',
                'X-Requested-With': 'XMLHttpRequest'
            },
            credentials: 'same-origin'
        })
            .then(function (r) {
                return r.json().then(function (j) {
                    return { ok: r.ok, j: j };
                });
            })
            .then(function (res) {
                var list = res.ok && res.j.success && Array.isArray(res.j.messages) ? res.j.messages : [];
                if (!list.length) {
                    hasMoreOlder = false;
                    removeLoadOlderWrap();

                    return;
                }
                prependHistoryChunk(list);
                oldestMsgId =
                    list[0] && typeof list[0].id === 'number'
                        ? list[0].id
                        : oldestMsgId;
                hasMoreOlder = !!res.j.has_more;
                if (!hasMoreOlder) {
                    removeLoadOlderWrap();
                }
                messagesEl.scrollTop = messagesEl.scrollHeight - prevScrollHeight + prevScrollTop;
                syncLoadOlderReveal();
            })
            .catch(function () {})
            .finally(function () {
                loadingOlder = false;
                restoreLoadOlderButton();
            });
    }

    function renderPersistedPage(list, serverHasMore) {
        messagesEl.innerHTML = '';
        oldestMsgId = null;
        hasMoreOlder = false;
        removeLoadOlderWrap();

        if (!list.length) {
            appendWelcome();
            scrollLog();
            syncLoadOlderReveal();

            return;
        }

        oldestMsgId = list[0] && typeof list[0].id === 'number' ? list[0].id : null;
        hasMoreOlder = !!serverHasMore && oldestMsgId != null;
        if (hasMoreOlder) {
            ensureLoadOlderWrap();
        }

        list.forEach(function (m) {
            if (m.role !== 'user' && m.role !== 'assistant') {
                return;
            }
            messagesEl.appendChild(buildMessageBlock(m.role, m.content || ''));
        });

        scrollLog();
        syncLoadOlderReveal();
        if (window.aibmFinalizeRichContent) {
            window.aibmFinalizeRichContent(messagesEl, function () {
                scrollLog();
            });
        }
    }

    function loadPersistedHistory() {
        if (!messagesUrl) {
            historyReady = true;
            setInputsEnabled(true);

            return;
        }
        setInputsEnabled(false);
        oldestMsgId = null;
        hasMoreOlder = false;
        messagesEl.innerHTML =
            '<div class="aibm-float-history-loading">' +
            window.aibmEscapeHtml(loadingHistoryText) +
            '</div>';

        fetch(messagesUrl, {
            method: 'GET',
            headers: {
                'Accept': 'application/json',
                'X-Requested-With': 'XMLHttpRequest'
            },
            credentials: 'same-origin'
        })
            .then(function (r) {
                return r.json().then(function (j) {
                    return { ok: r.ok, j: j };
                });
            })
            .then(function (res) {
                historyReady = true;
                setInputsEnabled(true);

                var list = res.ok && res.j.success && Array.isArray(res.j.messages) ? res.j.messages : [];
                var hm = res.ok && res.j.success ? !!res.j.has_more : false;

                renderPersistedPage(list, hm);
            })
            .catch(function () {
                messagesEl.innerHTML = '';
                oldestMsgId = null;
                hasMoreOlder = false;
                historyReady = true;
                setInputsEnabled(true);
                scrollLog();
            });
    }

    loadPersistedHistory();

    function setOpen(open) {
        panel.style.display = open ? 'block' : 'none';
        fab.setAttribute('aria-expanded', open ? 'true' : 'false');
        fabOpen.classList.toggle('is-hidden', open);
        fabClose.classList.toggle('is-hidden', !open);
        if (open) {
            window.requestAnimationFrame(function () {
                scrollLog();
                syncLoadOlderReveal();
            });
            window.setTimeout(function () {
                scrollLog();
                syncLoadOlderReveal();
                inputEl.focus();
            }, 100);
        }
    }

    fab.addEventListener('click', function () {
        // Panel visibility defaults from CSS (.aibm-float-panel { display: none }), so inline
        // style may be '' until we toggle — compare against 'block', not 'none'.
        setOpen(panel.style.display !== 'block');
    });
    btnClose.addEventListener('click', function () { setOpen(false); });

    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape') setOpen(false);
    });

    (function setupReportTeaser() {
        var teaser = document.getElementById('aibm-report-teaser');
        if (! teaser || ! reportPageContext || ! reportPageContext.path) {
            return;
        }
        var storageKey = 'aibm.reportTeaser.dismissed.' + reportPageContext.path;
        try {
            if (window.sessionStorage && sessionStorage.getItem(storageKey) === '1') {
                teaser.style.display = 'none';

                return;
            }
        } catch (err) {}

        var btnOpen = document.getElementById('aibm-report-teaser-open');
        var btnDismiss = document.getElementById('aibm-report-teaser-dismiss');
        if (btnOpen) {
            btnOpen.addEventListener('click', function () {
                setOpen(true);
            });
        }
        if (btnDismiss) {
            btnDismiss.addEventListener('click', function () {
                teaser.style.display = 'none';
                try {
                    if (window.sessionStorage) {
                        sessionStorage.setItem(storageKey, '1');
                    }
                } catch (err2) {}
            });
        }
    })();

    function appendUser(text) {
        messagesEl.appendChild(buildMessageBlock('user', text));
        scrollLog();
    }

    function appendThinking() {
        var wrap = document.createElement('div');
        wrap.id = 'aibm-float-thinking';
        wrap.className = 'aibm-float-thinking';
        wrap.innerHTML = '<div class="aibm-float-meta">' + window.aibmEscapeHtml(aiLabel) + '</div>' +
            '<div class="aibm-float-bubble-ai" id="aibm-float-thinking-body">' +
            '<span class="aibm-float-dot"></span><span class="aibm-float-dot"></span><span class="aibm-float-dot"></span>' +
            '</div>';
        messagesEl.appendChild(wrap);
        scrollLog();
        return document.getElementById('aibm-float-thinking-body');
    }

    function removeThinking() {
        var t = document.getElementById('aibm-float-thinking');
        if (t) t.remove();
    }

    function appendAiShell() {
        removeThinking();
        var wrap = document.createElement('div');
        wrap.innerHTML = '<div class="aibm-float-meta">' + window.aibmEscapeHtml(aiLabel) + '</div>' +
            '<div class="aibm-float-bubble-ai ai-float-ai-content"></div>';
        messagesEl.appendChild(wrap);
        scrollLog();
        return wrap.querySelector('.ai-float-ai-content');
    }

    function send() {
        var msg = (inputEl.value || '').trim();
        if (!msg || !chatUrl || !historyReady) return;

        appendUser(msg);
        inputEl.value = '';
        sendBtn.disabled = true;
        inputEl.disabled = true;
        appendThinking();

        var payload = { message: msg };
        if (reportPageContext && typeof reportPageContext === 'object') {
            payload.page_context = reportPageContext;
        }

        fetch(chatUrl, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': token,
                'Accept': 'application/json'
            },
            body: JSON.stringify(payload)
        }).then(function (r) { return r.json().then(function (j) { return { ok: r.ok, j: j }; }); })
        .then(function (res) {
            sendBtn.disabled = false;
            inputEl.disabled = false;
            var bodyEl = appendAiShell();
            if (res.ok && res.j.success) {
                window.aibmRevealAssistantReply(bodyEl, res.j.reply || '', messagesEl, {
                    wordsPerTick: 1,
                    intervalMs: 68,
                });
            } else {
                bodyEl.textContent = res.j.msg || 'Error';
                scrollLog();
            }
            inputEl.focus();
        }).catch(function () {
            sendBtn.disabled = false;
            inputEl.disabled = false;
            var bodyEl = appendAiShell();
            bodyEl.textContent = @json(__('aibusinessmanager::lang.error_generic'));
            scrollLog();
        });
    }

    sendBtn.addEventListener('click', send);
    inputEl.addEventListener('keydown', function (e) {
        if (e.key === 'Enter') {
            e.preventDefault();
            send();
        }
    });
})();
</script>
