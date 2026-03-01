# BOS – Contrib Modules

This document lists all contributed Drupal modules used by BOS.
Contrib usage is intentionally minimal.

Each module must have a justification.
Modules without justification are candidates for removal.

---

## Enabled Contrib Modules

This file tiers only **enabled contrib** modules from your Drush table output.
Custom modules (your BOS/Work Orders/etc) are intentionally excluded here.

How to use:
- Move module lines between tiers.
- If BOS depends on something in Tier 3, promote it to Tier 2 and we’ll design an exit plan later.

---

## Tier 1 — Allowed / Foundational (keep; low drama; core building blocks)

addanother
address
admin_toolbar
admin_toolbar_search
admin_toolbar_tools
config_ignore
config_pages
ctools
devel
devel_generate
dropzonejs
eck
entity
entity_reference_revisions
field_group
filefield_paths
flag
form_mode_control
fpa
inline_entity_form
key
migrate_plus
migrate_tools
module_filter
paragraphs
paragraphs_type_permissions
pathauto
profile
recaptcha
redirect
smart_date
smtp
stage_file_proxy
symfony_mailer
token
typed_data
upgrade_status
views_bulk_operations
views_data_export
xmlsitemap

---

## Tier 2 — Allowed With Justification (keep only if actively used; document why)

ai
ai_ckeditor
ai_agents
ai_provider_openai
allowed_formats
auto_entitylabel
better_exposed_filters
block_visibility_groups
calendar
calendar_datetime
calendar_view
calendar_view_demo
calendar_view_multiday
captcha
colorbox
comment_notify
composer_deploy
computed_field
computed_field_ui
conditional_fields
config_update
config_update_ui
csv_serialization
ctools_block
ctools_views
dropzonejs_eb_widget
eck_bundle_permissions
editablefields
entity_browser
epp
eva
feeds
feeds_log
feeds_tamper
field_expression
field_group_accordion
field_group_migrate
field_token_value
file_mdm
file_mdm_exif
file_mdm_font
flag_bookmark
flag_count
flag_follower
fullcalendar_view
geocoder
geocoder_address
geocoder_field
geocoder_geofield
geofield
geofield_map
geofield_map_extras
google_analytics
image_effects
image_style_warmer
imagefield_tokens
insert_view
job_scheduler
jquery_ui
jquery_ui_accordion
jquery_ui_autocomplete
jquery_ui_datepicker
jquery_ui_menu
jquery_ui_slider
jquery_ui_touch_punch
jquery_ui_touch_punch
linkicon
mailer_transport
mailsystem
media_bulk_upload
media_bulk_upload_dropzonejs
migrate_devel
migrate_file
migrate_source_csv
migrate_upgrade
modal_page
optional_end_date
page_manager
page_manager_ui
prepopulate
redirect_404
role_delegation
rules_flag
rules_token
tamper
taxonomy_menu
token_filter
token_views_filter
tr_rulez
twig_tweak
views_aggregator
views_aggregator_more_functions
views_autocomplete_filters
views_bootstrap
actions_permissions
views_entity_form_field
views_field_view
views_load_more
views_simple_math_field
views_slideshow
views_slideshow_cycle
views_templates
views_tree
views_url_path_arguments
weight
xls_serialization
xmlsitemap_custom
xmlsitemap_engines

---

## Tier 3 — Discouraged / Legacy / Risky (candidates to disable/remove unless proven required)

cer
date_popup
module_builder
modeler_api
rules
rules_examples

---

## “Fix These Now” Notes (small cleanup wins)

- Duplicate entry in your enabled list:
  - jquery_ui_touch_punch is listed twice above in Tier 2 — keep it once.
- field_group_accordion is marked Deprecated in your module list:
  - If you aren’t actively using the Accordion formatter, disable it.
- rules + tr_rulez:
  - If you keep them, they belong in Tier 2 with strict governance; otherwise move toward removal.

---

## Your Adjustment Section (edit these as you decide)

### Promote to Tier 1
- 

### Demote to Tier 3
- 

### Needs Verification (tell me if these are truly used in BOS)
- page_manager / page_manager_ui
- views_aggregator*
- file_mdm*
- feeds*
- computed_field*
- calendar* / fullcalendar_view
- rules* / tr_rulez