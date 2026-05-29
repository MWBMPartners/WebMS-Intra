<?php
/**
 * -----------------------------------------------------------------------------
 * English (en) Translation File 🇬🇧
 * -----------------------------------------------------------------------------
 * Baseline translation file for the portal. All user-facing strings are defined
 * here and referenced by other locale files. Keys use dot-notation style naming
 * for logical grouping (e.g. 'auth.login_title', 'nav.dashboard').
 *
 * Parameterised strings use :param syntax: 'Welcome, :name'
 * Pluralised strings use | separator: 'One item|:count items'
 * Three-form plurals: 'No items|One item|:count items'
 *
 * @package   Portal\Lang
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2025-present MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   0.7.0
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

return [

    // =========================================================================
    // 🧭 Navigation
    // =========================================================================
    'nav.dashboard'         => 'Dashboard',
    'nav.admin'             => 'Admin',
    'nav.admin_dashboard'   => 'Admin Dashboard',
    'nav.error_log'         => 'Error Log',
    'nav.activity_log'      => 'Activity Log',
    'nav.user_management'   => 'User Management',
    'nav.migrations'        => 'Migrations',
    'nav.settings'          => 'Settings',
    'nav.my_account'        => 'My Account',
    'nav.sign_in'           => 'Sign In',
    'nav.sign_out'          => 'Sign Out',
    'nav.toggle_navigation' => 'Toggle navigation',
    'nav.toggle_dark_mode'  => 'Toggle dark mode',
    'nav.change_language'   => 'Change language',

    // 🌐 i18n meta — used by the language switcher to badge non-English
    //    locales that aren't yet at full key parity (#210).
    'i18n.partial_coverage_tooltip' => 'This language is :percent% translated. Some text will appear in English where translations are missing.',

    // =========================================================================
    // 🔐 Authentication
    // =========================================================================
    'auth.sign_in'                  => 'Sign In',
    'auth.sign_in_title'            => 'Sign In',
    'auth.username_or_email'        => 'Username or Email',
    'auth.password'                 => 'Password',
    'auth.forgot_password'          => 'Forgot your password?',
    'auth.forgot_password_title'    => 'Forgot Password',
    'auth.reset_password_title'     => 'Reset Password',
    'auth.sign_in_with_ms365'       => 'Sign in with Microsoft 365',
    'auth.sign_in_with_google'      => 'Sign in with Google',
    'auth.sign_in_with_passkey'     => 'Sign in with Passkey',
    'auth.or'                       => 'or',
    'auth.or_use_passkey'           => 'or use a passkey',
    'auth.password_reset_success'   => 'Your password has been updated. Please sign in.',
    'auth.invalid_session_token'    => 'Invalid session token. Please try again.',
    'auth.captcha_failed'           => 'Captcha verification failed.',
    'auth.enter_credentials'        => 'Please enter your username or email and password.',
    'auth.invalid_credentials'      => 'Invalid credentials.',
    'auth.too_many_attempts'        => 'Too many failed attempts. Please try again in :minutes minute(s).',
    'auth.new_password'             => 'New Password',
    'auth.confirm_password'         => 'Confirm Password',
    'auth.reset_password'           => 'Reset Password',
    'auth.send_reset_link'          => 'Send Reset Link',
    'auth.email_address'            => 'Email Address',
    'auth.back_to_login'            => 'Back to Sign In',

    // =========================================================================
    // 👤 Account
    // =========================================================================
    'account.title'                 => 'My Account',
    'account.profile'               => 'Profile',
    'account.full_name'             => 'Full Name',
    'account.email'                 => 'Email Address',
    'account.phone'                 => 'Phone Number',
    'account.change_password'       => 'Change Password',
    'account.current_password'      => 'Current Password',
    'account.new_password'          => 'New Password',
    'account.confirm_password'      => 'Confirm New Password',
    'account.update_password'       => 'Update Password',
    'account.linked_accounts'       => 'Linked Accounts',
    'account.passkeys'              => 'PassKeys',
    'account.register_passkey'      => 'Register New PassKey',
    'account.passkey_name'          => 'Passkey Name',
    'account.no_passkeys'           => 'No passkeys registered yet.',
    'account.unlink'                => 'Unlink',
    'account.delete'                => 'Delete',
    'account.link_google'           => 'Link Google Account',
    'account.link_ms365'            => 'Link Microsoft 365 Account',
    'account.account_type'          => 'Account Type',
    'account.member_since'          => 'Member Since',
    'account.last_login'            => 'Last Login',

    // =========================================================================
    // 🏠 Dashboard
    // =========================================================================
    'dashboard.title'               => 'Dashboard',

    // =========================================================================
    // 💰 Expenses
    // =========================================================================
    'expenses.title'                => 'Expenses',
    'expenses.submit_claim'         => 'Submit Claim',
    'expenses.my_claims'            => 'My Claims',
    'expenses.approve'              => 'Approve Claims',
    'expenses.treasury'             => 'Treasury',
    'expenses.new_claim'            => 'New Expense Claim',
    'expenses.claim_title'          => 'Claim Title',
    'expenses.department'           => 'Department',
    'expenses.description'          => 'Description',
    'expenses.amount'               => 'Amount',
    'expenses.date'                 => 'Date',
    'expenses.receipt'              => 'Receipt',
    'expenses.add_line'             => 'Add Line Item',
    'expenses.remove_line'          => 'Remove',
    'expenses.total'                => 'Total',
    'expenses.submit'               => 'Submit Claim',
    'expenses.status_draft'         => 'Draft',
    'expenses.status_submitted'     => 'Submitted',
    'expenses.status_approved'      => 'Approved',
    'expenses.status_rejected'      => 'Rejected',
    'expenses.status_reimbursed'    => 'Reimbursed',
    'expenses.no_claims'            => 'No expense claims found.',
    'expenses.view_claim'           => 'View Claim',
    'expenses.claim_number'         => 'Claim #:number',
    'expenses.submitted_by'         => 'Submitted by',
    'expenses.submitted_on'         => 'Submitted on',
    'expenses.approval_history'     => 'Approval History',
    'expenses.payment_records'      => 'Payment Records',
    'expenses.download_pdf'         => 'Download PDF',
    'expenses.mark_reimbursed'      => 'Mark as Reimbursed',
    'expenses.payment_reference'    => 'Payment Reference',
    'expenses.approve_claim'        => 'Approve',
    'expenses.reject_claim'         => 'Reject',
    'expenses.comments'             => 'Comments',

    // =========================================================================
    // 📅 Calendar / Events
    // =========================================================================
    'calendar.title'                => 'Calendar',
    'calendar.events'               => 'Events',
    'calendar.today'                => 'Today',
    'calendar.month'                => 'Month',
    'calendar.week'                 => 'Week',
    'calendar.day'                  => 'Day',
    'calendar.new_event'            => 'New Event',
    'calendar.edit_event'           => 'Edit Event',
    'calendar.event_title'          => 'Event Title',
    'calendar.start_date'           => 'Start Date',
    'calendar.end_date'             => 'End Date',
    'calendar.start_time'           => 'Start Time',
    'calendar.end_time'             => 'End Time',
    'calendar.location'             => 'Location',
    'calendar.all_day'              => 'All Day',
    'calendar.recurring'            => 'Recurring',
    'calendar.no_events'            => 'No events scheduled.',
    'calendar.preaching_plan'       => 'Preaching Plan',
    'calendar.subscribe'            => 'Subscribe',

    // =========================================================================
    // 📊 Attendance
    // =========================================================================
    'attendance.title'              => 'Attendance',
    'attendance.record'             => 'Record Attendance',
    'attendance.service_type'       => 'Service Type',
    'attendance.date'               => 'Date',
    'attendance.headcount'          => 'Headcount',
    'attendance.total'              => 'Total',
    'attendance.adults'             => 'Adults',
    'attendance.children'           => 'Children',
    'attendance.visitors'           => 'Visitors',
    'attendance.recent_sessions'    => 'Recent Sessions',
    'attendance.reports'            => 'Reports',
    'attendance.manage_types'       => 'Manage Service Types',
    'attendance.no_sessions'        => 'No attendance sessions recorded.',
    'attendance.save'               => 'Save Attendance',

    // =========================================================================
    // 🛡️ Admin
    // =========================================================================
    'admin.title'                   => 'Admin Dashboard',
    'admin.error_log_title'         => 'Error Log',
    'admin.activity_log_title'      => 'Activity Log',
    'admin.user_management_title'   => 'User Management',
    'admin.migrations_title'        => 'Database Migrations',
    'admin.no_errors'               => 'No errors recorded.',
    'admin.no_activity'             => 'No activity recorded.',
    'admin.no_users'                => 'No users found.',
    'admin.add_user'                => 'Add User',
    'admin.edit_user'               => 'Edit User',
    'admin.deactivate_user'         => 'Deactivate',
    'admin.activate_user'           => 'Activate',
    'admin.run_migration'           => 'Run Migration',
    'admin.migration_status'        => 'Status',
    'admin.migration_applied'       => 'Applied',
    'admin.migration_pending'       => 'Pending',

    // =========================================================================
    // ⚙️ Settings
    // =========================================================================
    'settings.title'                => 'Settings',
    'settings.add_setting'          => 'Add Setting',
    'settings.edit_setting'         => 'Edit Setting',
    'settings.save_changes'         => 'Save Changes',
    'settings.setting_deleted'      => 'Setting deleted.',
    'settings.setting_saved'        => 'Setting saved successfully.',
    'settings.root_only_delete'     => 'Only root admins can delete settings.',
    'settings.key'                  => 'Key',
    'settings.value'                => 'Value',
    'settings.updated'              => 'Updated',
    'settings.actions'              => 'Actions',
    'settings.sensitive_hint'       => 'This is a sensitive/encrypted setting. Enter the new plaintext value.',
    'settings.sensitive_badge'      => 'Encrypted in database',
    'settings.search_placeholder'   => 'Search settings by key or value...',
    'settings.settings_count'       => ':count settings|:count settings',
    'settings.use_dot_notation'     => 'Use dot-notation for grouping (e.g. site.name)',
    'settings.sensitive_label'      => 'Sensitive (encrypt in database)',

    // =========================================================================
    // ❓ Help Centre
    // =========================================================================
    'help.title'                    => 'Help Centre',
    'help.subtitle'                 => 'Find guides, tips, and answers for every part of the portal.',
    'help.quick_links'              => 'Quick Links',
    'help.getting_started'          => 'Getting Started',
    'help.getting_started_desc'     => 'Logging in, navigating the portal, first-time setup, and personalising your experience.',
    'help.expenses_title'           => 'Expenses',
    'help.expenses_desc'            => 'Submitting expense claims, uploading receipts, tracking statuses, and understanding the workflow.',
    'help.approvals_title'          => 'Approvals',
    'help.approvals_desc'           => 'For approvers: reviewing claims, making decisions, and leaving comments.',
    'help.treasury_title'           => 'Treasury',
    'help.treasury_desc'            => 'For treasury staff: recording reimbursements, payment references, and generating PDFs.',
    'help.admin_title'              => 'Admin Guide',
    'help.admin_desc'               => 'For administrators: managing settings, user roles, Gatekeeper access, and viewing logs.',
    'help.faq_title'                => 'FAQ',
    'help.faq_desc'                 => 'Frequently asked questions covering login issues, expenses, permissions, and more.',
    'help.submit_expense'           => 'Submit an Expense',
    'help.approve_claim'            => 'Approve a Claim',
    'help.still_need_help'          => 'Still need help?',
    'help.contact_admin'            => 'Contact your system administrator or raise a support request through your organisation\'s IT helpdesk.',

    // =========================================================================
    // 🚫 Error Pages
    // =========================================================================
    'error.page_not_found'          => 'Page Not Found',
    'error.page_not_found_text'     => 'The page you are looking for does not exist or may have been moved.',
    'error.access_denied'           => 'Access Denied',
    'error.access_denied_text'      => 'You do not have permission to access this page. Please contact your administrator if you believe this is an error.',
    'error.access_denied_inline'    => 'Access denied.',
    'error.server_error'            => 'Server Error',
    'error.something_wrong'         => 'Something Went Wrong',
    'error.server_error_text'       => 'An unexpected error occurred. The issue has been logged and our team will look into it. Please try again later.',
    'error.return_to_dashboard'     => 'Return to Dashboard',

    // ───── Inline error messages (flash / form failure paths) — issue #209
    'error.database'                       => 'Database error.',
    'error.db_create_service_type'         => 'Database error creating service type.',
    'error.db_create_session'              => 'Database error creating session.',
    'error.db_update_session'              => 'Database error updating session.',
    'error.db_create_user'                 => 'Database error creating user. Please try again.',
    'error.db_save_template'               => 'Database error saving template.',
    'error.db_save_preferences'            => 'Database error saving preferences.',
    'error.db_update_setting'              => 'Database error updating setting.',
    'error.db_add_setting'                 => 'Database error adding setting.',
    'error.db_create_role'                 => 'Database error creating role.',
    'error.db_import_prepare'              => 'Database error preparing insert.',
    'error.db_with_detail'                 => 'Database error: :detail',
    'error.csrf_failed'                    => 'CSRF check failed.',
    'error.umbrella_admin_only'            => 'Access denied. Umbrella admin privileges required.',
    'error.moderator_only'                 => 'Moderator access required.',
    'prayer_requests.error.not_found'      => 'Prayer request not found.',
    'prayer_requests.error.no_access'      => 'You do not have access to this prayer request.',

    // =========================================================================
    // 🔘 Common UI
    // =========================================================================
    'common.submit'                 => 'Submit',
    'common.save'                   => 'Save',
    'common.cancel'                 => 'Cancel',
    'common.delete'                 => 'Delete',
    'common.edit'                   => 'Edit',
    'common.add'                    => 'Add',
    'common.close'                  => 'Close',
    'common.confirm'                => 'Confirm',
    'common.back'                   => 'Back',
    'common.next'                   => 'Next',
    'common.previous'               => 'Previous',
    'common.search'                 => 'Search',
    'common.loading'                => 'Loading...',
    'common.no_results'             => 'No results found.',
    'common.yes'                    => 'Yes',
    'common.no'                     => 'No',
    'common.all_rights_reserved'    => 'All rights reserved.',

    // =========================================================================
    // 📧 Email
    // =========================================================================
    'email.greeting'                => 'Hello :name,',
    'email.regards'                 => 'Kind regards,',
    'email.footer'                  => 'This is an automated message from :site. Please do not reply directly.',

    // =========================================================================
    // 📐 Formatting
    // =========================================================================
    'format.date.short'             => 'd/m/Y',
    'format.date.medium'            => 'j M Y',
    'format.date.long'              => 'l, j F Y',
    'format.datetime.short'         => 'd/m/Y H:i',
    'format.datetime.medium'        => 'j M Y, H:i',
    'format.datetime.long'          => 'l, j F Y \\a\\t H:i',
    'format.decimal_point'          => '.',
    'format.thousands_separator'    => ',',
    'format.currency_position'      => 'before',

];
