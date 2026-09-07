#!/usr/bin/env python3
"""Protected, literal dotenv handling for the production Compose launcher.

This deliberately does not source shell code or expand environment variables.
Generated values are single quoted, matching Compose's literal dotenv syntax.
"""

import argparse
import base64
import ipaddress
import os
from pathlib import Path
import re
import secrets
import sys
import tempfile
from urllib.parse import urlsplit

IMAGE_KEYS = ("PROVIDENTIA_IMAGE", "PROVIDENTIA_WEB_IMAGE", "PROVIDENTIA_MEDIA_IMAGE")
IMAGE_REPOS = (
    "ghcr.io/providentia-systems/backend",
    "ghcr.io/providentia-systems/backend-web",
    "ghcr.io/providentia-systems/backend-media-worker",
)
KEYS_BASE64 = (
    "NOTIFICATION_PAYLOAD_KEK", "AI_MEDIA_KEK", "CATALOG_IMAGE_KEK",
    "DATA_EXPORT_KEK", "AI_CREDENTIAL_KEK",
)
KEYS_HEX = ("AUTH_TOKEN_PEPPER", "SYNC_CURSOR_SECRET", "PROVIDENTIA_METRICS_TOKEN")


def fail(message):
    raise ValueError(message)


def read_env(path):
    """Read only simple KEY=value or literal KEY='value' lines, never code."""
    values = {}
    for number, raw in enumerate(path.read_text().splitlines(), 1):
        line = raw.strip()
        if not line or line.startswith("#"):
            continue
        match = re.fullmatch(r"([A-Z][A-Z0-9_]*)=(.*)", line)
        if not match:
            fail(f"Invalid dotenv assignment at line {number}; use KEY=value.")
        key, value = match.groups()
        if key in values:
            fail(f"Duplicate dotenv key: {key}.")
        if value.startswith("'"):
            if not value.endswith("'") or len(value) < 2:
                fail(f"Unterminated literal dotenv value: {key}.")
            value = value[1:-1].replace("\\'", "'")
        elif value.startswith('"') or "$" in value or " #" in value:
            fail(f"Use a single-quoted literal dotenv value for {key}; expansion is not supported.")
        if any(char in value for char in ("\n", "\r", "\x00")):
            fail(f"Multiline dotenv values are not supported: {key}.")
        values[key] = value
    return values


def placeholder(value):
    return not value or "CHANGE_" in value or "example.net" in value


def version(value):
    if not re.fullmatch(r"[0-9]+\.[0-9]+\.[0-9]+", value):
        fail("Use an explicit stable release version X.Y.Z; edge and latest are not production locks.")
    return value


def validate_url(value):
    try:
        parsed = urlsplit(value)
        valid = parsed.scheme == "https" and parsed.hostname and parsed.port != 0
    except ValueError:
        valid = False
    if not valid or parsed.username or parsed.password or parsed.query or parsed.fragment or parsed.path not in ("", "/"):
        fail("PUBLIC_BASE_URL must be an HTTPS origin without credentials, a path, query, or fragment.")


def write_env(path, values):
    content = "# Production secrets: keep this file private and back it up with the encrypted data.\n"
    content += "# Values are literal Compose dotenv values; do not source this file as shell code.\n"
    for key, value in values.items():
        if any(char in value for char in ("\n", "\r", "\x00")):
            fail(f"Multiline values are not supported: {key}.")
        escaped = value.replace("'", "\\'")
        content += f"{key}='{escaped}'\n"
    if path.is_symlink():
        fail("The production env file must be a regular file, not a symbolic link.")
    if path.exists() and path.read_text() == content:
        os.chmod(path, 0o600)
        return
    handle, temporary = tempfile.mkstemp(prefix=".production-env-", dir=path.parent)
    try:
        with os.fdopen(handle, "w") as output:
            output.write(content)
            output.flush()
            os.fsync(output.fileno())
        os.chmod(temporary, 0o600)
        os.replace(temporary, path)
    finally:
        if os.path.exists(temporary):
            os.unlink(temporary)


def prepare(args):
    path = Path(args.env_file)
    if path.is_symlink() or (path.exists() and not path.is_file()):
        fail("The production env file must be a regular file, not a symbolic link.")
    new = not path.exists()
    values = read_env(Path(args.example)) if new else read_env(path)
    if new:
        if not args.version and not args.image_env:
            fail("A new deployment requires --version X.Y.Z or --image-env images.env.")
        for key in KEYS_HEX:
            values[key] = secrets.token_hex(32)
        for key in KEYS_BASE64:
            values[key] = base64.b64encode(secrets.token_bytes(32)).decode()
        values["PROVIDENTIA_SETUP_STARTED"] = "0"
        values["PROVIDENTIA_DATABASE"] = args.database or "mysql"
        values["PROVIDENTIA_MANAGED_REDIS"] = "0" if args.queue_dsn else "1"
        for prefix in ("MYSQL", "MARIADB"):
            if values["PROVIDENTIA_DATABASE"] == prefix.lower():
                values[f"{prefix}_PASSWORD"] = secrets.token_hex(24)
                values[f"{prefix}_ROOT_PASSWORD"] = secrets.token_hex(24)
                values["DATABASE_URL"] = (
                    f"mysql://{values[f'{prefix}_USER']}:{values[f'{prefix}_PASSWORD']}"
                    f"@{prefix.lower()}:3306/{values[f'{prefix}_DATABASE']}?charset=utf8mb4"
                )
            else:
                values[f"{prefix}_PASSWORD"] = ""
                values[f"{prefix}_ROOT_PASSWORD"] = ""
        if values["PROVIDENTIA_MANAGED_REDIS"] == "1":
            values["REDIS_PASSWORD"] = secrets.token_hex(24)
            values["QUEUE_DSN"] = f"redis+phpredis://:{values['REDIS_PASSWORD']}@redis:6379"
        else:
            values["REDIS_PASSWORD"] = ""
    elif args.database and args.database != values.get("PROVIDENTIA_DATABASE"):
        fail("Changing SQL engines on an existing deployment requires an explicit data migration and edited configuration.")

    if args.version:
        release = version(args.version.removeprefix("v"))
        values["APP_VERSION"] = release
        for key, default_repo in zip(IMAGE_KEYS, IMAGE_REPOS):
            repository = values.get(key, default_repo).split("@", 1)[0]
            if ":" in repository.rsplit("/", 1)[-1]:
                repository = repository.rsplit(":", 1)[0]
            values[key] = f"{repository}:{release}"
    if args.image_env:
        locked = read_env(Path(args.image_env))
        if set(locked) != {"APP_VERSION", *IMAGE_KEYS}:
            fail("The image lock must contain only APP_VERSION and the three PROVIDENTIA image references.")
        release = version(locked["APP_VERSION"])
        if args.version and release != args.version.removeprefix("v"):
            fail("--version and the image lock APP_VERSION disagree.")
        repositories = []
        for key in IMAGE_KEYS:
            match = re.fullmatch(r"([a-z0-9][a-z0-9._:/-]*)@sha256:[0-9a-f]{64}", locked[key])
            if not match:
                fail(f"The image lock requires a repository and sha256 digest for {key}.")
            repositories.append(match.group(1))
        if repositories[1:] != [repositories[0] + "-web", repositories[0] + "-media-worker"]:
            fail("The image lock must use matching runtime, -web and -media-worker repositories.")
        values.update(locked)

    overrides = {
        "PUBLIC_BASE_URL": args.public_url,
        "MAIL_FROM": args.mail_from,
        "MAIL_DSN": args.mail_dsn,
        "PROVIDENTIA_TRUSTED_PROXY_CIDRS": args.trusted_proxies,
        "DATABASE_URL": args.database_url,
        "QUEUE_DSN": args.queue_dsn,
        "PROVIDENTIA_DATA_DIRECTORY": args.data_directory,
    }
    for key, value in overrides.items():
        if value is not None:
            if not new and key == "PROVIDENTIA_DATA_DIRECTORY" and value != values.get(key, ""):
                fail("Moving an existing deployment's data requires an explicit backup, restore, and edited configuration.")
            values[key] = value
    if args.queue_dsn:
        values["PROVIDENTIA_MANAGED_REDIS"] = "0"
    if values.get("PROVIDENTIA_DATABASE") not in ("mysql", "mariadb", "external"):
        fail("Set PROVIDENTIA_DATABASE to mysql, mariadb, or external.")
    if args.database_url and values["PROVIDENTIA_DATABASE"] != "external":
        fail("--database-url requires --database external on a new deployment.")
    if values.get("PROVIDENTIA_MANAGED_REDIS") not in ("0", "1"):
        fail("Set PROVIDENTIA_MANAGED_REDIS to 0 or 1.")
    profiles = [] if values["PROVIDENTIA_DATABASE"] == "external" else [values["PROVIDENTIA_DATABASE"]]
    if values["PROVIDENTIA_MANAGED_REDIS"] == "1":
        profiles.append("redis")
    values["COMPOSE_PROFILES"] = ",".join(profiles)
    values["PROVIDENTIA_TRUSTED_PROXY_CIDRS"] = values.get("PROVIDENTIA_TRUSTED_PROXY_CIDRS", "").replace(",", " ")
    validate_url(values["PUBLIC_BASE_URL"])
    if placeholder(values.get("CORS_ALLOWED_ORIGINS", "")):
        values["CORS_ALLOWED_ORIGINS"] = values["PUBLIC_BASE_URL"]
    data_directory = values.get("PROVIDENTIA_DATA_DIRECTORY", "")
    if data_directory and (not Path(data_directory).is_absolute() or data_directory == "/" or any(c in data_directory for c in ("\n", "\r", "$", "'", ":"))):
        fail("PROVIDENTIA_DATA_DIRECTORY must be an absolute host directory other than /, without colon or shell expansion.")
    if new and data_directory:
        parent = Path(data_directory)
        if any((parent / child).is_dir() and any((parent / child).iterdir()) for child in ("app-var", "mysql", "mariadb", "redis")):
            fail("Existing data found without the matching env file. Restore its original secrets before proceeding.")
    # Persist newly generated secrets even when the operator still needs to fill
    # SMTP/external credentials. Deployment validates before pulling any image.
    write_env(path, values)


def validate(args):
    values = read_env(Path(args.env_file))
    version(values.get("APP_VERSION", ""))
    for key in ("PUBLIC_BASE_URL", "MAIL_DSN", "MAIL_FROM", "DATABASE_URL", "QUEUE_DSN", "PROVIDENTIA_TRUSTED_PROXY_CIDRS", *KEYS_HEX, *KEYS_BASE64[:-1]):
        if placeholder(values.get(key, "")):
            fail(f"Configure a real, non-placeholder {key} in the protected env file before deployment.")
    validate_url(values["PUBLIC_BASE_URL"])
    mail = urlsplit(values["MAIL_DSN"])
    if mail.scheme != "smtps" or not mail.hostname or not mail.username or not mail.password:
        fail("MAIL_DSN must be authenticated smtps:// with percent-encoded credentials and a real SMTP host.")
    if not re.fullmatch(r"[^\s@<>]+@[^\s@<>]+\.[^\s@<>]+", values["MAIL_FROM"]):
        fail("MAIL_FROM must be a sender email address accepted by your SMTP service.")
    for cidr in values["PROVIDENTIA_TRUSTED_PROXY_CIDRS"].replace(",", " ").split():
        network = ipaddress.ip_network(cidr, strict=False)
        if network.prefixlen == 0:
            fail("Trust only your actual reverse-proxy addresses; an unrestricted CIDR is unsafe.")
    if urlsplit(values["DATABASE_URL"]).scheme not in ("mysql", "pdo-mysql"):
        fail("DATABASE_URL must use the supported mysql:// or pdo-mysql:// driver.")
    if urlsplit(values["QUEUE_DSN"]).scheme not in ("redis+phpredis", "redis"):
        fail("QUEUE_DSN must use the supported redis+phpredis:// or redis:// transport.")
    secrets_seen = []
    for key in KEYS_HEX:
        if len(values[key]) < 32:
            fail(f"{key} must contain at least 32 random bytes of secret material.")
        secrets_seen.append(values[key])
    for key in KEYS_BASE64:
        if not values.get(key):
            if key == "AI_CREDENTIAL_KEK" and values.get("AI_SERVER_PROXY_ENABLED", "0") == "0":
                continue
            fail(f"Set {key} before enabling its service.")
        try:
            decoded = base64.b64decode(values[key], validate=True)
        except ValueError:
            fail(f"{key} must be a base64-encoded 32-byte key.")
        if len(decoded) != 32:
            fail(f"{key} must be a base64-encoded 32-byte key.")
        secrets_seen.append(values[key])
    if len(secrets_seen) != len(set(secrets_seen)):
        fail("Authentication, metrics, cursor, and encryption keys must all be independent.")
    for key in IMAGE_KEYS:
        reference = values.get(key, "")
        if not (re.search(r"@sha256:[0-9a-f]{64}$", reference) or reference.endswith(":" + values["APP_VERSION"])):
            fail(f"{key} must use the selected APP_VERSION tag or an immutable sha256 digest.")
    for prefix in ("MYSQL", "MARIADB"):
        if values["PROVIDENTIA_DATABASE"] == prefix.lower():
            for suffix in ("PASSWORD", "ROOT_PASSWORD"):
                if placeholder(values.get(f"{prefix}_{suffix}", "")):
                    fail(f"Configure {prefix}_{suffix} before starting the selected SQL engine.")
    if values["PROVIDENTIA_MANAGED_REDIS"] == "1" and placeholder(values.get("REDIS_PASSWORD", "")):
        fail("Configure REDIS_PASSWORD before starting the redis profile.")


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("action", choices=("prepare", "validate", "metadata", "directories", "keys", "mark-started"))
    parser.add_argument("--env-file", required=True)
    parser.add_argument("--example")
    for name in ("version", "image-env", "public-url", "mail-from", "mail-dsn", "trusted-proxies", "database", "database-url", "queue-dsn", "data-directory"):
        parser.add_argument("--" + name)
    args = parser.parse_args()
    if args.action == "prepare":
        prepare(args)
    elif args.action == "validate":
        validate(args)
    else:
        values = read_env(Path(args.env_file))
        if args.action == "metadata":
            for key in ("APP_VERSION", "PROVIDENTIA_DATABASE", "PROVIDENTIA_MANAGED_REDIS", "PROVIDENTIA_DATA_DIRECTORY", "PROVIDENTIA_SETUP_STARTED"):
                print(values.get(key, ""))
        elif args.action == "keys":
            print("\n".join(values))
        elif args.action == "mark-started":
            values["PROVIDENTIA_SETUP_STARTED"] = "1"
            write_env(Path(args.env_file), values)
        elif args.action == "directories" and values.get("PROVIDENTIA_DATA_DIRECTORY"):
            parent = Path(values["PROVIDENTIA_DATA_DIRECTORY"])
            if parent.is_symlink():
                fail("The data parent must not be a symbolic link.")
            parent.mkdir(mode=0o700, parents=True, exist_ok=True)
            children = ["app-var"]
            if values["PROVIDENTIA_DATABASE"] != "external":
                children.append(values["PROVIDENTIA_DATABASE"])
            if values["PROVIDENTIA_MANAGED_REDIS"] == "1":
                children.append("redis")
            for child in children:
                directory = parent / child
                if directory.is_symlink() or (directory.exists() and not directory.is_dir()):
                    fail(f"The {child} data path must be a directory, not a symlink or file.")
                directory.mkdir(mode=0o700, exist_ok=True)
                # Image-managed volume-init handles only a new empty app root.
                # Existing ownership, content, and permissions are preserved.


if __name__ == "__main__":
    try:
        main()
    except (ValueError, OSError) as error:
        # OSError paths are administrative paths, never dotenv values/DSNs.
        print(f"Production configuration error: {error}", file=sys.stderr)
        sys.exit(2)
