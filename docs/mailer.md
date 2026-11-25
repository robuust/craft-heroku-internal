# SMTP mailer

Set `MAILER_DSN` to an SMTP DSN for Mailpit, Mailtrap, or another SMTP service.
The module passes it to Craft's SMTP transport whenever it is set, in any
environment. It is required when Craft's environment is `dev`; otherwise, leaving
it unset preserves Craft's configured mailer.

For example, with Mailpit available at the hostname `mailpit`:

```dotenv
MAILER_DSN=smtp://mailpit:1025
```

## Migrating from MAILTRAP_DSN

Before upgrading to a version containing this change, rename `MAILTRAP_DSN` to
`MAILER_DSN` wherever the application configures it, including local `.env` files,
environment examples, Docker Compose configuration, CI, and Heroku config vars.
Keep the existing DSN value. `MAILTRAP_DSN` is no longer read and has no fallback:
development throws when only the old variable is set, and other environments no
longer apply its SMTP override.
