# After deploying the #518 fix: check for administrator rights given out before it

**Who this is for:** a global administrator of any WebMS Intra installation that has **more than one organisation** switched on.

**Why:** before the fix for issue #518, an administrator of *one* organisation could change *any* account in the installation. That included setting a new password or email address on a global administrator's account, switching accounts off, and giving any account the older "administrator of every organisation" flag (`isAdmin`). The fix stops this from now on. **It cannot undo anything already done.** This checklist helps you find anything that should not be there.

**Everything below only reads.** Each query is a `SELECT`, so running it changes nothing. Run them in your hosting control panel's database tool (for example phpMyAdmin), on the live database, after the fix is deployed. If you are unsure about a result, do not change anything; ask first.

Allow about 20 to 30 minutes.

*How these queries were checked:* each one was run on MySQL 8.0.36 against the portal's own database structure, filled with a made-up example of two organisations (a site administrator changing, offboarding and recording a DBS check for someone in the other organisation; a global administrator doing the same; an escalated account; an accepted "admin" invitation; an account with no organisation). Every query returned exactly the rows it should. They have not been run on MariaDB.

---

## 1. Accounts that are administrators of every organisation, but not global administrators

Only global administrators (`isRootAdmin`) should ever administer the whole installation. The older `isAdmin` flag also gives that power, and before the fix any organisation's administrator could switch it on.

```sql
SELECT userID, fullName, emailAddress, isActive, createdAt
FROM tblUsers
WHERE isAdmin = 1 AND isRootAdmin = 0
ORDER BY createdAt DESC;
```

**What to look for:** anyone on this list you did not deliberately make an administrator of every organisation.

**What to do:** open the account at **Admin → Users** as a global administrator and untick the administrator box. If the person should only run their own organisation, make them a site administrator of that organisation at **Admin → Sites → Users** instead.

## 2. Your global administrators: are they still who they should be?

```sql
SELECT userID, fullName, emailAddress, isActive, createdAt
FROM tblUsers
WHERE isRootAdmin = 1
ORDER BY userID;
```

**What to look for:** an email address you do not recognise, or a global administrator switched off (`isActive` = 0) without your knowledge. Changing a global administrator's email was a way to take the account over, because a password reset then goes to the new address.

**What to do:** if an email address is wrong, correct it as another global administrator, then set a new password for that account and sign out its sessions.

## 3. Password resets asked for on administrator accounts

```sql
SELECT r.createdAt, r.usedAt, r.createdIP,
       u.userID, u.fullName, u.emailAddress, u.isAdmin, u.isRootAdmin
FROM tblPasswordResets r
JOIN tblUsers u ON u.userID = r.userID
WHERE u.isAdmin = 1 OR u.isRootAdmin = 1
ORDER BY r.createdAt DESC;
```

**What to look for:** a reset the account's owner did not ask for, especially one that was used (`usedAt` is filled in).

**What to do:** check with the account's owner, then set a new password for the account.

## 4. Account changes made from an organisation the account does not belong to

The users page recorded each change as "Updated user #N" against the organisation whose address the administrator was using. This query lists changes where the changed account is **not a member of that organisation**. Some rows will be normal, because a global administrator can legitimately change anyone, so the query also shows whether the person who made the change was a global administrator.

```sql
SELECT x.timestamp, x.changedFromOrg, s.siteName,
       x.changedBy, b.fullName AS changedByName, b.isRootAdmin AS changedByIsGlobal,
       x.changedAccount, t.fullName AS changedAccountName, x.activityDescription
FROM (
    SELECT l.timestamp, l.siteID AS changedFromOrg, l.userID AS changedBy,
           CAST(SUBSTRING_INDEX(SUBSTRING(l.activityDescription, LOCATE('#', l.activityDescription) + 1), ':', 1) AS UNSIGNED) AS changedAccount,
           l.activityDescription
    FROM tblActivityLogs l
    WHERE l.activityType = 'UserUpdated'
) x
LEFT JOIN tblSites s ON s.siteID = x.changedFromOrg
LEFT JOIN tblUsers b ON b.userID = x.changedBy
LEFT JOIN tblUsers t ON t.userID = x.changedAccount
WHERE NOT EXISTS (
    SELECT 1 FROM tblUserSites us
    WHERE us.userID = x.changedAccount AND us.siteID = x.changedFromOrg
)
ORDER BY x.timestamp DESC;
```

**What to look for:** rows where `changedByIsGlobal` is 0. Those are changes an organisation's administrator made to someone outside their organisation.

**What to do:** check each changed account (name, email, whether it is switched on, administrator flag) with its owner and correct anything wrong. **The log does not record which details changed**, only that a change was saved.

To see every account creation, import and API change as well, for a wider review:

```sql
SELECT l.timestamp, l.siteID, s.siteName, l.userID AS doneBy, b.fullName AS doneByName,
       b.isRootAdmin AS doneByIsGlobal, l.activityType, l.activityDescription
FROM tblActivityLogs l
LEFT JOIN tblSites s ON s.siteID = l.siteID
LEFT JOIN tblUsers b ON b.userID = l.userID
WHERE l.activityType IN ('UserCreated', 'UserUpdated', 'BulkUserImport',
                         'ApiUserCreate', 'ApiUserUpdate', 'SafeguardingDbsRecorded')
ORDER BY l.timestamp DESC;
```

## 5. Invitations that were meant to make someone an administrator

Before the fix, accepting an invitation with the "admin" role made the person an administrator of **every** organisation. After the fix, it makes them administrator of the organisation that sent the invitation only, including invitations not yet accepted.

```sql
SELECT i.invitationID, i.siteID, s.siteName, i.email, i.createdAt, i.createdByID,
       i.acceptedAt, i.acceptedByID, u.fullName AS acceptedByName, u.isAdmin, u.isRootAdmin
FROM tblInvitation i
LEFT JOIN tblSites s ON s.siteID = i.siteID
LEFT JOIN tblUsers u ON u.userID = i.acceptedByID
WHERE i.intendedRole = 'admin'
ORDER BY i.createdAt DESC;
```

**What to look for:** accepted invitations where the new account still has `isAdmin` = 1 (it will also appear in step 1).

**What to do:** as in step 1, untick the administrator box, and make the person a site administrator of the organisation that invited them if that was the intention. Revoke any pending "admin" invitation you no longer want.

## 6. People offboarded or rehired by someone who did not administer their organisation

Offboarding switches an account off, clears its password and ends its memberships. Before the fix, any organisation's administrator could do that to anyone, including a global administrator.

```sql
SELECT o.offboardingID, o.offboardedAt, o.rehiredAt,
       o.userID, t.fullName AS personName, t.isRootAdmin AS personIsGlobal,
       o.offboardedByID, b.fullName AS offboardedByName, o.rehiredByID
FROM tblOffboarding o
LEFT JOIN tblUsers t ON t.userID = o.userID
LEFT JOIN tblUsers b ON b.userID = o.offboardedByID
WHERE COALESCE(b.isRootAdmin, 0) = 0
  AND NOT EXISTS (
      SELECT 1
      FROM tblUserSites a
      JOIN tblUserSites p ON p.siteID = a.siteID AND p.userID = o.userID
      WHERE a.userID = o.offboardedByID
        AND (a.isSiteAdmin = 1 OR a.isSiteRootAdmin = 1)
  )
ORDER BY o.offboardedAt DESC;
```

**What to look for:** any row at all. Each one is an offboarding by someone who was neither a global administrator nor an administrator of an organisation the person belonged to. The query uses memberships as they are *now*, so an offboarding that ended the person's memberships can also appear here; check each row.

**What to do:** confirm with the person and their organisation. If it was wrong, a global administrator can rehire them.

## 7. Safeguarding (DBS) records added by someone who did not administer the person's organisation

A DBS record can also give someone coordinator access, so a wrong one matters.

```sql
SELECT d.dbsCheckID, d.recordedAt, d.status, d.expiresAt,
       d.userID, t.fullName AS personName,
       d.recordedByID, b.fullName AS recordedByName
FROM tblDbsChecks d
LEFT JOIN tblUsers t ON t.userID = d.userID
LEFT JOIN tblUsers b ON b.userID = d.recordedByID
WHERE COALESCE(b.isRootAdmin, 0) = 0
  AND NOT EXISTS (
      SELECT 1
      FROM tblUserSites a
      JOIN tblUserSites p ON p.siteID = a.siteID AND p.userID = d.userID
      WHERE a.userID = d.recordedByID
        AND (a.isSiteAdmin = 1 OR a.isSiteRootAdmin = 1)
  )
ORDER BY d.recordedAt DESC;
```

**What to look for and do:** check each record with the organisation the person really belongs to, and correct or remove any that should not exist.

## 8. Accounts that belong to no organisation

After the fix, on an installation with several organisations, **only a global administrator can see or change an account that belongs to no organisation**. Organisation administrators will no longer see these people in their lists.

```sql
SELECT u.userID, u.fullName, u.emailAddress, u.isActive, u.isAdmin, u.isRootAdmin, u.createdAt
FROM tblUsers u
WHERE NOT EXISTS (SELECT 1 FROM tblUserSites us WHERE us.userID = u.userID)
ORDER BY u.createdAt DESC;
```

**What to look for:** people who should belong to an organisation. Global administrators often appear here legitimately.

**What to do:** add each person to the right organisation at **Admin → Sites → Users**.

---

## What this checklist cannot tell you

- **Which details a change altered.** The users page recorded that a change was saved, not what it was.
- **Anything done directly in the database**, outside the portal.
- **Changes whose log entries were cleared** by the portal's automatic clear-out of old activity logs, or removed by hand.
- **Whether someone signed in with a password they had set.** Sign-ins are not linked to these changes.

If anything here looks wrong and you are not sure what happened, keep a copy of the query results before changing anything. They are the record of what was found.
