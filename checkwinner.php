<?php
/**
 * Weekly 200 Ideas - winner checker.
 *
 * Intended to run every Friday at 11:00 AM Australia/Brisbane via cron.
 *
 * Flow:
 *   1. Fetch voting results from the combined-results endpoint.
 *   2. Stop if there are no ideas, or no idea has at least one vote.
 *   3. If the top vote count is shared by 2+ ideas -> send tie.html to the team, stop.
 *   4. Otherwise fetch the winning post's details/meta, resolve the author's
 *      profile image from custom/v1/user/{id}, POST a new scoreboard record
 *      to jet-cct, set winner=true on the winning post, email winner.html,
 *      and clear the scoreboard cache (WP Rocket).
 *
 * Usage:
 *   CLI  : php checkwinner.php [--force] [--dry-run]
 *   HTTP : https://checker.depthintranet.com/checkwinner.php?key=CRON_SECRET[&force=1][&dry=1]
 *
 *   --force   bypass the Friday 11am Brisbane schedule guard (for testing)
 *   --dry-run do everything except the scoreboard POST and the emails
 */

define('CHECKWINNER', true);
require __DIR__ . '/config.php';

// Fallback for config.php files created before this constant existed.
if (!defined('EP_CLEAR_CACHE')) {
    define('EP_CLEAR_CACHE', 'https://scoreboard.depthintranet.com/wp-json/custom/v1/clear-cache');
}
// BuddyPress's built-in avatar route. It replaces EP_USER_INFO
// (custom/v1/user), which disappeared from the intranet in September 2026
// and left two winners on the scoreboard without a photo.
if (!defined('EP_USER_AVATAR')) {
    define('EP_USER_AVATAR', WP_BASE_URL . '/wp-json/buddypress/v1/members/%d/avatar');
}
// How many of the newest scoreboard entries to check for a missing photo.
if (!defined('HEAL_RECENT')) {
    define('HEAL_RECENT', 10);
}
// Who hears about problems that should not stop the announcement.
if (!defined('MAIL_TO_ALERT')) {
    define('MAIL_TO_ALERT', MAIL_FROM);
}

date_default_timezone_set(APP_TIMEZONE);

/* ---------------------------------------------------------------- helpers */

function logline($msg)
{
    if (!is_dir(LOG_DIR)) {
        @mkdir(LOG_DIR, 0755, true);
    }
    $line = '[' . date('Y-m-d H:i:s') . '] ' . $msg;
    @file_put_contents(LOG_DIR . '/checkwinner-' . date('Y-m') . '.log', $line . PHP_EOL, FILE_APPEND | LOCK_EX);
    echo $line . (php_sapi_name() === 'cli' ? PHP_EOL : "<br>\n");
}

function fail($msg)
{
    logline('ERROR: ' . $msg);
    exit(1);
}

/**
 * @return array [int $httpCode, mixed $json, string $raw]
 */
function http_request($url, $method = 'GET', $jsonBody = null, $withAuth = false, $extraHeaders = [], $fatal = true)
{
    $ch = curl_init($url);
    $headers = array_merge(['Accept: application/json'], $extraHeaders);

    if ($withAuth) {
        $headers[] = 'Authorization: Basic ' . base64_encode(WP_API_USER . ':' . WP_APP_PASSWORD);
    }
    if ($jsonBody !== null) {
        $payload   = json_encode($jsonBody, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $headers[] = 'Content-Type: application/json';
        curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
    }

    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST  => $method,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_CONNECTTIMEOUT => 15,
        CURLOPT_TIMEOUT        => 60,
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_USERAGENT      => 'DepthCheckWinner/1.0',
        CURLOPT_SSL_VERIFYPEER => VERIFY_SSL,
        CURLOPT_SSL_VERIFYHOST => VERIFY_SSL ? 2 : 0,
    ]);

    $raw  = curl_exec($ch);
    $err  = curl_error($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);

    if ($raw === false) {
        if (!$fatal) {
            logline("Warning: cURL error calling $url: $err");
            return [0, null, ''];
        }
        fail("cURL error calling $url: $err");
    }
    return [$code, json_decode($raw, true), $raw];
}

/**
 * Read one (possibly multiline) SMTP server response.
 */
function smtp_read($fp)
{
    $resp = '';
    while (($line = fgets($fp, 1024)) !== false) {
        $resp .= $line;
        if (strlen($line) < 4 || $line[3] !== '-') {
            break;
        }
    }
    return $resp;
}

/**
 * Send $cmd (or nothing, to read the greeting) and require a reply code prefix.
 * Returns null on success, an error string on failure.
 */
function smtp_cmd($fp, $cmd, $expect, $maskLog = null)
{
    if ($cmd !== null) {
        fwrite($fp, $cmd . "\r\n");
    }
    $resp = smtp_read($fp);
    if (strpos($resp, (string) $expect) !== 0) {
        $what = ($cmd === null) ? 'greeting' : ($maskLog !== null ? $maskLog : $cmd);
        return "SMTP '$what' failed, expected $expect, got: " . trim($resp);
    }
    return null;
}

/**
 * Deliver a fully-built MIME message via authenticated SMTP.
 * Returns true on success, an error string on failure.
 */
function smtp_send($from, $recipients, $message)
{
    $pass   = str_replace(' ', '', SMTP_PASSWORD);
    $useSsl = (SMTP_SECURE === 'ssl');
    $remote = ($useSsl ? 'ssl://' : 'tcp://') . SMTP_HOST . ':' . SMTP_PORT;
    $ctx    = stream_context_create(['ssl' => [
        'verify_peer'      => VERIFY_SSL,
        'verify_peer_name' => VERIFY_SSL,
    ]]);

    $fp = @stream_socket_client($remote, $errno, $errstr, 30, STREAM_CLIENT_CONNECT, $ctx);
    if (!$fp) {
        return 'connect to ' . SMTP_HOST . ':' . SMTP_PORT . " failed: $errstr ($errno)";
    }
    stream_set_timeout($fp, 30);

    $hostname = gethostname() ?: 'localhost';
    $steps = [];
    $steps[] = [null, 220, null];
    $steps[] = ["EHLO $hostname", 250, null];
    if (!$useSsl) {
        $steps[] = ['STARTTLS', 220, null];
    }

    foreach ($steps as $s) {
        if (($err = smtp_cmd($fp, $s[0], $s[1], $s[2])) !== null) {
            fclose($fp);
            return $err;
        }
    }

    if (!$useSsl) {
        if (!stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
            fclose($fp);
            return 'STARTTLS negotiation failed';
        }
        if (($err = smtp_cmd($fp, "EHLO $hostname", 250)) !== null) {
            fclose($fp);
            return $err;
        }
    }

    $steps = [
        ['AUTH LOGIN', 334, null],
        [base64_encode(SMTP_USER), 334, 'AUTH LOGIN username'],
        [base64_encode($pass), 235, 'AUTH LOGIN password'],
        ['MAIL FROM:<' . $from . '>', 250, null],
    ];
    foreach ($recipients as $rcpt) {
        $steps[] = ['RCPT TO:<' . trim($rcpt) . '>', 250, null];
    }
    $steps[] = ['DATA', 354, null];

    foreach ($steps as $s) {
        if (($err = smtp_cmd($fp, $s[0], $s[1], $s[2])) !== null) {
            fclose($fp);
            return $err;
        }
    }

    // Normalise line endings and dot-stuff the body (RFC 5321 4.5.2).
    $message = preg_replace('/\r?\n/', "\r\n", $message);
    $message = preg_replace('/^\./m', '..', $message);
    fwrite($fp, $message . "\r\n.\r\n");
    if (($err = smtp_cmd($fp, null, 250)) !== null) {
        fclose($fp);
        return $err;
    }

    fwrite($fp, "QUIT\r\n");
    fclose($fp);
    return true;
}

function send_html_email($to, $subject, $html)
{
    $encodedSubject = '=?UTF-8?B?' . base64_encode($subject) . '?=';
    $recipients     = array_filter(array_map('trim', explode(',', $to)));

    if (defined('SMTP_ENABLED') && SMTP_ENABLED) {
        $headers = [
            'Date: ' . date('r'),
            'From: ' . MAIL_FROM_NAME . ' <' . MAIL_FROM . '>',
            'To: ' . $to,
            'Subject: ' . $encodedSubject,
            'Reply-To: ' . MAIL_FROM,
            'Message-ID: <' . uniqid('checkwinner.', true) . '@depthlogistics.com>',
            'MIME-Version: 1.0',
            'Content-Type: text/html; charset=UTF-8',
            'Content-Transfer-Encoding: 8bit',
        ];
        $result = smtp_send(MAIL_FROM, $recipients, implode("\r\n", $headers) . "\r\n\r\n" . $html);
        if ($result !== true) {
            logline('SMTP error: ' . $result);
            return false;
        }
        return true;
    }

    $headers = implode("\r\n", [
        'MIME-Version: 1.0',
        'Content-Type: text/html; charset=UTF-8',
        'From: ' . MAIL_FROM_NAME . ' <' . MAIL_FROM . '>',
        'Reply-To: ' . MAIL_FROM,
        'X-Mailer: PHP/' . PHP_VERSION,
    ]);
    return mail($to, $encodedSubject, $html, $headers, '-f' . MAIL_FROM);
}

/**
 * Resolve a user's profile photo URL from BuddyPress.
 * Returns [string $url, string $error]; exactly one of them is non-empty.
 */
function fetch_avatar($userId, $fatal = true)
{
    list($code, $avatars, $raw) = http_request(sprintf(EP_USER_AVATAR, $userId), 'GET', null, false, [], $fatal);
    $avatar = (is_array($avatars) && isset($avatars[0]['full'])) ? (string) $avatars[0]['full'] : '';
    if ($code !== 200) {
        return ['', "avatar lookup for user $userId returned HTTP $code: " . substr((string) $raw, 0, 200)];
    }
    if ($avatar === '') {
        return ['', "avatar lookup for user $userId returned no image"];
    }
    // Gravatar fallbacks come back protocol-relative and HTML-encoded.
    $avatar = html_entity_decode($avatar, ENT_QUOTES, 'UTF-8');
    return [(strpos($avatar, '//') === 0) ? 'https:' . $avatar : $avatar, ''];
}

/**
 * Fill in the profile photo on recent scoreboard entries that are missing one.
 *
 * Entries store the winner's name but not their user ID, so each entry is
 * matched back to its weekly-idea post by title and week to find the author.
 * Every request is non-fatal: this must never stop the winner run.
 */
function heal_missing_photos($dryRun)
{
    list($code, $records) = http_request(EP_SCOREBOARD, 'GET', null, true, [], false);
    if ($code !== 200 || !is_array($records)) {
        logline("Photo check: could not read the scoreboard (HTTP $code). Skipping.");
        return;
    }

    usort($records, function ($a, $b) { return (int) ($b['_ID'] ?? 0) - (int) ($a['_ID'] ?? 0); });
    $missing = array_values(array_filter(array_slice($records, 0, HEAL_RECENT), function ($r) {
        return is_array($r) && trim((string) ($r['_profile_image'] ?? '')) === '';
    }));

    foreach ($missing as $rec) {
        $id      = (int) $rec['_ID'];
        $subject = html_entity_decode((string) ($rec['_subject'] ?? ''), ENT_QUOTES, 'UTF-8');
        $week    = (string) ($rec['_week'] ?? '');
        $label   = '#' . $id . ' "' . $subject . '" (' . ($rec['_month'] ?? '?') . ' week ' . $week . ')';

        $url = rtrim(EP_IDEA_POST, '/') . '?' . http_build_query([
            'search'   => $subject,
            'per_page' => 20,
            '_fields'  => 'id,title,author,meta.week',
        ]);
        list($code, $posts) = http_request($url, 'GET', null, true, [], false);

        $authorId = 0;
        foreach ((is_array($posts) ? $posts : []) as $post) {
            $title    = html_entity_decode((string) ($post['title']['rendered'] ?? ''), ENT_QUOTES, 'UTF-8');
            $postWeek = (string) ($post['meta']['week'] ?? '');
            if ($title === $subject && ($week === '' || $postWeek === '' || $postWeek === $week)) {
                $authorId = (int) ($post['author'] ?? 0);
                break;
            }
        }
        if ($authorId <= 0) {
            logline("Photo check: $label has no photo and its idea post could not be found (HTTP $code).");
            continue;
        }

        list($photo, $error) = fetch_avatar($authorId, false);
        if ($photo === '') {
            logline("Photo check: $label still has no photo - $error");
            continue;
        }

        if ($dryRun) {
            logline("[dry-run] Photo check: would set $label photo to $photo");
            continue;
        }
        list($code, $resp, $raw) = http_request(EP_SCOREBOARD . '/' . $id, 'POST', ['_profile_image' => $photo], true, [], false);
        if ($code === 200 && !empty($resp['success'])) {
            logline("Photo check: filled in $label photo: $photo");
        } else {
            logline("Photo check: could not update $label (HTTP $code): " . substr((string) $raw, 0, 200));
        }
    }
}

function load_template($file)
{
    $path = __DIR__ . '/' . $file;
    $html = @file_get_contents($path);
    if ($html === false) {
        fail("Email template not found: $path");
    }
    return $html;
}

/* ------------------------------------------------------ entry / arguments */

$isCli = (php_sapi_name() === 'cli');

if (!$isCli) {
    $key = isset($_GET['key']) ? (string) $_GET['key'] : '';
    if (CRON_SECRET === '' || !hash_equals(CRON_SECRET, $key)) {
        http_response_code(403);
        exit('Forbidden');
    }
    header('Content-Type: text/html; charset=UTF-8');
    $force  = isset($_GET['force']);
    $dryRun = isset($_GET['dry']);
} else {
    $args   = array_slice($GLOBALS['argv'], 1);
    $force  = in_array('--force', $args, true);
    $dryRun = in_array('--dry-run', $args, true);
}

/* -------------------------------------------------- prevent parallel runs */

$lock = fopen(__DIR__ . '/checkwinner.lock', 'c');
if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) {
    fail('Another run is already in progress.');
}

logline('--- Run started (force=' . ($force ? 'yes' : 'no') . ', dry-run=' . ($dryRun ? 'yes' : 'no') . ') ---');

/* --------------------------------- heal: backfill missing winner photos */

// Runs on every invocation, before the schedule guard, so a photo that
// could not be found at 11:00 is retried on each later hourly Friday run.
heal_missing_photos($dryRun);

/* ------------------------------------------------------- schedule guard */

$now = new DateTime('now', new DateTimeZone(APP_TIMEZONE));
if (ENFORCE_SCHEDULE && !$force) {
    if ($now->format('l') !== RUN_DAY || (int) $now->format('G') !== RUN_HOUR) {
        logline('Schedule guard: now is ' . $now->format('l H:i') . ' ' . APP_TIMEZONE
            . ', expected ' . RUN_DAY . ' ' . RUN_HOUR . ':00-' . RUN_HOUR . ':59. Nothing to do.');
        exit(0);
    }
}

/* ------------------------------------------------ step 1: voting results */

list($code, $ideas) = http_request(EP_COMBINED_RESULTS);
if ($code !== 200 || !is_array($ideas)) {
    fail("Could not fetch voting results (HTTP $code).");
}

logline('Fetched ' . count($ideas) . ' idea(s) from combined results.');

/* --------------------------------------- step 2: validate, filter 0 votes */

if (count($ideas) === 0) {
    logline('No ideas submitted this week. Stopping - no scoreboard update, no email.');
    exit(0);
}

$votedIdeas = array_values(array_filter($ideas, function ($idea) {
    return isset($idea['ID'], $idea['post_title']) && (int) ($idea['votes'] ?? 0) >= 1;
}));

if (count($votedIdeas) === 0) {
    logline('All ideas have 0 votes / no valid submissions. Stopping - no scoreboard update, no email.');
    exit(0);
}

foreach ($votedIdeas as $idea) {
    logline(sprintf('  Idea #%s "%s" by %s - %d vote(s)',
        $idea['ID'], $idea['post_title'], $idea['author_name'] ?? '?', (int) $idea['votes']));
}

/* -------------------------------------------- step 3: winner or tie check */

$maxVotes = max(array_map(function ($i) { return (int) $i['votes']; }, $votedIdeas));
$topIdeas = array_values(array_filter($votedIdeas, function ($i) use ($maxVotes) {
    return (int) $i['votes'] === $maxVotes;
}));

if (count($topIdeas) > 1) {
    logline('Tie detected: ' . count($topIdeas) . " ideas share the top vote count of $maxVotes.");

    $rows = '';
    foreach ($topIdeas as $n => $idea) {
        $bg    = ($n % 2 === 0) ? '#ffffff' : '#f9f9f9';
        $rows .= '<tr style="background-color:' . $bg . '">'
            . '<td style="padding:10px">' . htmlspecialchars($idea['post_title'], ENT_QUOTES, 'UTF-8') . '</td>'
            . '<td style="padding:10px;text-align:center">' . (int) $idea['votes'] . '</td>'
            . "</tr>\n";
    }
    $html = str_replace('{{TIE_ROWS}}', $rows, load_template('tie.html'));

    if ($dryRun) {
        logline('[dry-run] Would send tie email to ' . MAIL_TO_TIE);
    } elseif (send_html_email(MAIL_TO_TIE, MAIL_SUBJECT_TIE, $html)) {
        logline('Tie email sent to ' . MAIL_TO_TIE);
    } else {
        fail('Tie email could not be sent (mail() returned false).');
    }
    logline('Tie run finished - scoreboard NOT updated.');
    exit(0);
}

$winner = $topIdeas[0];
logline(sprintf('Winner: #%s "%s" by %s with %d vote(s).',
    $winner['ID'], $winner['post_title'], $winner['author_name'] ?? '?', $maxVotes));

/* ----------------------------------- step 4: winning post details / meta */

list($code, $post) = http_request(EP_IDEA_POST . (int) $winner['ID']);
if ($code !== 200 || !is_array($post)) {
    fail("Could not fetch winning post #{$winner['ID']} (HTTP $code).");
}
$meta = isset($post['meta']) && is_array($post['meta']) ? $post['meta'] : [];

$week        = (string) ($meta['week'] ?? $winner['week'] ?? '');
$title       = trim((string) ($post['title']['rendered'] ?? $winner['post_title']));
$title       = html_entity_decode($title, ENT_QUOTES, 'UTF-8');
$description = (string) ($meta['descriptions'] ?? $winner['descriptions'] ?? '');
// NB: the post-meta key is spelled "attachements" on the intranet.
// jet-cct requires _attachments to be a string, so normalise null/arrays.
$attachments = $meta['attachements'] ?? $winner['attachments'] ?? '';
if (is_array($attachments)) {
    $attachments = implode(',', array_map('strval', $attachments));
} else {
    $attachments = trim((string) $attachments);
}
$authorName  = (string) ($winner['author_name'] ?? '');
$authorId    = (int) ($winner['author_id'] ?? ($post['author'] ?? 0));
$month       = $now->format('F');

if ($week === '' || $title === '') {
    fail('Winning post is missing week meta or title - aborting.');
}

/* --------------------------------------- step 5: author's profile image */

$profileImage = '';
$avatarError  = '';
if ($authorId <= 0) {
    $avatarError = 'the winning post has no author ID';
} else {
    list($profileImage, $avatarError) = fetch_avatar($authorId, false);
}
if ($avatarError !== '') {
    // Carry on: a missing photo should not block the announcement. An alert
    // goes out at the end of the run, and heal_missing_photos() retries later.
    logline('WARNING: no profile image - ' . $avatarError);
} else {
    logline('Profile image: ' . $profileImage);
}

/* -------------------------------- step 6: duplicate guard on scoreboard */

list($code, $records) = http_request(EP_SCOREBOARD, 'GET', null, true);
if ($code === 200 && is_array($records)) {
    foreach ($records as $rec) {
        if (!is_array($rec)) {
            continue;
        }
        if ((string) ($rec['_month'] ?? '') === $month
            && (string) ($rec['_week'] ?? '') === $week
            && (string) ($rec['_subject'] ?? '') === $title) {
            logline("Scoreboard already has a record for $month week $week (\"$title\"). Stopping to avoid a duplicate.");
            exit(0);
        }
    }
} else {
    logline("Warning: could not read scoreboard for duplicate check (HTTP $code) - continuing.");
}

/* ------------------------------------------ step 7: POST scoreboard row */

$payload = [
    '_week'          => $week,
    '_month'         => $month,
    '_subject'       => $title,
    '_descriptions'  => $description,
    '_employee_name' => $authorName,
    '_profile_image' => $profileImage,
    '_attachments'   => $attachments,
    'cct_status'     => 'publish',
];

if ($dryRun) {
    logline('[dry-run] Would POST scoreboard record: ' . json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
} else {
    list($code, $resp, $raw) = http_request(EP_SCOREBOARD, 'POST', $payload, true);
    if ($code < 200 || $code >= 300) {
        fail("Scoreboard POST failed (HTTP $code): $raw");
    }
    logline("Scoreboard updated for $month week $week (HTTP $code).");
}

/* ---------------------------- step 8: mark the post as this week's winner */

if ($dryRun) {
    logline("[dry-run] Would set winner=true on post #{$winner['ID']}.");
} else {
    list($code, $resp, $raw) = http_request(EP_IDEA_POST . (int) $winner['ID'], 'POST', ['meta' => ['winner' => 'true']], true);
    if ($code >= 200 && $code < 300) {
        logline("Post #{$winner['ID']} marked as winner (meta winner=true).");
    } else {
        // The scoreboard is already updated, so warn but still send the announcement.
        logline("Warning: could not set winner=true on post #{$winner['ID']} (HTTP $code): $raw");
    }
}

/* ----------------------------------------- step 9: winner email to team */

$html = load_template('winner.html');
// Optional placeholders - winner.html is currently static, but these work if added.
$html = str_replace(
    ['{{WINNER_NAME}}', '{{IDEA_TITLE}}', '{{MONTH}}', '{{WEEK}}'],
    [
        htmlspecialchars($authorName, ENT_QUOTES, 'UTF-8'),
        htmlspecialchars($title, ENT_QUOTES, 'UTF-8'),
        $month,
        $week,
    ],
    $html
);

if ($dryRun) {
    logline('[dry-run] Would send winner email to ' . MAIL_TO);
} elseif (send_html_email(MAIL_TO, MAIL_SUBJECT_WINNER, $html)) {
    logline('Winner email sent to ' . MAIL_TO);
} else {
    fail('Scoreboard was updated but the winner email could not be sent (mail() returned false).');
}

/* -------------------------------------- step 10: clear scoreboard cache */

if ($dryRun) {
    logline('[dry-run] Would clear the scoreboard cache.');
} else {
    // Shared secret expected by the endpoint's permission_callback,
    // derived from the WP app password so it is never stored separately.
    $cacheKey = substr(sha1('cache-clear:' . WP_APP_PASSWORD), 0, 20);
    list($code, $resp, $raw) = http_request(EP_CLEAR_CACHE, 'POST', null, false, ['X-Cache-Key: ' . $cacheKey]);
    if ($code === 200 && !empty($resp['success'])) {
        logline('Scoreboard cache cleared: ' . ($resp['message'] ?? 'ok'));
    } else {
        // Everything important already succeeded - just flag it.
        logline("Warning: cache clear failed (HTTP $code): $raw");
    }
}

/* ------------------------------ step 11: alert if the photo was missing */

if ($avatarError !== '') {
    $alert = '<p>The Weekly $200 winner for this week was posted to the scoreboard and announced, '
        . 'but <strong>without a profile image</strong>, so the scoreboard shows a placeholder.</p>'
        . '<ul>'
        . '<li>Winner: ' . htmlspecialchars($authorName, ENT_QUOTES, 'UTF-8') . " (user ID $authorId)</li>"
        . '<li>Idea: ' . htmlspecialchars($title, ENT_QUOTES, 'UTF-8') . '</li>'
        . '<li>Scoreboard entry: ' . htmlspecialchars("$month, week $week", ENT_QUOTES, 'UTF-8') . '</li>'
        . '<li>Reason: ' . htmlspecialchars($avatarError, ENT_QUOTES, 'UTF-8') . '</li>'
        . '</ul>'
        . '<p>The checker retries every hour on Fridays and fills the photo in once it can be found. '
        . 'If it is still missing after that, check the BuddyPress avatar route for this user.</p>';
    if ($dryRun) {
        logline('[dry-run] Would send missing-photo alert to ' . MAIL_TO_ALERT);
    } elseif (send_html_email(MAIL_TO_ALERT, 'Weekly 200: winner posted without a profile image', $alert)) {
        logline('Missing-photo alert sent to ' . MAIL_TO_ALERT);
    } else {
        logline('WARNING: missing-photo alert to ' . MAIL_TO_ALERT . ' could not be sent.');
    }
}

logline('--- Run finished successfully ---');
exit(0);
