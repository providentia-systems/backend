#!/usr/bin/env python3
"""Release trusted main commits; all writes run inside the workflow's release queue.

GitHub draft assets are the recovery journal. Once images are locked, a retry
must reuse those exact digests and may never rebuild an existing version.
"""

import hashlib
import json
import os
from pathlib import Path
import re
import subprocess
import sys
import tarfile
import time
from urllib.parse import urlencode


VERSION = re.compile(r"v(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)\Z")
SHA = re.compile(r"[0-9a-f]{40}\Z")
NAMESPACE = re.compile(r"[a-z0-9][a-z0-9._-]*/[a-z0-9][a-z0-9._-]*\Z")
DIGEST = re.compile(r"sha256:[0-9a-f]{64}\Z")
IMAGE_SUFFIXES = {"runtime": "", "web": "-web", "media": "-media-worker"}
IMAGE_ENV = {"runtime": "PROVIDENTIA_IMAGE", "web": "PROVIDENTIA_WEB_IMAGE", "media": "PROVIDENTIA_MEDIA_IMAGE"}
MARKER = re.compile(r"<!-- providentia-release-sha:([0-9a-f]{40}) -->")
ARTIFACT_DIRECTORY = Path("var/release")


def command(arguments, **kwargs):
    result = subprocess.run(arguments, capture_output=True, check=False, **kwargs)
    if result.returncode:
        # Command arguments never contain tokens; do not echo external error bodies.
        raise RuntimeError(f"Command failed ({result.returncode}): {arguments[0]} {arguments[1]}")
    return result.stdout


def api(path, payload=None):
    arguments = ["gh", "api", path]
    if payload is not None:
        arguments += ["--method", "POST", "--input", "-"]
    return json.loads(command(arguments, input=json.dumps(payload).encode() if payload is not None else None))


def repository_path():
    return f"repos/{os.environ['GITHUB_REPOSITORY']}"


def releases():
    pages = json.loads(command(["gh", "api", f"{repository_path()}/releases?per_page=100", "--paginate", "--slurp"]))
    return [release for page in pages for release in page]


def version_tuple(tag):
    match = VERSION.fullmatch(tag)
    if not match:
        raise ValueError("Release versions must be vX.Y.Z without leading zeroes or prerelease suffixes.")
    return tuple(int(part) for part in match.groups())


def release_sha(release):
    match = MARKER.search(release.get("body") or "")
    return match.group(1) if match else None


def select_version(items, sha, requested=""):
    versions = [item for item in items if VERSION.fullmatch(item["tag_name"])]
    matching = [item for item in versions if release_sha(item) == sha]
    if len(matching) > 1:
        raise ValueError("Multiple backend releases refer to this commit; resolve the conflict before retrying.")
    if matching:
        if requested and requested != matching[0]["tag_name"]:
            raise ValueError("This commit already has a reserved release version; rerun with that version.")
        return matching[0]["tag_name"], matching[0]
    highest = max((version_tuple(item["tag_name"]) for item in versions), default=None)
    if requested:
        chosen = version_tuple(requested)
        if highest is not None and chosen <= highest:
            raise ValueError("A new release must be greater than every published or reserved version.")
        return requested, None
    if highest is None:
        return "v0.1.0", None
    return f"v{highest[0]}.{highest[1]}.{highest[2] + 1}", None


def requested_version(items, sha, explicit="", next_version=""):
    if explicit:
        return explicit
    # A repository hint controls the next new commit, never an existing retry.
    if not next_version or any(release_sha(item) == sha for item in items):
        return ""
    wanted = version_tuple(next_version)
    highest = max((version_tuple(item["tag_name"]) for item in items if VERSION.fullmatch(item["tag_name"])), default=None)
    return next_version if highest is None or wanted > highest else ""


def gate_state(runs, sha, branch, repository):
    matching = [run for run in runs if run.get("head_sha") == sha
                and run.get("head_branch") == branch and run.get("event") == "push"
                and run.get("head_repository", {}).get("full_name", "").lower() == repository.lower()]
    if not matching:
        return "pending"
    latest = max(matching, key=lambda run: (run["id"], run.get("run_attempt", 1)))
    if latest.get("status") != "completed":
        return "pending"
    return "success" if latest.get("conclusion") == "success" else "failed"


def wait_for_gates():
    deadline = time.monotonic() + 3600
    sha = os.environ["GITHUB_SHA"]
    branch = os.environ["DEFAULT_BRANCH"]
    repository = os.environ["GITHUB_REPOSITORY"]
    while True:
        pending = []
        for workflow in ("quality.yml", "security.yml"):
            query = urlencode({"head_sha": sha, "branch": branch, "event": "push", "per_page": 100})
            data = api(f"{repository_path()}/actions/workflows/{workflow}/runs?{query}")
            state = gate_state(data["workflow_runs"], sha, branch, repository)
            if state == "failed":
                raise RuntimeError(f"{workflow} did not pass for {sha}; fix or rerun that workflow first.")
            if state != "success":
                pending.append(workflow)
        if not pending:
            return
        if time.monotonic() >= deadline:
            raise RuntimeError("Timed out waiting for successful Quality and Security runs on this exact commit.")
        print(f"Waiting for {', '.join(pending)} on {sha}.", flush=True)
        time.sleep(20)


def output(**values):
    with open(os.environ["GITHUB_OUTPUT"], "a", encoding="utf-8") as stream:
        for name, value in values.items():
            if "\n" in str(value) or "\r" in str(value):
                raise ValueError("Workflow outputs must be single-line values.")
            stream.write(f"{name}={value}\n")


def image_repositories():
    namespace = (os.environ.get("NAMESPACE_OVERRIDE") or os.environ["GITHUB_REPOSITORY"]).lower()
    if not NAMESPACE.fullmatch(namespace):
        raise ValueError("CONTAINER_IMAGE_NAMESPACE must be a lowercase owner/name path.")
    return {name: f"ghcr.io/{namespace}{suffix}" for name, suffix in IMAGE_SUFFIXES.items()}


def validate_manifest(manifest, version, sha, repositories):
    if manifest.get("schemaVersion") != 1 or manifest.get("version") != version or manifest.get("commit") != sha:
        raise ValueError("The release digest lock does not match the reserved version and commit.")
    if manifest.get("php") != "8.5" or manifest.get("platforms") != ["linux/amd64", "linux/arm64"]:
        raise ValueError("The release digest lock has an unsupported runtime or platform matrix.")
    if set(manifest.get("images", {})) != set(IMAGE_SUFFIXES):
        raise ValueError("The release digest lock must include all three production images.")
    for name, repository in repositories.items():
        image = manifest["images"][name]
        prefix = f"{repository}@"
        if not isinstance(image, str) or not image.startswith(prefix) or not DIGEST.fullmatch(image[len(prefix):]):
            raise ValueError("The release digest lock contains an invalid or unexpected image reference.")
    return manifest


def download_asset(asset):
    return command(["gh", "api", f"{repository_path()}/releases/assets/{asset['id']}",
                    "--header", "Accept: application/octet-stream"])


def prepare():
    sha = os.environ["GITHUB_SHA"]
    if not SHA.fullmatch(sha):
        raise ValueError("A full Git commit SHA is required.")
    ARTIFACT_DIRECTORY.mkdir(parents=True, exist_ok=True)
    repositories = image_repositories()
    output(**{f"{name}_repository": repository for name, repository in repositories.items()})
    candidate = f"sha-{sha}-run-{os.environ['GITHUB_RUN_ID']}-{os.environ['GITHUB_RUN_ATTEMPT']}"
    output(**{name: f"{repository}:{candidate}" for name, repository in repositories.items()})
    if os.environ["GITHUB_REF"] != f"refs/heads/{os.environ['DEFAULT_BRANCH']}":
        if os.environ.get("RELEASE_VERSION"):
            raise ValueError("Versioned releases are permitted only from the default branch.")
        output(version=f"sha-{sha[:12]}", release_id="", reuse="false")
        return
    wait_for_gates()
    items = releases()
    requested = requested_version(items, sha, os.environ.get("RELEASE_VERSION", ""), os.environ.get("NEXT_RELEASE_VERSION", ""))
    version, release = select_version(items, sha, requested)
    ensure_tag(version, sha, create=False)
    if release is None:
        # A delayed old run must never turn old code into a newer version.
        for item in items:
            previous = release_sha(item)
            if previous and VERSION.fullmatch(item["tag_name"]):
                result = subprocess.run(["git", "merge-base", "--is-ancestor", previous, sha], check=False)
                if result.returncode != 0:
                    raise ValueError("This commit predates or diverges from a reserved release; release the newer main commit instead.")
        release = api(f"{repository_path()}/releases", {
            "tag_name": version, "target_commitish": sha, "name": f"Providentia backend {version}",
            "draft": True, "prerelease": False,
            "body": f"<!-- providentia-release-sha:{sha} -->\n\n"
                    f"Backend `{version}` from `{sha}`. PHP 8.5; Linux amd64 and arm64.\n\n"
                    "Download the deployment archive and follow its deployment guide. "
                    "`images.env` pins all three images by digest. Quality, Security, "
                    "platform vulnerability scans and container smoke checks must pass before publication.",
        })
    asset = next((asset for asset in release.get("assets", []) if asset["name"] == "release-manifest.json"), None)
    if asset and asset.get("state", "uploaded") != "uploaded":
        if not release["draft"] or asset.get("state") != "starter" or asset.get("size", 0) != 0:
            raise ValueError("The release digest lock upload is incomplete; refusing an ambiguous recovery.")
        # GitHub can leave an empty starter asset after a failed upload. No
        # registry promotion can have happened before a successful lock upload.
        command(["gh", "api", f"{repository_path()}/releases/assets/{asset['id']}", "--method", "DELETE"])
        asset = None
    if asset:
        manifest = validate_manifest(json.loads(download_asset(asset)), version[1:], sha, repositories)
        (ARTIFACT_DIRECTORY / "release-manifest.json").write_text(json.dumps(manifest, indent=2) + "\n")
        output(**{f"{name}_locked": image for name, image in manifest["images"].items()})
    elif not release["draft"]:
        raise ValueError("A published release is missing its digest lock; refusing to rebuild or replace it.")
    output(version=version[1:], release_id=release["id"], reuse=str(asset is not None).lower())


def images():
    references = {}
    for name, repository in image_repositories().items():
        locked = os.environ.get(f"{name.upper()}_LOCKED", "")
        digest = os.environ.get(f"{name.upper()}_DIGEST", "")
        if locked:
            references[name] = locked
        elif DIGEST.fullmatch(digest):
            references[name] = f"{repository}@{digest}"
        else:
            raise ValueError(f"Missing image digest for {name}.")
    # The same validation applies to new candidates and a resumed release.
    manifest = {"schemaVersion": 1, "version": os.environ["APP_VERSION"], "commit": os.environ["GITHUB_SHA"],
                "php": "8.5", "platforms": ["linux/amd64", "linux/arm64"], "images": references}
    validate_manifest(manifest, manifest["version"], manifest["commit"], image_repositories())
    ARTIFACT_DIRECTORY.mkdir(parents=True, exist_ok=True)
    (ARTIFACT_DIRECTORY / "release-manifest.json").write_text(json.dumps(manifest, indent=2) + "\n")
    Path("published-images.txt").write_text("".join(f"{image}\n" for image in references.values()))
    output(**references)


def may_promote(items, version):
    # Include locked drafts: partial promotion must not be rolled back by a retry
    # of an older release while the newer release is waiting to be completed.
    relevant = [item for item in items if VERSION.fullmatch(item["tag_name"])
                and (not item["draft"] or any(asset["name"] == "release-manifest.json" for asset in item.get("assets", [])))]
    return all(version_tuple(item["tag_name"]) <= version_tuple(version) for item in relevant)


def image_tags(name, version, floating):
    tags = [version]
    if name != "web":
        tags.append(f"{version}-php8.5")
    if floating:
        tags += ["latest", "edge"]
        if name != "web":
            tags.append("php8.5")
    return tags


def inspect_image(target):
    inspected = subprocess.run(["docker", "buildx", "imagetools", "inspect", target,
                                "--format", "{{json .Manifest.Digest}}"], capture_output=True, check=False)
    if inspected.returncode:
        error = inspected.stderr.decode(errors="replace").lower()
        if any(reason in error for reason in ("not found", "manifest unknown", "name unknown")):
            return None
        raise RuntimeError(f"Cannot safely determine whether this image tag already exists: {target}")
    digest = json.loads(inspected.stdout)
    if not isinstance(digest, str) or not DIGEST.fullmatch(digest):
        raise ValueError("Registry inspection did not return a valid image digest.")
    return digest


def tag_image(source, target, immutable):
    actual = inspect_image(target)
    if actual is not None:
        if actual == source.split("@", 1)[1]:
            return
        if immutable:
            raise ValueError(f"Existing immutable image tag has a different digest: {target}")
    command(["docker", "buildx", "imagetools", "create", "--tag", target, source])
    if inspect_image(target) != source.split("@", 1)[1]:
        raise ValueError(f"Promoted image digest differs from the tested candidate: {target}")


def bundle(manifest):
    version = "v" + manifest["version"]
    directory = ARTIFACT_DIRECTORY
    directory.mkdir(parents=True, exist_ok=True)
    (directory / "images.env").write_text(f"APP_VERSION={manifest['version']}\n" + "".join(
        f"{IMAGE_ENV[name]}={image}\n" for name, image in manifest["images"].items()))
    archive = directory / f"providentia-backend-{version}.tar.gz"
    inputs = [Path(name) for name in ("compose.production.yaml", "compose.production.bind.yaml", ".env.production.example",
                                    "scripts/setup-production.sh", "scripts/lib/production-env.py", "LICENSE", "README.md")]
    inputs += sorted(Path("docs/deployment").glob("*.md"))
    prefix = f"providentia-backend-{version}"
    # Files come exclusively from the exact checkout, never a working .env file.
    with tarfile.open(archive, "w:gz") as stream:
        for path in inputs:
            stream.add(path, arcname=f"{prefix}/{path}", recursive=False)
        for name in ("images.env", "release-manifest.json"):
            stream.add(directory / name, arcname=f"{prefix}/{name}")
    checksums = directory / "SHA256SUMS"
    checksums.write_text("".join(f"{hashlib.sha256(path.read_bytes()).hexdigest()}  {path.name}\n"
                                for path in (archive, directory / "images.env", directory / "release-manifest.json")))
    return [directory / "release-manifest.json", directory / "images.env", archive, checksums]


def ensure_tag(version, sha, create=True):
    existing = command(["git", "ls-remote", "--tags", "origin", f"refs/tags/{version}", f"refs/tags/{version}^{{}}"], text=True).splitlines()
    if existing:
        resolved = existing[-1].split()[0]
        if resolved != sha:
            raise ValueError("The release Git tag points to another commit; it will never be overwritten.")
        return
    if create:
        api(f"{repository_path()}/git/refs", {"ref": f"refs/tags/{version}", "sha": sha})


def finalize():
    manifest = validate_manifest(json.loads((ARTIFACT_DIRECTORY / "release-manifest.json").read_text()),
                                 os.environ["APP_VERSION"], os.environ["GITHUB_SHA"], image_repositories())
    if not os.environ.get("RELEASE_ID"):
        promote_commit(manifest)
        return
    release = api(f"{repository_path()}/releases/{os.environ['RELEASE_ID']}")
    version = "v" + manifest["version"]
    if release["tag_name"] != version or release_sha(release) != manifest["commit"]:
        raise ValueError("Release identity changed after preparation.")
    if not release["draft"]:
        ensure_tag(version, manifest["commit"])
        print(f"{version} is already published; its assets and tags remain unchanged.")
        return
    # Upload the journal FIRST, before creating any immutable or floating tags.
    existing_lock = next((asset for asset in release.get("assets", []) if asset["name"] == "release-manifest.json"), None)
    if existing_lock and json.loads(download_asset(existing_lock)) != manifest:
        raise ValueError("Reserved release images differ from the existing digest lock.")
    paths = bundle(manifest)
    if not existing_lock:
        command(["gh", "release", "upload", version, str(paths[0]), "--repo", os.environ["GITHUB_REPOSITORY"]])
    command(["gh", "release", "upload", version, *[str(path) for path in paths[1:]], "--clobber", "--repo", os.environ["GITHUB_REPOSITORY"]])
    ensure_tag(version, manifest["commit"])
    floating = may_promote(releases(), version)
    # All three immutable version tags precede all floating aliases.
    for mutable in (False, True):
        if mutable and not floating:
            continue
        for name, source in manifest["images"].items():
            for tag in image_tags(name, manifest["version"], floating):
                is_mutable = tag in ("latest", "edge", "php8.5")
                if is_mutable == mutable:
                    tag_image(source, f"{source.split('@', 1)[0]}:{tag}", immutable=not mutable)
    promote_commit(manifest)
    command(["gh", "api", f"{repository_path()}/releases/{release['id']}", "--method", "PATCH", "--input", "-"],
            input=json.dumps({"draft": False, "make_latest": "true" if floating else "false"}).encode())
    print(f"Published {version} from {manifest['commit']} with immutable deployment assets.")


def promote_commit(manifest):
    # Existing prebuilt development installs resolve this convenient commit tag
    # and verify the full OCI revision. Production uses the digest lock instead.
    for source in manifest["images"].values():
        target = f"{source.split('@', 1)[0]}:sha-{manifest['commit'][:12]}"
        tag_image(source, target, immutable=False)


def mirror():
    namespace = os.environ["DOCKERHUB_IMAGE_NAMESPACE"]
    if not NAMESPACE.fullmatch(namespace):
        raise ValueError("DOCKERHUB_IMAGE_NAMESPACE must be a lowercase owner/name path.")
    manifest = json.loads((ARTIFACT_DIRECTORY / "release-manifest.json").read_text())
    validate_manifest(manifest, os.environ["APP_VERSION"], os.environ["GITHUB_SHA"], image_repositories())
    floating = may_promote(releases(), "v" + manifest["version"])
    authfile = str(Path.home() / ".docker/config.json")
    for name, source in manifest["images"].items():
        repository = f"docker.io/{namespace}{IMAGE_SUFFIXES[name]}"
        # Skopeo transfers every platform and the manifest without changing its digest.
        target = f"{repository}:{manifest['version']}"
        existing = inspect_image(target)
        if existing is not None and existing != source.split("@", 1)[1]:
            raise ValueError(f"Docker Hub version already contains a different digest: {target}")
        command(["skopeo", "copy", "--all", "--preserve-digests", "--authfile", authfile, f"docker://{source}", f"docker://{target}"])
        for tag in image_tags(name, manifest["version"], floating):
            tag_image(f"{repository}@{source.split('@', 1)[1]}", f"{repository}:{tag}", immutable=tag not in ("latest", "edge", "php8.5"))


if __name__ == "__main__":
    operations = {"prepare": prepare, "images": images, "finalize": finalize, "mirror": mirror}
    try:
        if len(sys.argv) != 2 or sys.argv[1] not in operations:
            raise ValueError("Usage: release.py prepare|images|finalize|mirror")
        operations[sys.argv[1]]()
    except (ValueError, RuntimeError) as error:
        print(f"Release stopped: {error}", file=sys.stderr)
        sys.exit(1)
