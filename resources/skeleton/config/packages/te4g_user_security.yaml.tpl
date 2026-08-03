security:
    expose_security_errors: none
    password_hashers:
        Te4g\UserBundle\Contract\AccountUserInterface: 'auto'
    providers:
        te4g_user_provider:
            id: Te4g\UserBundle\Security\AccountUserProvider
    firewalls:
        main:
            lazy: true
            provider: te4g_user_provider
            user_checker: Te4g\UserBundle\Security\VerifiedAccountUserChecker
            form_login:
                login_path: te4g_user_login
                check_path: te4g_user_login
                enable_csrf: true
                default_target_path: '%te4g_user.redirects.login_success%'
            login_throttling:
                max_attempts: 5
                interval: '15 minutes'
            login_link:
                check_route: te4g_user_login_link_check
                signature_properties: [id, email, password]
                lifetime: 600
                max_uses: 1
                check_post_only: true
                used_link_cache: cache.te4g_user_login_links
                default_target_path: '%te4g_user.redirects.login_success%'
            logout:
                path: te4g_user_logout
                enable_csrf: true
    access_control:
        - { route: te4g_user_register, roles: PUBLIC_ACCESS }
        - { route: te4g_user_verify_email, roles: PUBLIC_ACCESS }
        - { route: te4g_user_verify_resend, roles: PUBLIC_ACCESS }
        - { route: te4g_user_login, roles: PUBLIC_ACCESS }
        - { route: te4g_user_login_link_request, roles: PUBLIC_ACCESS }
        - { route: te4g_user_logout, roles: PUBLIC_ACCESS }
        - { route: te4g_user_login_link_check, roles: PUBLIC_ACCESS }
        - { route: te4g_user_password_request, roles: PUBLIC_ACCESS }
        - { route: te4g_user_password_reset, roles: PUBLIC_ACCESS }
        - { route: te4g_user_check_email, roles: PUBLIC_ACCESS }
        - { route: te4g_user_account, roles: IS_AUTHENTICATED_FULLY }
        - { route: te4g_user_profile, roles: IS_AUTHENTICATED_FULLY }
        - { route: te4g_user_email_change_confirm, roles: IS_AUTHENTICATED_FULLY }
        - { route: te4g_user_password_change, roles: IS_AUTHENTICATED_FULLY }
        - { route: te4g_user_account_delete, roles: IS_AUTHENTICATED_FULLY }

framework:
    cache:
        pools:
            cache.te4g_user_login_links: ~
    rate_limiter:
        te4g_user_account_requests:
            policy: sliding_window
            limit: 5
            interval: '15 minutes'
