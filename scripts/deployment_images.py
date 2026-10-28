"""Check deployment image configuration and anonymous GHCR downloads."""

import argparse
import hashlib
import json
import os
import subprocess
import sys
import tempfile
import time
import urllib.error
import urllib.parse
import urllib.request


def check_compose_image(compose, repository):
    expected = f"ghcr.io/{repository.lower()}:latest"
    actual = compose.get("services", {}).get("app", {}).get("image")
    if actual != expected:
        raise ValueError(f"Compose app image is {actual!r}; release destination requires {expected!r}.")
    return expected


def public_image_refs(image, event_name, tag, prerelease):
    if event_name != "release":
        return []
    if not tag:
        raise ValueError("A release tag is required for anonymous verification.")
    refs = [f"{image}:{tag}"]
    if not prerelease and tag != "latest":
        refs.append(f"{image}:latest")
    return refs


def anonymous_manifest(image_ref):
    repository, tag = image_ref.removeprefix("ghcr.io/").rsplit(":", 1)
    if not image_ref.startswith("ghcr.io/"):
        raise ValueError("Anonymous verification only supports ghcr.io images.")
    query = urllib.parse.urlencode({"service": "ghcr.io", "scope": f"repository:{repository}:pull"})
    # This token is issued anonymously. Never use GITHUB_TOKEN or Docker credentials.
    with urllib.request.urlopen(f"https://ghcr.io/token?{query}", timeout=30) as response:
        token = json.load(response)["token"]
    request = urllib.request.Request(
        f"https://ghcr.io/v2/{repository}/manifests/{urllib.parse.quote(tag, safe='')}",
        headers={
            "Authorization": f"Bearer {token}",
            "Accept": "application/vnd.oci.image.index.v1+json, application/vnd.docker.distribution.manifest.list.v2+json",
        },
    )
    with urllib.request.urlopen(request, timeout=30) as response:
        raw = response.read()
    return json.loads(raw), "sha256:" + hashlib.sha256(raw).hexdigest()


def check_manifest(manifest, digest, expected_digest):
    if digest != expected_digest:
        raise ValueError(f"Manifest digest {digest} does not match published digest {expected_digest}.")
    platforms = {
        (item.get("platform", {}).get("os"), item.get("platform", {}).get("architecture"))
        for item in manifest.get("manifests", [])
    }
    missing = {("linux", "amd64"), ("linux", "arm64")} - platforms
    if missing:
        raise ValueError("Missing platform(s): " + ", ".join("/".join(item) for item in sorted(missing)))


def verify_public_image(image_ref, expected_digest, platform, attempts=3, retry_delay=10):
    with tempfile.TemporaryDirectory(prefix="davvy-anonymous-docker-") as config:
        env = os.environ.copy()
        env["DOCKER_CONFIG"] = config
        env.pop("DOCKER_AUTH_CONFIG", None)
        for attempt in range(1, attempts + 1):
            try:
                manifest, digest = anonymous_manifest(image_ref)
                check_manifest(manifest, digest, expected_digest)
                subprocess.run(
                    ["docker", "--config", config, "pull", "--platform", platform, image_ref],
                    env=env, check=True, timeout=300,
                )
                # Catch a tag moving between inspection and the pull.
                manifest, digest = anonymous_manifest(image_ref)
                check_manifest(manifest, digest, expected_digest)
                print(f"Anonymous pull verified: {image_ref} ({platform}, {digest})", flush=True)
                return
            except (OSError, ValueError, KeyError, subprocess.SubprocessError) as error:
                # Do not print response bodies or tokens from registry authentication.
                if isinstance(error, urllib.error.HTTPError):
                    detail = f"registry HTTP {error.code}"
                else:
                    detail = str(error)
                print(f"Anonymous verification failed for {image_ref} ({platform}), attempt {attempt}/{attempts}: {detail}", file=sys.stderr)
                if attempt == attempts:
                    raise RuntimeError(f"Cannot anonymously pull the published image {image_ref}. Check package visibility, tags, and platforms.") from None
                time.sleep(retry_delay)


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    commands = parser.add_subparsers(dest="command", required=True)
    config = commands.add_parser("check-config")
    config.add_argument("--repository", required=True)
    public = commands.add_parser("verify-public")
    public.add_argument("--image", required=True)
    public.add_argument("--event-name", required=True)
    public.add_argument("--tag", required=True)
    public.add_argument("--prerelease", choices=["true", "false"], required=True)
    public.add_argument("--digest", required=True)
    public.add_argument("--platform", choices=["linux/amd64", "linux/arm64"], required=True)
    args = parser.parse_args()
    try:
        if args.command == "check-config":
            image = check_compose_image(json.load(sys.stdin), args.repository)
            print(f"Compose image matches release destination: {image}")
        else:
            for image in public_image_refs(args.image, args.event_name, args.tag, args.prerelease == "true"):
                verify_public_image(image, args.digest, args.platform)
    except (ValueError, RuntimeError) as error:
        parser.exit(1, f"{error}\n")


if __name__ == "__main__":
    main()
