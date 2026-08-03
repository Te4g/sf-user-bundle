symfonycasts_reset_password:
    request_password_repository: __APP_NAMESPACE__\Repository\ResetPasswordRequestRepository
    lifetime: 3600
    throttle_limit: 3600
    enable_garbage_collection: true

services:
    SymfonyCasts\Bundle\ResetPassword\Persistence\ResetPasswordRequestRepositoryInterface:
        alias: __APP_NAMESPACE__\Repository\ResetPasswordRequestRepository
