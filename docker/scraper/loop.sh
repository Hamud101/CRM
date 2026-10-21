#!/usr/bin/env bash
# The scraper's job loop: it watches /outreach/jobs for ready folders, runs the
# scraper's command line on each, and marks the job done. No HTTP anywhere.
set -u

DIR=/outreach
mkdir -p "$DIR/jobs"

# The CRM reads the heartbeat to tell whether the scraper is alive, so keep it
# fresh even while a long scrape runs.
beat() {
    date +%s > "$DIR/heartbeat.tmp" && mv -f "$DIR/heartbeat.tmp" "$DIR/heartbeat"
}

# Print KEY= from a job.env. The file comes from the CRM, so it is read, never
# sourced or evaluated.
getv() {
    grep -m1 "^$2=" "$1" 2>/dev/null | cut -d= -f2- | tr -d '\r\n'
}

run_job() {
    local j="$1"

    local DEPTH LANG EMAIL MAXTIME
    DEPTH=$(getv "$j/job.env" DEPTH)
    LANG=$(getv "$j/job.env" LANG)
    EMAIL=$(getv "$j/job.env" EMAIL)
    MAXTIME=$(getv "$j/job.env" MAXTIME)

    # Bad values from the CRM fall back to the defaults; one bad job must not
    # stop the loop.
    case "$DEPTH" in ''|*[!0-9]*) DEPTH=2 ;; esac
    if [ "$DEPTH" -lt 1 ] || [ "$DEPTH" -gt 10 ]; then DEPTH=2; fi
    case "$LANG" in [a-z][a-z]) ;; *) LANG=en ;; esac
    if [ "$EMAIL" != "1" ]; then EMAIL=0; fi
    case "$MAXTIME" in ''|*[!0-9]*) MAXTIME=3600 ;; esac
    if [ "$MAXTIME" -lt 60 ] || [ "$MAXTIME" -gt 14400 ]; then MAXTIME=3600; fi

    date +%s > "$j/started"

    local args=(-input "$j/queries.txt" -results "$j/results.csv" -depth "$DEPTH"
                -lang "$LANG" -c 2 -exit-on-inactivity 3m)
    if [ "$EMAIL" = "1" ]; then args+=(-email); fi

    google-maps-scraper "${args[@]}" > "$j/log.txt" 2>&1 &
    local pid=$!
    local start
    start=$(date +%s)

    while kill -0 "$pid" 2>/dev/null; do
        beat
        if [ -f "$j/cancel" ]; then
            kill -TERM "$pid" 2>/dev/null
            sleep 10
            kill -0 "$pid" 2>/dev/null && kill -KILL "$pid" 2>/dev/null
            break
        fi
        if [ $(( $(date +%s) - start )) -gt "$MAXTIME" ]; then
            touch "$j/timedout"
            kill -TERM "$pid" 2>/dev/null
            sleep 10
            kill -0 "$pid" 2>/dev/null && kill -KILL "$pid" 2>/dev/null
            break
        fi
        sleep 5
    done

    wait "$pid"
    local rc=$?
    # done is written last, through a rename, so the CRM never sees it half made.
    echo "$rc" > "$j/done.tmp" && mv -f "$j/done.tmp" "$j/done"
}

# One job at a time: Google rate-limits a single IP, and two parallel scrapes
# get blocked sooner.
while true; do
    beat
    for j in $(ls -1dtr "$DIR"/jobs/*/ 2>/dev/null); do
        [ -f "$j/ready" ] || continue
        [ -f "$j/started" ] && continue
        [ -f "$j/cancel" ] && continue
        run_job "$j"
        break
    done
    sleep 5
done
