#!/usr/bin/env bash
# Waits until START_HHMM (Brisbane/AEST), then reminds executives@ every 10 min
# until total votes reach 3, or the Friday 11:00 Brisbane cutoff passes.
# Exit: 3 = threshold reached, 4 = cutoff.
export CHECKWINNER_CAFILE="C:\\Program Files\\Git\\usr\\ssl\\certs\\ca-bundle.crt"
cd "$(dirname "$0")" || exit 1
mkdir -p logs

START_HHMM="${START_HHMM:-0855}"
LOG=logs/vote-watch.log

# Brisbane wall clock as HHMM, via PHP so it matches APP_TIMEZONE exactly.
now_hhmm() { php -r 'define("CHECKWINNER",true); require "config.php"; echo (new DateTime("now", new DateTimeZone(APP_TIMEZONE)))->format("Hi");'; }
now_full() { php -r 'define("CHECKWINNER",true); require "config.php"; echo (new DateTime("now", new DateTimeZone(APP_TIMEZONE)))->format("l Y-m-d H:i");'; }

echo "[$(now_full)] Waiting until $START_HHMM Brisbane to start reminders." | tee -a "$LOG"

# Poll rather than sleep-until, so a clock jump (machine resume) can't overshoot silently.
while [ "$(now_hhmm)" \< "$START_HHMM" ]; do
    sleep 60
done

echo "[$(now_full)] Start time reached - beginning reminder loop." | tee -a "$LOG"

while true; do
    php send-reminder.php executives@depthlogistics.com --min=3 2>&1 | tee -a "$LOG"
    ec=${PIPESTATUS[0]}
    case $ec in
        3) echo "[$(now_full)] 3+ votes reached - stopping." | tee -a "$LOG"; exit 3 ;;
        4) echo "[$(now_full)] Cutoff reported by send-reminder.php - stopping." | tee -a "$LOG"; exit 4 ;;
    esac
    sleep 600
done
