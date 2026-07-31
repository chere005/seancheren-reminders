# Scheduled tasks (cron) and daemons on NFS.NET

## Scheduled tasks (cron jobs)

- Create them in the member panel: **Site Information → Actions box → "Manage
  Scheduled Tasks."** There is no crontab file you edit over SSH; it's panel-
  managed.
- **Minimum frequency is once every 10 minutes.** Available on all server types,
  including static sites.
- Each task can run **as the site owner** or **as the `web` user** — choose
  `web` when the job reads or writes files that PHP (the web server) created, or
  you'll hit the ownership wall described in the main skill.
- **Output handling:** stdout+stderr are emailed to you when there is any. If
  the mail bounces/rejects or the output is very large, it's written to a file
  in **`/home/logs`** instead. A task that prints nothing sends no mail — so
  "no email" means *either* success *or* a job that simply had no output; don't
  read silence as proof it worked. Log a heartbeat if you need certainty.
- The command runs in a normal shell on the FreeBSD host. Use **absolute paths**
  (`/usr/local/bin/php /home/protected/tools/job.php`) — don't assume your login
  shell's `$PATH` or working directory.
- A task can also just hit a URL on your own site (`curl -s https://…`) — handy
  when the work must run **as `web`** and lives behind PHP, mirroring the
  HTTP-triggered pattern this repo uses for seeding.

## Daemons (persistent processes)

- For work that must run more often than every 10 minutes, or must stay
  resident (a queue worker, a websocket/long-poll backend, a non-PHP server),
  use a **daemon** instead of cron.
- **Daemon and Proxy management only appear once the site's server type is set to
  "Custom" or "Apache 2.4 Generic"** (Site Information Panel). Plain PHP sites
  don't show these options until you switch.
- A daemon is an **always-on process → continuous RAM cost** under the pay-as-
  you-go model. Don't add one for something a 10-minute cron or an on-request
  PHP handler can do. For this site's needs (a small self-posting PHP suite),
  neither a daemon nor cron is currently required.

## Rule of thumb

Static/periodic upkeep → scheduled task. Something that must listen or react
continuously → daemon (and accept the RAM bill). Anything that only needs to run
when a user acts → just do it in the request, no scheduler at all.
