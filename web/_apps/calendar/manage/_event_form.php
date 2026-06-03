<?php
// Path: public_html/calendar/manage/_event_form.php
/**
 * -----------------------------------------------------------------------------
 * Event Form Partial (Create/Edit) 📝
 * -----------------------------------------------------------------------------
 * Shared form fields for creating and editing events. Included by both the
 * create and edit sections of manage/index.php. Expects $editEvent to be
 * set (or null for create mode), and $categories, $eventTypes, $seriesList.
 *
 * @package   Portal\Calendar
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2025-present MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   0.3.0
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

// 📌 Extract values for pre-population (edit mode)
$ev = $editEvent ?? [];
$isEdit = $editEvent !== null;
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

    <div class="col-12 col-md-4">
        <label class="form-label">Latitude</label>
        <input type="number" class="form-control" name="locationGeoLat" step="0.0000001"
               value="<?php echo htmlspecialchars((string) ($ev['locationGeoLat'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>">
    </div>

    <div class="col-12 col-md-4">
        <label class="form-label">Longitude</label>
        <input type="number" class="form-control" name="locationGeoLng" step="0.0000001"
               value="<?php echo htmlspecialchars((string) ($ev['locationGeoLng'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>">
    </div>

    <div class="col-12 col-md-4">
        <label class="form-label">what3words</label>
        <input type="text" class="form-control" name="locationW3W" placeholder="///word.word.word"
               value="<?php echo htmlspecialchars($ev['locationW3W'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">
    </div>

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
</div>
