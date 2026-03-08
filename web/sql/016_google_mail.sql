-- Migration 016: Google Workspace Email Sending
-- Adds settings for Gmail API service account delegation and mail provider toggle.
-- @see https://github.com/MWBMPartners/WebMS-Intra/issues/48

-- 📧 Mail provider selector (ms365 or google)
INSERT IGNORE INTO tblSettings (settingKey, settingValue, isSensitive, siteID)
VALUES ('mail.provider', 'ms365', 0, NULL);

-- 🔑 Google service account key file (filename in _auth_keys/)
INSERT IGNORE INTO tblSettings (settingKey, settingValue, isSensitive, siteID)
VALUES ('mail.google.serviceAccountKeyFile', '', 1, NULL);

-- 📧 Google delegate user (email address to impersonate for sending)
INSERT IGNORE INTO tblSettings (settingKey, settingValue, isSensitive, siteID)
VALUES ('mail.google.delegateUser', '', 0, NULL);
