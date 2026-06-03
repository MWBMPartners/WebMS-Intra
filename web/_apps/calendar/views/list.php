<?php
// Path: public_html/calendar/views/list.php
/**
 * -----------------------------------------------------------------------------
 * Calendar — List View Partial 📋
 * -----------------------------------------------------------------------------
 * Card-grid presentation of upcoming (or past) events with optional category /
 * type filtering. Rendered as a partial under the new calendar view router
 * (index.php) which provides:
 *   $events, $totalRows, $totalPages, $page,
 *   $filterCategory, $filterType, $showPast
 *
 * @package   Portal\Calendar
 * @license   All Rights Reserved
 * @version   0.11.0
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

?>
<?php if (count($events) === 0): ?>
    <div class="alert alert-info">
        <i class="fa-solid fa-circle-info me-1"></i>
        <?php echo $showPast === true ? 'No events found matching your filters.' : 'No upcoming events. Check back soon!'; ?>
    </div>
<?php else: ?>
    <div class="row g-4">
        <?php foreach ($events as $event): ?>
            <?php
            $startDt = new DateTime($event['startDateTime']);
            $isToday = $startDt->format('Y-m-d') === date('Y-m-d');
            $isPast  = $startDt < new DateTime();
            ?>
            <div class="col-12 col-md-6 col-lg-4">
                <div class="card h-100 <?php echo $event['isFeatured'] === '1' ? 'border-warning' : ''; ?> <?php echo $isPast === true ? 'opacity-75' : ''; ?>">
                    <?php if ($event['heroImage'] !== null && $event['heroImage'] !== ''): ?>
                        <img src="/assets/uploads/calendar/<?php echo htmlspecialchars($event['heroImage'], ENT_QUOTES, 'UTF-8'); ?>"
                             class="card-img-top" alt="" style="height:180px;object-fit:cover;">
                    <?php endif; ?>
                    <div class="card-body">
                        <?php if ($event['isFeatured'] === '1'): ?>
                            <span class="badge bg-warning text-dark mb-2"><i class="fa-solid fa-star me-1"></i>Featured</span>
                        <?php endif; ?>

                        <h5 class="card-title">
                            <a href="/calendar/event?slug=<?php echo htmlspecialchars($event['eventSlug'], ENT_QUOTES, 'UTF-8'); ?>" class="text-decoration-none">
                                <?php echo htmlspecialchars($event['eventName'], ENT_QUOTES, 'UTF-8'); ?>
                            </a>
                        </h5>

                        <p class="card-text mb-1">
                            <i class="fa-regular fa-calendar me-1 text-primary"></i>
                            <strong><?php echo htmlspecialchars(\Portal\Core\I18n::formatDate($startDt->format('Y-m-d H:i:s'), 'long'), ENT_QUOTES, 'UTF-8'); ?></strong>
                            <?php if ($event['isAllDay'] !== '1' && (int) $event['isAllDay'] !== 1): ?>
                                <span class="text-muted ms-1"><?php echo htmlspecialchars($startDt->format('g:i A'), ENT_QUOTES, 'UTF-8'); ?></span>
                            <?php else: ?>
                                <span class="badge bg-light text-dark ms-1">All Day</span>
                            <?php endif; ?>
                            <?php if ($isToday === true): ?>
                                <span class="badge bg-success ms-1">Today</span>
                            <?php endif; ?>
                        </p>

                        <?php if ($event['locationName'] !== null && $event['locationName'] !== ''): ?>
                            <p class="card-text mb-1 small text-muted">
                                <i class="fa-solid fa-location-dot me-1"></i>
                                <?php echo htmlspecialchars($event['locationName'], ENT_QUOTES, 'UTF-8'); ?>
                            </p>
                        <?php endif; ?>

                        <div class="mt-2">
                            <?php if ($event['categoryName'] !== null): ?>
                                <span class="badge bg-primary me-1"><?php echo htmlspecialchars($event['categoryName'], ENT_QUOTES, 'UTF-8'); ?></span>
                            <?php endif; ?>
                            <?php if ($event['typeName'] !== null): ?>
                                <span class="badge bg-secondary me-1"><?php echo htmlspecialchars($event['typeName'], ENT_QUOTES, 'UTF-8'); ?></span>
                            <?php endif; ?>
                            <?php if ($event['seriesName'] !== null): ?>
                                <span class="badge bg-info me-1"><?php echo htmlspecialchars($event['seriesName'], ENT_QUOTES, 'UTF-8'); ?></span>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
    </div>

    <!-- 📖 Pagination -->
    <?php if (($totalPages ?? 1) > 1): ?>
        <nav aria-label="Calendar pagination" class="mt-4">
            <ul class="pagination justify-content-center">
                <?php
                $baseUrl = '/calendar?' . http_build_query(array_filter([
                    'view'     => 'list',
                    'category' => $filterCategory,
                    'type'     => $filterType,
                    'past'     => $showPast ? '1' : '',
                ]));
                $sep = '&';
                ?>
                <li class="page-item <?php echo ($page <= 1) ? 'disabled' : ''; ?>">
                    <a class="page-link" href="<?php echo htmlspecialchars($baseUrl . $sep . 'page=' . ($page - 1), ENT_QUOTES, 'UTF-8'); ?>">&laquo;</a>
                </li>
                <?php for ($i = 1; $i <= $totalPages; $i++): ?>
                    <?php if ($i <= 3 || $i >= $totalPages - 2 || abs($i - $page) <= 1): ?>
                        <li class="page-item <?php echo ($i === $page) ? 'active' : ''; ?>">
                            <a class="page-link" href="<?php echo htmlspecialchars($baseUrl . $sep . 'page=' . $i, ENT_QUOTES, 'UTF-8'); ?>"><?php echo $i; ?></a>
                        </li>
                    <?php elseif ($i === 4 || $i === $totalPages - 3): ?>
                        <li class="page-item disabled"><span class="page-link">&hellip;</span></li>
                    <?php endif; ?>
                <?php endfor; ?>
                <li class="page-item <?php echo ($page >= $totalPages) ? 'disabled' : ''; ?>">
                    <a class="page-link" href="<?php echo htmlspecialchars($baseUrl . $sep . 'page=' . ($page + 1), ENT_QUOTES, 'UTF-8'); ?>">&raquo;</a>
                </li>
            </ul>
        </nav>
    <?php endif; ?>
<?php endif; ?>
