<?php
// Path: public_html/calendar/manage/_event_form.php
/**
 * -----------------------------------------------------------------------------
 * Event Form Partial (Create/Edit) 📝
 * -----------------------------------------------------------------------------
 * Shared form fields for creating and editing events. Included by both the
 * create and edit sections of manage/index.php. Expects $editEvent to be
 * set (or null for create mode), and $categories, $eventTypes, $seriesList,
 * $defaultVenueId, $venueOptions, $venueRoomOptions.
 *
 * 🏛️ Venue Bookings (#429, #436) Surface A: when $venueOptions is non-empty
 * (manage/index.php's guarded venues integration), renders a persistent
 * "External Venue" + "Room" picker + a hidden alert div wired to a
 * debounced fetch against /api/venues/check. #436 flipped this from a
 * transient advisory-only helper into a PERSISTED link — save.php now
 * writes the posted venueID/roomID into tblEvents.venueID/roomID
 * (site+venue scoped, silently NULL on anything invalid) — while still
 * warning an admin, before they save, whether the picked venue/room is
 * actually booked/confirmed/closed/unavailable for the chosen date/time.
 *
 * @package   Portal\Calendar
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2025-present MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   0.4.0
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/436
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

use Portal\Core\I18n;

// 📌 Extract values for pre-population (edit mode)
$ev = $editEvent ?? [];
$isEdit = $editEvent !== null;

// 🏛️ Venue Bookings (#429, #436) — set by manage/index.php's guarded block;
// defensive fallback keeps this partial safe if ever included elsewhere.
$venueOptions     = $venueOptions     ?? [];
$venueRoomOptions = $venueRoomOptions ?? [];
$defaultVenueId   = $defaultVenueId   ?? 0;
$hasVenueCheck    = count($venueOptions) > 0;

// 🏛️ #436 — the persisted link beats the site default when editing an
// event that already has one; option value 0 = "— Not applicable —" ⇒ NULL.
$selectedVenueId = (isset($ev['venueID']) === true && (int) $ev['venueID'] > 0)
    ? (int) $ev['venueID']
    : $defaultVenueId;
$selectedRoomId  = (int) ($ev['roomID'] ?? 0);
?>

<div class="row g-3">
    <!-- 📝 Basic Info -->
    <div class="col-12">
        <h6 class="text-muted text-uppercase"><i class="fa-solid fa-circle-info me-1"></i> Basic Information</h6>
        <hr class="mt-0">
    </div>

    <div class="col-12 col-md-8">
        <label class="form-label">Event Name <span class="text-danger">*</span></label>
        <input type="text" class="form-control" name="eventName" required
               value="<?php echo htmlspecialchars($ev['eventName'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">
    </div>

    <div class="col-12 col-md-4">
        <label class="form-label">Status</label>
        <select name="status" class="form-select">
            <option value="draft" <?php echo ($ev['status'] ?? 'draft') === 'draft' ? 'selected' : ''; ?>>Draft</option>
            <option value="published" <?php echo ($ev['status'] ?? '') === 'published' ? 'selected' : ''; ?>>Published</option>
            <option value="cancelled" <?php echo ($ev['status'] ?? '') === 'cancelled' ? 'selected' : ''; ?>>Cancelled</option>
            <option value="postponed" <?php echo ($ev['status'] ?? '') === 'postponed' ? 'selected' : ''; ?>>Postponed</option>
        </select>
    </div>

    <div class="col-12">
        <label class="form-label">Description</label>
        <textarea class="form-control" name="description" rows="4"><?php echo htmlspecialchars($ev['description'] ?? '', ENT_QUOTES, 'UTF-8'); ?></textarea>
    </div>

    <!-- 📅 Date & Time -->
    <div class="col-12 mt-4">
        <h6 class="text-muted text-uppercase"><i class="fa-regular fa-clock me-1"></i> Date & Time</h6>
        <hr class="mt-0">
    </div>

    <div class="col-12 col-md-4">
        <label class="form-label">Start Date/Time <span class="text-danger">*</span></label>
        <input type="datetime-local" class="form-control" name="startDateTime" required
               value="<?php echo htmlspecialchars(isset($ev['startDateTime']) === true ? (new DateTime($ev['startDateTime']))->format('Y-m-d\TH:i') : '', ENT_QUOTES, 'UTF-8'); ?>">
    </div>

    <div class="col-12 col-md-4">
        <label class="form-label">End Date/Time</label>
        <input type="datetime-local" class="form-control" name="endDateTime"
               value="<?php echo htmlspecialchars(isset($ev['endDateTime']) === true && $ev['endDateTime'] !== null ? (new DateTime($ev['endDateTime']))->format('Y-m-d\TH:i') : '', ENT_QUOTES, 'UTF-8'); ?>">
    </div>

    <div class="col-12 col-md-2">
        <label class="form-label">Timezone</label>
        <input type="text" class="form-control" name="timezone"
               value="<?php echo htmlspecialchars($ev['timezone'] ?? 'Europe/London', ENT_QUOTES, 'UTF-8'); ?>">
    </div>

    <div class="col-12 col-md-2 d-flex align-items-end">
        <div class="form-check">
            <input class="form-check-input" type="checkbox" name="isAllDay" value="1" id="isAllDay-<?php echo $isEdit ? 'edit' : 'new'; ?>"
                   <?php echo (isset($ev['isAllDay']) === true && ($ev['isAllDay'] === '1' || (int) $ev['isAllDay'] === 1)) ? 'checked' : ''; ?>>
            <label class="form-check-label" for="isAllDay-<?php echo $isEdit ? 'edit' : 'new'; ?>">All Day</label>
        </div>
    </div>

    <!-- 📂 Classification -->
    <div class="col-12 mt-4">
        <h6 class="text-muted text-uppercase"><i class="fa-solid fa-tags me-1"></i> Classification</h6>
        <hr class="mt-0">
    </div>

    <div class="col-12 col-md-4">
        <label class="form-label">Category</label>
        <select name="categoryID" class="form-select">
            <option value="">— None —</option>
            <?php foreach ($categories as $cat): ?>
                <option value="<?php echo (int) $cat['categoryID']; ?>"
                    <?php echo ((int) ($ev['categoryID'] ?? 0) === (int) $cat['categoryID']) ? 'selected' : ''; ?>>
                    <?php echo htmlspecialchars($cat['categoryName'], ENT_QUOTES, 'UTF-8'); ?>
                </option>
            <?php endforeach; ?>
        </select>
    </div>

    <div class="col-12 col-md-4">
        <label class="form-label">Type</label>
        <select name="typeID" class="form-select">
            <option value="">— None —</option>
            <?php foreach ($eventTypes as $et): ?>
                <option value="<?php echo (int) $et['typeID']; ?>"
                    <?php echo ((int) ($ev['typeID'] ?? 0) === (int) $et['typeID']) ? 'selected' : ''; ?>>
                    <?php echo ($et['parentID'] !== null ? '  ↳ ' : '') . htmlspecialchars($et['typeName'], ENT_QUOTES, 'UTF-8'); ?>
                </option>
            <?php endforeach; ?>
        </select>
    </div>

    <div class="col-12 col-md-4">
        <label class="form-label">Series</label>
        <select name="seriesID" class="form-select">
            <option value="">— Standalone —</option>
            <?php foreach ($seriesList as $s): ?>
                <option value="<?php echo (int) $s['seriesID']; ?>"
                    <?php echo ((int) ($ev['seriesID'] ?? 0) === (int) $s['seriesID']) ? 'selected' : ''; ?>>
                    <?php echo htmlspecialchars($s['seriesName'], ENT_QUOTES, 'UTF-8'); ?>
                </option>
            <?php endforeach; ?>
        </select>
    </div>

    <!-- 📍 Location -->
    <div class="col-12 mt-4">
        <h6 class="text-muted text-uppercase"><i class="fa-solid fa-location-dot me-1"></i> Location</h6>
        <hr class="mt-0">
    </div>

    <div class="col-12 col-md-6">
        <label class="form-label">Venue Name</label>
        <input type="text" class="form-control" name="locationName"
               value="<?php echo htmlspecialchars($ev['locationName'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">
    </div>

    <div class="col-12 col-md-6">
        <label class="form-label">Web URL</label>
        <input type="url" class="form-control" name="locationWebURL"
               value="<?php echo htmlspecialchars($ev['locationWebURL'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">
    </div>

    <div class="col-12 col-md-6">
        <label class="form-label">Address</label>
        <textarea class="form-control" name="locationAddress" rows="2"><?php echo htmlspecialchars($ev['locationAddress'] ?? '', ENT_QUOTES, 'UTF-8'); ?></textarea>
    </div>

    <div class="col-12 col-md-3">
        <label class="form-label">Phone</label>
        <input type="tel" class="form-control" name="locationPhone"
               value="<?php echo htmlspecialchars($ev['locationPhone'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">
    </div>

    <div class="col-12 col-md-3">
        <label class="form-label">Email</label>
        <input type="email" class="form-control" name="locationEmail"
               value="<?php echo htmlspecialchars($ev['locationEmail'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">
    </div>

    <?php
    require_once PORTAL_CORE . DIRECTORY_SEPARATOR . 'partials' . DIRECTORY_SEPARATOR . 'location-input.php';
    portal_location_input([
        'values' => [
            'lat' => $ev['locationGeoLat'] ?? '',
            'lng' => $ev['locationGeoLng'] ?? '',
            'w3w' => $ev['locationW3W'] ?? '',
        ],
        'names' => ['lat' => 'locationGeoLat', 'lng' => 'locationGeoLng', 'w3w' => 'locationW3W'],
        'showAddress'         => false,
        'lookup'              => true,
        'lookupAddressField'  => 'locationAddress',
        'w3wSuggest'          => true,
    ]);
    ?>

    <!-- 🏢 Organisation -->
    <div class="col-12 mt-4">
        <h6 class="text-muted text-uppercase"><i class="fa-solid fa-building me-1"></i> Organisation</h6>
        <hr class="mt-0">
    </div>

    <div class="col-12 col-md-6">
        <label class="form-label">Host Organisation</label>
        <input type="text" class="form-control" name="hostOrgName"
               value="<?php echo htmlspecialchars($ev['hostOrgName'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">
    </div>

    <div class="col-12 col-md-6">
        <label class="form-label">Partner Organisations (comma-separated)</label>
        <?php
        $partnersStr = '';
        if (isset($ev['partnerOrgs']) === true && $ev['partnerOrgs'] !== null) {
            $partnerArr = json_decode($ev['partnerOrgs'], true);
            if (is_array($partnerArr) === true) {
                $partnersStr = implode(', ', $partnerArr);
            }
        }
        ?>
        <input type="text" class="form-control" name="partnerOrgs"
               value="<?php echo htmlspecialchars($partnersStr, ENT_QUOTES, 'UTF-8'); ?>">
    </div>

    <!-- 🖼️ Images -->
    <div class="col-12 mt-4">
        <h6 class="text-muted text-uppercase"><i class="fa-solid fa-image me-1"></i> Images</h6>
        <hr class="mt-0">
    </div>

    <div class="col-12 col-md-4">
        <label class="form-label">Hero Image</label>
        <input type="file" class="form-control" name="heroImage" accept="image/*">
        <?php if (isset($ev['heroImage']) === true && $ev['heroImage'] !== null && $ev['heroImage'] !== ''): ?>
            <small class="text-muted">Current: <?php echo htmlspecialchars($ev['heroImage'], ENT_QUOTES, 'UTF-8'); ?></small>
        <?php endif; ?>
    </div>

    <div class="col-12 col-md-4">
        <label class="form-label">Poster Image</label>
        <input type="file" class="form-control" name="posterImage" accept="image/*,.pdf">
        <?php if (isset($ev['posterImage']) === true && $ev['posterImage'] !== null && $ev['posterImage'] !== ''): ?>
            <small class="text-muted">Current: <?php echo htmlspecialchars($ev['posterImage'], ENT_QUOTES, 'UTF-8'); ?></small>
        <?php endif; ?>
    </div>

    <div class="col-12 col-md-4">
        <label class="form-label">Profile Image</label>
        <input type="file" class="form-control" name="profileImage" accept="image/*">
        <?php if (isset($ev['profileImage']) === true && $ev['profileImage'] !== null && $ev['profileImage'] !== ''): ?>
            <small class="text-muted">Current: <?php echo htmlspecialchars($ev['profileImage'], ENT_QUOTES, 'UTF-8'); ?></small>
        <?php endif; ?>
    </div>

    <!-- 📊 Visibility -->
    <div class="col-12 mt-4">
        <h6 class="text-muted text-uppercase"><i class="fa-solid fa-eye me-1"></i> Visibility</h6>
        <hr class="mt-0">
    </div>

    <div class="col-12">
        <div class="form-check form-check-inline">
            <input class="form-check-input" type="checkbox" name="isPublic" value="1" id="isPublic-<?php echo $isEdit ? 'edit' : 'new'; ?>"
                   <?php echo (isset($ev['isPublic']) === false || $ev['isPublic'] === '1' || (int) ($ev['isPublic'] ?? 1) === 1) ? 'checked' : ''; ?>>
            <label class="form-check-label" for="isPublic-<?php echo $isEdit ? 'edit' : 'new'; ?>">Public (visible on calendar)</label>
        </div>
        <div class="form-check form-check-inline">
            <input class="form-check-input" type="checkbox" name="isFeatured" value="1" id="isFeatured-<?php echo $isEdit ? 'edit' : 'new'; ?>"
                   <?php echo (isset($ev['isFeatured']) === true && ($ev['isFeatured'] === '1' || (int) $ev['isFeatured'] === 1)) ? 'checked' : ''; ?>>
            <label class="form-check-label" for="isFeatured-<?php echo $isEdit ? 'edit' : 'new'; ?>">Featured</label>
        </div>
    </div>

    <?php if ($hasVenueCheck === true): ?>
    <!-- 🏛️ Venue Bookings (#429, #436) Surface A — venue/room picker +
         "is it booked?" advisory check. #436: venueID/roomID are now
         PERSISTED (save.php writes them, site+venue scoped, silently NULL
         on anything invalid) — they also still drive the client-side fetch
         below and Surface B's post-save flash check. -->
    <div class="col-12 mt-4">
        <h6 class="text-muted text-uppercase"><i class="fa-solid fa-building-columns me-1"></i>
            <?php echo htmlspecialchars(I18n::t('venues.check.heading'), ENT_QUOTES, 'UTF-8'); ?>
        </h6>
        <hr class="mt-0">
    </div>

    <div class="col-12 col-md-6">
        <label class="form-label" for="venueCheckSelect-<?php echo $isEdit ? 'edit' : 'new'; ?>">
            <?php echo htmlspecialchars(I18n::t('venues.check.select_label'), ENT_QUOTES, 'UTF-8'); ?>
        </label>
        <select class="form-select" name="venueID" id="venueCheckSelect-<?php echo $isEdit ? 'edit' : 'new'; ?>">
            <option value="0"><?php echo htmlspecialchars(I18n::t('venues.check.select_placeholder'), ENT_QUOTES, 'UTF-8'); ?></option>
            <?php foreach ($venueOptions as $v): ?>
                <option value="<?php echo (int) $v['venueID']; ?>"
                    <?php echo ($selectedVenueId === (int) $v['venueID']) ? 'selected' : ''; ?>>
                    <?php echo htmlspecialchars((string) $v['venueName'], ENT_QUOTES, 'UTF-8'); ?>
                </option>
            <?php endforeach; ?>
        </select>
        <div class="form-text"><?php echo htmlspecialchars(I18n::t('venues.check.help_text'), ENT_QUOTES, 'UTF-8'); ?></div>
    </div>

    <?php
        // 🚪 #436 — room options for the INITIALLY selected venue only;
        // JS repopulates roomsByVenue[...] client-side on venue change.
        // Disabled (never hidden — Open Question 6) when the chosen venue
        // has no rooms: stable layout, roomID NULL = whole venue (mirrors
        // tblVenueBookings.roomID's own semantics).
        $initialRooms = $venueRoomOptions[$selectedVenueId] ?? [];
    ?>
    <div class="col-12 col-md-6">
        <label class="form-label" for="venueRoomSelect-<?php echo $isEdit ? 'edit' : 'new'; ?>">
            <?php echo htmlspecialchars(I18n::t('venues.check.room_label'), ENT_QUOTES, 'UTF-8'); ?>
        </label>
        <select class="form-select" name="roomID" id="venueRoomSelect-<?php echo $isEdit ? 'edit' : 'new'; ?>"
            <?php echo (count($initialRooms) === 0) ? 'disabled' : ''; ?>>
            <option value="0"><?php echo htmlspecialchars(I18n::t('venues.check.room_placeholder'), ENT_QUOTES, 'UTF-8'); ?></option>
            <?php foreach ($initialRooms as $r): ?>
                <option value="<?php echo (int) $r['roomID']; ?>"
                    <?php echo ($selectedRoomId === (int) $r['roomID']) ? 'selected' : ''; ?>>
                    <?php echo htmlspecialchars((string) $r['roomName'], ENT_QUOTES, 'UTF-8'); ?>
                </option>
            <?php endforeach; ?>
        </select>
        <div class="form-text"><?php echo htmlspecialchars(I18n::t('venues.check.room_help'), ENT_QUOTES, 'UTF-8'); ?></div>
    </div>

    <div class="col-12">
        <div id="venueCheckAlert-<?php echo $isEdit ? 'edit' : 'new'; ?>" class="alert d-none mb-0 w-100" role="status" aria-live="polite"></div>
    </div>

    <script>
    (function () {
        // 🏛️ Venue Bookings (#429, #436) Surface A — venue→room cascade +
        // debounced "is it booked?" check. Silent no-op on ANY failure
        // (network error, app disabled, aborted request) — this is a
        // pre-save advisory, it must never block or interfere with the
        // form itself (venueID/roomID persistence happens server-side in
        // save.php, independent of whether this check ever runs).
        var idSuffix   = <?php echo json_encode($isEdit ? 'edit' : 'new'); ?>;
        var select     = document.getElementById('venueCheckSelect-' + idSuffix);
        var roomSelect = document.getElementById('venueRoomSelect-' + idSuffix);
        var alertBox   = document.getElementById('venueCheckAlert-' + idSuffix);
        if (select === null || alertBox === null) { return; }

        var form = select.closest('form');
        if (form === null) { return; }

        var startInput = form.querySelector('[name="startDateTime"]');
        var endInput   = form.querySelector('[name="endDateTime"]');
        var tzInput    = form.querySelector('[name="timezone"]');

        // 🚪 #436 — venueID => [{roomID, roomName}, …] active-room map,
        // used to rebuild the room <select> client-side on venue change.
        // JSON_HEX_TAG belt-and-braces even though this sits inside a
        // <script> block and roomName already goes through
        // htmlspecialchars() server-side wherever it's rendered as HTML.
        var roomsByVenue = <?php echo json_encode($venueRoomOptions, JSON_HEX_TAG); ?>;
        var roomPlaceholder = <?php echo json_encode(I18n::t('venues.check.room_placeholder')); ?>;

        var debounceTimer   = null;
        var currentController = null;

        function hideAlert() {
            alertBox.classList.add('d-none');
            alertBox.classList.remove('alert-danger', 'alert-warning', 'alert-success');
            alertBox.textContent = '';
        }

        // 🚪 #436 — rebuild the room <select> for the given venue. Uses
        // createElement/textContent only (never innerHTML) since
        // roomName is user-authored. preselectRoomId lets the initial
        // page-load call restore the edit-mode selection; subsequent
        // (user-driven) venue changes always reset to "whole venue".
        function rebuildRoomOptions(venueId, preselectRoomId) {
            if (roomSelect === null) { return; }
            while (roomSelect.firstChild) {
                roomSelect.removeChild(roomSelect.firstChild);
            }
            var placeholderOpt = document.createElement('option');
            placeholderOpt.value = '0';
            placeholderOpt.textContent = roomPlaceholder;
            roomSelect.appendChild(placeholderOpt);

            var rooms = roomsByVenue[String(venueId)] || [];
            for (var i = 0; i < rooms.length; i++) {
                var opt = document.createElement('option');
                opt.value = String(rooms[i].roomID);
                opt.textContent = rooms[i].roomName;
                if (preselectRoomId > 0 && rooms[i].roomID === preselectRoomId) {
                    opt.selected = true;
                }
                roomSelect.appendChild(opt);
            }
            roomSelect.disabled = rooms.length === 0;
        }

        function runCheck() {
            var venueId  = parseInt(select.value, 10) || 0;
            var roomId   = (roomSelect !== null) ? (parseInt(roomSelect.value, 10) || 0) : 0;
            var startVal = startInput !== null ? startInput.value : '';
            if (venueId <= 0 || startVal === '') {
                hideAlert();
                return;
            }

            // 🐛 #436 fix — check.php reads start/end/tz (its documented
            // contract); this JS previously sent startDateTime/endDateTime/
            // timezone, so `start` always arrived empty, every check 400'd,
            // and the .catch() below silently hid the alert. Every live
            // check was dead until this fix.
            var params = new URLSearchParams();
            params.set('venueID', String(venueId));
            if (roomId > 0) {
                params.set('roomID', String(roomId));
            }
            params.set('start', startVal);
            if (endInput !== null && endInput.value !== '') {
                params.set('end', endInput.value);
            }
            if (tzInput !== null && tzInput.value !== '') {
                params.set('tz', tzInput.value);
            }

            if (currentController !== null && typeof currentController.abort === 'function') {
                currentController.abort();
            }
            currentController = (typeof AbortController !== 'undefined') ? new AbortController() : null;

            fetch('/api/venues/check?' + params.toString(), {
                method: 'GET',
                credentials: 'same-origin',
                signal: currentController !== null ? currentController.signal : undefined
            }).then(function (resp) {
                if (resp.ok === false) {
                    throw new Error('venue check failed');
                }
                return resp.json();
            }).then(function (data) {
                var message = (data && typeof data.message === 'string') ? data.message : '';
                if (message === '') {
                    hideAlert();
                    return;
                }
                var severity = (data && typeof data.severity === 'string') ? data.severity : 'warning';
                var cssClass = 'alert-warning';
                if (severity === 'success') { cssClass = 'alert-success'; }
                else if (severity === 'danger') { cssClass = 'alert-danger'; }

                alertBox.classList.remove('d-none', 'alert-danger', 'alert-warning', 'alert-success');
                alertBox.classList.add(cssClass);
                // 🛡️ Assigned via the safe DOM text property only, never the
                // unsafe HTML-parsing one — defence in depth even though the
                // message is server-built.
                alertBox.textContent = message;
            }).catch(function () {
                hideAlert();
            });
        }

        function scheduleCheck() {
            if (debounceTimer !== null) {
                window.clearTimeout(debounceTimer);
            }
            debounceTimer = window.setTimeout(runCheck, 500);
        }

        select.addEventListener('change', function () {
            // 🚪 #436 — a user-driven venue change always resets the room
            // to "whole venue" (a room from the PREVIOUS venue would be
            // meaningless against the new one).
            rebuildRoomOptions(parseInt(select.value, 10) || 0, 0);
            scheduleCheck();
        });
        if (roomSelect  !== null) { roomSelect.addEventListener('change', scheduleCheck); }
        if (startInput  !== null) { startInput.addEventListener('change', scheduleCheck); }
        if (endInput    !== null) { endInput.addEventListener('change', scheduleCheck); }
        if (tzInput     !== null) { tzInput.addEventListener('change', scheduleCheck); }
    })();
    </script>
    <?php endif; ?>
</div>
