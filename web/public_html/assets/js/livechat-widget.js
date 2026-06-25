/**
 * COP Live Chat — viewer-facing widget (#313 Phase 2 / #317 Phase 2 overlap)
 *
 * Single vanilla JS file. No build step. Embeds into the /live page when a
 * `<div data-livechat-widget data-event-id="N">` element is present. Boots
 * automatically on DOMContentLoaded.
 *
 * Responsibilities:
 *   • Mint a 32-char hex sessionToken on first load; persist in
 *     sessionStorage so a page refresh doesn't drop the viewer's identity.
 *   • Ping /api/livestream/ping every 30s to mark the viewer as live
 *     (handler at _apps/livestream/api/ping.php accepts BOTH `sessionToken`
 *     and `token` for backward compat with the older snippet).
 *   • Poll /api/livechat/list every 4s with sinceID = last messageID seen,
 *     advancing the local cursor on each response so we never re-fetch.
 *   • POST /api/livechat/send when the user submits the form.
 *   • Poll /api/livechat/prompts every 8s for active host pushes;
 *     deduplicate via a Set of seen promptIDs.
 *   • Client-side scheme allowlist on prompt ctaUrl — empty / root-relative
 *     (not '//') / http / https only. javascript: data: vbscript: file:
 *     URLs are dropped client-side as belt-and-braces with the server-side
 *     LivePrompt::validateCtaUrl gate.
 *   • XSS-safe: every viewer-supplied string is set via textContent /
 *     escapeHtml; never innerHTML with untrusted input.
 *
 * @see https://github.com/MWBMPartners/WebMS-Intra/issues/313
 * @see https://github.com/MWBMPartners/WebMS-Intra/issues/317
 */
(function () {
    'use strict';

    const POLL_MESSAGES_MS = 4000;
    const POLL_PROMPTS_MS  = 8000;
    const PING_MS          = 30000;
    const ORIGIN           = window.location.protocol + '//' + window.location.host;

    function mintSessionToken() {
        let t = sessionStorage.getItem('webms-livechat-token');
        if (t && /^[a-f0-9]{32,64}$/.test(t)) {
            return t;
        }
        // 16 random bytes → 32 hex chars (server accepts 32-64).
        const buf = new Uint8Array(16);
        crypto.getRandomValues(buf);
        t = Array.from(buf, b => b.toString(16).padStart(2, '0')).join('');
        sessionStorage.setItem('webms-livechat-token', t);
        return t;
    }

    function escapeHtml(s) {
        return String(s)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#39;');
    }

    // ctaUrl allowlist — matches server-side LivePrompt::validateCtaUrl.
    function safeCtaUrl(u) {
        if (typeof u !== 'string' || u === '') { return null; }
        if (/[\s\x00-\x1F\x7F]/.test(u)) { return null; }
        if (u.startsWith('//')) { return null; }
        if (u.startsWith('/'))  { return u; }
        try {
            const parsed = new URL(u);
            if (parsed.protocol === 'http:' || parsed.protocol === 'https:') {
                return parsed.href;
            }
        } catch (_) { /* not parseable → reject */ }
        return null;
    }

    function getCaptchaPayload(form) {
        const out = {};
        // Common captcha provider field names; widget reads form data flexibly.
        for (const name of ['cf-turnstile-response', 'g-recaptcha-response', 'h-captcha-response']) {
            const el = form.querySelector('[name="' + name + '"]');
            if (el && el.value) { out[name] = el.value; }
        }
        return out;
    }

    function makeWidget(host, eventID) {
        const token   = mintSessionToken();
        const seenIds = new Set();
        let lastMsgId = 0;

        host.innerHTML = ''; // clear placeholder
        host.classList.add('webms-livechat');

        const css = `
.webms-livechat { font-family: var(--portal-font-family, system-ui, sans-serif); border: 1px solid #dee2e6; border-radius: .5rem; background: #fff; display: flex; flex-direction: column; max-height: 480px; }
.webms-livechat .webms-livechat-prompts { padding: .5rem; }
.webms-livechat .webms-livechat-prompt { background: #e7f1ff; border-left: 4px solid #0d6efd; padding: .5rem .75rem; border-radius: .25rem; margin-bottom: .35rem; }
.webms-livechat .webms-livechat-prompt strong { display: block; margin-bottom: .15rem; }
.webms-livechat .webms-livechat-prompt a { display: inline-block; margin-top: .35rem; padding: .25rem .75rem; background: #0d6efd; color: #fff; text-decoration: none; border-radius: .25rem; font-size: .9em; }
.webms-livechat .webms-livechat-stream { flex: 1 1 auto; overflow-y: auto; padding: .5rem .75rem; min-height: 220px; }
.webms-livechat .webms-livechat-msg { padding: .35rem 0; border-bottom: 1px solid #f1f3f5; font-size: .92em; }
.webms-livechat .webms-livechat-msg .nm { font-weight: 600; color: #0d6efd; margin-right: .35rem; }
.webms-livechat .webms-livechat-form { display: flex; gap: .35rem; padding: .5rem; border-top: 1px solid #dee2e6; background: #f8f9fa; }
.webms-livechat .webms-livechat-form input[name="displayName"] { flex: 0 0 130px; padding: .35rem .5rem; border: 1px solid #ced4da; border-radius: .25rem; }
.webms-livechat .webms-livechat-form input[name="body"] { flex: 1 1 auto; padding: .35rem .5rem; border: 1px solid #ced4da; border-radius: .25rem; }
.webms-livechat .webms-livechat-form button { padding: .35rem .85rem; background: #0d6efd; color: #fff; border: 0; border-radius: .25rem; cursor: pointer; }
.webms-livechat .webms-livechat-form button:disabled { background: #6c757d; cursor: not-allowed; }
.webms-livechat .webms-livechat-status { padding: .25rem .75rem; font-size: .8em; color: #6c757d; min-height: 1.2em; }
`;
        const styleEl = document.createElement('style');
        styleEl.textContent = css;
        host.appendChild(styleEl);

        const promptsEl = document.createElement('div');
        promptsEl.className = 'webms-livechat-prompts';
        host.appendChild(promptsEl);

        const streamEl = document.createElement('div');
        streamEl.className = 'webms-livechat-stream';
        host.appendChild(streamEl);

        const form = document.createElement('form');
        form.className = 'webms-livechat-form';
        form.innerHTML =
            '<input name="displayName" type="text" maxlength="40" placeholder="Your name" required>' +
            '<input name="body" type="text" maxlength="500" placeholder="Say something…" required>' +
            '<button type="submit">Send</button>';
        host.appendChild(form);

        const statusEl = document.createElement('div');
        statusEl.className = 'webms-livechat-status';
        host.appendChild(statusEl);

        function setStatus(text) {
            statusEl.textContent = String(text || '');
        }

        function renderMessages(msgs) {
            for (const m of msgs) {
                const id = Number(m.messageID);
                if (!Number.isFinite(id) || seenIds.has(id)) { continue; }
                seenIds.add(id);
                lastMsgId = Math.max(lastMsgId, id);

                const row = document.createElement('div');
                row.className = 'webms-livechat-msg';
                const name = document.createElement('span');
                name.className = 'nm';
                name.textContent = String(m.displayName || '');
                const body = document.createElement('span');
                body.textContent = String(m.body || '');
                row.appendChild(name);
                row.appendChild(body);
                streamEl.appendChild(row);
            }
            streamEl.scrollTop = streamEl.scrollHeight;
        }

        const seenPromptIds = new Set();

        function renderPrompts(prompts) {
            // 1. drop stale (now expired or removed from server-side list)
            const visibleIds = new Set(prompts.map(p => Number(p.promptID)));
            for (const child of Array.from(promptsEl.querySelectorAll('[data-prompt-id]'))) {
                const id = Number(child.getAttribute('data-prompt-id'));
                if (!visibleIds.has(id)) {
                    child.remove();
                    seenPromptIds.delete(id);
                }
            }
            // 2. add new
            for (const p of prompts) {
                const id = Number(p.promptID);
                if (!Number.isFinite(id) || seenPromptIds.has(id)) { continue; }
                seenPromptIds.add(id);

                const box = document.createElement('div');
                box.className = 'webms-livechat-prompt';
                box.setAttribute('data-prompt-id', String(id));

                const title = document.createElement('strong');
                title.textContent = String(p.title || '');
                box.appendChild(title);

                if (p.body) {
                    const body = document.createElement('div');
                    body.textContent = String(p.body);
                    box.appendChild(body);
                }

                if (p.ctaLabel && p.ctaUrl) {
                    const safe = safeCtaUrl(p.ctaUrl);
                    if (safe !== null) {
                        const a = document.createElement('a');
                        a.href = safe;
                        a.rel = 'noopener noreferrer';
                        a.target = '_blank';
                        a.textContent = String(p.ctaLabel);
                        box.appendChild(a);
                    }
                }
                promptsEl.appendChild(box);
            }
        }

        async function ping(leaving) {
            try {
                await fetch(ORIGIN + '/api/livestream/ping', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ sessionToken: token, eventID: eventID, leaving: !!leaving }),
                    keepalive: true,
                    credentials: 'omit',
                });
            } catch (_) { /* swallow */ }
        }

        async function pollMessages() {
            try {
                const r = await fetch(ORIGIN + '/api/livechat/list?eventID=' + encodeURIComponent(String(eventID)) + '&sinceID=' + lastMsgId, {
                    credentials: 'omit',
                });
                if (!r.ok) { return; }
                const j = await r.json();
                if (j && j.data && Array.isArray(j.data.messages)) {
                    renderMessages(j.data.messages);
                }
            } catch (_) { /* swallow */ }
        }

        async function pollPrompts() {
            try {
                const r = await fetch(ORIGIN + '/api/livechat/prompts?eventID=' + encodeURIComponent(String(eventID)), {
                    credentials: 'omit',
                });
                if (!r.ok) { return; }
                const j = await r.json();
                if (j && j.data && Array.isArray(j.data.prompts)) {
                    renderPrompts(j.data.prompts);
                }
            } catch (_) { /* swallow */ }
        }

        form.addEventListener('submit', async (ev) => {
            ev.preventDefault();
            const submitBtn = form.querySelector('button');
            const nameEl    = form.querySelector('input[name="displayName"]');
            const bodyEl    = form.querySelector('input[name="body"]');
            const displayName = String(nameEl.value || '').slice(0, 40);
            const body        = String(bodyEl.value || '').slice(0, 500);
            if (!displayName || !body) { return; }

            submitBtn.disabled = true;
            setStatus('Sending…');
            try {
                const payload = Object.assign({
                    sessionToken: token,
                    eventID: eventID,
                    displayName: displayName,
                    body: body,
                }, getCaptchaPayload(form));
                const r = await fetch(ORIGIN + '/api/livechat/send', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify(payload),
                    credentials: 'omit',
                });
                const j = await r.json().catch(() => null);
                if (!r.ok) {
                    setStatus((j && j.error && (j.error.message || j.error)) || 'Send failed.');
                } else {
                    bodyEl.value = '';
                    setStatus(j && j.data && j.data.status === 'pending' ? 'Sent — awaiting moderator.' : 'Sent.');
                    pollMessages();
                }
            } catch (_) {
                setStatus('Network error.');
            } finally {
                submitBtn.disabled = false;
            }
        });

        // Boot loop.
        ping(false);
        pollMessages();
        pollPrompts();
        setInterval(() => ping(false), PING_MS);
        setInterval(pollMessages, POLL_MESSAGES_MS);
        setInterval(pollPrompts,  POLL_PROMPTS_MS);
        window.addEventListener('beforeunload', () => ping(true));
    }

    function init() {
        const hosts = document.querySelectorAll('[data-livechat-widget]');
        for (const host of hosts) {
            const eventID = Number(host.getAttribute('data-event-id'));
            if (!Number.isFinite(eventID) || eventID <= 0) { continue; }
            makeWidget(host, eventID);
        }
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
