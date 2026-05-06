# BOS Entity — Users

Entity Type:
- user (Drupal core)

Related Entity Type:
- profile (Profile module)
  - customer_profile (Client Info)
  - teammate_profile (Teammate Profile)

---

## Purpose

Users are the BOS **account hub** for authentication, roles, and account-level flags.

Users are used for:
- login/authentication
- role-based access control
- global account flags (credit hold, do-not-schedule, consent)
- linkage to Profile records (client identity and teammate identity)

Operational business data must not be stored directly on the User entity unless it is truly account-level.

---

## Profiles Relationship (Authoritative)

Users link to Profiles via:
- customer_profile_profiles (entity_reference) — Client Info profiles
- teammate_profile_profiles (entity_reference) — Teammate Profile profiles

Profile bundles in use:
- customer_profile | Client Info
- teammate_profile | Teammate Profile

Rules:
- A user may have a Client Info profile, a Teammate Profile, or both (BOS decision).
- Profiles contain identity and business details; Users contain account + permissions.
- All “who is this person/company” logic should live in Profiles, not on User.

---

## User Fields (Global)

System/base:
- uid | integer | User ID
- uuid | uuid | UUID
- langcode | language | Language code
- preferred_langcode | language | Preferred language code
- preferred_admin_langcode | language | Preferred admin language code
- name | string | Name
- pass | password | Password
- mail | email | Email
- timezone | string | Timezone
- status | boolean | User status
- created | created | Created
- changed | changed | Changed
- access | timestamp | Last access
- login | timestamp | Last login
- init | email | Initial email
- roles | entity_reference | Roles
- default_langcode | boolean | Default translation
- path | path | URL alias
- user_picture | image | Picture

Profile refs:
- customer_profile_profiles | entity_reference | Client Info profiles
- teammate_profile_profiles | entity_reference | Teammate Profile profiles

Role delegation / role-change tooling:
- role_change | entity_reference | Roles

Imports:
- feeds_item | feeds_item | Feeds item

---

## User Account Flags (Operational Governance)

Consent/communication:
- field_ok_to_email | boolean | Receive emails from Brookstone Outdoors
- field_sms_consent | boolean | Receive text messages from Brookstone Outdoors System and Staff
- field_consent_updated | timestamp | Consent last updated
- field_paperless_delivery | boolean | Go paperless

Scheduling/billing controls:
- field_do_not_schedule | boolean | Do Not Schedule work for this account
- field_service_suspension_reason | string_long | Suspension Reason
- field_credit_hold | boolean | Credit Hold

QuickBooks linkage (account-level mapping):
- field_qb_refnum | string | QuickBooks Reference Number
- field_qb_list_id | string | QuickBooks List ID

Invariants:
- If field_do_not_schedule = TRUE, field_service_suspension_reason should be populated.
- Credit hold/do-not-schedule are account governance flags and must be respected by scheduling workflows.

---

## Profile Bundle: customer_profile (Client Info)

Purpose:
- Canonical client identity record (person or company).
- Holds contact identity, billing/portal flags, QuickBooks details, and contacts.

Key fields:
- field_client_type | entity_reference | Client Type
- field_client_status | list_string | Client Status
- field_company_name | string | Company Name
- field_first_name | string | First Name
- field_last_name | string | Last Name
- field_contact_email | email | General Company/Organization Email
- field_main_phone_number | telephone | Legacy Main Phone Number
- field_payment_terms | list_string | Payment Terms
- field_invoice_delivery_method | list_string | Invoice Delivery Method
- field_portal_allowed | boolean | Portal Allowed
- field_billing_allowed | boolean | Billing Allowed

Contacts:
- field_primary_contact_ref | entity_reference | Primary Contact
- field_contacts | entity_reference | Additional Contacts

QuickBooks:
- field_quickbooks_notes | text_long | QuickBooks Notes

Tax:
- field_tax_status | list_string | Tax Status
- field_tax_exempt_certificate | file | Tax Exempt Certificate

---

## Profile Bundle: teammate_profile (Teammate Profile)

Purpose:
- Canonical teammate identity record for staff/crew users.

Key fields:
- field_job_title | string | Job Title
- field_assigned_crew | entity_reference | Assigned Crew
- field_cell_number | telephone | Cell Number
- field_emergency_contacts | entity_reference | Emergency Contacts
- field_public_viewable | boolean | Public Viewable
- field_portal_allowed | boolean | Portal Allowed
- field_billing_allowed | boolean | Billing Allowed
- field_list_position | weight | Employee List Position

HR/personal:
- field_first_name, field_middle_name, field_last_name
- field_birthday, field_anniversary
- field_spouse_name, field_spouse_birthday
- field_shirt_size, field_hat_preference
- field_teammate_bio

QuickBooks:
- field_qb_account_number | string | QuickBooks Account Number
- field_qb_list_id | string | QuickBooks List ID

External System Identifiers:
- field_wex_driver_prompt_id | string (varchar 4) | WEX Driver Prompt ID

The 4-digit Driver Prompt ID (PIN) entered at the fuel pump in WEX. Used to match WEX fuel transactions to a teammate during import. Optional. Stored as 4-character zero-padded string (e.g., `0625`, not `625`). Validation enforced in the `user_teammate_profile` module's `hook_entity_presave`:

- Numeric only — non-digit values rejected with `EntityStorageException`
- Maximum 4 digits — over-length values rejected
- 1–4 digit input is auto-padded to 4 chars on save (`625` → `0625`)
- Unique across all teammate_profile records — error message names the conflicting teammate
- Watchdog warning logged on every rejection (channel: `user_teammate_profile`)

Form display group: `group_external_system_identifiers` (label "External System Identifiers"), weight 13, collapsible/collapsed by default. Sits as a separate group from the existing `group_office_administation` (which still holds the QuickBooks fields). The QB fields were intentionally NOT moved into the new group — that cleanup is deferred. New external-system identifiers (future WEX-style integrations) should be added to `group_external_system_identifiers` rather than `group_office_administation`.

View display:
- `default` — visible (terminal pair, weight after the QB fields)
- `teammate_internal` — hidden (matches the QB fields' treatment on the crew-facing view mode)

Indexed: `wex_prompt_id_idx` on the value column for fast lookup performance during fuel-import driver resolution.

Maintained by:
- `user_teammate_profile` module (validation hook, see above)
- `bos_wex_import` module (reads via `WexFuelImportService::resolveDriver()` to match WEX transactions to BOS users)

---

## Deletion / Archival

Default:
- Do not delete Users.

Preferred:
- Disable accounts via user.status = FALSE.
- Preserve Profiles for historical reference.

Rules:
- User disablement must not delete related Profiles or operational history.
- Client/teammate identity must remain queryable for historical contracts/work orders.

---

## Reporting Expectations

Users must support reporting by:
- role
- client vs teammate profile presence
- scheduling restrictions (do-not-schedule)
- billing restrictions (credit hold)
- contact preference flags (email/sms/paperless)

---

## Invariants (Non-Negotiable)

- User is the account/permissions hub; Profile is the identity/business details layer.
- Work Orders, Contracts, and Properties must not depend on mutable user-facing names.
- Account governance flags must be enforced by scheduling and billing workflows.
- `teammate_profile.field_wex_driver_prompt_id` is always 4-character zero-padded and unique across all teammate_profile records when populated. Validation is enforced at presave by `user_teammate_profile`; the import service relies on this canonical form for exact-match lookup.

## Roles & Permissions

User access and permissions in BOS are controlled by Drupal Roles.

Role definitions, intent, and governance rules are documented in:
- `user_roles.md`

Users may have multiple roles.
Profiles do not grant permissions; roles do.
