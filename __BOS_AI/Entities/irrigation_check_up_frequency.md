# Irrigation Check Up Frequency

## Purpose
Defines the allowable scheduling cadence for irrigation (sprinkler) system check-ups. This taxonomy standardizes how often check-ups may be planned and sold, ensuring consistency across contracts, services, and scheduling within BOS.

This taxonomy governs **planning and contract intent only**. It must not be used for execution logging, time tracking, or production reporting.

---

## Scope
- Applies to irrigation-related services that include system inspections or check-ups
- Used in Contract Sections, Services, and scheduling logic where frequency selection is required
- Read-only once a Contract is executed

---

## Governance Rules
- Terms define **how often** a check-up may occur, not how it is performed
- Exactly one frequency term must be selected where required
- Terms must not be interpreted as authorization for unlimited or ad-hoc work
- No execution data, timestamps, or completion counts may be derived from this taxonomy
- Changes to terms or definitions require administrative review

---

## Irrigation Season Definition
These definitions assume the standard regional irrigation season:

- **Season Start:** May 1
- **Season End:** October 15

All frequency terms are interpreted within this seasonal window.

---

## Canonical Term List

### Weekly (TID: 1113)
One irrigation system check-up performed **once per week** during the active irrigation season.

**Rules:**
- Maximum of one check-up per calendar week
- Not cumulative beyond the season window

---

### Biweekly (TID: 1114)
One irrigation system check-up performed **once every two weeks** during the active irrigation season.

**Rules:**
- Minimum spacing of approximately 14 days between check-ups
- Not interchangeable with Monthly

---

### Monthly (TID: 1115)
One irrigation system check-up performed **once per calendar month** during the active irrigation season.

**Rules:**
- One check-up per month maximum
- Months are evaluated within the March–October season only

---

### Mid Season (TID: 1116)
One irrigation system check-up performed at the defined midpoint of the active irrigation season.

**Operational Window:**
- Last week of June through first week of July

**Rules:**
- Exactly one check-up per season
- Must be scheduled within the defined window
- Must not be interpreted as monthly, biweekly, or ad-hoc

---

## Data Model Notes
- Entity type: taxonomy_term
- Vocabulary: irrigation_check_up_frequency
- Structure: flat (no hierarchy)
- No custom term fields
- Term IDs are considered stable once in use

---

## Enforcement Guidance
- Validate selection at save-time where required
- Prevent multiple frequency selections for the same planning context
- Do not retroactively reinterpret frequency terms after execution

---

## Change Control
- Term label or definition changes may impact pricing, scheduling, and client expectations
- Any modification requires administrative approval and documentation update
- Deprecated terms must remain for historical integrity
