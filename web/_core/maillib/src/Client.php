<?php
// Path: web/_core/maillib/src/Client.php
/**
 * -----------------------------------------------------------------------------
 * Client — the one door an application sends through ✉️
 * -----------------------------------------------------------------------------
 * Holds the route in use and, if the application wants one, a second route to
 * fall back on. It adds two rules on top of whatever the routes do:
 *
 *   1. A SECOND ROUTE IS TRIED ONLY WHEN THE FIRST COULD NOT BE REACHED. Not
 *      when the first route refused the message, and never after a message
 *      may already have gone: sending by another route then could deliver it
 *      twice.
 *   2. A DEAD SERVER CANNOT STALL A BATCH. After a route has failed to connect
 *      twice, it is not tried again by this client. The remaining messages
 *      come back at once as "try later, not attempted". Without this, three
 *      hundred reminders to a server that is down would each wait out the
 *      connection time limit.
 *
 * The count is kept for the life of this object, which is meant to be one
 * request or one run of a scheduled job. A success does not reset it: two
 * failures in one run are reason enough to stop leaning on that route.
 *
 * @package   MWBM\Mail
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2026-present MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   0.1.0
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

namespace MWBM\Mail;

final class Client
{
    /** How many connection failures a route is allowed before it is left alone. */
    public const CONNECTION_FAILURES_ALLOWED = 2;

    /** @var list<Transport> The routes, in the order they are tried. */
    private array $routes;

    /** @var array<int,int> Connection failures so far, by the route's place in the list. */
    private array $failures = [];

    public function __construct(Transport $first, ?Transport $second = null)
    {
        $this->routes = $second === null ? [$first] : [$first, $second];
    }

    /**
     * Send one message. Never throws.
     *
     * @param SendOptions|null $options Leave out for the normal, fully checked send.
     */
    public function send(Message $message, ?SendOptions $options = null): SendResult
    {
        $last = null;
        foreach ($this->routes as $index => $route) {
            if (($this->failures[$index] ?? 0) >= self::CONNECTION_FAILURES_ALLOWED) {
                continue;
            }
            $last = $route->send($message, $options);
            if ($last->isConnectionFailure() !== true) {
                return $last;
            }
            $this->failures[$index] = ($this->failures[$index] ?? 0) + 1;
        }
        if ($last !== null) {
            return $last;
        }
        return new SendResult(
            SendResult::TRY_LATER,
            SendResult::STAGE_CONNECT,
            SendResult::REASON_NOT_ATTEMPTED,
            0,
            'Not attempted: the mail server could not be reached ' . self::CONNECTION_FAILURES_ALLOWED
            . ' times already in this run.',
        );
    }

    /** Close every route's open connection. A later send opens one again. */
    public function close(): void
    {
        foreach ($this->routes as $route) {
            $route->close();
        }
    }
}
