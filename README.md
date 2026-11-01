# AtCronLog

**See your cron job in ProcessWire's admin log viewer — even when the shell redirect
gives you nothing.**

AtCronLog is a small ProcessWire module that makes cron output readable under
**Setup → Logs**. It mirrors a log file (by default `site/assets/logs/at-cron.log`,
the file the AgentTools `--at-cron` job writes to)
into a real ProcessWire log, and — when AgentTools is installed — records what every
cron run actually did, derived from the job queue.

Two ProcessWire behaviours stand in the way of "just point cron at the logs folder",
and this module is built around both:

1. **The log viewer only lists `*.txt`.** `WireLog::getFiles()` compares the file
   extension with `$logExtension = 'txt'`, so a `cron.log` sitting in
   `site/assets/logs/` never appears in the admin, no matter how correct its content is.
2. **The log viewer only parses timestamped lines.** `FileLog::isValidLine()` drops any
   line that does not start with `YYYY-MM-DD HH:MM:SS` followed by a tab. Output of a
   shell redirect (`AgentTools cron queued 0 scheduled job(s), …`) has neither, so even
   a correctly named `.txt` file renders as an **empty** log.

On top of that, some hosts (Hostinger shared hosting, for example) run the cron command
in a wrapper that discards the process output: the redirect target is created but stays
at 0 bytes while the job itself runs fine. There is nothing to mirror in that case —
which is why the module also writes a line per cron run from the job queue counters.

## Features

- **Mirrors any log file into a ProcessWire log** — incremental by byte offset, so
  repeated calls never duplicate entries; handles truncation/rotation of the source and
  waits for a trailing partial line to be completed
- **Visible where you already look** — the entries show up in *Setup → Logs → at-cron*
  with proper timestamps, search and date filters, like every other PW log
- **Records what each cron run did** (AgentTools only): `cron run: processed=2 failed=0
  pending=0`, computed from the `pending`/`running`/`done`/`failed` queue directories
- **No duplicate noise** — the counter line is skipped whenever the mirrored file itself
  delivered new lines in that run
- **Idle heartbeat with a sane interval** — cron runs without work are logged at most
  once per hour (configurable, `0` disables), so the log proves the cron is alive without
  growing by 1,440 lines per day
- **Zero configuration required** — defaults work out of the box; every path and toggle
  is exposed in the module config
- **Never eats content** — only complete lines are consumed; the read offset is stored
  outside the cache so clearing PW's cache does not re-import the file

## Requirements

- ProcessWire >= 3.0.200
- PHP >= 8.0
- AgentTools (optional) — only needed for the per-run counters; the file mirror works
  without it
- LazyCron core module (optional) — only if you want ingestion triggered by page views

## Installation

Copy the module directory into your site's `site/modules/` as **`AtCronLog`**:

```sh
cd /path/to/processwire/site/modules
git clone https://github.com/webmanufaktur/AtCronLog.git AtCronLog
```

Then in the admin: **Modules → Refresh** → find *AgentTools Cron Log → PW Logs* →
**Install**.

Installing creates `site/assets/AtCronLog/` for the ingest state and an `at-cron` log
name (the file appears with its first entry).

## Configuration

*Modules → AgentTools Cron Log → PW Logs*:

| Setting | Default | Description |
| --- | --- | --- |
| Log file to mirror | `site/assets/logs/at-cron.log` | Path relative to the ProcessWire root. Paths outside the installation are rejected. |
| ProcessWire log name | `at-cron` | Name shown under *Setup → Logs*; the file is `site/assets/logs/at-cron.txt`. |
| Maximum bytes per ingest | `65536` | Upper bound for one ingest call. |
| Idle heartbeat interval | `60` | Log idle cron runs at most once per this many minutes. `0` = never. |
| Record what cron runs did | on | One line per run that processed/failed a job. |
| Ingest when Setup → Logs is opened | on | Picks up lines written since the last ingestion before the log renders. |
| Ingest every minute via LazyCron | off | Only useful if nothing else triggers ingestion. |

The same screen shows whether the configured file exists, how large it is and when it
last changed — the quickest way to tell "log is empty" apart from "log is missing".

## Cron setup

If you run AgentTools' background jobs, the cron entry looks like this (system crontab;
on panels such as Hostinger's hPanel use **absolute paths** for script *and* log — a
relative log path silently kills the job):

```
* * * * * cd /path/to/site && php index.php --at-cron >> /path/to/site/site/assets/logs/at-cron.log 2>&1
```

You can keep writing to `at-cron.log`: the module reads it and republishes every line it
finds in the ProcessWire log. Renaming the target to `.txt` is *not* a substitute — see
the two ProcessWire filters above; raw lines would be dropped by `isValidLine()`.

## How it works

- `ingest()` opens the configured file, seeks to the stored offset
  (`site/assets/AtCronLog/state.json`), reads at most *max bytes*, writes every complete
  line through `$log->save('at-cron', $line)`, and stores the new offset. If the file
  shrank, reading restarts at 0.
- On CLI runs whose arguments contain `--at-cron` the module hooks
  `ProcessWire::finished`, counts the job files in AgentTools' queue directories, and
  compares them with the previous run. New `done`/`failed` files become
  `processed`/`failed`; `pending` is reported as the current queue length.
  `AgentToolsJobs::runCron()` itself is not hookable (no `___runCron`), which is why the
  detection goes through the CLI arguments.
- `ProcessLogger::execute` (and optionally `LazyCron::everyMinute`) trigger ingestion, so
  the viewer is current whenever you open it.

## Troubleshooting

**The log exists under Setup → Logs but stays empty.**
Check the source file: if it is 0 bytes while the cron runs, the host discards the
process output (common on shared hosting wrappers). Verify liveness elsewhere
(AgentTools heartbeat: `site/assets/at/jobs/cron-last-run.txt`), or enable the idle
heartbeat interval so the module itself records runs.

**Nothing at all appears in Setup → Logs until a job runs.**
That is the default: only runs that processed or failed a job are recorded, plus one
idle line per hour. Set the idle interval to `1` while debugging.

**Duplicate entries after restoring a backup or clearing caches.**
The offset lives in `site/assets/AtCronLog/state.json`, not in the cache. If it is
missing, the next ingest starts at byte 0 and re-imports the file once. Delete the state
file deliberately when you want that.

**The ProcessWire log grows.**
ProcessWire logs are plain text; trim them with the tools on the *Setup → Logs* screen
(or empty `site/assets/logs/at-cron.txt`). The offset state stays valid because the
module detects the truncation itself.

## License

MIT — see [LICENSE](LICENSE).
