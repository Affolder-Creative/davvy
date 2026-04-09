# Mobile Sync + Review Queue Test Script (iOS + Android)

Use this runbook to validate real-device behavior for shared resources across mixed iOS/Android DAV clients.

## Goal

Confirm all of the following in one repeatable test pass:

1. Contact edits by an `Editor` are queued when review moderation is enabled.
2. `Approve` propagates to all subscribed devices.
3. `Deny` does not propagate to other users.
4. Calendar edits by an `Editor` apply directly (no review queue).

## Scope and Timing

- Typical duration: 30 to 45 minutes.
- Best run in one continuous session to reduce sync timing noise.
- Time zone for logs: use your local device time and keep it consistent.

## Prerequisites

1. Davvy instance is running and reachable over HTTPS from all devices.
2. Contact management is enabled.
3. Review queue moderation is enabled.
4. Owner sharing is enabled.
5. If 2FA is enabled for any test user, generate DAV app passwords and use those in clients.

Optional bootstrap helper:

- Run `php artisan app:qa:seed-mobile-review-queue --force` to seed or refresh this exact QA fixture.
- If you use DDEV, run `ddev php artisan app:qa:seed-mobile-review-queue --force`.
- Override defaults with command options (for example `--owner-email`, `--editor-email`, `--observer-email`, `--observer-permission`).

## Test Accounts

Create or reset these users before each full run:

1. `owner_admin` (admin role)
2. `editor_mobile` (regular role)
3. `observer_mobile` (regular role)

Recommended temporary password format for test runs:

- `OwnerTemp!234`
- `EditorTemp!234`
- `ObserverTemp!234`

Change these after testing if run outside a disposable environment.

## Baseline Resource Setup (Owner)

From `owner_admin` web session:

1. Create address book `rq-shared-contacts`.
2. Mark `rq-shared-contacts` as sharable.
3. Create calendar `rq-shared-calendar`.
4. Mark `rq-shared-calendar` as sharable.
5. Share `rq-shared-contacts` with:
- `editor_mobile` as `Editor`
- `observer_mobile` as `Read Only` (or `Editor` if preferred)
6. Share `rq-shared-calendar` with:
- `editor_mobile` as `Editor`
- `observer_mobile` as `Read Only` (or `Editor` if preferred)
7. Seed contact in `rq-shared-contacts`:
- Name: `RQ Test Person`
- Phone: `+1 317-555-0111`
- Email: `rq-test-person@example.test`
8. Seed event in `rq-shared-calendar`:
- Title: `RQ Calendar Control Event`
- Set to a near-future date/time for easy verification.

## Device Assignment

1. iOS device:
- Account: `owner_admin`
- Apps: Apple Contacts + Apple Calendar
2. Android device:
- Account: `editor_mobile`
- Apps: DAVx5 + a contacts app + a calendar app
3. Optional second device (iOS or Android):
- Account: `observer_mobile`

## Sync Preparation

Before each test case:

1. Open each client app once to force a manual refresh.
2. Confirm all devices currently show the same baseline values.
3. Confirm `Review Queue` is empty unless testing pending behavior.

## Test Case 1: Contact Update -> Approve

### Action

1. On `editor_mobile` device, edit `RQ Test Person` phone:
- From `+1 317-555-0111`
- To `+1 317-555-0222`
2. On `owner_admin` web UI, open `Review Queue`.
3. Confirm a pending request exists for that contact update.
4. Approve the request.
5. Force refresh on all devices.

### Expected

1. Queue entry transitions to `applied`.
2. `owner_admin`, `editor_mobile`, and `observer_mobile` all show phone `+1 317-555-0222`.
3. No conflicting duplicate contact is created.

## Test Case 2: Contact Update -> Deny

### Action

1. On `editor_mobile` device, edit `RQ Test Person` email:
- From `rq-test-person@example.test`
- To `rq-denied-change@example.test`
2. On `owner_admin` web UI, verify pending queue request.
3. Deny the request.
4. Force refresh on all devices.

### Expected

1. Queue entry transitions to `denied`.
2. `owner_admin` and `observer_mobile` keep previous approved email (not denied value).
3. `editor_mobile` behavior is client dependent and must be recorded:
- Option A: local unsynced edit remains and retries.
- Option B: local edit reverts to server value on refresh.

## Test Case 3: Contact Delete -> Approve (Recommended)

### Action

1. On `editor_mobile` device, delete `RQ Test Person`.
2. On `owner_admin` web UI, confirm pending delete request.
3. Approve the delete request.
4. Force refresh on all devices.

### Expected

1. Queue entry transitions to `applied`.
2. Contact is removed for all subscribed users/devices.
3. No orphan duplicate appears after refresh.

## Test Case 4: Calendar Edit Control (No Queue)

### Action

1. On `editor_mobile` device, edit `RQ Calendar Control Event`:
- Example title change to `RQ Calendar Control Event - Edited by Editor`
2. On `owner_admin` web UI, check `Review Queue`.
3. Force refresh on owner and observer devices.

### Expected

1. No contact-change queue entry is created for this calendar edit.
2. Event update appears on other subscribed devices.
3. Change sync is direct and does not require queue approval.

## Pass Criteria

A run is considered passing only if all conditions are true:

1. Contact update/delete by `editor_mobile` creates queue entries in cross-owner context.
2. Approved queue items propagate to all subscribed devices.
3. Denied queue items do not propagate denied values to other users.
4. Shared calendar event edits by editor sync without queue involvement.

## Failure Triage Notes

If a case fails, capture at least:

1. App + platform version
2. Exact field changed
3. Whether device showed sync error banner/toast
4. Queue state at time of failure (`pending`, `manual_merge_needed`, `applied`, `denied`)
5. Whether a manual re-sync or account re-add changed the outcome

## Capture Template

Use this template for each step:

- Timestamp
- Device + OS + app (`iOS Contacts`, `iOS Calendar`, `DAVx5`, Android app name)
- Action performed
- Queue state (`pending`, `applied`, `denied`, `none`)
- Final value on owner/editor/observer
- Notes (error text, retries, local stale state, revert behavior)

You can copy the CSV starter file at:

- `docs/templates/mobile-sync-review-queue-capture.csv`
