# Heroku worker autoscaling

Worker autoscaling is enabled when `HEROKU_APP_NAME` and `HEROKU_API_KEY` are
configured and Craft's `runQueueAutomatically` setting is disabled.

Heroku requests have a 2-second connection timeout and a 5-second total timeout.
A reconciliation can perform one GET followed by one PATCH, so it can spend up
to 10 seconds on HTTP requests. Requests are never retried inline.

## Cache and recovery

Configure Craft's cache component with a backend shared by all application
dynos. The default filesystem cache does not share cooldowns between dynos.
Formation quantities are cached for one hour. The application's mutex prevents
simultaneous scaling requests; use a mutex backend shared between dynos too.

Failures defer new attempts for 30, 60, 120, 240, 480 and then 900 seconds, plus
up to 5 seconds of jitter. Other HTTP 4xx responses use a 900-second cooldown.
An HTTP 429 is reported as rate limiting. A valid `Retry-After` header can extend
the cooldown, using either seconds or an HTTP date. Transport errors are logged
separately from HTTP errors, including any available `RateLimit-Remaining` and
`Request-Id` response headers.

Later queue events retry after the cooldown. An already-running worker also
checks for a due retry in its normal queue loop, so a failed shutdown can recover
while the queue is idle. This does not launch a scheduler or a one-off recovery
dyno. Loop checks do not call Heroku unless a failed attempt is due.

If starting workers fails while all workers are off, jobs wait for the next
queue push after the cooldown. When no new jobs are being pushed, recover
manually with the following command, replacing `<module-id>` with the registered
Craft module ID. Run it only when needed; do not schedule it:

```sh
php craft <module-id>/dyno-count-cache/reconcile
```

The manual command computes its target from the current queue and honours the
cooldown. A failed PATCH invalidates the cached quantity because Heroku may have
applied the change before the client timed out. The next attempt reads the
formation before deciding whether another PATCH is needed.

The `dyno-count-cache/clear` action clears the cached formation without bypassing
an active cooldown. Deploying this module does not configure a shared cache or
mutex. No scheduled recovery dynos are required.

These changes apply to the Craft module. Clubee's Laravel autoscaler is a separate
package and is not changed by this release.
