# BOS Entity — Zipcode

Entity Type ID:

* zipcodes

Bundle(s):

* zipcode

Storage:

* ECK entity type

---

## Purpose

The Zipcode entity represents **geographic routing and grouping** within BOS.

Zipcodes are used to:

* group Properties by service area
* drive URL structure and navigation
* support scheduling and routing logic
* store trip fee and routing metadata by area

Zipcodes are **operational geography**, not mailing data.

---

## Global Fields

System/base:

* id | integer | ID
* uuid | uuid | UUID
* langcode | language | Language
* type | entity_reference | Type
* title | string | Zipcode Title
* created | created | Authored on
* changed | changed | Changed
* default_langcode | boolean | Default translation
* path | path | URL alias

Geography references:

* field_state | entity_reference | State
* field_county | entity_reference | County
* field_city | entity_reference | City

Zipcode identity:

* field_zipcode | string | Zipcode
* field_zipcode_description | text_long | Zipcode Description

Routing & pricing:

* field_check_up_route_day | list_integer | Check Up Route Day
* field_trip_fee | decimal | Trip Fee

---

## Routing & Scheduling Fields

### field_check_up_route_day | Check Up Route Day

Purpose:

* Defines the **preferred route day** for irrigation check-up services within this Zipcode.

Rules:

* This field represents **routing preference**, not a hard constraint.
* Scheduling may override this value when needed.

---

## Trip Fee

### field_trip_fee | Trip Fee

Purpose:

* Stores the trip fee for this Zipcode/service area.

Rules:

* This is an area-level default; job-specific overrides may exist elsewhere.
* Trip fee here must not be treated as execution/billing history.

---

## Usage Rules

* Zipcodes must exist before Properties that reference them.
* Zipcode routing fields must not encode execution or completion data.
* Zipcodes may be reused across multiple years and contracts.

---

## Invariants (Non-Negotiable)

* Zipcodes represent routing and grouping only.
* Zipcodes must not store client-specific or contract-specific data.
* Routing fields must not imply work completion.
* Execution truth lives in Work Orders and Scheduling entities.

---

## Reporting Expectations

Zipcodes must support reporting by:

* number of properties
* scheduled workload by day
* service density by area

Zipcode-based reports must be used for planning, not billing.
