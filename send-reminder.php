<?php
/**
 * One-off / loop reminder sender for the Weekly $200 vote.
 *
 * Usage: php send-reminder.php <to,addresses> [--dry-run] [--min=N]
 *
 * Exit codes: 0 sent | 1 error | 3 threshold already met (nothing sent)
 *             4 past the Friday cutoff (nothing sent)
 *
 * Fetches the live combined results, fills reminder.html and sends it.
 * Exits 0 on success, 1 on failure. Prints the current total vote count.
 */
define('CHECKWINNER', true);
require __DIR__ . '/config.php';
require __DIR__ . '/mailer.php';
date_default_timezone_set(APP_TIMEZONE);

// Local Windows PHP builds ship without a CA bundle; point at one via
// CHECKWINNER_CAFILE. On the server this is unset and nothing changes.
$caFile = getenv('CHECKWINNER_CAFILE') ?: '';
if ($caFile !== '' && is_file($caFile)) {
    ini_set('openssl.cafile', $caFile);   // used by the SMTP stream context
}

$args   = array_slice($argv, 1);
$dryRun = in_array('--dry-run', $args, true);
$min    = 0;
foreach ($args as $a) {
    if (strpos($a, '--min=') === 0) { $min = (int) substr($a, 6); }
}
$to     = '';
foreach ($args as $a) {
    if (strpos($a, '--') !== 0) { $to = $a; break; }
}
if ($to === '') { fwrite(STDERR, "No recipient given.\n"); exit(1); }

$ch = curl_init(EP_COMBINED_RESULTS);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_FOLLOWLOCATION => true,
    CURLOPT_TIMEOUT        => 60,
    CURLOPT_HTTPHEADER     => ['Accept: application/json'],
    CURLOPT_USERAGENT      => 'DepthCheckWinner/1.0',
    CURLOPT_SSL_VERIFYPEER => VERIFY_SSL,
    CURLOPT_SSL_VERIFYHOST => VERIFY_SSL ? 2 : 0,
]);
if ($caFile !== '' && is_file($caFile)) {
    curl_setopt($ch, CURLOPT_CAINFO, $caFile);
}
$raw = curl_exec($ch);
curl_close($ch);
$ideas = json_decode((string) $raw, true);
if (!is_array($ideas)) { logline('Reminder: could not fetch voting results.'); exit(1); }

$total = 0;
$rows  = '';
foreach ($ideas as $n => $i) {
    $total += (int) ($i['votes'] ?? 0);
    $bg = ($n % 2 === 0) ? '#ffffff' : '#f9f9f9';
    $rows .= '<tr style="background-color:' . $bg . '">'
        . '<td style="padding:10px">' . htmlspecialchars((string) $i['post_title'], ENT_QUOTES, 'UTF-8') . '</td>'
        . '<td style="padding:10px;text-align:center">' . (int) ($i['votes'] ?? 0) . '</td></tr>' . "\n";
}
echo "TOTAL_VOTES=$total\n";

if ($min > 0 && $total >= $min) {
    logline("Reminder: threshold reached (total votes: $total >= $min). Nothing sent.");
    exit(3);
}

// Voting closes on RUN_DAY at RUN_HOUR Brisbane; reminders after that are pointless.
$now = new DateTime('now', new DateTimeZone(APP_TIMEZONE));
if ($now->format('l') === RUN_DAY && (int) $now->format('G') >= RUN_HOUR) {
    logline('Reminder: past the ' . RUN_DAY . ' ' . RUN_HOUR . ':00 cutoff (clock reads '
        . $now->format('l Y-m-d H:i:s T') . '). Nothing sent.');
    exit(4);
}

$html = str_replace('{{IDEA_ROWS}}', $rows, (string) file_get_contents(__DIR__ . '/reminder.html'));
$subject = 'Weekly 200: Voting Closes Friday 11am - ' . ($total === 0 ? 'No Votes Yet' : "Only $total Vote" . ($total === 1 ? '' : 's') . ' So Far');

if ($dryRun) { logline("[dry-run] Would send reminder to $to (subject: $subject)"); exit(0); }
if (send_html_email($to, $subject, $html)) { logline("Reminder sent to $to (total votes: $total)."); exit(0); }
logline("Reminder to $to FAILED."); exit(1);
