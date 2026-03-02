# BOS Module — estimate_notifications

Module machine name: `estimate_notifications`
Package: Brookstone
Dependencies: `drupal:user`, `estimate:estimate`

---

## Purpose

Sends an email notification to the assigned estimator when `field_assigned_to` is
populated on an `estimate_request` entity.

Covers both workflow entry points:
- Contract path: estimate_request auto-created with field_assigned_to already set.
- Manual path: office user assigns an estimator to an existing request.

---

## Responsibilities

- Watch `estimate_request` entity insert and update hooks (thin guards only).
- Delegate all logic to `EstimateRequestNotifier` service.
- Define `hook_mail` key `assignment_notification` for the Drupal mail system.

## Non-Responsibilities

- Does not send notifications for any other entity type.
- Does not handle re-assignment (value → different value); that is a future addition.
- Does not manage estimate_request status transitions.
- Does not send notifications to the client (client-facing notifications are a future gap).

---

## Service: `estimate_notifications.estimate_request_notifier`

Class: `Drupal\estimate_notifications\Service\EstimateRequestNotifier`

Constructor arguments: `@entity_type.manager`, `@logger.factory`, `@current_user`,
`@plugin.manager.mail`

### Trigger Rules

**On insert:**
- Fires if `field_assigned_to` is non-empty.

**On update:**
- Fires only if `field_assigned_to` changed from empty → non-empty.
- Does NOT fire on re-assignment (value → different value).
- Uses `$entity->original` to read the pre-save state.

### Email Details

| Property | Value |
|---|---|
| Module key | `estimate_notifications` |
| Mail key | `assignment_notification` |
| Recipient | `field_assigned_to` user's email address |
| Subject | `New Estimate Request Assigned — #[entity id]` |
| Format | HTML (mimemail compatible) + plain text fallback |
| Language | `en` |
| Send flag | TRUE (always send) |

### Email Body Fields

| Label | Source |
|---|---|
| Request # | entity id |
| Service | field_service → taxonomy_term.getName() |
| Property | field_property → properties entity label |
| Client | field_owner → user.getDisplayName() |
| Priority | field_priority.value → ucfirst() |
| Assigned by | current_user.getDisplayName() (web context) or '(system)' |
| Link | entity canonical URL (absolute) |

### Null Safety

Every entity load is null-checked. Missing or empty fields fall back to `'(unknown)'`.
A missing email address on the assigned user aborts the send with a warning log.
No exception is thrown; failures are logged and silently absorbed.

---

## hook_mail Implementation

Key: `assignment_notification`

Sets:
- `$message['subject']` from params.
- `$message['body']` as HTML line array (mimemail compatible).
- `$message['mimemail']['text']` as joined HTML string.
- `$message['plain']` as newline-joined plain text.
- `$message['headers']['Content-Type']` = `text/html; charset=UTF-8`.

Pattern follows `wo_sign_off` module (existing BOS convention).

---

## Logging

Channel: `estimate_notifications`

| Level | Event |
|---|---|
| info | Assignment notification sent (includes eid, to, display name) |
| error | Failed to send notification (includes eid, to) |
| warning | Assigned user not found |
| warning | Assigned user has no email address |

---

## Related Modules

- `estimate_contract_residential` — the primary source of auto-created estimate_requests;
  field_assigned_to is pre-populated from service_term.field_default_estimator, which
  immediately triggers the assignment notification on insert.
- `estimate` — defines the estimate_request entity type.
