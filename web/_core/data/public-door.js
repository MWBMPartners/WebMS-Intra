/* ============================================================================
 * Path: _core/data/public-door.js
 * The public door's script — what an organisation pastes into its own website
 * (#493 package P4b; D-056, D-057, D-084, D-097)
 * ============================================================================
 * Served ONLY through the public door (`Portal\Core\PublicDoor`), at
 * /w/{name}/door.js on the portal's own address or /door.js on the
 * organisation's approved public address. It lives outside the web root on
 * purpose, so the door's one gate (door on, organisation active, app on, the
 * test-copy rule) decides whether it answers, exactly as it does for the
 * feeds.
 *
 * WHAT TO PASTE (the card on Admin → Public website prints this with the real
 * address filled in):
 *
 *   <script src="https://portal.example.org/w/NAME/door.js" data-show="events" defer></script>
 *
 * Options, all on the <script> tag:
 *   data-show     "events" (the default): the events on the public website;
 *                 "posters": the noticeboard posters on the public website;
 *                 "next": a countdown to the next event — the old countdown
 *                 widget (#319) lives on as this mode (D-084).
 *   data-limit    how many to show, 1 to 50 (default 10). Ignored by "next".
 *   data-target   a CSS selector naming where to draw. Without it the script
 *                 draws just before its own <script> tag (or at the end of the
 *                 page when the tag sits in the page's <head>).
 *   data-lang     the language dates are written in, such as "en-GB" or "cy".
 *                 Default: the page's own language, else the browser's.
 *   data-refresh  "next" only: minutes between fresh looks at the feed, 1 to
 *                 1440 (default 15). The clock itself ticks every second.
 *
 * HOW IT DRAWS, AND WHY
 *   - The address of its feed is worked out from the script's OWN address
 *     (door.js → events.json or posters.json beside it), so the same file works
 *     on /w/{name}/ and on a public address, and nothing about the
 *     organisation is written into the script.
 *   - TEXT ONLY. Every word from the feed is put on the page with
 *     `textContent` into elements made with `createElement`. NOTHING here
 *     turns text into HTML: no innerHTML, outerHTML, insertAdjacentHTML or
 *     document.write anywhere, so a title such as `<img onerror=…>` shows as
 *     those characters and never runs. A link is drawn only for an address
 *     whose protocol is https:, and a picture only from an https: address.
 *   - INSIDE A SHADOW ROOT on an element of its own, so the website's styles
 *     do not reach in and this script's styles do not leak out. The ONE thing
 *     it adds to the website's own page is that empty element — no style
 *     attribute, no <style> in the page's head, no global variable.
 *   - CSP-FRIENDLY. No eval, no `new Function`, no timer given a string. Its
 *     styles go in as a constructed stylesheet (`adoptedStyleSheets`), which a
 *     website's Content-Security-Policy does not block; only where a browser
 *     lacks that does it fall back to a <style> element inside the shadow
 *     root, which a strict policy may refuse — the words still appear, just
 *     unstyled. A website with a policy must allow this portal's address for
 *     scripts (script-src), for reading the feed (connect-src) and, for
 *     poster pictures, for images (img-src).
 *   - It asks for the feed WITHOUT cookies (`credentials: 'omit'`): the door
 *     answers everybody alike, and sends `Access-Control-Allow-Origin: *`,
 *     which browsers refuse to combine with credentials anyway.
 *   - When the feed cannot be read (the door switched off, its name replaced,
 *     the app switched off, a network fault) it draws NOTHING and writes one
 *     line to the browser's console: silence is the failure mode (D-057).
 *
 * TIMES
 *   Each event's time is written on the event's OWN clock, read straight from
 *   the feed's text — `startsOnClock`, the reading as stored, which inside the
 *   hour a clock skips is still the time the organisation wrote — so a
 *   visitor in another country sees the time the event happens where it
 *   happens, not a converted one. Where an event's clock differs from the
 *   organisation's, its zone's name is added. The countdown is the one place
 *   a real instant is needed, and the feed's offset gives it.
 *
 * WHAT IT CANNOT DO, said plainly
 *   - Its own words ("No upcoming events.", "Every Sunday", "days") are in
 *     English. Dates and weekday names follow data-lang.
 *   - It cannot work where the website forbids scripts from other addresses
 *     (some website builders do): the frameable page (#493 P4c) is for those.
 *   - A visitor sees a change within about a minute, the time the door lets
 *     browsers keep a copy.
 *   - It needs a browser from about 2020 onwards (shadow roots, fetch, URL).
 *
 * @package   Portal\Core
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2026-present MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   1.0.0
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/493
 * ============================================================================ */
(function () {
    'use strict';

    // 🛟 Too old a browser: do nothing at all rather than half a thing.
    if (typeof window.fetch !== 'function' || typeof window.URL !== 'function'
        || typeof Element === 'undefined' || typeof Element.prototype.attachShadow !== 'function') {
        return;
    }

    // 📋 The <script> tag that loaded THIS copy. `currentScript` is set while a
    //    classic script (deferred or async included) first runs, and is the
    //    only reliable way to tell two pasted copies apart on one page.
    var me = document.currentScript;
    if (!me || !me.src) {
        return;
    }

    var LABEL = '[webms-door]';
    var EVENTS_FORMAT = 'webms-public-events';
    var POSTERS_FORMAT = 'webms-public-posters';
    var FORMAT_VERSION = 1;
    var WEEKDAYS = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];

    var show = (me.getAttribute('data-show') || 'events').toLowerCase();
    if (show !== 'events' && show !== 'posters' && show !== 'next') {
        warn('data-show must be "events", "posters" or "next"; nothing is shown');
        return;
    }
    var limit = wholeNumber(me.getAttribute('data-limit'), 10, 1, 50);
    var refreshMinutes = wholeNumber(me.getAttribute('data-refresh'), 15, 1, 1440);
    var locale = chooseLocale(me.getAttribute('data-lang') || document.documentElement.getAttribute('lang') || '');

    // 🔗 The feed beside this script: door.js → events.json / posters.json.
    //    Only an http(s) address is believed (a script cannot really be
    //    loaded from anything else, but the check costs nothing).
    var feedUrl;
    try {
        var mine = new URL(me.src);
        if (mine.protocol !== 'https:' && mine.protocol !== 'http:') {
            return;
        }
        feedUrl = new URL(show === 'posters' ? 'posters.json' : 'events.json', mine);
        // "next" asks for the most the door gives: the soonest event is picked
        // here by its real instant, not by the feed's order.
        // ⚠️ Since #882 (D-129) an event with no end time stays in the feed
        //    until the end of its day, so on a busy day events that began
        //    earlier take some of these 50 places although the countdown skips
        //    them (endOf() below). It cannot ask for more: 50 is the most the
        //    door gives, and a larger `limit` is refused, never shortened. It
        //    would take more than 50 events already running or begun today
        //    with no end time, all listed ahead of the next one, for that next
        //    one to be pushed out; the countdown then shows the soonest it was
        //    given. Accepted rather than solved (round 2 of #882's check).
        feedUrl.searchParams.set('limit', String(show === 'next' ? 50 : limit));
    } catch (e) {
        return;
    }

    // 🧱 The ONE element this script adds to the website's page, and its
    //    shadow root. Everything else is drawn inside the shadow root.
    var host = document.createElement('div');
    host.setAttribute('data-webms-door', show);
    var shadow = host.attachShadow({ mode: 'open' });
    applyStyles(shadow);
    var box = document.createElement('div');
    box.className = 'door door--' + show;
    shadow.appendChild(box);

    var target = me.getAttribute('data-target');
    if (target) {
        var found = null;
        try {
            found = document.querySelector(target);
        } catch (e) {
            found = null;
        }
        if (!found) {
            warn('data-target "' + target + '" matches nothing on this page; nothing is shown');
            return;
        }
        found.appendChild(host);
    } else if (me.parentNode && me.parentNode !== document.head) {
        me.parentNode.insertBefore(host, me);
    } else {
        whenReady(function () { document.body.appendChild(host); });
    }

    var feed = null;      // the last feed read, or null
    var ticking = null;   // the countdown's one-second timer

    load();
    if (show === 'next') {
        setInterval(load, refreshMinutes * 60 * 1000);
    }

    // =========================================================================
    // 📡 Reading the feed
    // =========================================================================

    function load() {
        fetch(feedUrl.href, { credentials: 'omit', mode: 'cors', referrerPolicy: 'no-referrer' })
            .then(function (r) {
                if (!r.ok) {
                    throw new Error('the public door answered ' + r.status);
                }
                return r.json();
            })
            .then(function (data) {
                var wanted = show === 'posters' ? POSTERS_FORMAT : EVENTS_FORMAT;
                if (!data || data.format !== wanted || data.version !== FORMAT_VERSION) {
                    throw new Error('the public door\'s answer is not in the expected format');
                }
                feed = data;
                draw();
            })
            .catch(function (e) {
                // Silence on the page; one line for whoever is setting it up.
                feed = null;
                clear();
                warn('could not read ' + feedUrl.href + ' (' + (e && e.message ? e.message : 'unknown fault') + '); nothing is shown');
            });
    }

    function draw() {
        clear();
        if (show === 'events') {
            drawEvents(listOf(feed.events));
        } else if (show === 'posters') {
            drawPosters(listOf(feed.posters));
        } else {
            drawNext();
        }
    }

    // =========================================================================
    // 🗓️ The three modes
    // =========================================================================

    function drawEvents(events) {
        if (events.length === 0) {
            box.appendChild(el('p', 'door__empty', 'No upcoming events.'));
            return;
        }
        var list = el('ul', 'door__list');
        events.forEach(function (ev) {
            var item = el('li', 'door__item');
            item.appendChild(el('p', 'door__title', text(ev.title)));
            var when = whenOfEvent(ev);
            if (when !== '') {
                item.appendChild(el('p', 'door__when', when));
            }
            var place = ev.location && typeof ev.location === 'object' ? ev.location : null;
            if (place) {
                var where = [text(place.name), text(place.address)].filter(Boolean).join(', ');
                if (where !== '') {
                    item.appendChild(el('p', 'door__where', where));
                }
                var placeLink = link(place.url, 'Venue details');
                if (placeLink) {
                    item.appendChild(wrap('p', 'door__link', placeLink));
                }
            }
            if (text(ev.description) !== '') {
                item.appendChild(el('p', 'door__about', text(ev.description)));
            }
            list.appendChild(item);
        });
        box.appendChild(list);
    }

    function drawPosters(posters) {
        if (posters.length === 0) {
            box.appendChild(el('p', 'door__empty', 'Nothing on the noticeboard just now.'));
            return;
        }
        var list = el('ul', 'door__list');
        posters.forEach(function (p) {
            var item = el('li', 'door__item');
            var picture = p.thumbUrl || (p.media && p.media.type === 'image' ? p.media.url : null);
            var src = httpsOnly(picture);
            if (src) {
                var img = document.createElement('img');
                img.className = 'door__picture';
                img.alt = '';
                img.loading = 'lazy';
                img.decoding = 'async';
                img.referrerPolicy = 'no-referrer';
                img.src = src;
                item.appendChild(img);
            }
            if (text(p.kicker) !== '') {
                item.appendChild(el('p', 'door__kicker', text(p.kicker)));
            }
            item.appendChild(el('p', 'door__title', text(p.title)));
            var when = whenOfPoster(p);
            if (when !== '') {
                item.appendChild(el('p', 'door__when', when));
            }
            if (text(p.location) !== '') {
                item.appendChild(el('p', 'door__where', text(p.location)));
            }
            var more = link(p.link, 'Find out more');
            if (more) {
                item.appendChild(wrap('p', 'door__link', more));
            }
            list.appendChild(item);
        });
        box.appendChild(list);
    }

    /**
     * The countdown (D-084): the event that starts soonest, judged by its REAL
     * instant (events on different clocks are compared fairly), counted down
     * to the second. An event already under way says so instead.
     */
    function drawNext() {
        var now = Date.now();
        var best = null;
        listOf(feed.events).forEach(function (ev) {
            var start = Date.parse(ev.startsAt);
            if (isNaN(start)) {
                return;
            }
            var end = endOf(ev, start);
            if (end <= now) {
                return;
            }
            if (best === null || start < best.start) {
                best = { ev: ev, start: start };
            }
        });
        if (best === null) {
            box.appendChild(el('p', 'door__empty', 'No upcoming events.'));
            return;
        }
        var heading = el('p', 'door__kicker', '');
        box.appendChild(heading);
        box.appendChild(el('p', 'door__title', text(best.ev.title)));
        var when = whenOfEvent(best.ev);
        if (when !== '') {
            box.appendChild(el('p', 'door__when', when));
        }
        var clock = el('div', 'door__clock');
        clock.setAttribute('role', 'timer');
        clock.setAttribute('aria-label', 'Time until it starts');
        var parts = {};
        ['days', 'hours', 'minutes', 'seconds'].forEach(function (unit) {
            var cell = el('span', 'door__unit');
            parts[unit] = el('span', 'door__number', '');
            cell.appendChild(parts[unit]);
            cell.appendChild(el('span', 'door__unit-name', unit));
            clock.appendChild(cell);
        });
        box.appendChild(clock);

        function tick() {
            var left = Math.floor((best.start - Date.now()) / 1000);
            if (left <= 0) {
                heading.textContent = 'Happening now';
                clock.hidden = true;
                return;
            }
            heading.textContent = 'Next';
            clock.hidden = false;
            parts.days.textContent = String(Math.floor(left / 86400));
            parts.hours.textContent = two(Math.floor((left % 86400) / 3600));
            parts.minutes.textContent = two(Math.floor((left % 3600) / 60));
            parts.seconds.textContent = two(left % 60);
        }
        tick();
        ticking = setInterval(tick, 1000);
    }

    // =========================================================================
    // 🕰️ Times, on the event's own clock
    // =========================================================================

    /**
     * When an event ends, as an instant: its end, or for an all-day event the
     * end of its last day on its own clock; an event with no end time ends at
     * its START, so once it has begun the countdown moves on to the next one.
     *
     * ⚠️ DELIBERATELY NOT the feed's `listedUntil` (#882, round 1 of its
     *    check). That field says how long the door keeps an event on its LIST
     *    (D-129: an event with no end time until the end of its day), which
     *    is not when it ends. Used here it made a 10:00 service with no end
     *    time "Happening now" until midnight, so the countdown never reached
     *    the 18:30 service after it: a claim about the event that nobody made.
     *    D-129 decided the list, not what the countdown says is under way.
     */
    function endOf(ev, start) {
        if (ev.allDay === true) {
            var basis = typeof ev.endsAt === 'string' ? ev.endsAt : ev.startsAt;
            var lastDay = Date.parse(basis.slice(0, 10) + 'T23:59:59' + offsetOf(basis));
            return isNaN(lastDay) ? start + 86400000 : lastDay;
        }
        var end = typeof ev.endsAt === 'string' ? Date.parse(ev.endsAt) : NaN;
        return isNaN(end) ? start : end;
    }

    /** The "+01:00" (or "Z") at the end of a feed time, or "" when it has none. */
    function offsetOf(stamp) {
        var m = /([+-]\d{2}:\d{2}|Z)$/.exec(stamp);
        return m ? m[1] : '';
    }

    /**
     * A feed time ("2026-06-01T10:30:00+01:00") as words, on the clock it was
     * written on: its date and time are read from the TEXT, then formatted as
     * if they were UTC, so the browser's own zone never moves them.
     */
    function clockWords(stamp, withTime) {
        var m = /^(\d{4})-(\d{2})-(\d{2})T(\d{2}):(\d{2})/.exec(typeof stamp === 'string' ? stamp : '');
        if (!m) {
            return '';
        }
        var reading = new Date(Date.UTC(+m[1], +m[2] - 1, +m[3], +m[4], +m[5]));
        var options = { timeZone: 'UTC', weekday: 'short', day: 'numeric', month: 'short', year: 'numeric' };
        if (withTime) {
            options.hour = 'numeric';
            options.minute = '2-digit';
        }
        try {
            return new Intl.DateTimeFormat(locale, options).format(reading);
        } catch (e) {
            return m[1] + '-' + m[2] + '-' + m[3] + (withTime ? ' ' + m[4] + ':' + m[5] : '');
        }
    }

    function whenOfEvent(ev) {
        // The reading AS STORED (startsOnClock) is what is shown: inside the
        // hour a clock skips it is still the time the organisation wrote,
        // where startsAt — an instant — has moved past the gap (#777).
        var shown = typeof ev.startsOnClock === 'string' ? ev.startsOnClock : ev.startsAt;
        var words = clockWords(shown, ev.allDay !== true);
        if (words === '') {
            return '';
        }
        var org = feed && feed.organisation ? text(feed.organisation.timezone) : '';
        var zone = text(ev.timezone);
        if (zone !== '' && org !== '' && zone !== org) {
            // "America/New_York" becomes "New York time": the place, as a person says it.
            words += ' (' + zone.split('/').pop().replace(/_/g, ' ') + ' time)';
        }
        return words;
    }

    function whenOfPoster(p) {
        var when = p.when && typeof p.when === 'object' ? p.when : null;
        var words = '';
        if (when && when.type === 'weekly' && typeof when.weekday === 'number' && WEEKDAYS[when.weekday]) {
            words = 'Every ' + weekdayName(when.weekday);
        } else if (when && when.type === 'once' && typeof when.date === 'string') {
            words = clockWords(when.date + 'T00:00', false);
        }
        if (text(p.time) !== '') {
            words = words === '' ? text(p.time) : words + ', ' + text(p.time);
        }
        return words;
    }

    /** A weekday's name in the page's language (2023-01-01 was a Sunday). */
    function weekdayName(n) {
        try {
            return new Intl.DateTimeFormat(locale, { timeZone: 'UTC', weekday: 'long' }).format(new Date(Date.UTC(2023, 0, 1 + n)));
        } catch (e) {
            return WEEKDAYS[n];
        }
    }

    // =========================================================================
    // 🧹 Small helpers — none of them turns text into HTML
    // =========================================================================

    /** A new element holding `words` as TEXT. */
    function el(tag, cls, words) {
        var node = document.createElement(tag);
        if (cls) {
            node.className = cls;
        }
        if (typeof words === 'string' && words !== '') {
            node.textContent = words;
        }
        return node;
    }

    function wrap(tag, cls, child) {
        var node = el(tag, cls, '');
        node.appendChild(child);
        return node;
    }

    /** A link, only to an https: address, opened away from the website, telling the other side nothing about where it came from. */
    function link(address, words) {
        var safe = httpsOnly(address);
        if (!safe) {
            return null;
        }
        var a = el('a', '', words);
        a.href = safe;
        a.target = '_blank';
        a.rel = 'noopener noreferrer nofollow';
        a.referrerPolicy = 'no-referrer';
        return a;
    }

    /** The address back, whole, when its protocol is https: — otherwise null (http:, javascript:, data:, a path). */
    function httpsOnly(address) {
        if (typeof address !== 'string' || address === '') {
            return null;
        }
        try {
            var u = new URL(address);
            return u.protocol === 'https:' ? u.href : null;
        } catch (e) {
            return null;
        }
    }

    function text(value) {
        return typeof value === 'string' ? value.trim() : '';
    }

    function listOf(value) {
        return Array.isArray(value) ? value.filter(function (one) { return one && typeof one === 'object'; }) : [];
    }

    function clear() {
        if (ticking !== null) {
            clearInterval(ticking);
            ticking = null;
        }
        while (box.firstChild) {
            box.removeChild(box.firstChild);
        }
    }

    function two(n) {
        return n < 10 ? '0' + n : String(n);
    }

    function wholeNumber(raw, fallback, low, high) {
        var n = /^\d{1,4}$/.test(raw || '') ? parseInt(raw, 10) : fallback;
        return n < low || n > high ? fallback : n;
    }

    function chooseLocale(wanted) {
        if (wanted !== '') {
            try {
                new Intl.DateTimeFormat(wanted);
                return wanted;
            } catch (e) {
                // An unknown language: the browser's own.
            }
        }
        return undefined;
    }

    function whenReady(fn) {
        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', fn);
        } else {
            fn();
        }
    }

    function warn(words) {
        if (window.console && typeof window.console.warn === 'function') {
            window.console.warn(LABEL + ' ' + words);
        }
    }

    // =========================================================================
    // 🎨 Its look — kept inside the shadow root
    // =========================================================================

    /**
     * A website can change the colours from outside, because custom
     * properties pass into a shadow root: for example
     *   [data-webms-door] { --webms-door-accent: #0a6; }
     */
    function applyStyles(root) {
        var css =
            ':host{display:block;--webms-door-accent:#4f5bd5;--webms-door-text:#1b1f24;--webms-door-muted:#57606a;' +
            '--webms-door-back:transparent;--webms-door-line:#d0d7de;' +
            'font-family:system-ui,-apple-system,"Segoe UI",Roboto,sans-serif;color:var(--webms-door-text);}' +
            '@media (prefers-color-scheme:dark){:host{--webms-door-accent:#9aa3ff;--webms-door-text:#e6edf3;' +
            '--webms-door-muted:#9da7b3;--webms-door-line:#30363d;}}' +
            '.door{background:var(--webms-door-back);}' +
            '.door__list{list-style:none;margin:0;padding:0;}' +
            '.door__item{padding:.75rem 0;border-bottom:1px solid var(--webms-door-line);}' +
            '.door__item:last-child{border-bottom:0;}' +
            'p{margin:0 0 .25rem;}' +
            '.door__title{font-weight:700;font-size:1.05rem;}' +
            '.door__kicker{color:var(--webms-door-accent);font-size:.8rem;text-transform:uppercase;letter-spacing:.06em;}' +
            '.door__when,.door__where{color:var(--webms-door-muted);font-size:.9rem;}' +
            '.door__about{font-size:.95rem;white-space:pre-line;}' +
            '.door__link a{color:var(--webms-door-accent);}' +
            '.door__picture{display:block;max-width:100%;height:auto;border-radius:.4rem;margin-bottom:.5rem;}' +
            '.door__empty{color:var(--webms-door-muted);}' +
            '.door__clock{display:flex;gap:.5rem;margin-top:.5rem;font-variant-numeric:tabular-nums;}' +
            '.door__clock[hidden]{display:none;}' +
            '.door__unit{border:1px solid var(--webms-door-line);border-radius:.4rem;padding:.4rem .6rem;min-width:3.2rem;text-align:center;}' +
            '.door__number{display:block;font-size:1.5rem;font-weight:700;line-height:1.1;color:var(--webms-door-accent);}' +
            '.door__unit-name{display:block;font-size:.7rem;color:var(--webms-door-muted);text-transform:uppercase;letter-spacing:.08em;}';
        // A constructed stylesheet is not refused by a website's
        // Content-Security-Policy; a <style> element (the fallback, for a
        // browser without them) may be.
        if (typeof CSSStyleSheet === 'function' && 'adoptedStyleSheets' in root) {
            try {
                var sheet = new CSSStyleSheet();
                sheet.replaceSync(css);
                root.adoptedStyleSheets = [sheet];
                return;
            } catch (e) {
                // Fall through to the <style> element.
            }
        }
        var style = document.createElement('style');
        style.textContent = css;
        root.appendChild(style);
    }
})();
