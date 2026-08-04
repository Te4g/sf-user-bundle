# Security operations

## Enumeration resistance

Registration, verification resend, magic-link request, and password-reset request use neutral known/unknown-account responses. Preserve that behavior when customizing templates or controllers. Invalid submitted forms return HTTP 422, while successful neutral outcomes do not reveal whether an email exists.

The generated security configuration exposes no detailed authentication errors. Login is limited to five attempts per fifteen minutes. Account request flows use a separate sliding-window limiter with the same limit, keyed by a composite of client IP and a hash of normalized email; the raw email is not used as the limiter key. Reset-password requests also use the upstream one-hour throttle.

## Signed-link lifetimes

| Link | Lifetime | Additional controls |
| --- | --- | --- |
| email verification | 3,600 seconds | signed link |
| password reset | 3,600 seconds | persisted reset request; one-hour request throttle |
| magic login | 600 seconds | ID/email/password signature properties, one use, used-link cache, POST-only completion |
| pending email change | signed workflow | current-password confirmation before request |

Magic links make the email inbox an authentication factor. They are a convenience, not a substitute for step-up authentication or MFA in a security-critical application.

## Password, email, session, and deletion behavior

- Password change and password reset replace the password hash. The generated `User` serializes only a `crc32c` fingerprint of that hash into the session, so a refreshed session is invalidated after a password change without storing the raw hash in session data.
- Email changes require the current password and use a pending-email signed-link flow. Password or email changes remove outstanding reset tokens.
- Account deletion requires the current password, runs every application deletion guard, clears the security token, and invalidates the session after removal.
- Login and logout use CSRF protection. Logout is POST-only.

Application listeners must preserve these boundaries. In particular, do not send irreversible side effects from a pre-deletion listener unless they are designed for transaction failure and retry behavior.

## Log redaction

Signed URLs contain credentials. Configure application logs, reverse-proxy access logs, CDN/WAF logs, and APM capture so they:

- never record query strings for email verification, magic-login, or email-change confirmation URLs;
- redact the `{token}` path segment on `/reset-password/{token}`;
- exclude authentication and account form bodies, including passwords, reset tokens, and CSRF tokens;
- avoid logging complete lifecycle event objects or user objects.

Prefer generic message types, exception class names, and stable account IDs. Treat email addresses as personal data and log them only under an explicit retention/access policy.

## Generated UI provenance

The application UI snapshot is intentionally vendored and hash-checked. Its provenance is recorded in `resources/shadcn/manifest.json`:

- Symfony UX Toolkit 3.3.0, reference `f01228fe7d0e3bd26b1c27eb34b0cc1012a9b208`;
- Lucide icon package `@iconify-json/lucide` 1.2.121, exactly seven regular SVG files;
- license/notices and a SHA-256 for every synchronized snapshot file.

Tailwind CSS builds are pinned to standalone version 4.3.3 and platform checksums in `tests/Fixtures/App/PinnedTailwindBinary.php`. Tests never download a binary; `TE4G_TAILWIND_BINARY` must name a pre-provisioned, validated executable.

To update the snapshot, use a disposable development application and run the required recipes sequentially, for example:

```bash
php bin/console ux:install alert --kit shadcn --no-interaction
php bin/console ux:install alert-dialog --kit shadcn --no-interaction
# Continue with each recipe recorded in resources/shadcn/manifest.json.
```

Then run the repository's `tools/ShadcnSnapshotSynchronizer.php` workflow, review every copied file, manifest hash, package reference, icon, and notice, and run `make test` with the pinned Tailwind binary. Never regenerate the production snapshot implicitly during install or test.
