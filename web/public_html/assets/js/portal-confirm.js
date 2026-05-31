/* =============================================================================
 * WebMS Intra — Portal.Confirm 🪟
 * =============================================================================
 * Promise-returning replacement for window.confirm() using a Bootstrap modal.
 *
 * Usage:
 *   Portal.Confirm.show({
 *     title: 'Delete record?',
 *     body: 'This cannot be undone.',
 *     destructive: true,
 *     confirmLabel: 'Delete',
 *     cancelLabel: 'Cancel'
 *   }).then(function (confirmed) {
 *     if (confirmed === true) { ... }
 *   });
 *
 * Form-helper:
 *   <form data-confirm="Delete this record? This cannot be undone."
 *         data-confirm-destructive="true">
 *
 * Replaces native window.confirm() with this themed equivalent. Provides
 * focus-trap, Esc/backdrop dismiss, role="dialog", aria-labelled-by,
 * destructive variant (btn-danger). Localisable.
 *
 * Dependencies: Bootstrap 5 JS (bundled via Asset::bootstrapJs()).
 *
 * @see   https://github.com/MWBMPartners/WebMS-Intra/issues/244
 * ============================================================================= */
(function () {
    'use strict';

    if (typeof window === 'undefined') {
        return;
    }
    window.Portal = window.Portal || {};

    var modalEl = null;
    var modalInstance = null;

    function ensureModal() {
        if (modalEl !== null) {
            return modalEl;
        }
        modalEl = document.createElement('div');
        modalEl.className = 'modal fade';
        modalEl.id = 'portalConfirmModal';
        modalEl.tabIndex = -1;
        modalEl.setAttribute('aria-hidden', 'true');
        modalEl.setAttribute('aria-labelledby', 'portalConfirmTitle');
        modalEl.innerHTML =
            '<div class="modal-dialog modal-dialog-centered modal-sm">' +
                '<div class="modal-content">' +
                    '<div class="modal-header">' +
                        '<h5 class="modal-title" id="portalConfirmTitle"></h5>' +
                        '<button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>' +
                    '</div>' +
                    '<div class="modal-body" id="portalConfirmBody"></div>' +
                    '<div class="modal-footer">' +
                        '<button type="button" class="btn btn-outline-secondary" data-portal-action="cancel"></button>' +
                        '<button type="button" class="btn btn-primary" data-portal-action="confirm"></button>' +
                    '</div>' +
                '</div>' +
            '</div>';
        document.body.appendChild(modalEl);
        return modalEl;
    }

    function show(opts) {
        opts = opts || {};
        var title = opts.title || 'Are you sure?';
        var body = opts.body || '';
        var destructive = opts.destructive === true;
        var confirmLabel = opts.confirmLabel || (destructive === true ? 'Confirm' : 'OK');
        var cancelLabel = opts.cancelLabel || 'Cancel';

        var el = ensureModal();
        el.querySelector('#portalConfirmTitle').textContent = title;
        el.querySelector('#portalConfirmBody').textContent = body;
        var confirmBtn = el.querySelector('[data-portal-action="confirm"]');
        var cancelBtn  = el.querySelector('[data-portal-action="cancel"]');
        confirmBtn.textContent = confirmLabel;
        cancelBtn.textContent  = cancelLabel;
        confirmBtn.className   = destructive === true
            ? 'btn btn-danger'
            : 'btn btn-primary';

        if (typeof bootstrap === 'undefined' || typeof bootstrap.Modal !== 'function') {
            // 🪞 No Bootstrap — fall back to native confirm so we don't
            //    silently break callers. Returns synchronously-resolved Promise.
            var ok = window.confirm(title + (body !== '' ? '\n\n' + body : ''));
            return Promise.resolve(ok);
        }

        if (modalInstance === null) {
            modalInstance = new bootstrap.Modal(el, { backdrop: 'static', keyboard: true });
        }

        return new Promise(function (resolve) {
            var settled = false;
            function settle(value) {
                if (settled === true) {
                    return;
                }
                settled = true;
                cleanup();
                modalInstance.hide();
                resolve(value);
            }
            function onConfirm() { settle(true); }
            function onCancel()  { settle(false); }
            function onHidden()  { settle(false); }
            function cleanup() {
                confirmBtn.removeEventListener('click', onConfirm);
                cancelBtn.removeEventListener('click',  onCancel);
                el.removeEventListener('hidden.bs.modal', onHidden);
            }
            confirmBtn.addEventListener('click', onConfirm);
            cancelBtn.addEventListener('click',  onCancel);
            el.addEventListener('hidden.bs.modal', onHidden);

            modalInstance.show();
            // Focus the cancel button by default (Hicks-Wilson safer default).
            setTimeout(function () { cancelBtn.focus(); }, 150);
        });
    }

    /**
     * Intercept form submissions with `data-confirm="message"` attribute,
     * OR button clicks where the button (not the form) carries the attribute
     * — useful when one form has multiple submit actions and only some need
     * confirmation.
     *
     * Optional `data-confirm-title`, `data-confirm-destructive="true"`,
     * `data-confirm-confirm-label`, `data-confirm-cancel-label`.
     */
    function bindFormInterceptor() {
        // 🪞 Button-level interception. Listens before submit so we can
        //    preserve the button's name/value as the form submitter (which
        //    a programmatic form.submit() loses). The trick: synthesize a
        //    hidden input with the button's name/value before submitting.
        document.addEventListener('click', function (e) {
            var btn = e.target.closest('button[data-confirm], input[type="submit"][data-confirm]');
            if (btn === null) {
                return;
            }
            var form = btn.form;
            if (form === null) {
                return;
            }
            if (btn.dataset.confirmAccepted === '1') {
                return;
            }
            e.preventDefault();
            show({
                title: btn.getAttribute('data-confirm-title') || 'Are you sure?',
                body: btn.getAttribute('data-confirm') || '',
                destructive: btn.getAttribute('data-confirm-destructive') === 'true',
                confirmLabel: btn.getAttribute('data-confirm-confirm-label') || '',
                cancelLabel: btn.getAttribute('data-confirm-cancel-label') || '',
            }).then(function (confirmed) {
                if (confirmed !== true) {
                    return;
                }
                btn.dataset.confirmAccepted = '1';
                // 🪞 Preserve the button's name/value for the upcoming submit.
                if (btn.name !== '' && form.querySelector('input[name="' + btn.name + '"][data-portal-confirm-shim="1"]') === null) {
                    var shim = document.createElement('input');
                    shim.type = 'hidden';
                    shim.name = btn.name;
                    shim.value = btn.value;
                    shim.setAttribute('data-portal-confirm-shim', '1');
                    form.appendChild(shim);
                }
                form.submit();
            });
        }, true);

        // 🪞 Form-level interception (for the simpler one-action-per-form case).
        document.addEventListener('submit', function (e) {
            var form = e.target;
            if (form === null || form.tagName !== 'FORM') {
                return;
            }
            var message = form.getAttribute('data-confirm');
            if (message === null || form.dataset.confirmAccepted === '1') {
                return;
            }
            e.preventDefault();
            show({
                title: form.getAttribute('data-confirm-title') || 'Are you sure?',
                body: message,
                destructive: form.getAttribute('data-confirm-destructive') === 'true',
                confirmLabel: form.getAttribute('data-confirm-confirm-label') || '',
                cancelLabel: form.getAttribute('data-confirm-cancel-label') || '',
            }).then(function (confirmed) {
                if (confirmed === true) {
                    form.dataset.confirmAccepted = '1';
                    form.submit();
                }
            });
        }, true);
    }

    window.Portal.Confirm = { show: show };

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', bindFormInterceptor);
    } else {
        bindFormInterceptor();
    }
})();
