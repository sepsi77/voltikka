"""Model files and the frozen archive: the private archive bucket (production) or a local folder.

Settings (environment of the voltikka-worker service only; never printed):
  FORECAST_BUCKET, FORECAST_BUCKET_ENDPOINT, FORECAST_BUCKET_REGION,
  FORECAST_BUCKET_ACCESS_KEY_ID, FORECAST_BUCKET_SECRET_ACCESS_KEY
or FORECAST_STORE_DIR (a local folder; tests and local runs). The container has no volume, so
files are cached only in the temp directory (FORECAST_CACHE_DIR, default <tmp>/voltikka-forecast).

A fitted model is a pickle. It is loaded only after its SHA-256 equals the database row and its
scikit-learn version equals the installed one (a pickle runs code when it is loaded).
"""
import hashlib
import io
import os
import pickle
import tempfile
from pathlib import Path

import sklearn

from . import data as D


class ArtifactMismatch(RuntimeError):
    """A model file does not match its database row (hash, scikit-learn or code version)."""


def _cache_dir():
    path = Path(os.environ.get("FORECAST_CACHE_DIR", Path(tempfile.gettempdir()) / "voltikka-forecast"))
    path.mkdir(parents=True, exist_ok=True)
    return path


class Store:
    def __init__(self):
        self.local = os.environ.get("FORECAST_STORE_DIR")
        self.client = None
        if not self.local:
            import boto3

            self.bucket = os.environ["FORECAST_BUCKET"]
            self.client = boto3.client("s3", endpoint_url=os.environ["FORECAST_BUCKET_ENDPOINT"],
                                       region_name=os.environ.get("FORECAST_BUCKET_REGION", "auto"),
                                       aws_access_key_id=os.environ["FORECAST_BUCKET_ACCESS_KEY_ID"],
                                       aws_secret_access_key=os.environ["FORECAST_BUCKET_SECRET_ACCESS_KEY"])

    def put(self, key, payload):
        if self.local:
            path = Path(self.local) / key
            path.parent.mkdir(parents=True, exist_ok=True)
            tmp = path.with_suffix(path.suffix + ".part")
            tmp.write_bytes(payload)
            tmp.replace(path)
        else:
            self.client.put_object(Bucket=self.bucket, Key=key, Body=payload)

    def get(self, key, sha256):
        """The bytes of key, checked against sha256 (from the cache when it is there)."""
        cached = _cache_dir() / hashlib.sha256(key.encode()).hexdigest()
        if cached.exists() and hashlib.sha256(cached.read_bytes()).hexdigest() == sha256:
            return cached.read_bytes()
        if self.local:
            payload = (Path(self.local) / key).read_bytes()
        else:
            payload = self.client.get_object(Bucket=self.bucket, Key=key)["Body"].read()
        if hashlib.sha256(payload).hexdigest() != sha256:
            raise ArtifactMismatch(f"{key}: SHA-256 differs from the database row")
        tmp = cached.with_suffix(".part")
        tmp.write_bytes(payload)
        tmp.replace(cached)
        return payload

    def archive_dir(self, prefix="archive/s"):
        """The frozen S archive as a local folder (downloaded into the cache, pins checked by
        data.read_archive)."""
        if self.local:
            return Path(self.local) / prefix
        target = _cache_dir() / prefix
        target.mkdir(parents=True, exist_ok=True)
        for name in ("pins.json", *D.ARCHIVE_FILES):
            self.client.download_file(self.bucket, f"{prefix}/{name}", str(target / name))
        return target


def dump_model(model):
    buf = io.BytesIO()
    pickle.dump(model, buf, protocol=5)
    payload = buf.getvalue()
    return payload, hashlib.sha256(payload).hexdigest()


def load_model(store, row, code_sha256):
    if row["sklearn_version"] != sklearn.__version__:
        raise ArtifactMismatch(f"model {row['version']} needs scikit-learn {row['sklearn_version']}, "
                               f"installed {sklearn.__version__}")
    model = pickle.loads(store.get(row["object_key"], row["sha256"]))
    if model.get("code_sha256") != row["code_sha256"]:
        raise ArtifactMismatch(f"model {row['version']}: code hash differs from its row")
    if model["code_sha256"] != code_sha256:
        # A code change is a new model version (s-model-freeze.md section 5): refit first.
        raise ArtifactMismatch(f"model {row['version']} was fitted with other model code")
    return model
