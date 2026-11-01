# AGENTS.md — AtCronLog development guide

For humans and coding agents working *on* this module. Using it? Read the
[README](README.md) instead. Everything here is context that would otherwise have to be
re-derived from ProcessWire internals or from a production incident.

## What this is

A single-file ProcessWire 3.x module (`AtCronLog.module.php`, ~500 lines, PHP >= 8.0)
that mirrors a log file into a ProcessWire log and records AgentTools cron runs. No build
step, no dependencies outside ProcessWire core, no composer packages. It runs on a live
production site — treat `master` as stable.

The module exists because of two hard-coded ProcessWire behaviours and one hosting quirk:

1. `WireLog::getFiles()` (wire/core/Log/WireLog.php) filters the logs directory by
   `$logExtension = 'txt'` — a `*.log` file is invisible in *Setup → Logs*.
2. `FileLog::isValidLine()` (wire/core/Log/FileLog.php) rejects every line that does not
   start with `YYYY-MM-DD HH:MM:SS` followed by the tab delimiter — so a *renamed*
   `*.txt` written by a shell redirect still renders as an empty log.
3. Some hosts (Hostinger shared hosting) run cron commands in a wrapper that discards
   stdout: the redirect target is created but stays 0 bytes while the job runs fine.

Both PW behaviours were verified against 3.0.275, including an experimental
`site/assets/logs/rawtest.txt` with one raw cron line: `getTotalEntries()` = 1,
`getEntries()` = 0.

## Repo layout

```
AtCronLog.module.php   the module (single file, that's deliberate)
README.md              user-facing docs
AGENTS.md              this file
LICENSE                MIT
```

## Conventions

- ProcessWire coding style, tabs for indentation in the module file (PW core style),
  `namespace ProcessWire`, PHPDoc on every public method.
- One module file on purpose: a module of this size is easiest to install, copy and
  review as a single file. Do not split into classes below ~1,000 lines.
- No test suite in-repo. The manual recipe below plus `php -l` is the verification gate.
- Versioning: bump `getModuleInfo()['version']` on every behavioural change, tag
  `vX.Y.Z`, and put the change list into the GitHub release notes.
- i18n: all user-visible strings go through `$this->_()` / `__()`.

## Architecture invariants — do not break these

1. **Idempotent shutdown hook.** `ProcessWire::finished` fires *twice* on a typical
   ProcessWire CLI boot (AgentTools and other modules trigger another finish). The
   `$cronFinishedHandled` flag exists for exactly that reason; without it every run was
   logged twice.
2. **Never duplicate a mirrored line.** The read offset lives in
   `site/assets/AtCronLog/state.json` (module-owned, *not* the cache — cache clears must
   not cause re-imports). Only complete lines are consumed; the trailing partial line
   stays unread until it is finished.
3. **Detect the run, don't hook it.** `AgentToolsJobs::runCron()` has no `___runCron`
   prefix and is therefore not hookable. The run is recognised from `--at-cron` in
   `$_SERVER['argv']` and evaluated in `ProcessWire::finished`. Do not "fix" this by
   hooking the jobs object.
4. **Counters are a delta, not a total.** `done`/`failed` counts are compared with the
   previous run; a shrinking directory (manual cleanup) must clamp to 0, never produce a
   negative number.
5. **No double reporting.** When the mirrored file delivered new lines in the same run,
   the counter line is skipped — otherwise hosts with a working redirect get two entries
   per run.
6. **Paths stay inside the installation.** `getLogFilePath()` resolves the directory and
   rejects anything outside the PW root; only basename is used from the config value.
7. **AgentTools is optional.** `hookCronFinished` is registered only when the module is
   installed and only in CLI context; `getJobCounters()` returns `[]` otherwise. The file
   mirror must keep working on sites without AgentTools.
8. **Low noise by default.** Idle runs log at most once per `captureInterval` minutes
   (default 60). Do not turn that into "one line per run" — it is 1,440 lines per day.

## Manual test recipe

Needs a ProcessWire site with a cron log file and, for the counter part, AgentTools.

```sh
# 1. syntax
php -l site/modules/AtCronLog/AtCronLog.module.php

# 2. install (AgentTools CLI; adapt for a plain site)
php index.php --at-stdin <<'PHP'
$m = $modules; $m->refresh(); $m->isInstalled('AtCronLog') || $m->install('AtCronLog');
PHP

# 3. mirroring: append two complete lines + one partial, then ingest
printf 'line one\nline two\npartial' >> site/assets/logs/at-cron.log
php index.php --at-stdin <<'PHP'
echo $modules->get('AtCronLog')->ingest(), "\n";   // expect 2
PHP
cat site/assets/logs/at-cron.txt                   // both lines, timestamped

# 4. no duplicates: run again -> 0
# 5. truncation: : > file, append one line, ingest -> 1
# 6. counters: queue a job with an unknown type, run --at-cron
php index.php --at-cron                            // then check at-cron.txt
```

Expected log lines (module-generated):

```
2026-11-01 23:02:40	cron run: processed=0 failed=0 pending=0
2026-11-01 23:59:59	cron run: processed=0 failed=1 pending=0
```

## Known environment notes

- **Hostinger shared hosting:** no `crontab` binary; cron entries are created in hPanel
  and need absolute paths for both the script and the log target. A relative log path
  makes the shell fail before PHP starts, silently.
- Prod cron runs in UTC; the module logs with ProcessWire's configured timezone.
- After changing the module on a site, `$modules->refresh()` is required before
  `getModuleInfo()` reports the new version (ProcessWire caches module info per request).

## Relationship to the site

This module is also installed in the ProcessWire site it was written for
(`www/processwire.pw`, `site/modules/AtCronLog/`). That copy and this repository are
kept in sync by hand: change here first, bump the version, then copy the file into the
site and commit there. The site's `AGENTS.md` (§ Modules) points back to this repo.
