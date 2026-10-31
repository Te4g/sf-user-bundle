# Registration bot protection

New generated applications include Cloudflare Turnstile verification and two
independent registration limits: five attempts per client IP per fifteen minutes,
and five per IP/email pair. Changing the submitted address does not reset the IP
allowance. Verification occurs after normal form/CSRF validation and before user
creation or any duplicate-account verification email.

## Configure production

Create a **Managed** Turnstile widget in the Cloudflare dashboard and allow your
application's hostname. Existing DNS and hosting can remain with their providers.
Configure these values privately in the deployed application:

```dotenv
TURNSTILE_SITE_KEY=your-public-site-key
TURNSTILE_SECRET_KEY=your-secret-key
TURNSTILE_HOSTNAME=app.example.com
```

The hostname is the exact lowercase public hostname, without a scheme or path.
It is checked against the provider's verification result, independently of request
headers. The expected widget action is `register`.

Turnstile is enabled by default in production. Missing or incomplete configuration
rejects registration without sending mail or creating accounts. Existing login,
password reset and email verification keep their normal behavior. Cloudflare's
published dummy keys are rejected in production. Do not put the secret key in
HTML, Git, logs, or chat; only the public site key is rendered in the widget.

Development and test environments default to disabled for offline use. Set
`TURNSTILE_ENABLED=1` to exercise the widget there; Cloudflare's documented dummy
keys are available for local browser testing. `TURNSTILE_ENABLED=0` explicitly
disables the challenge, including in production, so use it only as a deliberate
operational decision. The independent IP limit remains active.

The server checks every token through Siteverify with short timeouts and no
redirects. Cloudflare enforces token expiration and single use. Rejected tokens,
wrong action/hostname, malformed responses and provider/network errors all fail
closed. The dedicated HTTP client is not traced by Symfony's HTTP profiler.
Do not log registration form bodies or Siteverify payloads. If you enforce a
Content Security Policy, allow Cloudflare's documented script/frame origins.

The Stimulus controller loads Turnstile only on registration pages, removes
widgets before Turbo caches the page, clears expired responses and renders a new
widget when the form returns with errors. JavaScript is required for protected
registration; blocked scripts display a retry message and cannot bypass the
server-side check.

## Updating an existing generated application

The starter remains a fresh-project installer. **Do not rerun `install` on an
existing application.** Port the following resources through a normal code review:

- `src/Account/RegistrationChallenge.php` and `config/packages/account_bot_challenge.yaml`;
- the controller gate and unmapped `botChallenge` field in `RegistrationType`;
- the widget markup, `turnstile_controller.js`, its registration in `assets/app.js`, and translations;
- the independent `account_registration_ip` factory and its test cache-pool configuration;
- the generated unit and functional bot-protection tests.

Add `symfony/http-client:^8.1` as an application runtime dependency. Preserve the
application's existing user identifiers, account customizations, test database
configuration and other registration validation. Tests explicitly inject a
configured verifier with a mock HTTP client; no production verification bypass is
introduced. Configure production keys before deploying if signup must remain
available throughout the rollout.

References: [Cloudflare setup](https://developers.cloudflare.com/turnstile/get-started/),
[server validation](https://developers.cloudflare.com/turnstile/get-started/server-side-validation/),
[test keys](https://developers.cloudflare.com/turnstile/troubleshooting/testing/).
