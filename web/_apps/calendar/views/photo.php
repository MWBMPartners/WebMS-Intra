<?php
// Path: _apps/calendar/views/photo.php
/**
 * -----------------------------------------------------------------------------
 * Calendar — Photo View Partial 🖼️ (#331)
 * -----------------------------------------------------------------------------
 * Image-grid layout — joins the existing seven views (day/week/weekdays/
 * weekend/month/year/list) from PR #137. Renders events as big hero-image
 * tiles. Events without `heroImage` get a brand-aware placeholder so the
 * grid still looks balanced.
 *
 * @package   Portal\Calendar
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2025-present MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   1.0.0
 * @link      https://github.com/MWBMPartners/webMS-Intra/issues/331
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

use Portal\Core\Site;

?>
<?php if (count($events) === 0): ?>
    <div class="alert alert-info">
        <i class="fa-solid fa-circle-info me-1"></i>
        <?php echo $showPast === true ? 'No events found matching your filters.' : 'No upcoming events. Check back soon!'; ?>
    </div>
<?php else: ?>
    <div class="row g-3 calendar-photo-grid">
        <?php
        $brandFallback = '/assets/images/brands/' . htmlspecialchars(
            'webms-intra', ENT_QUOTES, 'UTF-8'
        ) . '/full.svg';
        ?>
        <?php foreach ($events as $event): ?>
            <?php
            $img = $event['heroImage'] !== null && $event['heroImage'] !== ''
                ? '/assets/uploads/calendar/' . $event['heroImage']
                : $brandFallback;
            $startTs = strtotime((string) $event['startDateTime']);
            ?>
            <div class="col-md-6 col-lg-4">
                <a href="/calendar/event?slug=<?php echo htmlspecialchars((string) $event['eventSlug'], ENT_QUOTES, 'UTF-8'); ?>"
                   class="text-decoration-none text-reset d-block">
                    <div class="card h-100 shadow-sm overflow-hidden">
                        <div style="aspect-ratio: 16/9; background: linear-gradient(135deg,#5e6ad2,#7a85e8); display:flex; align-items:center; justify-content:center;">
                            <img src="<?php echo htmlspecialchars($img, ENT_QUOTES, 'UTF-8'); ?>"
                                 alt="<?php echo htmlspecialchars((string) $event['eventName'], ENT_QUOTES, 'UTF-8'); ?>"
                                 style="width:100%; height:100%; object-fit: <?php echo $event['heroImage'] ? 'cover' : 'contain'; ?>; padding: <?php echo $event['heroImage'] ? '0' : '24px'; ?>;">
                        </div>
                        <div class="card-body">
                            <h2 class="h6 mb-1"><?php echo htmlspecialchars((string) $event['eventName'], ENT_QUOTES, 'UTF-8'); ?></h2>
                            <div class="text-muted small">
                                <i class="fa-solid fa-calendar me-1"></i>
                                <?php echo htmlspecialchars(date('j M Y, H:i', $startTs), ENT_QUOTES, 'UTF-8'); ?>
                                <?php if (!empty($event['locationName'])): ?>
                                    <br><i class="fa-solid fa-location-dot me-1"></i>
                                    <?php echo htmlspecialchars((string) $event['locationName'], ENT_QUOTES, 'UTF-8'); ?>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </a>
            </div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>
