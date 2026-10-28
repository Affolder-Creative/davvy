# Production Release Checklist (Core) ✅

Use this checklist for every production release, regardless of platform.

## Container publication and anonymous access

The production image is `ghcr.io/affolder-creative/davvy`. Before the first organization release, a package administrator must verify the package is Public and grant Actions write access to `Affolder-Creative/davvy`. If the package does not exist yet, let the release workflow create it, set it to Public, then rerun the failed jobs. See [GitHub package access and visibility](https://docs.github.com/en/packages/learn-github-packages/configuring-a-packages-access-control-and-visibility).

For the namespace migration and subsequent releases:

1. Merge the deployment fix and publish a new patch release from the merged commit. Keep existing release tags unchanged. Manual workflow dispatch is a dry run that publishes to `-dry-run`, not the production package.
2. Confirm the deployment configuration check passes. It compares the Compose app image with the lowercase repository-derived release destination before container builds.
3. Confirm both anonymous-pull jobs pass. They check the release tag and, for stable releases, `latest`; verify the published digest and both architectures; and download the image on native amd64 and arm64 runners with empty Docker credentials. Prereleases do not check `latest`, and dry runs do not require public package visibility.
4. If anonymous verification fails, inspect package visibility, tag publication, and the reported digest/platform mismatch. Repair the package setting or publication problem and rerun failed jobs. The optional Coolify webhook is blocked until both anonymous-pull jobs pass. A failure does not undo already published tags.
5. On a clean host, deploy the documented Compose configuration with a disposable database and volumes. Confirm `/up` returns HTTP 200. Use a separate Compose project for this check, preserving existing installations.
6. Tell existing users to update saved image references using the [migration instructions](./deployment.md#container-image-and-migration). Retain existing data volumes and configuration; historical images are not deleted or republished by this fix.

Do not consider issue #133 resolved until the production package is anonymously downloadable and the fresh deployment check succeeds.

## 1. Common App Environment Variables

Set these in your app service environment.

| Variable | Value | Notes |
| --- | --- | --- |
| `APP_ENV` | `production` | Required |
| `APP_DEBUG` | `false` | Required |
| `APP_KEY` | `base64:<generated-key>` | Required; generate once and keep stable across replicas |
| `APP_URL` | `https://<your-domain>` | Required |
| `APP_LOCALE` | `en` | Default application locale |
| `APP_FALLBACK_LOCALE` | `en` | Fallback locale for unsupported request/user locales |
| `APP_SUPPORTED_LOCALES` | `de,en,es,fr,it,ja,pt,zh` | Comma-separated supported locale codes |
| `DB_CONNECTION` | `pgsql` | Required |
| `DB_HOST` | platform-specific | Required |
| `DB_PORT` | `5432` | Required unless your PostgreSQL uses a different port |
| `DB_DATABASE` | platform-specific | Required |
| `DB_USERNAME` | platform-specific | Required |
| `DB_PASSWORD` | platform-specific | Required |
| `SESSION_DRIVER` | `database` | Recommended |
| `AUTH_REMEMBER_DURATION_MINUTES` | `43200` | Trusted-device login duration for PWA/browser sessions with "Stay signed in" enabled |
| `SESSION_SECURE_COOKIE` | `true` | Required in production |
| `SESSION_HTTP_ONLY` | `true` | Recommended |
| `SESSION_SAME_SITE` | `lax` | Recommended |
| `MAIL_MAILER` | `smtp` (or other non-`log` mailer) | Required when onboarding emails should be delivered |
| `MAIL_HOST` | platform-specific | Required for SMTP delivery |
| `MAIL_PORT` | platform-specific | Required for SMTP delivery |
| `MAIL_USERNAME` | platform-specific | Required for authenticated SMTP providers |
| `MAIL_PASSWORD` | platform-specific | Required for authenticated SMTP providers |
| `MAIL_ENCRYPTION` | provider-specific (`tls`/`ssl`) | Recommended for SMTP |
| `MAIL_FROM_ADDRESS` | `noreply@<your-domain>` | Recommended sender address for onboarding emails |
| `MAIL_FROM_NAME` | `Davvy` (or your app name) | Sender display name for onboarding emails |
| `CORS_ALLOWED_ORIGINS` | *(empty)* | Recommended for same-origin deployments; set explicit origins only when needed |
| `CORS_SUPPORTS_CREDENTIALS` | `false` | Recommended; if `true`, do not use wildcard origins |
| `CACHE_STORE` | `database` | Recommended |
| `QUEUE_CONNECTION` | `database` | Recommended |
| `TRUSTED_PROXIES` | `*` or explicit proxies | Recommended behind reverse proxy |
| `ENABLE_WEB_PUSH_NOTIFICATIONS` | `false` | Set `true` only after configuring VAPID keys and queue workers |
| `VAPID_PUBLIC_KEY` | _(empty)_ | Required when WebPush is enabled |
| `VAPID_PRIVATE_KEY` | _(empty)_ | Required when WebPush is enabled; store as a secret |
| `VAPID_SUBJECT` | `mailto:admin@<your-domain>` or `https://<your-domain>` | Required for Safari/iOS WebPush |
| `ENABLE_PUBLIC_REGISTRATION` | `false` | Env default only; setting is primarily managed via app settings/admin toggle |
| `ONBOARDING_REQUIRE_PUBLIC_EMAIL_VERIFICATION` | `true` | Require verification before sign-in for public registration users |
| `ONBOARDING_SEND_EMAILS` | `true` (with working mailer) | Sends onboarding verification and invitation emails |
| `ONBOARDING_INVITE_EXPIRES_HOURS` | `72` | Admin invite token TTL in hours |
| `ONBOARDING_VERIFICATION_EXPIRES_HOURS` | `24` | Public registration verification token TTL in hours |
| `ONBOARDING_EXPOSE_LINKS_WITHOUT_MAILER` | `false` (production recommendation) | When `true`, fallback onboarding URLs may be returned by API when mail delivery is unavailable |
| `ENABLE_OWNER_SHARE_MANAGEMENT` | `true` | Product default |
| `ENABLE_DAV_COMPATIBILITY_MODE` | `false` | Secure default (strict mode) |
| `ENABLE_CONTACT_MANAGEMENT` | `false` | Secure default for gated contacts UI/API |
| `ENABLE_CONTACT_CHANGE_MODERATION` | `false` | Default-off moderation mode for collaborative deployments |
| `CONTACT_CHANGE_REQUEST_RETENTION_DAYS` | `90` | Queue history purge horizon for applied/denied requests |
| `ENABLE_TWO_FACTOR_ENFORCEMENT` | `false` | Admin UI can toggle mandatory 2FA rollout |
| `TWO_FACTOR_GRACE_PERIOD_DAYS` | `14` | Days before mandatory 2FA lock applies for non-enrolled users |
| `ENABLE_AUTOMATED_BACKUPS` | `false` | Enable scheduled local/S3 backup rotation |
| `BACKUPS_LOCAL_ENABLED` | `true` | Keep local backup destination enabled unless intentionally remote-only |
| `BACKUPS_LOCAL_PATH` | `/var/www/html/storage/app/backups` | Local backup root path in container |
| `BACKUPS_S3_ENABLED` | `false` | Enable when remote S3 backup upload is desired |
| `BACKUPS_S3_DISK` | `s3` | Laravel filesystem disk used for remote backups |
| `BACKUPS_S3_PREFIX` | `davvy-backups` | S3 key prefix for snapshots |
| `BACKUPS_SCHEDULE_TIMES` | `02:30` | One or more comma-separated `HH:MM` backup windows |
| `BACKUPS_TIMEZONE` | `UTC` | Timezone used for backup schedule + rotation boundaries |
| `BACKUPS_WEEKLY_DAY` | `0` | Weekly anchor day (`0=Sunday`) |
| `BACKUPS_MONTHLY_DAY` | `1` | Monthly anchor day |
| `BACKUPS_YEARLY_MONTH` | `1` | Yearly anchor month |
| `BACKUPS_YEARLY_DAY` | `1` | Yearly anchor day |
| `BACKUPS_RETENTION_DAILY` | `7` | Daily snapshots retained |
| `BACKUPS_RETENTION_WEEKLY` | `4` | Weekly snapshots retained |
| `BACKUPS_RETENTION_MONTHLY` | `12` | Monthly snapshots retained |
| `BACKUPS_RETENTION_YEARLY` | `3` | Yearly snapshots retained |
| `RUN_SCHEDULER` | `true` | Runs Laravel scheduler worker in container |
| `RUN_QUEUE_WORKER` | `true` | Runs Laravel queue worker in container |
| `DAV_LOG_CLIENT_TRAFFIC` | `false` | Optional targeted DAV traffic debug logging |
| `RUN_DB_MIGRATIONS` | `true` | Recommended; set `false` only if migrations run out-of-band |
| `RUN_DB_SEED` | `false` | Secure default; opt-in only for bootstrap |
| `DEFAULT_ADMIN_EMAIL` | *(empty)* | Keep empty unless `RUN_DB_SEED=true` for one-time bootstrap |
| `DEFAULT_ADMIN_PASSWORD` | *(empty)* | Keep empty unless `RUN_DB_SEED=true` for one-time bootstrap |

## 2. Optional One-Time Admin Bootstrap

If you want automatic admin creation via seeding:

1. Set:
   - `RUN_DB_SEED=true`
   - `DEFAULT_ADMIN_EMAIL=<admin email>`
   - `DEFAULT_ADMIN_PASSWORD=<strong random password>`
2. Deploy once.
3. Confirm admin login works.
4. Set `RUN_DB_SEED=false` and clear both `DEFAULT_ADMIN_EMAIL` and `DEFAULT_ADMIN_PASSWORD`.

## 3. Deploy Sanity Checks

1. Deploy succeeds and container starts.
2. Logs include `Preflight checks passed.` from `php artisan app:preflight`.
3. Health endpoint returns `200`:
   - `GET /up`
4. App routes respond:
   - `GET /`
   - `GET /.well-known/caldav` -> `301` to `/dav`
   - `GET /.well-known/carddav` -> `301` to `/dav`
5. Login works for an admin account.
6. DAV endpoint is reachable:
   - `/dav`
7. DB migrations are applied successfully (no pending migration errors).
8. If replicas > 1, logs show advisory-lock serialization during startup and no migration race errors.
9. If automated backups are enabled, confirm scheduler is running (`RUN_SCHEDULER=true` or external `schedule:run`).
10. Verify localization:
    - `GET /api/public/config` returns `locale`, `supported_locales`, and `fallback_locale`
    - Profile language switch updates UI language and persists after reload

## 4. Post-Deploy Security Quick Check

1. Verify `APP_DEBUG=false`.
2. Verify `SESSION_SECURE_COOKIE=true`.
3. Verify `ENABLE_DAV_COMPATIBILITY_MODE=false` unless needed for legacy clients.
4. Verify no demo/default credentials are present.
5. Ensure your custom domain uses HTTPS.

## 5. Platform Supplements

- [Railway Supplement](./release-checklist.md)
- [Coolify Supplement](./release-checklist-coolify.md)
