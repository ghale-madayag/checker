<?php
/**
 * Configuration for the Weekly 200 winner checker.
 * Copy this file to config.php and fill in the real credentials.
 * config.php is gitignored - never commit it.
 */
if (!defined('CHECKWINNER')) {
    http_response_code(403);
    exit('Forbidden');
}

/* ---------- WordPress REST API ---------- */
define('WP_BASE_URL', 'https://depthintranet.com');

// WordPress user that is allowed to write jet-cct scoreboard records.
// Create an Application Password: WP Admin -> Users -> Profile -> Application Passwords.
define('WP_API_USER', 'YOUR_WP_USERNAME');
define('WP_APP_PASSWORD', 'xxxx xxxx xxxx xxxx xxxx xxxx');

define('EP_COMBINED_RESULTS', WP_BASE_URL . '/wp-json/my/combine-result-weekl/');
define('EP_IDEA_POST',        WP_BASE_URL . '/wp-json/wp/v2/weekly-idea/');            // + {post_id}
define('EP_SCOREBOARD',       WP_BASE_URL . '/wp-json/jet-cct/_weekly_idea_sboard');
define('EP_USER_INFO',        WP_BASE_URL . '/wp-json/custom/v1/user/%d');             // + {user_id}

/* ---------- Email ---------- */
define('MAIL_TO',             'everyone@depthlogistics.com');
define('MAIL_TO_TIE',         'everyone@depthlogistics.com');
define('MAIL_FROM',           'backup@depthlogistics.com');
define('MAIL_FROM_NAME',      'Depth Intranet');
define('MAIL_SUBJECT_WINNER', 'Weekly 200: This Week\'s Winner!');
define('MAIL_SUBJECT_TIE',    'Weekly 200: Voting Tie - Exec Vote Required');

/* ---------- SMTP (Google Workspace) ---------- */
// When true, emails are sent through SMTP_HOST with the app password below.
// When false, falls back to PHP mail().
define('SMTP_ENABLED', true);
define('SMTP_HOST', 'smtp.gmail.com');
define('SMTP_PORT', 465);
define('SMTP_SECURE', 'ssl');            // 'ssl' = implicit TLS :465, 'tls' = STARTTLS :587
define('SMTP_USER', 'backup@depthlogistics.com');
define('SMTP_PASSWORD', 'xxxx xxxx xxxx xxxx');   // Google app password (spaces are ignored)

/* ---------- Schedule guard ---------- */
define('APP_TIMEZONE', 'Australia/Brisbane');
// When true, the script only does its work on RUN_DAY during the RUN_HOUR
// (Brisbane time), no matter when the cron fires. Bypass with --force / ?force=1.
define('ENFORCE_SCHEDULE', true);
define('RUN_DAY', 'Friday');
define('RUN_HOUR', 11);

/* ---------- Security ---------- */
// Required as ?key=... when the script is called over HTTP (browser / http cron).
// Use a long random string.
define('CRON_SECRET', 'CHANGE-ME-to-a-long-random-string');

/* ---------- SSL ---------- */
// Keep true in production. Set false only for local Windows testing where
// PHP has no CA bundle configured.
define('VERIFY_SSL', true);

/* ---------- Logging ---------- */
define('LOG_DIR', __DIR__ . '/logs');
