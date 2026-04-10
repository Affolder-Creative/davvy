# User Guide

This guide covers day-to-day Davvy usage in the web UI.

## 1. Sign In

- Open the app URL and sign in with email/password.
- If your account has 2FA enabled, complete the second step using authenticator code or backup code.
- If public registration is enabled, a registration link appears on the login page.
- If registration approval is required, newly registered users must be approved by an admin before they can sign in.
- Approved users automatically receive:
  - one default calendar
  - one default address book (`contacts`)

## 2. Dashboard

The dashboard is the main resource management page.

You can:
- View your DAV endpoint and principal info
- Create calendars and address books
- Rename resources (display name only; URI stays stable)
- Mark owned resources as sharable
- Export all calendars/address books or individual collections
- View resources shared with you and their permission badges

### Sharing Your Resources

If sharing is enabled for your role:
1. Select resource type (`calendar` or `address_book`)
2. Select a sharable owned resource
3. Select target user
4. Choose permission
5. Save share

Permission levels:
- `General` (`read_only`)
- `Editor` (`editor`, full edit without collection delete)
- `Admin` (`admin`, full edit + collection delete)

### Milestone Calendars (Address Books)

For each owned address book, you can configure:
- Birthday calendar on/off
- Anniversary calendar on/off
- Optional custom calendar names

Generated calendars are read from contact data and auto-updated.
- Upcoming milestone horizons are automatically rolled forward daily at `00:15` in `APP_TIMEZONE`/`app.timezone` by scheduled command `app:milestones:sync`.
- Keep a scheduler running (`RUN_SCHEDULER=true`) or run `php artisan schedule:run` externally every minute.

Birthday generation behavior:
- `MILESTONE_BIRTHDAY_INCLUDE_LAST_NAME` controls whether birthday titles include last names (default is `true`, so `Ben Williams`; set `false` for `Ben`).
- `MILESTONE_BIRTHDAY_PRIORITIZE_NICKNAME` controls whether birthday titles prefer nickname over first name (default is `true`, so `Jon Doe`; set `false` for `Jonathan Doe`).

Anniversary generation behavior:
- Contacts with the same anniversary month/day can be combined into one event when they are mutually linked with spouse-like related-name labels (`spouse`, `partner`, `husband`, `wife`, including custom labels containing those terms).
- `Head of Household` determines name order in the combined anniversary title.
- `MILESTONE_ANNIVERSARY_PAIR_INCLUDE_LAST_NAME` controls whether combined titles include the shared last name (default is `false`, so `John & Jane`; set `true` for `John & Jane Doe`).
- `MILESTONE_ANNIVERSARY_PRIORITIZE_NICKNAME` controls whether anniversary titles prefer nickname over first name (default is `true`, so `Jon & Jane`; set `false` for `Jonathan & Jane`).
- If either contact has an anniversary year, the combined title includes an ordinal (for example, `13th`). If neither contact has a year, the title omits the ordinal.
- Contacts that do not match a mutual pair still generate individual anniversary events.

### Private Working Set (Shared Contacts)

Think of this as a personal draft layer for shared contacts:
- You edit contacts on your own devices.
- Davvy keeps those edits in your private copy first.
- You explicitly choose what to share back to shared address books.

What it does:
- Creates/uses a private address book for your account
- Copies selected source address books into linked private cards
- Keeps local-only overrides (for example personal notes/photos) in your private set until you promote
- Optionally includes your own sharable address books as source books too

Recommended flow:
1. Enable Private Working Set and select source address books.
2. Edit contacts from your devices as usual.
3. In dashboard, use `Suggested updates to share` or `Private cards linked to shared contacts`.
4. Click `Share this update` only for changes you want everyone to receive.
5. Use `Show advanced options` only when you need sync-policy controls.

Key settings in plain language:
- `Use private working set for shared contacts`: turns this feature on/off.
- `Hide selected source books in my DAV apps`: reduces accidental direct edits in source books.
- `Also include my own sharable address books`: lets your own books use the same draft/promote model.
- `Queue my own promotions for review` (admin only): routes your own promotions through Review Queue first.
- `Last promotion results`: shows your most recent queued/applied promotion outcomes for quick confirmation.

Action buttons:
- `Refresh from source books (keep my private edits)`: updates from source but preserves your private overrides.
- `Reset from source books (replace my private edits)`: updates from source and replaces private overrides.
- `Share this update`: sends one linked private card back to source.
  - with moderation enabled: queued for review
  - with moderation disabled: applied immediately

Moderation behavior:
- Non-admin users:
  - if moderation is enabled, promotions are always queued (including self-owned sharable sources)
  - if moderation is disabled, promotions apply directly when permissions allow
- Admin users:
  - can choose whether self-promotions queue (when moderation is enabled)
  - can self-approve queued self-promotions

For a focused walkthrough and FAQ, see [Private Working Set Guide](./private-working-set.md).

### Apple Contacts Compatibility

Optional feature for Apple ecosystem visibility:
- Mirrors selected source address books into your default `contacts` address book
- Source books can be owned or shared books you can access
- You can enable/disable and choose mirror sources in dashboard

## 3. Contacts (When Enabled)

If admin enables contact management, the `Contacts` tab appears.

You can:
- Search/filter contacts
- Create/update/delete managed contacts
- Assign contacts to one or more writable address books
- Edit structured fields (phones, emails, addresses, dates, related names, IM)
- Opt contact out of milestone calendar generation

Validation rules:
- At least one of `First Name`, `Last Name`, or `Company`
- At least one assigned writable address book

Queue behavior:
- If `Review Queue` is enabled by admin, some changes (especially cross-owner contexts) may be queued for approval
- If `Review Queue` is disabled, cross-owner changes apply immediately (latest write wins)
- UI shows queued notice when server returns `202`
- Private working-set promotions follow the same moderation rules (`202` queued vs immediate apply)

## 4. Review Queue

The `Review Queue` tab is optional and appears only when admins enable it.

Recommended usage:
- Personal/single-user deployments: keep disabled (default)
- Family/team deployments: enable when you need owner/admin review before applying cross-owner contact changes

When enabled, the tab is for approving/denying queued contact changes.

Capabilities:
- Filter by status/operation
- Search by requester/contact
- Approve or deny individual requests
- Bulk approve/deny visible actionable groups
- For merge conflicts, use "Edit & Approve" to resolve payload and assignment JSON

Status values include:
- `pending`
- `approved`
- `manual_merge_needed`
- `applied`
- `denied`

## 5. Profile

The `Profile` page shows current account details and security controls.

Important:
- Password updates affect both web login and DAV clients.
- Update saved client credentials after password change.
- You can choose your preferred language in Profile -> Language.
- Current locale options in the web UI are Chinese, English, French, German, Italian, Japanese, Portuguese, and Spanish.
- You can enable/disable two-factor authentication (TOTP) and regenerate backup codes.
- You can create/revoke DAV app passwords for clients like iOS Calendar/Contacts, DAVx5, or Thunderbird.
- App passwords are shown once at creation time and are required for DAV when 2FA is enabled.

## 6. Admin Control Center (Admin Users)

Admins can:
- Toggle feature flags:
  - public registration
  - require registration approval
  - owner sharing
  - DAV compatibility mode
  - contact management
  - review queue moderation (off by default)
  - 2FA enforcement (with grace period rollout)
- Create users with role selection
- Reset a user's 2FA enrollment and revoke their DAV app passwords (emergency recovery)
- Delete users with typed admin-email confirmation
- Optionally transfer ownership of calendars, address books, and contacts to another user before deleting an account
- Reset a user's 2FA enrollment and revoke their DAV app passwords (emergency recovery)
- Manage cross-user share assignments globally
- Set contact change queue history retention (days)
- Configure automated backups:
  - enable/disable backup automation
  - configure local and optional S3 destinations
  - define one or more daily backup windows (`HH:MM`)
  - tune retention tiers (`daily`, `weekly`, `monthly`, `yearly`)
- Run backups on demand from Admin Control Center
- Restore backups from Admin Control Center using ZIP import (`merge`, `replace`, and optional dry-run)
- Manual backup reruns in the same day/week/month/year replace that period snapshot (no duplicate period artifacts)
- Purge generated milestone calendars (destructive maintenance action)

Important guards:
- Admins cannot disable review queue moderation while unresolved queue requests still exist; requests must be approved/denied first.
- Admins cannot delete their own account.
- Admins cannot delete the last remaining admin account.
- Ownership transfer is blocked if contact UID conflicts exist between source and target owners.

## 7. DAV Client Connection Quick Values

From the dashboard:
- DAV endpoint: `https://<host>/dav`
- Principal: `principals/<your-user-id>`

See detailed client setup: [DAV Client Setup](./clients.md)
