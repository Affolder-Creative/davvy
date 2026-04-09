# Private Working Set Guide

This guide explains Private Working Set (PWS) in plain language.

## Mental Model

Treat PWS as a personal draft layer for shared contacts:
1. You edit shared contacts on your device.
2. Davvy keeps those edits in your private copy first.
3. You explicitly choose what to publish back to the shared source.

This helps prevent accidental global changes while still letting you sync private edits across your own devices.

## What PWS Is Good For

- Personal notes/photos that should stay private.
- Reviewing your own changes before sharing.
- Family/team environments where accidental edits are common.
- Gating even your own sharable books behind explicit promotion.

## Quick Setup

1. In Dashboard, open `Private Working Set`.
2. Enable `Use private working set for shared contacts`.
3. Select source address books to sync into your private set.
4. (Optional) enable `Hide selected source books in my DAV apps`.
5. Save settings.
6. Keep the panel in simple mode by default; open `Show advanced options` only when needed.

## Settings Reference

- `Use private working set for shared contacts`
  - Turns PWS on/off for your account.

- `Hide selected source books in my DAV apps`
  - Hides selected source books from DAV discovery for your account.
  - Reduces accidental direct writes to shared source books.

- `Also include my own sharable address books`
  - Includes your owned sharable books as PWS source candidates.
  - Useful if you want your own edits to follow draft -> promote flow.

- `Queue my own promotions for review` (admin only)
  - Routes admin self-promotions through review queue first.
  - Only effective when review queue moderation is enabled.

## Daily Usage Flow

1. Edit contacts on phone/desktop as normal.
2. Check `Suggested updates to share` for safe field-level diffs.
3. Use `Share this update` only for changes you want everyone to see.
4. Use `Hide suggestion` for items you want to ignore for now.
5. Confirm outcomes in `Last promotion results` (`Queued for review` vs `Applied`).

## Refresh vs Reset

- `Refresh from source books (keep my private edits)`
  - Pulls latest source data while preserving private override fields.

- `Reset from source books (replace my private edits)`
  - Pulls latest source data and overwrites private overrides with source values.

## Promotion and Review Behavior

- Non-admin users:
  - If moderation is enabled: promotions are queued for review.
  - If moderation is disabled: promotions apply directly (if permissions allow).

- Admin users:
  - Can choose self-review queue policy when moderation is enabled.
  - Can self-approve queued self-promotions.

- Read-only users:
  - Cannot promote/write to sources.

## Common Questions

### Why does it say “No eligible source address books available”?

Usually one of these:
- No address book has been shared to you.
- Shared address books are not marked sharable.
- You only have read-only access and expect writable suggestions.
- `Also include my own sharable address books` is off, and your own books are the only potential sources.

### Why did my update go to queue instead of applying?

- Review queue moderation is enabled, and your role/policy requires review.

### Why did my update apply immediately?

- Review queue moderation is disabled, or your admin policy is set to direct apply.
