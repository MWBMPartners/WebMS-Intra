/* =============================================================================
 * WebMS Intra — small actions an element asks for with a data- attribute
 * =============================================================================
 * Path: web/public_html/assets/js/portal-actions.js
 *
 * Two things many pages need, each asked for by ONE attribute on the element:
 *
 *   data-portal-submit-on-change
 *       On a <select>, a tick box or a date box. When its value changes, the
 *       form it belongs to is sent. For a filter with no button of its own.
 *           <select name="status" data-portal-submit-on-change> ... </select>
 *
 *   data-portal-select-on-click
 *       On a read-only text box that holds something to copy, such as a public
 *       link. A click selects all of its text.
 *           <input type="text" readonly value="..." data-portal-select-on-click>
 *
 * WHY THIS FILE EXISTS (#625). These used to be written on the element itself:
 * onchange="this.form.submit()" and onclick="this.select()". Since 3 June 2026
 * (#144) the page header tells browsers to run only scripts that carry the
 * page's one-time mark (a nonce), and a browser that knows about such marks
 * then REFUSES every handler written on an element. Nothing reported it: the
 * page looked the same, and sixteen controls that send their form and three
 * "click to select" boxes simply did nothing (on the venues pages no filter
 * could be used at all, and a small group's leader could not change a member's
 * role). A script FILE from the portal's own address is allowed, so the
 * listening is done here.
 *
 * DO NOT turn one of these back into an on...="" attribute. It will look right
 * and do nothing. tools/audit-checks/check_inline_scripts_have_nonce.py fails
 * on one, and tools/page-scripts-selftest.php fails on a real page that sends
 * one.
 *
 * WHAT IT DOES NOT DO
 *   - It sends the form with form.submit(), exactly as the old handlers did.
 *     That skips the browser's own "is every required box filled in?" check and
 *     never tells anything listening for the form being sent. So a form with
 *     data-confirm would NOT ask first, and a form marked for the offline queue
 *     would not be queued. None of the forms that use this attribute has
 *     either. A form that needs them wants a button, not this attribute.
 *   - Nothing here is page-specific. A page's own behaviour stays in that
 *     page's own script (which must carry the nonce).
 *   - It reads no address and sends no request of its own.
 *
 * Loaded once, from web/_core/templates/footer.php, with `defer`. Both
 * listeners sit on the document, so they also serve an element that a script
 * adds to the page later.
 *
 * @package    Portal\Assets
 * @author     MWBM Partners Ltd (t/a MWservices)
 * @copyright  2026-present MWBM Partners Ltd (t/a MWservices). All Rights Reserved.
 * @version    1.4.0
 * @see        https://github.com/MWBMPartners/WebMS-Intra/issues/625
 * ============================================================================= */
(function () {
    'use strict';

    if (typeof document === 'undefined') {
        return;
    }

    // The element an event happened on, or null when it is not an element at
    // all (a click can land on the document itself).
    function elementOf(event) {
        var target = event.target;
        if (target === null || typeof target.hasAttribute !== 'function') {
            return null;
        }
        return target;
    }

    // 📨 Send the form when the choice changes.
    document.addEventListener('change', function (event) {
        var el = elementOf(event);
        if (el === null || el.hasAttribute('data-portal-submit-on-change') === false) {
            return;
        }
        // `el.form` is the form the control belongs to, wherever it sits
        // inside it. An element outside any form has none: do nothing.
        if (el.form) {
            el.form.submit();
        }
    });

    // 🖱️ Select all the text of a box when it is clicked.
    document.addEventListener('click', function (event) {
        var el = elementOf(event);
        if (el === null || el.hasAttribute('data-portal-select-on-click') === false) {
            return;
        }
        if (typeof el.select === 'function') {
            el.select();
        }
    });
}());
