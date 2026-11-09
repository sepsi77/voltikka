from voltikka_orchestration.settings import Settings


def test_sentry_environment_wins_over_app_env(monkeypatch):
    monkeypatch.setenv("APP_ENV", "production")
    monkeypatch.setenv("SENTRY_ENVIRONMENT", "vps-test")
    assert Settings.from_env().environment == "vps-test"


def test_app_env_is_the_fallback(monkeypatch):
    monkeypatch.setenv("APP_ENV", "production")
    monkeypatch.delenv("SENTRY_ENVIRONMENT", raising=False)
    assert Settings.from_env().environment == "production"
