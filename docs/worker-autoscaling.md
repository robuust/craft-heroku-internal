# Heroku worker autoscaling

Autoscaling requires `HEROKU_APP_NAME`, worker client credentials, and Craft's
`runQueueAutomatically` setting disabled. Configure `HEROKU_CONTROL_URL` and
`HEROKU_CONTROL_TOKEN` together to use Heroku Control. The URL must use HTTPS
without user information, query, or fragment; the token must be a gateway
credential. Incomplete gateway configuration fails instead of using the legacy
credential. With neither gateway variable set, `HEROKU_API_KEY` retains the
existing direct Heroku behavior. A queue push can only
increase the worker count, using one worker per 100 jobs, up to 10 workers.
After execution and release, workers are stopped only when the queue is empty.

Direct Heroku requests have a 2-second connection timeout and a 5-second total
timeout. Gateway requests allow 35 seconds total and do not follow redirects.
An attempt may need a formation GET followed by a PATCH; account for both
requests in the caller's execution budget. Requests are never retried inline.

Use a shared persistent cache and mutex backend across application dynos.
The default filesystem cache does not share cooldowns between dynos. Formation
quantities are cached for one hour, with state scoped by Heroku app name.
A non-blocking mutex prevents simultaneous scaling calls. A failed attempt
invalidates the cached quantity because a timed-out PATCH may already have
changed the formation.

Failures defer subsequent attempts for 30, 60, 120, 240, 480 and then 900 seconds,
plus up to 5 seconds of jitter. Other HTTP 4xx responses use a 900-second cooldown.
A valid `Retry-After`, in seconds or HTTP-date format, can extend the cooldown.
HTTP 429 is logged as rate limiting; transport errors are logged separately.
Diagnostics include the status, exception class and available `Request-Id`,
`RateLimit-Remaining` and `Retry-After` headers, without authenticated requests
or raw exception messages.

Retries depend exclusively on subsequent regular queue events. There is no
worker-loop recovery, scheduled task or background retry process. Cooldown expiry
alone does not trigger a request. A failed attempt, or one skipped while another
request holds the mutex, can remain waiting until a later applicable event.
This also applies to failed startup with no active workers and failed shutdown
with no further jobs. Operators can intervene when necessary.

The existing `dyno-count-cache/clear` action clears the cached formation without
bypassing an active cooldown or making a Heroku request. Deploying this module
does not configure the shared cache or mutex. Clubee's Laravel autoscaler is a
separate package and is unaffected by this release.

When switching worker client credentials or route, clear the formation cache and
any stale backoff before the first controlled request. Queue events use the
client factory for both direct and gateway requests.
