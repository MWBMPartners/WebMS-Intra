-- =============================================================================
-- Migration 167: PayPal payment adapter + online giving/pledge checkout (gap #1)
-- =============================================================================
-- Wires the `paypal` branch of Portal\Core\Payments (Orders v2 create +
-- capture-on-return + verified-webhook backstop + refunds) and ships the
-- previously-missing user-facing checkout UI (`/giving/give`, Projects
-- "Pay now"). See Payments.php's "🟡 PayPal implementation" section for the
-- full REST flow, and markPaymentSucceeded()'s docblock for the S1 amount/
-- currency integrity gate that every PayPal success path funnels through.
--
-- Seeds only -- no DDL at all, so check_mariadb_only_ddl.py is trivially
-- green. `payments.paypal.clientId`/`.secret` already exist (migration
-- 097_payments.sql); this migration adds the two still-missing PayPal
-- runtime keys, flips `clientId` to encrypted-at-rest (predicate-guarded --
-- see the hazard note below), and registers the `giving/give` route.
--
-- 1) New PayPal settings (global defaults, siteID NULL):
--      payments.paypal.webhookId -- the WH-... id from the PayPal developer
--        dashboard; required for webhook signature verification -- empty
--        means every PayPal webhook 401s until an admin sets it
--        (fail-closed by construction, not a bug -- see S13 in the
--        threat/mitigation table).
--      payments.paypal.mode -- 'sandbox' | 'live'; Payments::paypalBase()
--        treats anything other than exactly 'live' as sandbox -- a
--        misconfigured/garbage value fails SAFE to sandbox, never live
--        money.
--
-- 2) payments.paypal.clientId sensitivity flip -- PayPal itself treats
--    client ids as public identifiers, but the gap-item directive (build
--    plan Q2) calls for encrypting it at rest as defence in depth.
--    decrypt_setting() on a PLAINTEXT value returns '' (bootstrap.php), so
--    flipping a row that already holds a plaintext client id would
--    silently blank it at the very next page load. This UPDATE is
--    predicate-guarded to ONLY flip rows that are STILL EMPTY -- a site
--    that already saved a plaintext client id keeps isSensitive = 0 until
--    an admin re-saves the Payments page (save.php now writes it encrypted
--    + isSensitive = 1 on every save, blank-value-skipped). Idempotent by
--    predicate -- replays as a no-op once flipped (or once a real value
--    exists either way).
--
-- 3) `giving/give` route -- the new "Give online" checkout-initiator page;
--    protected (login required), handler ships in this same PR
--    (`giving/give.php`).
--
-- Replay-proof: two ON-DUPLICATE-KEY-UPDATE seed statements + one
-- predicate-guarded UPDATE + the standard idempotent tblMigrations
-- self-record. No ALTER/CREATE/DROP anywhere in this file.
--
-- @link https://github.com/MWBMPartners/WebMS-Intra/issues/268
-- =============================================================================

-- #############################################################################
-- 🌱 SEEDS
-- #############################################################################

-- ⚙️ PayPal runtime settings not yet seeded (clientId/secret already exist
-- as of migration 097_payments.sql).
INSERT INTO `tblSettings` (`siteID`, `settingKey`, `settingValue`, `defaultValue`, `isSensitive`) VALUES
    (NULL, 'payments.paypal.webhookId', '', '', 0),
    (NULL, 'payments.paypal.mode',      'sandbox', 'sandbox', 0)
ON DUPLICATE KEY UPDATE `settingKey` = `settingKey`;

-- 🔐 Flip clientId to encrypted-at-rest -- ONLY where the stored value is
-- still empty (see the hazard note above). Sites with an already-saved
-- plaintext client id keep isSensitive = 0 until the admin re-saves the
-- Payments page.
UPDATE `tblSettings` SET `isSensitive` = 1
 WHERE `settingKey` = 'payments.paypal.clientId'
   AND (`settingValue` = '' OR `settingValue` IS NULL);

-- 🗺️ Give-online page route.
INSERT INTO `tblRoutes` (`routeKey`, `targetFile`, `isProtected`) VALUES
    ('giving/give', 'giving/give.php', 1)
ON DUPLICATE KEY UPDATE `targetFile` = VALUES(`targetFile`);

-- -----------------------------------------------------------------------------
-- 📋 Self-record this migration (installer replays every numbered migration
-- after full_schema.sql, ignoring tblMigrations -- this INSERT is idempotent).
-- -----------------------------------------------------------------------------
INSERT INTO `tblMigrations` (`filename`) VALUES ('167_paypal_checkout.sql')
ON DUPLICATE KEY UPDATE `filename` = `filename`;
