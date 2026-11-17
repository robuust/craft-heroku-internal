# Queue lock reproduction

This regression test uses the real Craft queue retry/reserve methods and Yii's
PostgreSQL advisory mutex. It compares the 2.10.3 module at
`d2a1e030ce0a2309c9756634ff86125a9de017c8` with the current `src/Module.php`.
Each concurrency case runs two PHP processes with distinct PostgreSQL backend
PIDs. The formation GET is mocked to take six seconds, exceeding the unchanged
five-second queue mutex wait. GET/PATCH never use an HTTP transport.

Prerequisites: PHP with `pdo_pgsql`, Python 3, and PostgreSQL server binaries
(`pg_config`, `initdb`, `pg_ctl`). Run as an ordinary user, not root. A private
cluster is created below `/tmp`, listens only on its own Unix socket, and is
stopped and deleted in a `finally` block. Existing PostgreSQL instances, project
configuration, `.env` files, and credentials are not used. Fixtures are synthetic;
no job is deserialized or executed.

To reproduce against the incident's Craft/Yii versions without changing this
repository's Composer files:

```sh
deps_dir="$(mktemp -d /tmp/queue-lock-deps.XXXXXX)"
cat > "$deps_dir/composer.json" <<'JSON'
{
  "require": {
    "craftcms/cms": "5.12.1",
    "yiisoft/yii2": "2.0.55",
    "yiisoft/yii2-queue": "2.3.9",
    "php-heroku-client/php-heroku-client": "4.0.4"
  },
  "config": { "allow-plugins": false }
}
JSON
composer install --working-dir="$deps_dir" --no-plugins --no-scripts
python3 tests/queue-lock/run.py \
  --autoload "$deps_dir/vendor/autoload.php" \
  --report "$deps_dir/queue-lock-report.json"
```

The runner checks both `retry()` and `retryAll()`:

- Before the fix, the mock observes the queue lock, and concurrent `reserve()`
  throws `craft\errors\MutexException` after at least five seconds.
- With the fix, retry returns before autoscaling, the mock observes no queue lock,
  and concurrent `reserve()` succeeds while the mock is still sleeping.
- Normal delayed pushes still scale immediately. Worker-loop and
  after-execution/release boundaries flush pending retry pushes, repeated
  boundaries do not repeat reconciliation, reserved jobs retain workers, and an
  empty queue scales down.

This is an isolated reproduction of a possible failure mechanism, not evidence
that a production retry caused a particular incident. Redis delivery is replaced
by Yii's in-memory sync queue (execution disabled), and application lifecycle
boundaries are emitted explicitly. No Heroku gateway, dynos, full Craft bootstrap,
web request, or production load is exercised. There is no repository CI test or
coverage threshold configured for this suite.
