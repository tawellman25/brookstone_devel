# BOS Service Request

Public service-request intake layer. A customer or prospect requests a service;
this produces a **controlled internal request record** (`service_request` ECK
entity). A real BOS Work Order is created **only after a human in the office
approves it**. Intake is never execution.

First bundle: `sprinkler_winterizing` (2026 postcard QR campaign). The layer is
built to accept additional bundles without re-architecture.

## Security boundary — anonymous CANNOT create entities via a permission

`service_request` entities are created **programmatically** in the public form's
submit handler (Gate 3), running as `uid = 0`. The anonymous role is granted **no
ECK create permission** on this entity. The access boundary is the route +
`captcha`/`recaptcha` + Drupal `flood` control, not an entity permission.

**Do not "fix" this by granting anonymous a create/edit/view permission.** That
would open direct entity manipulation and defeat the whole design. Anonymous
users cannot view, list, or reach a `service_request` canonical page.

## Property-disclosure invariant (Gate 3)

The public form must never display, accept, or let the submitter influence which
property a request binds to. Property matching runs entirely server-side, after
submission, invisibly. Ambiguous matches are resolved by the office at approval.
This is a security requirement, not a UX preference.

## Landing / setup (per environment — not cim)

ECK field instances silently skip on `cim`, so the entity is created by an
idempotent entity-API script rather than config import:

```
drush en bos_service_request                                  # installs settings + grants perm
drush php:script web/scripts/seed_service_request_status.php  # status vocab + terms (content; run per env)
drush php:script web/scripts/setup_service_request_entity.php # entity + bundle + fields + displays
drush cr
```

Status terms are **content** — their TIDs differ per environment. Never hardcode
a `service_request_status` TID; resolve by name (Gate 2 `ServiceRequestStatusResolver`).
WO status TIDs stay hardcoded (stable, system-wide).

## Instant kill switch

**Office-editable (2026-08-22):** Business Settings → **Public Service Requests**
(`/admin/config/business_settings`) — uncheck **Winterize Signup — Open**, or set
**Opens On / Closes On** dates. `WinterizeForm::signupOpen()` reads these
config_pages fields (`field_winterize_signup_open` / `_open_from` / `_open_until`)
first, falling back to `bos_service_request.settings` →
`bundles.sprinkler_winterizing.{signup_open,open_from,open_until}` when unset.
Either way, a closed form renders the "call the office" page (Gate 3), no deploy.

## Gate status

- **Gate 1 (this):** data model — entity, bundle, 23 fields, status vocab,
  permission, role grants. Done.
- Gate 2: services (eligibility, property matcher, converter, status resolver).
- Gate 3: public `/winterize` form.
- Gate 4: office admin view + Approve & Create Work Order.
- Gate 5: campaign reporting + QR asset.
- Gate 6: additional bundles (offseason).
