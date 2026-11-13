# Heroku worker autoscaling

Autoscaling requires `HEROKU_APP_NAME`, worker client credentials, and Craft's
`runQueueAutomatically` setting disabled. Configure `HEROKU_CONTROL_URL` and
`HEROKU_CONTROL_TOKEN` together to use Heroku Control. The URL must use HTTPS
without user information, query, or fragment; the token must be a gateway
credential. Incomplete gateway configuration fails instead of using the legacy
credential. With neither gateway variable set, `HEROKU_API_KEY` retains the
existing direct Heroku behavior. A queue push can only
increase the worker count, using one worker per 100 waiting or delayed jobs, up to
10 workers.
Basic and Eco workers are limited to one worker per process type, based on the
trusted formation size returned by Heroku. The same limit applies to queue events
and manual reconciliation. Other dyno tiers retain the existing scaling policy.
After execution and release, workers are stopped only when no waiting, delayed or
reserved jobs remain. Failed jobs do not count toward scaling.

Delayed jobs are counted before waiting jobs so a job becoming runnable between
the two reads cannot disappear from both counts.

For Redis queues, the queue driver's worker loop promotes delayed jobs during
`reserve()` after their delay expires; Redis does not promote them independently.
Worker dynos must keep a queue listener running while delayed jobs remain. A
positive formation quantity alone does not guarantee that the listener is alive,
and delay expiry does not emit another queue push event or restart a stopped
listener. This module manages formation quantity, not listener recovery.

All delayed jobs count toward the worker target, regardless of when they
become runnable. Long delays can therefore retain billed worker capacity for the entire
delay, subject to the tier limits above. Scaling to zero before they become due
would require a separate wake-up mechanism, which this module does not provide.

Direct Heroku requests have a 2-second connection timeout and a 5-second total
timeout. Gateway requests allow 35 seconds total and do not follow redirects.
An attempt may need a formation GET followed by a PATCH; account for both
requests in the caller's execution budget. Requests are never retried inline.

Use a shared persistent cache and mutex backend across application dynos.
The default filesystem cache does not share cooldowns between dynos. Formation
quantities and sizes are cached for one hour, with state scoped by Heroku app name.
A quantity cached by an earlier adapter version is refreshed when its size is
missing. Heroku Control must return the trusted formation `size` before deploying
this adapter version; direct Heroku formation responses already provide it.
A non-blocking mutex prevents simultaneous scaling calls. A failed attempt
invalidates the cached quantity and size because a timed-out PATCH may already have
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

The existing `dyno-count-cache/clear` action clears the cached quantity and size without
bypassing an active cooldown or making a Heroku request. Deploying this module
does not configure the shared cache or mutex. Clubee's Laravel autoscaler is a
separate package and is unaffected by this release.

The 2.x line retains its existing manual `dyno-count-cache/reconcile` action. It
uses the same HTTP limits, mutex and cooldown as queue events, and preserves
workers while jobs are reserved and starts workers for delayed jobs as well. It
does not register a scheduled task. A cooldown, busy mutex or API failure returns
a nonzero exit code instead of reporting a successful reconciliation. API failures
are recorded in the application logs.

When switching worker client credentials or route, clear the formation cache and
any stale backoff before the first controlled request. Both queue events and the
manual reconcile action use the same client factory.

When changing a worker's dyno tier, clear its formation cache so the next queue
event reads the new size before choosing a quantity. See [Heroku's scaling
limits](https://devcenter.heroku.com/articles/dyno-scaling-and-process-limits).
