@extends('layouts.app')
@section('title', __('aibusinessmanager::lang.module_title'))

@section('content')
<section class="content-header">
    <h1>@lang('aibusinessmanager::lang.chat_heading')</h1>
    <p class="text-muted" style="margin-top:4px;margin-bottom:0;font-size:13px;">
        @lang('aibusinessmanager::lang.eli_brand_line', ['eli' => __('aibusinessmanager::lang.eli_name'), 'meaning' => __('aibusinessmanager::lang.eli_meaning')])
    </p>
    @if(!empty($first_name))
        <small>@lang('aibusinessmanager::lang.welcome_back', ['name' => $first_name])</small>
    @endif
</section>

<section class="content">
    <div
        class="aibm-theme-scope"
        style="--aibm-accent: {{ $theme_accent['hex'] }}; --aibm-accent-rgb: {{ $theme_accent['rgb'] }};"
    >
        <style>
            .aibm-theme-scope .aibm-grid {
                display: grid;
                grid-template-columns: minmax(0, 1fr) 320px;
                gap: 14px;
                align-items: start;
            }
            .aibm-theme-scope .aibm-grid.context-collapsed {
                grid-template-columns: minmax(0, 1fr);
                gap: 0;
            }
            .aibm-theme-scope .aibm-grid.context-collapsed #aibm-context-panel {
                display: none !important;
            }
            .aibm-theme-scope #aibm-context-reveal {
                display: none;
                flex-shrink: 0;
                align-self: flex-start;
                border-radius: 8px;
                border: 1px solid rgba(var(--aibm-accent-rgb), 0.35);
                background: #fff;
                color: var(--aibm-accent);
                font-weight: 600;
            }
            .aibm-theme-scope .aibm-grid.context-collapsed #aibm-context-reveal {
                display: inline-flex;
                align-items: center;
                gap: 6px;
            }
            .aibm-theme-scope .aibm-panel {
                border: 1px solid rgba(var(--aibm-accent-rgb), 0.22);
                border-radius: 12px;
                background: rgba(var(--aibm-accent-rgb), 0.04);
            }
            .aibm-theme-scope .aibm-chat-shell {
                border: 1px solid rgba(var(--aibm-accent-rgb), 0.22);
                border-radius: 12px;
                background: #fff;
            }
            .aibm-theme-scope .aibm-chat-log {
                min-height: 280px;
                max-height: 58vh;
                overflow-y: auto;
                background: #fff;
                border: 1px solid rgba(var(--aibm-accent-rgb), 0.14);
                border-radius: 10px;
                padding: 14px;
            }
            .aibm-theme-scope .aibm-title { font-weight: 700; color: var(--aibm-accent); }
            .aibm-theme-scope .aibm-context-body {
                max-height: 70vh;
                overflow: auto;
                white-space: pre-wrap;
                font-size: 11px;
                background: #fff;
                border: 1px solid rgba(var(--aibm-accent-rgb), 0.14);
                padding: 10px;
                border-radius: 8px;
            }
            .aibm-theme-scope .aibm-context-toggle { width: 100%; }
            .aibm-theme-scope .aibm-user-bubble {
                background: rgba(var(--aibm-accent-rgb), 0.1);
                border: 1px solid rgba(var(--aibm-accent-rgb), 0.28);
            }
            .aibm-theme-scope .aibm-assistant-bubble {
                background: rgba(var(--aibm-accent-rgb), 0.06);
                border: 1px solid rgba(var(--aibm-accent-rgb), 0.18);
            }
            .aibm-theme-scope .aibm-input-border { border: 1px solid rgba(var(--aibm-accent-rgb), 0.22) !important; }
            .aibm-theme-scope .aibm-send-btn {
                background: var(--aibm-accent) !important;
                border-color: var(--aibm-accent) !important;
                color: #fff !important;
            }
            .aibm-theme-scope .aibm-thinking-inline {
                display: flex;
                align-items: center;
                gap: 12px;
                min-height: 32px;
            }
            .aibm-theme-scope .aibm-shimmer-bar {
                flex: 1;
                height: 10px;
                border-radius: 999px;
                overflow: hidden;
                background: rgba(var(--aibm-accent-rgb), 0.08);
            }
            .aibm-theme-scope .aibm-shimmer-move {
                height: 100%;
                width: 42%;
                background: linear-gradient(
                    90deg,
                    transparent,
                    rgba(var(--aibm-accent-rgb), 0.55),
                    transparent
                );
                animation: aibm-bar-slide 1.1s ease-in-out infinite;
            }
            @keyframes aibm-bar-slide {
                0% { transform: translateX(-120%); }
                100% { transform: translateX(260%); }
            }
            .aibm-theme-scope .aibm-dot-loader {
                display: flex;
                gap: 4px;
                align-items: center;
            }
            .aibm-theme-scope .aibm-dot-loader span {
                width: 7px;
                height: 7px;
                border-radius: 50%;
                background: var(--aibm-accent);
                opacity: 0.35;
                animation: aibm-dot-bounce 0.9s ease-in-out infinite;
            }
            .aibm-theme-scope .aibm-dot-loader span:nth-child(2) { animation-delay: 0.15s; }
            .aibm-theme-scope .aibm-dot-loader span:nth-child(3) { animation-delay: 0.3s; }
            @keyframes aibm-dot-bounce {
                0%, 80%, 100% { transform: translateY(0); opacity: 0.35; }
                40% { transform: translateY(-5px); opacity: 1; }
            }
        </style>

        @include('aibusinessmanager::partials.assistant_markdown_js')

        <div id="aibm-grid" class="aibm-grid">
            <div class="aibm-chat-shell">
                <div class="box-header with-border" style="border-bottom:1px solid rgba(var(--aibm-accent-rgb),0.12);padding:12px 14px;">
                    <div class="aibm-chat-header-row" style="display:flex;align-items:flex-start;justify-content:space-between;gap:12px;flex-wrap:wrap;">
                        <div style="min-width:0;flex:1;">
                            <h3 class="box-title aibm-title" style="margin-top:0;">@lang('aibusinessmanager::lang.chat_intro')</h3>
                            <div class="text-muted" style="margin-top:4px;font-size:12px;">@lang('aibusinessmanager::lang.chat_saved_hint')</div>
                        </div>
                        <button type="button" id="aibm-context-reveal" class="btn btn-default btn-sm">@lang('aibusinessmanager::lang.context_show')</button>
                    </div>
                </div>
                <div class="box-body" style="padding:14px;">
                    <div id="ai-bm-chat-log" class="aibm-chat-log"></div>

                    <div class="form-group" style="margin-top:12px;margin-bottom:8px;">
                        <textarea id="ai-bm-input" class="form-control aibm-input-border" rows="3" style="resize:vertical;border-radius:8px;" placeholder="@lang('aibusinessmanager::lang.placeholder')"></textarea>
                    </div>
                    <div style="display:flex;gap:8px;align-items:center;">
                        <button type="button" id="ai-bm-send" class="btn btn-primary aibm-send-btn">@lang('aibusinessmanager::lang.send')</button>
                    </div>
                    <form method="post" action="{{ $clear_url }}" style="margin-top:8px;">
                        {{ csrf_field() }}
                        <button type="submit" class="btn btn-link btn-xs" onclick="return confirm('Start a new chat and clear current history?')">@lang('aibusinessmanager::lang.clear')</button>
                    </form>
                </div>
            </div>

            <aside id="aibm-context-panel" class="aibm-panel">
                <div style="padding:10px;">
                    <button type="button" id="aibm-context-toggle" class="btn btn-default btn-xs aibm-context-toggle">@lang('aibusinessmanager::lang.context_hide')</button>
                    <div class="aibm-context-title aibm-title" style="margin:10px 0 8px 0;">@lang('aibusinessmanager::lang.business_snapshot')</div>
                    <div class="aibm-context-body">{{ $snapshot }}</div>
                </div>
            </aside>
        </div>
    </div>
</section>
@endsection

@section('javascript')
<script type="text/javascript">
(function () {
    var chatUrl = @json($chat_url);
    var historyMessages = @json($messages);
    var token = document.querySelector('meta[name="csrf-token"]');
    token = token ? token.getAttribute('content') : '';

    var logEl = document.getElementById('ai-bm-chat-log');
    var inputEl = document.getElementById('ai-bm-input');
    var sendBtn = document.getElementById('ai-bm-send');
    var gridEl = document.getElementById('aibm-grid');
    var contextToggle = document.getElementById('aibm-context-toggle');
    var contextReveal = document.getElementById('aibm-context-reveal');

    var hideContextText = @json(__('aibusinessmanager::lang.context_hide'));
    var showContextText = @json(__('aibusinessmanager::lang.context_show'));
    var eliName = @json(__('aibusinessmanager::lang.eli_name'));
    var isContextCollapsed = false;

    function appendBubble(role, text) {
        var wrap = document.createElement('div');
        wrap.style.marginBottom = '12px';
        var label = role === 'user' ? 'You' : eliName;
        var bubbleClass = role === 'user' ? 'aibm-user-bubble' : 'aibm-assistant-bubble';
        wrap.innerHTML = '<div style="font-size:11px;color:#888;margin-bottom:4px;">' + window.aibmEscapeHtml(label) + '</div>' +
            '<div class="' + bubbleClass + '" style="white-space:pre-wrap;padding:10px;border-radius:8px;">' + window.aibmEscapeHtml(text) + '</div>';
        logEl.appendChild(wrap);
        logEl.scrollTop = logEl.scrollHeight;
    }

    function appendFormattedAssistantBubble(text) {
        var wrap = document.createElement('div');
        wrap.style.marginBottom = '12px';
        wrap.innerHTML = '<div style="font-size:11px;color:#888;margin-bottom:4px;">' + window.aibmEscapeHtml(eliName) + '</div>' +
            '<div class="aibm-assistant-bubble" style="white-space:pre-wrap;padding:10px;border-radius:8px;">' + window.aibmFormatAssistantText(text) + '</div>';
        logEl.appendChild(wrap);
        logEl.scrollTop = logEl.scrollHeight;
        if (window.aibmFinalizeRichContent) {
            window.aibmFinalizeRichContent(wrap, function () {
                logEl.scrollTop = logEl.scrollHeight;
            });
        }
    }

    function appendAssistantBubbleShell() {
        var wrap = document.createElement('div');
        wrap.style.marginBottom = '12px';
        wrap.innerHTML = '<div style="font-size:11px;color:#888;margin-bottom:4px;">' + window.aibmEscapeHtml(eliName) + '</div>' +
            '<div class="ai-bm-assistant-content aibm-assistant-bubble" style="padding:10px;border-radius:8px;"></div>';
        logEl.appendChild(wrap);
        logEl.scrollTop = logEl.scrollHeight;
        return wrap.querySelector('.ai-bm-assistant-content');
    }

    function setAssistantThinking(el) {
        el.innerHTML = '<div class="aibm-thinking-inline">' +
            '<div class="aibm-shimmer-bar"><div class="aibm-shimmer-move"></div></div>' +
            '<div class="aibm-dot-loader"><span></span><span></span><span></span></div>' +
            '</div>';
    }

    function setLoading(on) {
        sendBtn.disabled = on;
        inputEl.disabled = on;
    }

    function setContextCollapsed(collapse) {
        isContextCollapsed = collapse;
        gridEl.classList.toggle('context-collapsed', collapse);
        contextToggle.textContent = collapse ? showContextText : hideContextText;
    }

    function send() {
        var msg = (inputEl.value || '').trim();
        if (!msg) return;

        appendBubble('user', msg);
        inputEl.value = '';
        setLoading(true);
        var assistantBubble = appendAssistantBubbleShell();
        setAssistantThinking(assistantBubble);

        fetch(chatUrl, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': token,
                'Accept': 'application/json'
            },
            body: JSON.stringify({ message: msg })
        }).then(function (r) { return r.json().then(function (j) { return { ok: r.ok, j: j }; }); })
        .then(function (res) {
            setLoading(false);
            if (res.ok && res.j.success) {
                window.aibmRevealAssistantFadeIn(assistantBubble, res.j.reply || '', logEl);
                inputEl.focus();
            } else {
                assistantBubble.textContent = res.j.msg || 'Error';
                inputEl.focus();
            }
        }).catch(function () {
            setLoading(false);
            assistantBubble.textContent = @json(__('aibusinessmanager::lang.error_generic'));
            inputEl.focus();
        });
    }

    sendBtn.addEventListener('click', send);
    inputEl.addEventListener('keydown', function (e) {
        if (e.key === 'Enter' && !e.shiftKey) {
            e.preventDefault();
            send();
        }
    });
    contextToggle.addEventListener('click', function () {
        setContextCollapsed(!isContextCollapsed);
    });
    contextReveal.addEventListener('click', function () {
        setContextCollapsed(false);
    });

    if (Array.isArray(historyMessages) && historyMessages.length) {
        historyMessages.forEach(function (m) {
            if (m.role === 'assistant') {
                appendFormattedAssistantBubble(m.content || '');
            } else if (m.role === 'user') {
                appendBubble('user', m.content || '');
            }
        });
    } else {
        appendFormattedAssistantBubble(@json(__('aibusinessmanager::lang.chat_welcome_eli')));
    }
})();
</script>
@endsection
