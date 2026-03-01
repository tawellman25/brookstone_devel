# BOS Module Tier Policy

This file defines which modules are allowed in BOS
and under what conditions.

---

## Tier 1 — Allowed / Foundational

These modules are safe, expected, and encouraged.

[address]
[admin_toolbar*]
[config_ignore]
[config_pages]
[ctools]
[devel*]
[eck]
[entity]
[entity_reference_revisions]
[filefield_paths]
[inline_entity_form]
[key]
[migrate_plus]
[migrate_tools]
[module_filter]
[paragraphs]
[pathauto]
[profile]
[recaptcha]
[redirect]
[s3fs]
[smart_date]
[smtp]
[stage_file_proxy*]
[symfony_mailer]
[token]
[upgrade_status]
[views]
[views_bulk_operations]
[views_data_export]
[xmlsitemap]

---

## Tier 2 — Allowed With Justification

Allowed only when needed and documented.

[calendar*]
[computed_field*]
[conditional_fields]
[feeds*]
[file_mdm*]
[fullcalendar_view]
[google_analytics]
[jquery_ui*]
[migrate_*]
[page_manager*]
[rules_flag]
[rules_token]
[twig_tweak]
[views_* extensions]

---

## Tier 3 — Discouraged / Legacy / Risky

Avoid. Existing use should have an exit plan.

[cer]
[config_update]
[modeler_api]
[rules]
[rules_examples]
[tr_rulez]
[views_aggregator*]
