/*
 * -----------------------------------------------------------------------------
 * The "Translate" control (#485, #761)
 * -----------------------------------------------------------------------------
 * Path: public_html/assets/js/portal-translate.js
 *
 * A page that offers a translation carries ONE element per piece of content:
 *
 *   <div data-portal-translate
 *        data-url="…/api/translation/content"   the address, built by Site::url()
 *        data-table="tblAnnouncements"          which allow-listed table
 *        data-id="42"                           the row's own number
 *        data-language="cy"                     the reader's own language
 *        data-csrf="…">                         the form token
 *     <button type="button" data-portal-translate-button>Translate</button>
 *     <span data-portal-translate-status></span>
 *     <div data-portal-translate-output hidden></div>
 *   </div>
 *
 * THE TRANSLATION GOES IN ITS OWN BLOCK, AND THE ORIGINAL IS NEVER TOUCHED.
 * That is the most important decision in this file and it is about safety, not
 * layout. Two reasons:
 *
 *   • The translated words come from an outside company. Writing them into the
 *     page as HTML would be handing a third party the ability to put markup in
 *     our page, so this only ever sets `textContent`. There is no `innerHTML`
 *     anywhere here and none may be added.
 *   • An announcement's body is Markdown rendered to HTML before it reaches
 *     the page. Replacing it with plain text and putting it back would lose
 *     the rendering, so the original stays exactly where it was and the
 *     translation appears underneath it. "The original is always available"
 *     is then true because it never went anywhere.
 *
 * WHY A FILE AND NOT A SCRIPT IN THE PAGE. Every inline script has to carry
 * the answer's own nonce (#625), and a script or element handler without one
 * is refused by the browser with nothing logged — so the page looks finished
 * and the control does nothing. A file from the portal's own address needs no
 * nonce, which is why `portal-actions.js` and `portal-offline.js` are files
 * too. There is no `onclick` anywhere here for the same reason.
 *
 * FOUR RULES THIS FILE KEEPS, each because getting it wrong has cost this
 * project something before:
 *
 *   • **THE FORM TOKEN BELONGS TO THE PAGE, NOT TO ONE BUTTON, and getting
 *     that wrong broke a form that takes money.** `Auth::csrfToken()` is ONE
 *     token per session, and `Auth::verifyCsrf()` replaces it on every
 *     acceptance. So every control on the page AND every ordinary form on it
 *     are stamped with the same token, and the first accepted press kills all
 *     of them. The first version of this file kept the replacement in the
 *     pressed element's own `data-csrf` only, and an independent check
 *     measured the cost on `/projects/view`: press Translate, then pledge, and
 *     the pledge is refused with a bare "Bad request" and what the member
 *     typed is gone — the #634 fault, on a page where somebody gives money.
 *     A second Translate control on the same page was refused for ever.
 *
 *     So `shareToken()` writes every replacement into all THREE places a page
 *     keeps the token: every control's `data-csrf`, every
 *     `input[name="csrf_token"]`, and `<meta name="csrf-token">` — which five
 *     other scripts read, two of them on every page. Leaving the meta tag out
 *     was the same fault a second time, measured: after a press, a
 *     guided-tour post answered 403 "Invalid CSRF token". That is #625's rule — "a page
 *     that writes more than once must be handed the replacement token and must
 *     keep it" — applied page-wide rather than per button.
 *
 *     **A REFUSED token carries no replacement**, and the first version of
 *     this comment claimed otherwise: `ApiAuth::requireWrite()` answers the
 *     CSRF refusal BEFORE it records the rotation, so `meta.csrfToken` is
 *     absent exactly when it would be most useful. Nothing here can recover
 *     from that, so it asks for a reload instead of showing a member the words
 *     "CSRF check failed" on every press.
 *   • **One request at a time.** Two in flight can never both succeed,
 *     because the first spends the token the second is holding.
 *   • **The deadline covers reading the answer**, not just waiting for the
 *     headers. A translation waits on an outside company, so a reply whose
 *     body never arrives is a real shape rather than a theoretical one.
 *   • **It says only what it knows.** A request that failed to come back may
 *     still have been made and paid for, so this never reports "nothing was
 *     sent"; it says the answer did not arrive, and that a second attempt is
 *     free because a translation is cached once it exists.
 * -----------------------------------------------------------------------------
 */

(function () {
    'use strict';

    /* How long to wait altogether — asking for the headers AND reading the
       body. Generous because the portal is waiting on another company's
       service, and the alternative to waiting is charging for an answer
       nobody reads. */
    var DEADLINE_MS = 45000;

    function say(box, words) {
        var where = box.querySelector('[data-portal-translate-status]');
        if (where !== null) {
            where.textContent = words;
        }
    }

    function outputBlock(box) {
        return box.querySelector('[data-portal-translate-output]');
    }

    function label(button, name, fallback) {
        var given = button.getAttribute(name);
        return (given !== null && given !== '') ? given : fallback;
    }

    /* Hand the replacement token to the WHOLE PAGE: every control, and every
       ordinary form on it. See the file comment for the measured cost of not
       doing this. Returns true when a replacement really arrived, so the
       caller can tell "refused, and we have no new token" apart from
       "refused, and we are still in step". */
    function shareToken(payload) {
        if (payload === null || typeof payload !== 'object'
            || payload.meta === null || typeof payload.meta !== 'object'
            || typeof payload.meta.csrfToken !== 'string' || payload.meta.csrfToken === '') {
            return false;
        }
        var fresh = payload.meta.csrfToken;
        var boxes = document.querySelectorAll('[data-portal-translate]');
        var fields = document.querySelectorAll('input[name="csrf_token"]');
        var meta = document.querySelector('meta[name="csrf-token"]');
        var index;
        for (index = 0; index < boxes.length; index += 1) {
            boxes[index].setAttribute('data-csrf', fresh);
        }
        /* The ordinary forms matter as much as the controls: `/projects/view`
           carries a pledge form, and leaving it holding a spent token is how
           pressing Translate came to break a payment. */
        for (index = 0; index < fields.length; index += 1) {
            fields[index].value = fresh;
        }
        /* ⚠️ THE THIRD CARRIER, AND LEAVING IT OUT WAS THE SAME FAULT AGAIN.
           `header.php` puts <meta name="csrf-token"> on EVERY page, and five
           scripts read the token out of it rather than out of a form —
           `portal.js` (which `Portal.apiRequest` uses), `portal-tour.js`,
           `push-subscribe.js`, `report-builder.js` and
           `event-hub-upload.js`. The first two load on every page, so on the
           very page where the pledge fault was measured a Translate press left
           a guided-tour post answering 403 "Invalid CSRF token" — measured
           by an independent check AFTER the first fix. The portal already had
           the right pattern in `event-hub-upload.js`'s `syncCsrf()`, which
           writes the meta tag AND every field; this was a third copy of that
           rule with a half missing. */
        if (meta !== null) {
            meta.setAttribute('content', fresh);
        }
        return true;
    }

    function wireOne(box) {
        var button = box.querySelector('[data-portal-translate-button]');
        var output = outputBlock(box);
        /* Both missing is a page fault rather than a run-time one, so the
           control does nothing at all rather than throwing on every press. */
        if (button === null || output === null) {
            return;
        }
        var busy = false;
        var fetched = false;

        button.addEventListener('click', function () {
            /* Once the translation is in the page the button only shows and
               hides it. No further request, so no further charge. */
            if (fetched === true) {
                if (output.hasAttribute('hidden') === true) {
                    output.removeAttribute('hidden');
                    button.textContent = label(button, 'data-hide-label', 'Hide the translation');
                } else {
                    output.setAttribute('hidden', 'hidden');
                    button.textContent = label(button, 'data-show-label', 'Show the translation');
                }
                return;
            }

            /* One at a time: see the file comment. */
            if (busy === true) {
                return;
            }
            busy = true;
            button.disabled = true;
            say(box, 'Translating…');

            var controller = new AbortController();
            /* Armed here and cleared only once the body has been read, so the
               deadline covers both halves of the exchange. */
            var deadline = window.setTimeout(function () {
                controller.abort();
            }, DEADLINE_MS);

            /* Read at press time, never captured earlier: another control's
               press may have replaced it since this page was drawn. */
            var token = box.getAttribute('data-csrf') || '';
            var payload = {
                sourceTable: box.getAttribute('data-table') || '',
                sourceID: box.getAttribute('data-id') || '',
                targetLanguage: box.getAttribute('data-language') || '',
                csrf_token: token
            };

            window.fetch(box.getAttribute('data-url') || '', {
                method: 'POST',
                credentials: 'same-origin',
                signal: controller.signal,
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-Token': token,
                    'Accept': 'application/json'
                },
                body: JSON.stringify(payload)
            }).then(function (response) {
                return response.json().then(function (body) {
                    return { ok: response.ok, status: response.status, body: body };
                }, function () {
                    /* An answer arrived and was not JSON. Treated as a failure
                       rather than guessed at. */
                    return { ok: false, status: response.status, body: null };
                });
            }).then(function (answer) {
                window.clearTimeout(deadline);
                var handedBack = shareToken(answer.body);

                var body = answer.body;
                if (answer.ok !== true || body === null || body.status !== 'ok') {
                    /* A refused form token is the one refusal that carries no
                       replacement, so this page cannot get back in step by
                       itself, and reloading is the only cure.

                       ⚠️ IT IS NOT THE ONLY 403 THAT CARRIES NO REPLACEMENT,
                       and the first version of this branch therefore gave
                       advice that cannot work. `ApiRouter` answers 403 "This
                       API endpoint is disabled" before any token is rotated,
                       so with the endpoint switched off a member was told to
                       reload, for ever, while the portal's own accurate words
                       were thrown away. The reload wording is used only where
                       the portal said nothing useful or said exactly the
                       form-token refusal; anything else is passed through. */
                    var said = (body !== null && typeof body.message === 'string') ? body.message : '';
                    if (answer.status === 403 && handedBack === false
                        && (said === '' || said === 'CSRF check failed')) {
                        say(box, 'This page has been open a while. Please reload it and try again.');
                        return;
                    }
                    say(box, said !== '' ? said : 'The translation could not be made.');
                    return;
                }

                var data = body.data || {};
                if (data.sameLanguage === true) {
                    say(box, typeof data.message === 'string'
                        ? data.message
                        : 'This is already in your language.');
                    return;
                }

                /* textContent, never innerHTML: see the file comment. */
                output.textContent = String(data.translation || '');
                output.removeAttribute('hidden');
                fetched = true;
                button.textContent = label(button, 'data-hide-label', 'Hide the translation');
                say(box, 'Translated by machine. The original is above, unchanged.');
            }).catch(function (problem) {
                window.clearTimeout(deadline);
                /* SAY ONLY WHAT IS KNOWN. The request may have reached the
                   portal and the outside company and been paid for; all this
                   page knows is that no answer came back to it. A second press
                   is free if the first one worked, because a translation is
                   cached once it exists. */
                var lost = (problem !== null && problem.name === 'AbortError')
                    ? 'No answer came back in time. '
                    : 'No answer came back. ';
                /* ⚠️ "Press Translate again" was a dead end in exactly the case
                   this sentence names: if the first attempt DID get through,
                   its token was spent, so a second press is refused and the
                   member is told to reload anyway. Say reload first. */
                say(box, lost + 'Reload the page and press Translate again — if the '
                    + 'first attempt did get through, the second is free.');
            }).then(function () {
                busy = false;
                button.disabled = false;
            });
        });
    }

    function wireAll() {
        var boxes = document.querySelectorAll('[data-portal-translate]');
        var index;
        for (index = 0; index < boxes.length; index += 1) {
            wireOne(boxes[index]);
        }
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', wireAll);
    } else {
        wireAll();
    }
}());
