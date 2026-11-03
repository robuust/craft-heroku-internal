# Turbo form responses

Turbo Drive only follows a form submission response that redirects with
status 303, or that renders the page again with a 4xx or 5xx status. The module
adjusts Craft responses for requests with the `X-Turbo-Request-Id` header, so
templates don't have to set status codes themselves.

A `POST` that redirects gets status 303 instead of 302.

When the Craft Contact Form plugin is installed, a failed submission that renders
the template again with the `submission` variable gets status 422. JSON requests
keep the plugin's 400 response, successful submissions keep their redirect, and
requests without Turbo are unchanged.

See [Redirecting After a Form Submission](https://turbo.hotwired.dev/handbook/drive#redirecting-after-a-form-submission).
