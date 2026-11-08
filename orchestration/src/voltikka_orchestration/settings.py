"""Worker settings from environment variables. Secrets are read here and never logged."""

from __future__ import annotations

import os
from dataclasses import dataclass

TASK_QUEUE = "voltikka-main"


@dataclass(frozen=True)
class Settings:
    temporal_address: str
    namespace: str
    api_key: str | None
    laravel_dir: str
    php_binary: str
    s_model_module: str | None
    sentry_dsn: str | None
    environment: str
    task_queue: str = TASK_QUEUE

    @classmethod
    def from_env(cls) -> "Settings":
        return cls(
            # Temporal Cloud: the namespace endpoint <namespace>.<account>.tmprl.cloud:7233 with an
            # API key (TEMPORAL_NAMESPACE is the full <namespace>.<account>); local dev server:
            # localhost:7233 without a key.
            temporal_address=os.environ.get("TEMPORAL_ADDRESS", "localhost:7233"),
            namespace=os.environ.get("TEMPORAL_NAMESPACE", "default"),
            api_key=os.environ.get("TEMPORAL_API_KEY") or None,
            laravel_dir=os.environ.get("LARAVEL_DIR", "/app"),
            php_binary=os.environ.get("PHP_BINARY", "php"),
            # Empty: the model activities return a stub result (live test of readiness/deadlines).
            s_model_module=os.environ.get("S_MODEL_MODULE") or None,
            sentry_dsn=os.environ.get("SENTRY_DSN") or os.environ.get("SENTRY_LARAVEL_DSN") or None,
            environment=os.environ.get("APP_ENV", "local"),
            task_queue=os.environ.get("TEMPORAL_TASK_QUEUE", TASK_QUEUE),
        )
