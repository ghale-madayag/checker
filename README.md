# Weekly 200 Ideas – Winner Checker

Automation that runs every **Friday 11:00 AM Australia/Brisbane**:

1. Fetches voting results from `https://depthintranet.com/wp-json/my/combine-result-weekl/`.
2. **Stops** (no update, no email) if there are no ideas, or every idea has 0 votes.
3. If two or more ideas tie for the top vote count → emails `tie.html` (Exec vote required) and **does not** touch the scoreboard.
4. Otherwise, for the single winner:
   - fetches post details/meta from `wp-json/wp/v2/weekly-idea/{post_id}`,
   - resolves the author's profile image from `wp-json/custom/v1/user/{user_id}`,
   - POSTs a record to `wp-json/jet-cct/_weekly_idea_sboard`
     (`_week`, `_month`, `_subject`, `_descriptions`, `_employee_name`, `_profile_image`, `_attachments`),
   - emails `winner.html` to `everyone@depthlogistics.com`,
   - clears the WP Rocket cache via
     `https://scoreboard.depthintranet.com/wp-json/custom/v1/clear-cache` so the
     scoreboard page shows the new winner immediately.

A duplicate guard checks the scoreboard first, so the same month/week/title is never posted twice even if the cron fires more than once.

## Files

| File | Purpose |
|---|---|
| `checkwinner.php` | Main script (cron entry point) |
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

## Testing

```
php checkwinner.php --force --dry-run   # bypass schedule guard, no POST / no emails
php checkwinner.php --force             # full run right now
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
