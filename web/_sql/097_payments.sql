-- =============================================================================
-- Migration 097: Payment processor integration (#268)
-- =============================================================================

CREATE TABLE IF NOT EXISTS `tblPaymentMethod` (
    `methodID`        INT          NOT NULL AUTO_INCREMENT,
    `siteID`          INT          NOT NULL DEFAULT 1,
    `userID`          INT          NOT NULL,
    `provider`        ENUM('stripe','paypal','gocardless') NOT NULL,
    `customerRef`     VARCHAR(100) DEFAULT NULL COMMENT 'Provider customer ID (e.g. Stripe cus_…)',
    `methodRef`       VARCHAR(100) NOT NULL COMMENT 'Tokenised payment method (pm_…, billing agreement, mandate)',
    `label`           VARCHAR(100) DEFAULT NULL COMMENT 'Display hint, e.g. last4 + brand',
    `isDefault`       TINYINT(1)   NOT NULL DEFAULT 0,
    `createdAt`       DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`methodID`),
    UNIQUE KEY `uq_pm_method` (`provider`, `methodRef`),
    KEY `idx_pm_user`   (`userID`),
    CONSTRAINT `fk_pm_site` FOREIGN KEY (`siteID`) REFERENCES `tblSites`(`siteID`),
    CONSTRAINT `fk_pm_user` FOREIGN KEY (`userID`) REFERENCES `tblUsers`(`userID`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `tblPayment` (
    `paymentID`        INT          NOT NULL AUTO_INCREMENT,
    `siteID`           INT          NOT NULL DEFAULT 1,
    `userID`           INT          DEFAULT NULL,
    `provider`         ENUM('stripe','paypal','gocardless') NOT NULL,
    `providerRef`      VARCHAR(100) NOT NULL COMMENT 'Charge/intent/payment ID',
    `idempotencyKey`   VARCHAR(80)  DEFAULT NULL,
    `amountPence`      INT          NOT NULL,
    `feePence`         INT          DEFAULT NULL,
    `currency`         CHAR(3)      NOT NULL DEFAULT 'GBP',
    `status`           ENUM('pending','succeeded','failed','refunded') NOT NULL DEFAULT 'pending',
    `purpose`          ENUM('giving','pledge','membership','other') NOT NULL DEFAULT 'other',
    `purposeRef`       VARCHAR(100) DEFAULT NULL COMMENT 'e.g. givingCategoryID or projectID:slug',
    `isRecurring`      TINYINT(1)   NOT NULL DEFAULT 0,
    `errorMsg`         VARCHAR(255) DEFAULT NULL,
    `occurredAt`       DATETIME     DEFAULT NULL,
    `createdAt`        DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`paymentID`),
    UNIQUE KEY `uq_pay_provider_ref` (`provider`, `providerRef`),
    KEY `idx_pay_site_date` (`siteID`, `createdAt`),
    KEY `idx_pay_user`      (`userID`),
    KEY `idx_pay_purpose`   (`purpose`, `purposeRef`),
    CONSTRAINT `fk_pay_site` FOREIGN KEY (`siteID`) REFERENCES `tblSites`(`siteID`),
    CONSTRAINT `fk_pay_user` FOREIGN KEY (`userID`) REFERENCES `tblUsers`(`userID`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `tblWebhookEvent` (
    `eventID`     INT          NOT NULL AUTO_INCREMENT,
    `provider`    VARCHAR(30)  NOT NULL,
    `eventType`   VARCHAR(100) NOT NULL,
    `providerRef` VARCHAR(100) DEFAULT NULL,
    `payload`     MEDIUMTEXT   DEFAULT NULL,
    `verified`    TINYINT(1)   NOT NULL DEFAULT 0,
    `handledAt`   DATETIME     DEFAULT NULL,
    `errorMsg`    VARCHAR(255) DEFAULT NULL,
    `receivedAt`  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`eventID`),
    UNIQUE KEY `uq_we_provider_ref` (`provider`, `providerRef`),
    KEY `idx_we_received` (`receivedAt`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

INSERT INTO `tblRoutes` (`routeKey`, `targetFile`, `isProtected`) VALUES
    ('payments',                      'payments/index.php',           1),
    ('payments/save',                 'payments/save.php',            1),
    ('payments/refund',               'payments/refund.php',          1),
    ('payments/checkout',             'payments/checkout.php',        1),
    ('payments/return',               'payments/return.php',          1),
    ('payments/webhook',              'payments/webhook.php',         0),
    ('account/payment-methods',       'account/payment-methods.php',  1),
    ('account/payment-methods/delete','account/pm-delete.php',        1),
    ('account/recurring',             'account/recurring.php',        1)
ON DUPLICATE KEY UPDATE `targetFile` = VALUES(`targetFile`);

INSERT INTO `tblSettings` (`siteID`, `settingKey`, `settingValue`, `defaultValue`, `isSensitive`) VALUES
    (NULL, 'payments.enabled',           '0', '0', 0),
    (NULL, 'payments.displayName',       'Payments', 'Payments', 0),
    (NULL, 'payments.displayIcon',       'fa-solid fa-credit-card', 'fa-solid fa-credit-card', 0),
    (NULL, 'payments.provider',          'stripe', 'stripe', 0),
    (NULL, 'payments.test_mode',         '1', '1', 0),
    (NULL, 'payments.currency',          'GBP', 'GBP', 0),
    (NULL, 'payments.stripe.publishable','', '', 0),
    (NULL, 'payments.stripe.secret',     '', '', 1),
    (NULL, 'payments.stripe.webhookSecret','', '', 1),
    (NULL, 'payments.paypal.clientId',   '', '', 0),
    (NULL, 'payments.paypal.secret',     '', '', 1),
    (NULL, 'payments.gocardless.token',  '', '', 1),
    (NULL, 'payments.gocardless.webhookSecret','', '', 1)
ON DUPLICATE KEY UPDATE `defaultValue` = VALUES(`defaultValue`);
