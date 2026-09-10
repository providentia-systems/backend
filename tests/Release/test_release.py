"""Offline regressions for release gates, recovery, ordering and immutable assets."""

import copy
import hashlib
import importlib.util
import json
import os
from pathlib import Path
import subprocess
import tarfile
import tempfile
import unittest
from unittest.mock import patch


ROOT = Path(__file__).resolve().parents[2]
SPEC = importlib.util.spec_from_file_location("release", ROOT / "scripts/release.py")
release = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(release)
COMMIT = "a" * 40
OLDER = "b" * 40
REPOSITORY = "providentia-systems/backend"
REPOSITORIES = {name: f"ghcr.io/{REPOSITORY}{suffix}" for name, suffix in release.IMAGE_SUFFIXES.items()}
IMAGES = {name: repository + "@sha256:" + str(index) * 64 for index, (name, repository) in enumerate(REPOSITORIES.items(), 1)}
MANIFEST = {"schemaVersion": 1, "version": "0.1.0", "commit": COMMIT,
            "php": "8.5", "platforms": ["linux/amd64", "linux/arm64"], "images": IMAGES}


def record(tag="v0.1.0", sha=COMMIT, draft=True, locked=False):
    return {"id": 1, "tag_name": tag, "body": f"<!-- providentia-release-sha:{sha} -->",
            "draft": draft, "assets": [{"name": "release-manifest.json", "id": 91}] if locked else []}


def workflow_run(**changes):
    run = {"id": 1, "run_attempt": 1, "head_sha": COMMIT, "head_branch": "main", "event": "push",
           "head_repository": {"full_name": REPOSITORY}, "status": "completed", "conclusion": "success"}
    return run | changes


class VersionTests(unittest.TestCase):
    def test_initial_release_and_numeric_patch_order(self):
        self.assertEqual(release.select_version([], COMMIT), ("v0.1.0", None))
        self.assertEqual(release.select_version([record("v0.1.9", OLDER), record("v0.1.10", "c" * 40)], COMMIT), ("v0.1.11", None))

    def test_partial_and_published_reruns_reuse_the_original_version(self):
        for draft in (True, False):
            existing = record(draft=draft)
            self.assertEqual(release.select_version([existing, record("v0.2.0", OLDER)], COMMIT), ("v0.1.0", existing))
            with self.assertRaises(ValueError):
                release.select_version([existing], COMMIT, "v0.1.1")

    def test_manual_minor_or_major_must_exceed_reserved_drafts(self):
        existing = [record("v0.1.8", OLDER)]
        self.assertEqual(release.select_version(existing, COMMIT, "v0.2.0"), ("v0.2.0", None))
        for tag in ("v0.1.8", "v0.1.7", "0.2.0", "v01.2.0", "v1.0.0-rc1", "v1.0.0\n"):
            with self.subTest(tag=tag), self.assertRaises(ValueError):
                release.select_version(existing, COMMIT, tag)

    def test_duplicate_commit_versions_fail_closed(self):
        with self.assertRaises(ValueError):
            release.select_version([record(), record("v0.1.1")], COMMIT)

    def test_late_reruns_cannot_rewind_floating_tags_even_after_partial_promotion(self):
        for newer in (record("v0.2.0", OLDER, draft=False), record("v0.2.0", OLDER, locked=True)):
            self.assertFalse(release.may_promote([record(), newer], "v0.1.0"))
        self.assertTrue(release.may_promote([record(), record("v0.2.0", OLDER)], "v0.1.0"))

    def test_php_aliases_only_apply_to_the_supported_runtime_images(self):
        self.assertEqual(release.image_tags("web", "0.1.0", True), ["0.1.0", "latest", "edge"])
        self.assertEqual(release.image_tags("runtime", "0.1.0", False), ["0.1.0", "0.1.0-php8.5"])
        self.assertIn("php8.5", release.image_tags("media", "0.1.0", True))

    def test_repository_version_hint_controls_next_commit_and_expired_hints_fall_back_to_patch(self):
        items = [record("v0.1.0", OLDER)]
        self.assertEqual(release.requested_version(items, COMMIT, next_version="v0.2.0"), "v0.2.0")
        self.assertEqual(release.requested_version(items, COMMIT, next_version="v0.1.0"), "")
        self.assertEqual(release.select_version(items, COMMIT, release.requested_version(items, COMMIT, next_version="v0.1.0")), ("v0.1.1", None))

    def test_hint_does_not_change_rerun_identity_and_explicit_dispatch_is_strict(self):
        items = [record()]
        self.assertEqual(release.requested_version(items, COMMIT, next_version="v0.2.0"), "")
        self.assertEqual(release.requested_version(items, COMMIT, explicit="v0.1.0", next_version="v0.2.0"), "v0.1.0")
        with self.assertRaises(ValueError):
            release.select_version(items, OLDER, release.requested_version(items, OLDER, explicit="v0.1.0"))


class GateTests(unittest.TestCase):
    def state(self, runs):
        return release.gate_state(runs, COMMIT, "main", REPOSITORY)

    def test_requires_success_on_exact_commit_branch_event_and_repository(self):
        self.assertEqual(self.state([workflow_run()]), "success")
        for change in ({"head_sha": OLDER}, {"head_branch": "agent/test"}, {"event": "pull_request"},
                       {"head_repository": {"full_name": "fork/backend"}}):
            with self.subTest(change=change):
                self.assertEqual(self.state([workflow_run(**change)]), "pending")

    def test_latest_run_attempt_overrules_an_older_pass(self):
        self.assertEqual(self.state([workflow_run(), workflow_run(id=2, status="in_progress")]), "pending")
        self.assertEqual(self.state([workflow_run(), workflow_run(run_attempt=2, conclusion="failure")]), "failed")
        for conclusion in ("failure", "cancelled", "skipped", "neutral", "timed_out", None):
            self.assertEqual(self.state([workflow_run(conclusion=conclusion)]), "failed")

    def test_either_required_workflow_failure_stops_release(self):
        with patch.dict(os.environ, {"GITHUB_SHA": COMMIT, "DEFAULT_BRANCH": "main", "GITHUB_REPOSITORY": REPOSITORY}), \
                patch.object(release, "api", side_effect=[{"workflow_runs": [workflow_run()]},
                                                         {"workflow_runs": [workflow_run(conclusion="failure")]}]), \
                self.assertRaisesRegex(RuntimeError, "security.yml"):
            release.wait_for_gates()


class ManifestTests(unittest.TestCase):
    def test_manifest_rejects_wrong_commit_versions_missing_images_or_mutable_tags(self):
        self.assertEqual(release.validate_manifest(MANIFEST, "0.1.0", COMMIT, REPOSITORIES), MANIFEST)
        invalid = []
        for key, value in (("commit", OLDER), ("version", "0.2.0"), ("php", "8.4"), ("platforms", ["linux/amd64"])):
            invalid.append(copy.deepcopy(MANIFEST) | {key: value})
        for value in ("ghcr.io/other/backend@sha256:" + "a" * 64, REPOSITORIES["runtime"] + ":latest", "injected\nkey=value"):
            broken = copy.deepcopy(MANIFEST)
            broken["images"]["runtime"] = value
            invalid.append(broken)
        broken = copy.deepcopy(MANIFEST)
        del broken["images"]["media"]
        invalid.append(broken)
        for manifest in invalid:
            with self.subTest(manifest=manifest), self.assertRaises(ValueError):
                release.validate_manifest(manifest, "0.1.0", COMMIT, REPOSITORIES)


class RecoveryTests(unittest.TestCase):
    def setUp(self):
        self.temporary = tempfile.TemporaryDirectory()
        self.addCleanup(self.temporary.cleanup)
        self.directory = Path(self.temporary.name)
        self.env = patch.dict(os.environ, {"GITHUB_REPOSITORY": REPOSITORY, "GITHUB_SHA": COMMIT,
                                          "GITHUB_REF": "refs/heads/main", "DEFAULT_BRANCH": "main",
                                          "RELEASE_VERSION": "", "NEXT_RELEASE_VERSION": "", "NAMESPACE_OVERRIDE": "", "RELEASE_ID": "1",
                                          "APP_VERSION": "0.1.0", "GITHUB_RUN_ID": "7", "GITHUB_RUN_ATTEMPT": "2",
                                          "GITHUB_OUTPUT": str(self.directory / "outputs")})
        self.env.start()
        self.addCleanup(self.env.stop)
        self.artifacts = patch.object(release, "ARTIFACT_DIRECTORY", self.directory)
        self.artifacts.start()
        self.addCleanup(self.artifacts.stop)
        (self.directory / "release-manifest.json").write_text(json.dumps(MANIFEST))

    def test_retry_after_partial_tags_reuses_journaled_digest_without_allocating_version(self):
        with patch.object(release, "wait_for_gates"), patch.object(release, "releases", return_value=[record(locked=True)]), \
                patch.object(release, "ensure_tag"), patch.object(release, "download_asset", return_value=json.dumps(MANIFEST)), \
                patch.object(release, "api") as api:
            release.prepare()
        api.assert_not_called()
        output = (self.directory / "outputs").read_text()
        self.assertIn("version=0.1.0\n", output)
        self.assertIn("reuse=true\n", output)
        self.assertIn(f"runtime_locked={IMAGES['runtime']}\n", output)

    def test_delayed_unreserved_older_commit_cannot_receive_a_newer_version(self):
        with patch.object(release, "wait_for_gates"), patch.object(release, "releases", return_value=[record("v0.2.0", OLDER)]), \
                patch.object(release, "ensure_tag"), \
                patch.object(release.subprocess, "run", return_value=subprocess.CompletedProcess([], 1)), \
                patch.object(release, "api") as api, self.assertRaisesRegex(ValueError, "predates"):
            release.prepare()
        api.assert_not_called()

    def test_empty_github_starter_lock_after_upload_failure_can_be_retried(self):
        existing = record(locked=True)
        existing["assets"][0].update(state="starter", size=0)
        with patch.object(release, "wait_for_gates"), patch.object(release, "releases", return_value=[existing]), \
                patch.object(release, "ensure_tag"), patch.object(release, "command") as command:
            release.prepare()
        self.assertEqual(command.call_args.args[0][-1], "DELETE")
        self.assertIn("reuse=false\n", (self.directory / "outputs").read_text())

    def test_branch_candidates_promote_commit_aliases_without_creating_a_release(self):
        with patch.dict(os.environ, {"RELEASE_ID": ""}), patch.object(release, "tag_image") as tags, patch.object(release, "api") as api:
            release.finalize()
        api.assert_not_called()
        self.assertEqual(tags.call_count, 3)
        for call in tags.call_args_list:
            self.assertTrue(call.args[1].endswith(":sha-" + COMMIT[:12]))
            self.assertFalse(call.kwargs["immutable"])

    def test_digest_lock_and_git_tag_precede_image_tags_and_public_release(self):
        events = []
        def run(arguments, **kwargs):
            events.append(("command", arguments))
            return b"{}"
        with patch.object(release, "api", return_value=record()), \
                patch.object(release, "bundle", return_value=[self.directory / name for name in ("release-manifest.json", "images.env", "archive.tar.gz", "SHA256SUMS")]), \
                patch.object(release, "command", side_effect=run), patch.object(release, "releases", return_value=[record(locked=True)]), \
                patch.object(release, "ensure_tag", side_effect=lambda *args: events.append(("git-tag", args))), \
                patch.object(release, "tag_image", side_effect=lambda *args, **kwargs: events.append(("image-tag", args, kwargs))):
            release.finalize()
        self.assertIn("release-manifest.json", " ".join(events[0][1]))
        self.assertEqual(events[2][0], "git-tag")
        image_events = [event for event in events if event[0] == "image-tag"]
        immutable = [event[2]["immutable"] for event in image_events]
        self.assertEqual(immutable, sorted(immutable, reverse=True))
        self.assertIn("PATCH", events[-1][1])

    def test_git_tag_conflict_stops_before_any_registry_mutation(self):
        with patch.object(release, "api", return_value=record(locked=True)), \
                patch.object(release, "download_asset", return_value=json.dumps(MANIFEST)), \
                patch.object(release, "bundle", return_value=[Path("lock"), Path("archive")]), \
                patch.object(release, "command"), patch.object(release, "ensure_tag", side_effect=ValueError("tag conflict")), \
                patch.object(release, "tag_image") as tags, self.assertRaises(ValueError):
            release.finalize()
        tags.assert_not_called()

    def test_published_rerun_does_not_modify_assets_or_any_image_tags(self):
        with patch.object(release, "api", return_value=record(draft=False, locked=True)), \
                patch.object(release, "ensure_tag"), patch.object(release, "command") as command, \
                patch.object(release, "tag_image") as tags:
            release.finalize()
        command.assert_not_called()
        tags.assert_not_called()

    def test_wrong_existing_git_tag_is_never_overwritten(self):
        for refs in (f"{OLDER}\trefs/tags/v0.1.0\n", f"{COMMIT}\trefs/tags/v0.1.0\n{OLDER}\trefs/tags/v0.1.0^{{}}\n"):
            with patch.object(release, "command", return_value=refs), patch.object(release, "api") as api, self.assertRaises(ValueError):
                release.ensure_tag("v0.1.0", COMMIT)
            api.assert_not_called()

    def test_existing_immutable_registry_tag_cannot_be_replaced(self):
        with patch.object(release, "inspect_image", return_value="sha256:" + "f" * 64), \
                patch.object(release, "command") as command, self.assertRaises(ValueError):
            release.tag_image(IMAGES["runtime"], REPOSITORIES["runtime"] + ":0.1.0", immutable=True)
        command.assert_not_called()

    def test_unknown_registry_failure_cannot_be_treated_as_a_missing_tag(self):
        with patch.object(release.subprocess, "run", return_value=subprocess.CompletedProcess([], 1, b"", b"unauthorized")), \
                self.assertRaises(RuntimeError):
            release.inspect_image("ghcr.io/example/backend:0.1.0")
        with patch.object(release.subprocess, "run", return_value=subprocess.CompletedProcess([], 1, b"", b"manifest unknown")):
            self.assertIsNone(release.inspect_image("ghcr.io/example/backend:0.1.0"))

    def test_registry_digest_parsing_is_independent_of_raw_manifest_formatting(self):
        digest = "sha256:" + "a" * 64
        with patch.object(release.subprocess, "run", return_value=subprocess.CompletedProcess([], 0, json.dumps(digest).encode() + b"\n", b"")):
            self.assertEqual(release.inspect_image("ghcr.io/example/backend:0.1.0"), digest)

    def test_archive_has_fixed_root_digest_lock_and_no_local_secrets(self):
        with tempfile.TemporaryDirectory() as temporary:
            root = Path(temporary)
            for filename in ("compose.production.yaml", "compose.production.bind.yaml", ".env.production.example", "scripts/setup-production.sh",
                             "scripts/lib/production-env.py", "LICENSE", "README.md",
                             "docs/deployment/production.md", "docs/deployment/server-quick-start.md",
                             "docs/deployment/post-release-acceptance.md", "docs/deployment/ai-byok.md"):
                path = root / filename
                path.parent.mkdir(parents=True, exist_ok=True)
                path.write_text("checked-in fixture")
            (root / ".env.production").write_text("LOCAL_SECRET=must-not-be-in-archive")
            previous = Path.cwd()
            try:
                os.chdir(root)
                paths = release.bundle(MANIFEST)
            finally:
                os.chdir(previous)
            with tarfile.open(paths[2]) as archive:
                self.assertTrue(all(name.startswith("providentia-backend-v0.1.0/") for name in archive.getnames()))
                self.assertNotIn("providentia-backend-v0.1.0/.env.production", archive.getnames())
                for document in ("server-quick-start.md", "post-release-acceptance.md", "ai-byok.md"):
                    self.assertIn(
                        f"providentia-backend-v0.1.0/docs/deployment/{document}",
                        archive.getnames(),
                    )
                lock = archive.extractfile("providentia-backend-v0.1.0/images.env").read().decode()
                self.assertIn("APP_VERSION=0.1.0\n", lock)
                self.assertIn(IMAGES["runtime"], lock)
            self.assertIn(hashlib.sha256(paths[2].read_bytes()).hexdigest(), paths[3].read_text())


if __name__ == "__main__":
    unittest.main()
