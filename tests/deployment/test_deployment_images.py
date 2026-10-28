import hashlib
import io
import json
import os
from pathlib import Path
import subprocess
import sys
import unittest
from unittest.mock import patch
import urllib.error

sys.path.insert(0, str(Path(__file__).resolve().parents[2] / "scripts"))
import deployment_images as images


IMAGE = "ghcr.io/affolder-creative/davvy"
REF = IMAGE + ":v3.1.1"
DIGEST = "sha256:" + "a" * 64
MANIFEST = {"manifests": [
    {"platform": {"os": "linux", "architecture": "amd64"}},
    {"platform": {"os": "linux", "architecture": "arm64"}},
]}


class ConfigurationTest(unittest.TestCase):
    def test_compose_matches_lowercase_release_repository(self):
        config = {"services": {"app": {"image": IMAGE + ":latest"}}}
        self.assertEqual(images.check_compose_image(config, "Affolder-Creative/davvy"), IMAGE + ":latest")

    def test_old_namespace_reports_both_destinations(self):
        config = {"services": {"app": {"image": "ghcr.io/kaffolder7/davvy:latest"}}}
        with self.assertRaises(ValueError) as caught:
            images.check_compose_image(config, "Affolder-Creative/davvy")
        self.assertIn("ghcr.io/kaffolder7/davvy:latest", str(caught.exception))
        self.assertIn(IMAGE + ":latest", str(caught.exception))

    def test_missing_app_image_is_rejected(self):
        with self.assertRaises(ValueError):
            images.check_compose_image({"services": {}}, "Affolder-Creative/davvy")

    def test_stable_release_checks_version_and_latest(self):
        self.assertEqual(images.public_image_refs(IMAGE, "release", "v3.1.1", False), [REF, IMAGE + ":latest"])

    def test_prerelease_does_not_check_latest(self):
        self.assertEqual(images.public_image_refs(IMAGE, "release", "v3.1.1-rc1", True), [IMAGE + ":v3.1.1-rc1"])

    def test_dispatch_does_not_require_public_dry_run_package(self):
        self.assertEqual(images.public_image_refs(IMAGE + "-dry-run", "workflow_dispatch", "v0.0.0", False), [])

    def test_release_requires_a_tag(self):
        with self.assertRaises(ValueError):
            images.public_image_refs(IMAGE, "release", "", False)


class ManifestTest(unittest.TestCase):
    def test_complete_manifest_accepts_attestation_entries(self):
        manifest = {"manifests": MANIFEST["manifests"] + [{"platform": {"os": "unknown", "architecture": "unknown"}}]}
        images.check_manifest(manifest, DIGEST, DIGEST)

    def test_missing_architecture_is_rejected(self):
        with self.assertRaisesRegex(ValueError, "linux/arm64"):
            images.check_manifest({"manifests": MANIFEST["manifests"][:1]}, DIGEST, DIGEST)

    def test_wrong_release_digest_is_rejected(self):
        with self.assertRaisesRegex(ValueError, "does not match"):
            images.check_manifest(MANIFEST, "sha256:other", DIGEST)

    @patch.dict(os.environ, {"GITHUB_TOKEN": "must-not-be-used"})
    @patch.object(images.urllib.request, "urlopen")
    def test_registry_request_uses_only_anonymously_issued_token(self, urlopen):
        raw = json.dumps(MANIFEST).encode()
        urlopen.side_effect = [io.BytesIO(b'{"token":"anonymous-token"}'), io.BytesIO(raw)]
        manifest, digest = images.anonymous_manifest(REF)
        self.assertEqual(manifest, MANIFEST)
        self.assertEqual(digest, "sha256:" + hashlib.sha256(raw).hexdigest())
        token_url = urlopen.call_args_list[0].args[0]
        self.assertIn("scope=repository%3Aaffolder-creative%2Fdavvy%3Apull", token_url)
        request = urlopen.call_args_list[1].args[0]
        self.assertEqual(request.get_header("Authorization"), "Bearer anonymous-token")
        self.assertEqual(request.full_url, "https://ghcr.io/v2/affolder-creative/davvy/manifests/v3.1.1")


class AnonymousPullTest(unittest.TestCase):
    def setUp(self):
        self.manifest = patch.object(images, "anonymous_manifest", return_value=(MANIFEST, DIGEST)).start()
        self.run = patch.object(images.subprocess, "run").start()
        self.sleep = patch.object(images.time, "sleep").start()
        patch("sys.stdout", new_callable=io.StringIO).start()
        self.stderr = patch("sys.stderr", new_callable=io.StringIO).start()
        self.addCleanup(patch.stopall)

    @patch.dict(os.environ, {"DOCKER_CONFIG": "/existing/credentials", "DOCKER_AUTH_CONFIG": "private-auth"})
    def test_pull_has_empty_credentials_and_removes_temporary_config(self):
        configs = []

        def inspect_pull(command, **kwargs):
            config = Path(command[2])
            configs.append(config)
            self.assertEqual(list(config.iterdir()), [])
            self.assertEqual(command, ["docker", "--config", str(config), "pull", "--platform", "linux/arm64", REF])
            self.assertEqual(kwargs["env"]["DOCKER_CONFIG"], str(config))
            self.assertNotIn("DOCKER_AUTH_CONFIG", kwargs["env"])
            self.assertTrue(kwargs["check"])
            self.assertEqual(kwargs["timeout"], 300)

        self.run.side_effect = inspect_pull
        images.verify_public_image(REF, DIGEST, "linux/arm64")
        self.assertFalse(configs[0].exists())
        self.assertEqual(self.manifest.call_count, 2)
        self.sleep.assert_not_called()

    def test_private_or_missing_package_fails_without_leaking_body(self):
        for status in (401, 403, 404):
            with self.subTest(status=status):
                self.manifest.side_effect = urllib.error.HTTPError(REF, status, "denied", {}, io.BytesIO(b"private-token"))
                with self.assertRaisesRegex(RuntimeError, "Cannot anonymously pull"):
                    images.verify_public_image(REF, DIGEST, "linux/amd64", attempts=1)
                self.assertIn(f"registry HTTP {status}", self.stderr.getvalue())
                self.assertNotIn("private-token", self.stderr.getvalue())
        self.run.assert_not_called()

    def test_registry_propagation_retries_then_succeeds(self):
        self.manifest.side_effect = [urllib.error.URLError("temporary failure"), (MANIFEST, DIGEST), (MANIFEST, DIGEST)]
        images.verify_public_image(REF, DIGEST, "linux/amd64")
        self.sleep.assert_called_once_with(10)
        self.run.assert_called_once()

    def test_blob_download_failure_exhausts_bounded_retries(self):
        self.run.side_effect = subprocess.CalledProcessError(1, "docker pull")
        with self.assertRaises(RuntimeError):
            images.verify_public_image(REF, DIGEST, "linux/amd64")
        self.assertEqual(self.run.call_count, 3)
        self.assertEqual(self.sleep.call_count, 2)

    def test_wrong_digest_prevents_pull(self):
        self.manifest.return_value = (MANIFEST, "sha256:wrong")
        with self.assertRaises(RuntimeError):
            images.verify_public_image(REF, DIGEST, "linux/amd64", attempts=1)
        self.run.assert_not_called()

    def test_tag_moving_during_pull_fails_verification(self):
        self.manifest.side_effect = [(MANIFEST, DIGEST), (MANIFEST, "sha256:moved")]
        with self.assertRaises(RuntimeError):
            images.verify_public_image(REF, DIGEST, "linux/amd64", attempts=1)
        self.run.assert_called_once()


if __name__ == "__main__":
    unittest.main()
