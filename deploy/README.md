# Deploy / ops artifacts

Infrastructure files for the production Droplet (`masjid.hopetechapps.com`).
These are committed for reproducibility — they are NOT auto-applied by the
GitHub Actions deploy (that only pulls code, migrates, rebuilds the SPA).
Apply them manually once per server.

## Queue worker — `masjid-queue.service`

The app uses `QUEUE_CONNECTION=database` and dispatches `SendMasjidNotificationJob`
(push notifications) as a `ShouldQueue` job. **Without a running worker, queued
jobs sit in the `jobs` table forever and notifications never send.** This systemd
unit runs the worker as a daemon: auto-restart on crash (`Restart=always`),
start on boot (`WantedBy=multi-user.target`), recycle hourly (`--max-time=3600`).

### Install (one-time, as root on the Droplet)

```sh
# Copy the unit from the repo checkout into systemd
cp /var/www/html/Masjids_App_Management_System/MasjidsManagementSystem/deploy/masjid-queue.service \
   /etc/systemd/system/masjid-queue.service

systemctl daemon-reload
systemctl enable --now masjid-queue.service
systemctl status masjid-queue.service        # confirm "active (running)"
```

### Verify it processes jobs

```sh
# Dispatch a throwaway job and confirm the queue drains:
cd /var/www/html/Masjids_App_Management_System/MasjidsManagementSystem
php artisan tinker --execute='\App\Models\Notification::query()->exists();'  # any harmless call
# Or watch the worker live:
journalctl -u masjid-queue.service -f
```

### Interaction with deploys

The GitHub Actions deploy already runs `php artisan queue:restart`, which writes
a cache flag the worker checks after each job; the worker then exits and systemd
respawns it with the freshly-pulled code. No manual restart needed on deploy.
(Both php-fpm and the worker run as `www-data` against the same `database` cache
store, so the restart signal propagates.)

### Path note

`WorkingDirectory` / `ExecStart` use the absolute prod path
`/var/www/html/Masjids_App_Management_System/MasjidsManagementSystem`. If the
app is ever relocated, update both lines in the unit and `daemon-reload`.

## Scheduler cron

`routes/console.php` schedules `sanctum:prune-expired --hours=24` daily (keeps
the `personal_access_tokens` table bounded). Laravel's scheduler only runs if
the system cron invokes `schedule:run` every minute. Install once (as root):

```sh
( crontab -l 2>/dev/null; \
  echo "* * * * * cd /var/www/html/Masjids_App_Management_System/MasjidsManagementSystem && /usr/bin/php artisan schedule:run >> /dev/null 2>&1" \
) | crontab -
crontab -l   # confirm the line is present
```

## CORS allowed origins

`config/cors.php` reads `CORS_ALLOWED_ORIGINS` (comma-separated). Default `*` is
acceptable for the anonymous public endpoints (`supports_credentials => false`,
no cookies/credentials cross-origin, public data only) but locked down in prod
for defense-in-depth:

```
CORS_ALLOWED_ORIGINS=https://www.burlingtonmasjid.com,https://burlingtonmasjid.com,https://masjid.hopetechapps.com
```

Only browser origins need listing (native iOS/Android apps don't send an Origin
header). The Nuxt site's client-side fetches (e.g. the splash modal) come from
`www.burlingtonmasjid.com`, so that origin must stay in the list.
