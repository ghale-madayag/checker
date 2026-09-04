<?php
/**
 * Weekly 200 Ideas - vote reminder.
 *
 * Companion to checkwinner.php. Nudges the exec group by email while the
 * week's voting is still short of REMIND_MIN_VOTES, so a week does not reach
 * the Friday 11:00 cutoff with too few votes to call a winner.
 *
 * Intended to run every 10 minutes on Fridays via cron; the schedule guard
 * below restricts the actual work to the reminder window, no matter when the
 * cron fires or what timezone the server clock is in.
 *
 * Flow:
 *   1. Stop unless it is RUN_DAY between REMIND_START_HOUR:REMIND_START_MIN
 *      and RUN_HOUR:00 Brisbane - the moment checkwinner.php takes over.
 *   2. Fetch the combined voting results.
 *   3. Stop if there are no ideas, or the total vote count already meets
 *      REMIND_MIN_VOTES.
 *   4. Otherwise email reminder.html to REMIND_TO.
 *
 * Usage:
 *   CLI  : php remind.php [--force] [--dry-run]
 *   HTTP : https://checker.depthintranet.com/remind.php?key=CRON_SECRET[&force=1][&dry=1]
 *
 *   --force   bypass the reminder-window guard (for testing)
 *   --dry-run do everything except sending the email
 */

define('CHECKWINNER', true);
require __DIR__ . '/config.php';
require __DIR__ . '/mailer.php';

// Fallbacks so an existing config.php does not have to be edited on deploy.
if (!defined('REMIND_TO'))           define('REMIND_TO', 'executives@depthlogistics.com');
if (!defined('REMIND_MIN_VOTES'))    define('REMIND_MIN_VOTES', 3);
if (!defined('REMIND_START_HOUR'))   define('REMIND_START_HOUR', 10);
if (!defined('REMIND_START_MIN'))    define('REMIND_START_MIN', 30);
if (!defined('REMIND_ENABLED'))      define('REMIND_ENABLED', true);

date_default_timezone_set(APP_TIMEZONE);

// Local Windows PHP builds ship without a CA bundle; point at one via
// CHECKWINNER_CAFILE when testing off-server. Unset in production.
$caFile = getenv('CHECKWINNER_CAFILE') ?: '';
if ($caFile !== '' && is_file($caFile)) {
    ini_set('openssl.cafile', $caFile);   // used by the SMTP stream context
}

/* ------------------------------------------------------- entry / arguments */

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

/* --------------------------------------------------- prevent parallel runs */

$lock = fopen(__DIR__ . '/remind.lock', 'c');
if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) {
    logline('Reminder: another run is already in progress.');
    exit(0);
}

if (!REMIND_ENABLED) {
    logline('Reminder: disabled via REMIND_ENABLED. Nothing to do.');
    exit(0);
}

/* --------------------------------------------------------- schedule guard */

$now = new DateTime('now', new DateTimeZone(APP_TIMEZONE));

if (!$force) {
    $windowStart = (clone $now)->setTime(REMIND_START_HOUR, REMIND_START_MIN, 0);
    $windowEnd   = (clone $now)->setTime(RUN_HOUR, 0, 0);

    if ($now->format('l') !== RUN_DAY || $now < $windowStart || $now >= $windowEnd) {
        logline('Reminder: outside the window (now ' . $now->format('l H:i') . ' ' . APP_TIMEZONE
            . ', window ' . RUN_DAY . ' ' . $windowStart->format('H:i') . '-' . $windowEnd->format('H:i')
            . '). Nothing to do.');
        exit(0);
    }
}

/* ----------------------------------------------------- fetch vote results */

$ch = curl_init(EP_COMBINED_RESULTS);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_FOLLOWLOCATION => true,
    CURLOPT_CONNECTTIMEOUT => 15,
    CURLOPT_TIMEOUT        => 60,
    CURLOPT_HTTPHEADER     => ['Accept: application/json'],
    // The endpoint answers HTTP 418 to requests without a known user agent.
    CURLOPT_USERAGENT      => 'DepthCheckWinner/1.0',
    CURLOPT_SSL_VERIFYPEER => VERIFY_SSL,
    CURLOPT_SSL_VERIFYHOST => VERIFY_SSL ? 2 : 0,
]);
if ($caFile !== '' && is_file($caFile)) {
    curl_setopt($ch, CURLOPT_CAINFO, $caFile);
}
$raw  = curl_exec($ch);
$err  = curl_error($ch);
$code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
curl_close($ch);

if ($raw === false) {
    logline("Reminder: cURL error fetching results: $err");
    exit(1);
}

$ideas = json_decode((string) $raw, true);
if ($code !== 200 || !is_array($ideas)) {
    logline("Reminder: could not fetch voting results (HTTP $code).");
    exit(1);
}

if (count($ideas) === 0) {
    logline('Reminder: no ideas submitted this week. Nothing to remind about.');
    exit(0);
}

/* ------------------------------------------------- threshold / build rows */

$total = 0;
$rows  = '';
foreach ($ideas as $n => $idea) {
    $votes  = (int) ($idea['votes'] ?? 0);
    $total += $votes;
    $bg     = ($n % 2 === 0) ? '#ffffff' : '#f9f9f9';
    // Submitter names are deliberately left out so voting stays blind.
    $rows  .= '<tr style="background-color:' . $bg . '">'
        . '<td style="padding:10px">' . htmlspecialchars((string) ($idea['post_title'] ?? ''), ENT_QUOTES, 'UTF-8') . '</td>'
        . '<td style="padding:10px;text-align:center">' . $votes . '</td>'
        . "</tr>\n";
}

if ($total >= REMIND_MIN_VOTES) {
    logline("Reminder: $total vote(s) cast, threshold of " . REMIND_MIN_VOTES . ' met. Nothing sent.');
    exit(0);
}

// A lone idea with any votes is checkwinner.php's winner regardless of the
// count - nothing left to remind anyone about.
if (count($ideas) === 1 && $total >= 1) {
    logline("Reminder: only one idea this week and it has $total vote(s) - it wins automatically. Nothing sent.");
    exit(0);
}

/* ------------------------------------------------------------ send email */

$html = @file_get_contents(__DIR__ . '/reminder.html');
if ($html === false) {
    logline('Reminder: reminder.html not found.');
    exit(1);
}
$summary = $total === 0
    ? 'no votes have been cast yet'
    : 'only ' . ($total === 1 ? 'one vote has' : "$total votes have") . ' been cast so far';
$consequence = $total === 0
    ? 'if no idea has received a vote by then, no winner can be announced for this week'
    : 'we need more votes in to land on a clear winner';

$html = str_replace(
    ['{{IDEA_ROWS}}', '{{VOTE_SUMMARY}}', '{{CONSEQUENCE}}'],
    [$rows, $summary, $consequence],
    $html
);

$subject = 'Weekly 200: Voting Closes ' . RUN_DAY . ' ' . RUN_HOUR . 'am - '
    . ($total === 0 ? 'No Votes Yet' : "Only $total Vote" . ($total === 1 ? '' : 's') . ' So Far');

if ($dryRun) {
    logline('[dry-run] Would send reminder to ' . REMIND_TO . " (total votes: $total, subject: $subject)");
    exit(0);
}

if (send_html_email(REMIND_TO, $subject, $html)) {
    logline('Reminder sent to ' . REMIND_TO . " (total votes: $total).");
    exit(0);
}

logline('Reminder: email to ' . REMIND_TO . ' could not be sent.');
exit(1);
