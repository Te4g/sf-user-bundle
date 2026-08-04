# Configuration

The Flex recipe initially copies a deliberately disabled bootstrap:

```yaml
# config/packages/te4g_user.yaml
te4g_user:
    enabled: false
```

`php bin/console te4g:user:install` recognizes only those exact bytes as the Flex bootstrap and replaces it with enabled generated configuration. Similar files, symlinks, or already customized files remain unmanaged conflicts.

The installer applies the same byte-exact rule to the unmodified fake-repository bootstrap installed by the official SymfonyCasts ResetPasswordBundle recipe. It replaces that predecessor with the application repository configuration; an edited reset-password configuration remains a conflict.

## Bundle options

`config/packages/te4g_user.yaml` is the authority for bundle behavior. These are all available values and defaults:

```yaml
te4g_user:
    enabled: true
    user_class: App\Entity\User
    account_repository: App\Repository\UserRepository
    mailer:
        from_email: no-reply@example.com
        from_name: Application
    redirects:
        login_success: app_home
        verified: te4g_user_login
        account_updated: te4g_user_account
        account_deleted: app_home
    templates:
        registration: '@Te4gUser/registration/register.html.twig'
        login: '@Te4gUser/security/login.html.twig'
        login_link_check: '@Te4gUser/security/login_link_check.html.twig'
        check_email: '@Te4gUser/security/check_email.html.twig'
        password_request: '@Te4gUser/password/request.html.twig'
        password_reset: '@Te4gUser/password/reset.html.twig'
        account: '@Te4gUser/account/index.html.twig'
        profile: '@Te4gUser/account/profile.html.twig'
        password_change: '@Te4gUser/account/password.html.twig'
        verification_email: '@Te4gUser/email/verify.html.twig'
        magic_link_email: '@Te4gUser/email/magic_link.html.twig'
        password_reset_email: '@Te4gUser/email/password_reset.html.twig'
        email_change_email: '@Te4gUser/email/email_change.html.twig'
    features:
        account_deletion: true
```

The generated file sets only application-specific values; omitted values use these bundle defaults.

## Generated configuration authorities

| File | Authority |
| --- | --- |
| `config/packages/te4g_user.yaml` | user class/repository, sender, redirects, templates, features |
| `config/packages/te4g_user_security.yaml` | provider, main firewall login/authentication, access control, login-link cache, rate limit |
| `config/packages/reset_password.yaml` | reset request repository, one-hour token lifetime and throttle |
| `config/packages/verify_email.yaml` | one-hour verification-link lifetime |
| `config/packages/te4g_user_twig.yaml` | generated form theme |
| `config/routes/te4g_user.yaml` | bundle route import and optional prefix |
| existing Tailwind bundle config, or generated `config/packages/te4g_user_tailwind.yaml` | Tailwind input and binary settings |
| `config/te4g_user.install.json` | installer-owned path and hash manifest |

The generated entity, repositories, migration, templates, Twig components, controllers, styles, icon SVGs, translations, and importmap changes are application-owned. Customize them in the application; do not edit bundle vendor files.

## Security integration

The generated `main` firewall uses `Te4g\UserBundle\Security\AccountUserProvider`, the verified-account checker, CSRF-protected form login/logout, single-use login links, and five-attempt/fifteen-minute login throttling. If the application already owns incompatible `main` firewall keys, the preflight reports a conflict; merge the policy deliberately rather than bypassing the check.

Route paths may be prefixed without changing route names:

```yaml
# config/routes/te4g_user.yaml
te4g_user:
    resource: '@Te4gUserBundle/config/routes.php'
    prefix: /auth
```
