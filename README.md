# Weekly 200 Ideas – Winner Checker

Automation that runs every **Friday 11:00 AM Australia/Brisbane**:

1. Fetches voting results from `https://depthintranet.com/wp-json/my/combine-result-weekl/`.
2. **Stops** (no update, no email) if there are no ideas, or every idea has 0 votes.
3. If two or more ideas tie for the top vote count → emails `tie.html` (Exec vote required) and **does not** touch the scoreboard.
4. Otherwise, for the single winner:
   - fetches post details/meta from `wp-json/wp/v2/weekly-idea/{post_id}`,
   - resolves the author's profile image from BuddyPress (`wp-json/buddypress/v1/members/{user_id}/avatar`); if that fails the winner is still posted and an alert goes to `MAIL_TO_ALERT`,
   - POSTs a record to `wp-json/jet-cct/_weekly_idea_sboard`
     (`_week`, `_month`, `_subject`, `_descriptions`, `_employee_name`, `_profile_image`, `_attachments`),
   - emails `winner.html` to `everyone@depthlogistics.com`,
   - clears the WP Rocket cache via
     `https://scoreboard.depthintranet.com/wp-json/custom/v1/clear-cache` so the
     scoreboard page shows the new winner immediately.

Every run, before the schedule guard, also checks the newest scoreboard
entries (`HEAL_RECENT`, default 10) for a missing profile photo. It traces each
one back to its idea post to find the author, looks the photo up in BuddyPress,
and fills it in through the scoreboard's update route. A photo that couldn't be
found at 11:00 is retried on every later hourly Friday run. This needs the
update REST endpoint enabled on the Weekly Idea Scoreboard content type in
JetEngine (enabled September 2026, `edit_posts` access).

A duplicate guard checks the scoreboard first, so the same month/week/title is never posted twice even if the cron fires more than once.

## Files

| File | Purpose |
|---|---|
| `checkwinner.php` | Main script (cron entry point) |
| `remind.php` | Vote reminder (second cron entry point) |
| `mailer.php` | SMTP helpers shared by both scripts |
| `reminder.html` | Reminder email (`{{IDEA_ROWS}}` is filled in with this week's ideas) |
| `config.php` | Credentials & settings – gitignored; copy `config.sample.php` and fill in |
| `config.sample.php` | Template for `config.php` (no real credentials) |
| `winner.html` | Winner announcement email (sent as-is) |
| `tie.html` | Tie notification email (`{{TIE_ROWS}}` is filled in with the tied ideas) |
| `logs/` | Created automatically; one log file per month |

## Setup

1. `config.php` already contains the WordPress Application Password used for the
   authenticated scoreboard POST (`WP_API_USER` / `WP_APP_PASSWORD`).
2. Edit `config.php`:
   - `CRON_SECRET` – set any long random string (protects the script when called over HTTP).
   - Adjust email addresses/subjects if needed.
3. Upload the whole folder to VentraIP so it is served at
   `https://checker.depthintranet.com/`. Keep `config.php` out of any public
   git repo – it holds live credentials.

## Cron on VentraIP (cPanel → Cron Jobs)

The script has a built-in guard: it only does its work when it is **Friday
between 11:00 and 11:59 Brisbane time**, regardless of the server's timezone.
So the safest schedule is *hourly on Fridays* – the guard picks the right hour:

```
0 * * * 5  /usr/local/bin/php -q /home/YOUR_CPANEL_USER/public_html/checker/checkwinner.php
```

(Adjust the path to wherever cPanel puts the subdomain's document root, e.g.
`/home/USER/checker.depthintranet.com/checkwinner.php`.)

If you prefer a single weekly firing and the server clock is AEST (UTC+10):

```
0 11 * * 5  /usr/local/bin/php -q /home/YOUR_CPANEL_USER/public_html/checker/checkwinner.php
```

Alternatively an HTTP cron works too:

```
0 * * * 5  curl -fsS "https://checker.depthintranet.com/checkwinner.php?key=YOUR_CRON_SECRET" > /dev/null
```

## Vote reminders

`remind.php` emails the exec group while the week's voting is still short of
`REMIND_MIN_VOTES` (default 3), so a week does not reach the Friday cutoff with
too few votes to call a winner. It never runs past `RUN_HOUR` - the moment
`checkwinner.php` takes over - and stops on its own when either:

- the total vote count reaches `REMIND_MIN_VOTES`, or
- only one idea was submitted and it has at least one vote. A lone idea is
  `checkwinner.php`'s winner whatever its count, so there is nothing to chase.

The email body reflects the live count ("no votes have been cast yet" / "only
2 votes have been cast so far"), as does the subject line.

The reminder window is Friday `REMIND_START_HOUR:REMIND_START_MIN` (default
10:30) until `RUN_HOUR:00` (11:00) Brisbane. Like the main script, the guard is
enforced in PHP, so the cron can fire all day and only the window does work:

```
*/10 * * * 5  /usr/local/bin/php -q /home/depthint/repositories/checker/remind.php
```

Outside the window the script exits before making any HTTP call, so the extra
firings cost nothing.

Defaults live in `remind.php` and only need to be in `config.php` to override:

| Constant | Default | Purpose |
|---|---|---|
| `REMIND_TO` | `executives@depthlogistics.com` | Who gets reminded |
| `REMIND_MIN_VOTES` | `3` | Stop reminding once total votes reach this |
| `REMIND_START_HOUR` / `REMIND_START_MIN` | `10` / `30` | When reminders start |
| `REMIND_ENABLED` | `true` | Set `false` to switch reminders off |

Submitter names are deliberately left out of the reminder email so voting stays
blind - only idea titles and vote counts are shown.

## Testing

```
php checkwinner.php --force --dry-run   # bypass schedule guard, no POST / no emails
php checkwinner.php --force             # full run right now
php remind.php --force --dry-run        # bypass reminder window, no email
php remind.php --force                  # send a reminder right now
```

Over HTTP: `https://checker.depthintranet.com/checkwinner.php?key=SECRET&force=1&dry=1`

Check `logs/checkwinner-YYYY-MM.log` for what happened on each run.

## Notes

- Winner rule: highest vote count wins; a single idea with ≥1 vote wins
  automatically; ideas with 0 votes are ignored entirely.
- `_month` is the current full month name in Brisbane time; `_week` comes from
  the winning post's `week` meta.
- Emails are sent through Google Workspace SMTP (`smtp.gmail.com:465`) as
  `backup@depthlogistics.com` using an app password (`SMTP_*` settings in
  `config.php`). If VentraIP blocks outbound port 465, switch to
  `SMTP_PORT 587` / `SMTP_SECURE 'tls'`; setting `SMTP_ENABLED` to `false`
  falls back to PHP `mail()`.
