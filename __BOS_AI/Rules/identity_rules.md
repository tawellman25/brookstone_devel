# BOS Identity Rules (Authoritative)

This document defines how **Users** and **Profiles** are used in BOS to represent
clients, teammates, and hybrid identities.

Scope:
- user (Drupal core)
- profile (Profile module)
  - customer_profile (Client Info)
  - teammate_profile (Teammate Profile)

---

## Core Identity Model

BOS separates **account access** from **identity and business data**.

- **User** = authentication, roles, permissions, account-level flags
- **Profile** = identity, contact, and business information

A User may have:
- no Profiles (system/admin accounts)
- a customer_profile
- a teammate_profile
- both profiles (hybrid role)

Profiles must never replace the User entity for access control.

---

## Profile Types & Meaning

### customer_profile (Client Info)

Represents:
- a client (individual or organization)
- the billing and contractual identity
- the authoritative source for:
  - contact information
  - billing rules
  - QuickBooks linkage
  - portal access

Rules:
- A customer_profile must be associated with a User account.
- A customer_profile may exist without active Work Orders.
- Client identity must persist even if the User account is disabled.

---

### teammate_profile (Teammate Profile)

Represents:
- an internal staff member
- crew, office, or management personnel

Rules:
- A teammate_profile must be associated with a User account.
- A teammate_profile controls:
  - crew assignment
  - internal contact information
  - visibility to crews and staff
- Teammate identity must persist for historical Work Orders and reporting.

---

## User → Profile Assignment Rules

### Client User
A User is considered a **Client User** if:
- it has a customer_profile
- regardless of whether it has a teammate_profile

### Teammate User
A User is considered a **Teammate User** if:
- it has a teammate_profile
- regardless of whether it has a customer_profile

### Hybrid User
A User may be both Client and Teammate if:
- it has both profile types
- roles and permissions must explicitly allow this

Hybrid users must be intentional and documented.

---

## System / Admin Users

System or admin-only users:
- may have no Profiles
- must not be treated as clients or teammates
- must not be used as owners for Contracts, Work Orders, or Properties

---

## Ownership & Reference Rules

- Contracts must reference:
  - customer_profile (client identity)
  - not the User entity directly

- Work Orders may reference:
  - teammate_profile (who performed the work)
  - or User only for audit/logging purposes

- Properties must reference:
  - customer_profile for ownership/contact
  - not User directly

User references are acceptable only for:
- authentication
- permissions
- audit trails
- notifications

---

## Account Governance Flags (User-Level)

The following flags live on the **User**, not Profiles:

- field_credit_hold
- field_do_not_schedule
- field_service_suspension_reason
- communication consent flags (email/SMS/paperless)

Rules:
- These flags apply to the entire account.
- Scheduling, billing, and automation must respect these flags.
- Profiles must not duplicate or override these flags.

---

## Disablement & Archival

User disablement:
- user.status = FALSE
- preserves Profiles and historical data
- blocks login and new activity

Profile archival:
- Profiles should remain active unless explicitly retired.
- Historical Profiles must remain queryable.

Rules:
- Never hard-delete Users or Profiles tied to Contracts or Work Orders.
- Historical integrity takes precedence over cleanup.

---

## Invariants (Non-Negotiable)

- User = access & governance
- Profile = identity & business data
- Contracts and Properties reference Profiles, not Users
- Work Orders reference Profiles for execution, Users for audit
- Identity must persist for historical accuracy
