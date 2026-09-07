#!/usr/bin/env python3
"""Production bootstrap regressions; no Docker daemon or real credentials needed."""

import base64
import importlib.util
import json
import os
from pathlib import Path
import shutil
import subprocess
import tempfile
import unittest

ROOT = Path(__file__).resolve().parents[2]
SCRIPT = ROOT / "scripts/setup-production.sh"
spec = importlib.util.spec_from_file_location("production_env", ROOT / "scripts/lib/production-env.py")
production_env = importlib.util.module_from_spec(spec)
spec.loader.exec_module(production_env)


class ProductionSetupTest(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory()
        self.addCleanup(self.temp.cleanup)
        self.path = Path(self.temp.name)
        self.env_file = self.path / "production.env"
        self.environment = os.environ.copy()
        for key in production_env.read_env(ROOT / ".env.production.example"):
            self.environment.pop(key, None)
        self.log = self.path / "docker-calls.jsonl"
        self.bin = self.path / "bin"
        self.bin.mkdir()
        docker = self.bin / "docker"
        docker.write_text('''#!/usr/bin/env python3
import json, os, sys
with open(os.environ["MOCK_DOCKER_LOG"], "a") as log:
    log.write(json.dumps(sys.argv[1:]) + "\\n")
if "volume" in sys.argv and "ls" in sys.argv and os.environ.get("MOCK_EXISTING_VOLUME"):
    print("providentia-production_app-var")
if os.environ.get("MOCK_EXPECT_CLEAR") == "1" and "--env-file" in sys.argv:
    if any(key in os.environ for key in ("DATABASE_URL", "APP_VERSION", "COMPOSE_PROFILES", "COMPOSE_FILE", "COMPOSE_PROJECT_NAME")):
        sys.exit(9)
if os.environ.get("MOCK_MIGRATE_FAIL") == "1" and "run" in sys.argv and sys.argv[-1] == "migrate":
    sys.exit(1)
''')
        docker.chmod(0o755)
        self.environment["PATH"] = str(self.bin) + os.pathsep + self.environment["PATH"]
        self.environment["MOCK_DOCKER_LOG"] = str(self.log)

    def run_script(self, *args, success=True):
        result = subprocess.run(
            ["bash", str(SCRIPT), "--env-file", str(self.env_file), *args],
            text=True, capture_output=True, env=self.environment,
        )
        if success:
            self.assertEqual(result.returncode, 0, result.stdout + result.stderr)
        else:
            self.assertNotEqual(result.returncode, 0, result.stdout + result.stderr)
        return result

    def prepare(self, *args):
        return self.run_script(
            "--prepare-only", "--version", "1.2.3", "--public-url", "https://api.providentia.test",
            "--mail-from", "no-reply@providentia.test", "--trusted-proxies", "172.30.0.1/32", *args,
        )

    def values(self):
        return production_env.read_env(self.env_file)

    def configure_mail(self):
        values = self.values()
        values["MAIL_DSN"] = "smtps://test-user:test%24pass@mail.providentia.test:465"
        production_env.write_env(self.env_file, values)

    def calls(self):
        return [json.loads(line) for line in self.log.read_text().splitlines()]

    def test_prepare_preserves_secrets_and_never_contacts_docker(self):
        self.prepare()
        values = self.values()
        self.assertEqual(self.env_file.stat().st_mode & 0o777, 0o600)
        self.assertEqual(values["COMPOSE_PROFILES"], "mysql,redis")
        self.assertEqual(values["MARIADB_PASSWORD"], "")
        self.assertEqual(values["CORS_ALLOWED_ORIGINS"], values["PUBLIC_BASE_URL"])
        keys = [values[key] for key in production_env.KEYS_HEX + production_env.KEYS_BASE64]
        self.assertEqual(len(set(keys)), len(keys))
        for key in production_env.KEYS_BASE64:
            self.assertEqual(len(base64.b64decode(values[key])), 32)
        before = self.env_file.read_bytes()
        self.run_script("--prepare-only")
        self.assertEqual(self.env_file.read_bytes(), before)
        self.assertFalse(self.log.exists())

    def test_missing_smtp_blocks_before_any_docker_operation(self):
        self.prepare()
        result = self.run_script(success=False)
        self.assertIn("MAIL_DSN", result.stderr)
        self.assertFalse(self.log.exists())
        self.assertNotIn(self.values()["AUTH_TOKEN_PEPPER"], result.stdout + result.stderr)

    def test_only_chosen_sql_and_external_services_are_started(self):
        self.prepare("--database", "mariadb")
        self.configure_mail()
        self.run_script()
        calls = self.calls()
        infrastructure = next(call for call in calls if "up" in call and "mariadb" in call)
        self.assertEqual(infrastructure[-2:], ["mariadb", "redis"])
        self.assertFalse(any("mysql" in call for call in calls))

    def test_external_database_and_queue_need_no_local_credentials(self):
        self.prepare("--database", "external", "--database-url", "mysql://app:test-pass@db.providentia.test/app", "--queue-dsn", "redis+phpredis://:test-pass@queue.providentia.test:6379")
        self.configure_mail()
        values = self.values()
        self.assertEqual(values["COMPOSE_PROFILES"], "")
        self.assertEqual(values["REDIS_PASSWORD"], "")
        self.run_script()
        self.assertFalse(any("mysql" in call or "mariadb" in call or "redis" in call for call in self.calls()))

    def test_pull_stop_migrate_start_order_and_failed_migration_stays_stopped(self):
        self.prepare()
        self.configure_mail()
        self.environment["MOCK_MIGRATE_FAIL"] = "1"
        self.run_script(success=False)
        calls = self.calls()
        pull = next(i for i, call in enumerate(calls) if "pull" in call)
        stop = next(i for i, call in enumerate(calls) if "stop" in call)
        migrate = next(i for i, call in enumerate(calls) if "run" in call and call[-1] == "migrate")
        self.assertLess(pull, stop)
        self.assertLess(stop, migrate)
        self.assertFalse(any("up" in call and "api" in call for call in calls))
        self.assertFalse(any("down" in call or "--volumes" in call for call in calls))
        self.assertEqual(sum("run" in call and call[-1] == "migrate" for call in calls), 1)
        self.assertNotIn(self.values()["MAIL_DSN"], self.log.read_text())

    def test_image_lock_is_validated_and_version_disagreement_is_atomic(self):
        self.prepare()
        before = self.env_file.read_bytes()
        lock = self.path / "images.env"
        locked = {"APP_VERSION": "1.2.4"}
        locked.update({key: repo + "@sha256:" + "a" * 64 for key, repo in zip(production_env.IMAGE_KEYS, production_env.IMAGE_REPOS)})
        production_env.write_env(lock, locked)
        self.run_script("--prepare-only", "--version", "1.2.5", "--image-env", str(lock), success=False)
        self.assertEqual(self.env_file.read_bytes(), before)
        self.run_script("--prepare-only", "--image-env", str(lock))
        self.assertEqual(self.values()["APP_VERSION"], "1.2.4")
        self.assertEqual(self.values()["PROVIDENTIA_IMAGE"], locked["PROVIDENTIA_IMAGE"])

    def test_env_is_data_and_never_shell_code(self):
        self.prepare()
        payload = self.path / "executed"
        values = self.values()
        values["MAIL_DSN"] = f"smtps://user:$(touch {payload})@smtp.providentia.test:465"
        production_env.write_env(self.env_file, values)
        self.run_script("--prepare-only")
        self.assertFalse(payload.exists())
        self.assertIn("$(touch", self.values()["MAIL_DSN"])

    def test_new_secrets_are_not_generated_over_existing_bind_data(self):
        parent = self.path / "data"
        (parent / "app-var").mkdir(parents=True)
        (parent / "app-var" / "encrypted-data").write_text("existing")
        self.run_script("--prepare-only", "--version", "1.2.3", "--data-directory", str(parent), success=False)
        self.assertFalse(self.env_file.exists())
        self.assertEqual((parent / "app-var" / "encrypted-data").read_text(), "existing")

    def test_conflicting_shell_configuration_cannot_redirect_deployment(self):
        self.prepare()
        self.configure_mail()
        self.environment.update({
            "DATABASE_URL": "mysql://wrong:wrong@wrong-db/wrong",
            "APP_VERSION": "99.99.99", "COMPOSE_PROFILES": "mysql,mariadb,backup",
            "COMPOSE_FILE": "/untrusted/compose.yaml", "COMPOSE_PROJECT_NAME": "wrong-project",
            "MOCK_EXPECT_CLEAR": "1",
        })
        self.run_script()
        self.assertEqual(self.values()["APP_VERSION"], "1.2.3")

    def test_new_env_cannot_adopt_existing_named_volumes(self):
        self.prepare()
        self.configure_mail()
        self.environment["MOCK_EXISTING_VOLUME"] = "1"
        result = self.run_script(success=False)
        self.assertIn("matching original env file", result.stderr)
        self.assertFalse(any("pull" in call or "run" in call for call in self.calls()))

    def test_alternate_image_namespace_and_tag_upgrade_preserve_repositories(self):
        self.prepare()
        lock = self.path / "mirror-images.env"
        locked = {"APP_VERSION": "1.2.4"}
        for key, suffix in zip(production_env.IMAGE_KEYS, ("", "-web", "-media-worker")):
            locked[key] = "docker.io/providentia/backend" + suffix + "@sha256:" + "b" * 64
        production_env.write_env(lock, locked)
        self.run_script("--prepare-only", "--image-env", str(lock))
        self.assertEqual(self.values()["PROVIDENTIA_WEB_IMAGE"], locked["PROVIDENTIA_WEB_IMAGE"])
        self.run_script("--prepare-only", "--version", "1.2.5")
        self.assertEqual(self.values()["PROVIDENTIA_IMAGE"], "docker.io/providentia/backend:1.2.5")

    def test_selected_profiles_and_bind_compose_models(self):
        binary = os.environ.get("PROVIDENTIA_COMPOSE_BINARY") or shutil.which("docker-compose")
        if binary:
            compose = [binary]
        elif shutil.which("docker"):
            compose = [shutil.which("docker"), "compose"]
        else:
            self.skipTest("Install Docker Compose or set PROVIDENTIA_COMPOSE_BINARY to validate its model.")
        for database in ("mysql", "mariadb", "external"):
            with self.subTest(database=database):
                self.env_file = self.path / f"{database}.env"
                args = ["--database", database, "--data-directory", str(self.path / database)]
                if database == "external":
                    args += ["--database-url", "mysql://app:test-pass@db.providentia.test/app", "--queue-dsn", "redis+phpredis://:test-pass@queue.providentia.test:6379"]
                self.prepare(*args)
                self.configure_mail()
                command = [*compose, "--env-file", str(self.env_file), "-f", str(ROOT / "compose.production.yaml"), "-f", str(ROOT / "compose.production.bind.yaml"), "config", "--format", "json"]
                result = subprocess.run(command, capture_output=True, text=True, env=self.environment)
                self.assertEqual(result.returncode, 0, result.stderr)
                model = json.loads(result.stdout)
                services = model["services"]
                self.assertEqual("mysql" in services, database == "mysql")
                self.assertEqual("mariadb" in services, database == "mariadb")
                self.assertEqual("redis" in services, database != "external")
                self.assertNotIn("backup", services)
                self.assertEqual(services["api"]["environment"]["AUTH_RATE_LIMIT_RETENTION_DAYS"], "2")
                self.assertEqual(services["api"]["environment"]["AI_ALLOW_PRIVATE_NETWORK_ENDPOINTS"], "0")
                self.assertEqual(services["api"]["environment"]["CORS_ALLOWED_ORIGINS"], "https://api.providentia.test")
                self.assertEqual(services["ai-video-worker"]["tmpfs"], ["/tmp:size=512m,mode=1777"])
                self.assertEqual(model["volumes"]["app-var"]["driver_opts"]["device"], str(self.path / database / "app-var"))


if __name__ == "__main__":
    unittest.main(verbosity=2)
