-- ============================================================
-- BOS Calendar Setup — Run via: ddev drush sqlc < setup.sql
-- Run AFTER: drush cim (which imports the field_color field)
-- ============================================================

-- ── 1. Populate department field_color values ────────────────

INSERT INTO department__field_color
  (bundle, deleted, entity_id, revision_id, langcode, delta, field_color_value)
SELECT
  'details' AS bundle,
  0 AS deleted,
  d.id AS entity_id,
  d.id AS revision_id,
  'en' AS langcode,
  0 AS delta,
  colors.hex AS field_color_value
FROM department_field_data d
JOIN (
  SELECT 1 AS dept_id, '#2d7a2d' AS hex UNION ALL
  SELECT 2,            '#b8860b'        UNION ALL
  SELECT 3,            '#6a0dad'        UNION ALL
  SELECT 4,            '#1a5276'        UNION ALL
  SELECT 5,            '#c0392b'        UNION ALL
  SELECT 6,            '#5d6d7e'
) colors ON colors.dept_id = d.id
ON DUPLICATE KEY UPDATE field_color_value = VALUES(field_color_value);

-- ── 2. Fix NULL department assignments on service terms ──────

-- Hard Scape (TID 1771) → Landscaping (1)
INSERT INTO taxonomy_term__field_department
  (bundle, deleted, entity_id, revision_id, langcode, delta, field_department_target_id)
SELECT 'services', 0, t.tid, t.tid, 'en', 0, 1
FROM taxonomy_term_data t WHERE t.tid = 1771
ON DUPLICATE KEY UPDATE field_department_target_id = 1;

-- Planting (TID 1772) → Landscaping (1)
INSERT INTO taxonomy_term__field_department
  (bundle, deleted, entity_id, revision_id, langcode, delta, field_department_target_id)
SELECT 'services', 0, t.tid, t.tid, 'en', 0, 1
FROM taxonomy_term_data t WHERE t.tid = 1772
ON DUPLICATE KEY UPDATE field_department_target_id = 1;

-- Rough Grading (TID 1770) → Landscaping (1)
INSERT INTO taxonomy_term__field_department
  (bundle, deleted, entity_id, revision_id, langcode, delta, field_department_target_id)
SELECT 'services', 0, t.tid, t.tid, 'en', 0, 1
FROM taxonomy_term_data t WHERE t.tid = 1770
ON DUPLICATE KEY UPDATE field_department_target_id = 1;

-- Licensed Insect Control (TID 398) → Spray (3)
INSERT INTO taxonomy_term__field_department
  (bundle, deleted, entity_id, revision_id, langcode, delta, field_department_target_id)
SELECT 'services', 0, t.tid, t.tid, 'en', 0, 3
FROM taxonomy_term_data t WHERE t.tid = 398
ON DUPLICATE KEY UPDATE field_department_target_id = 3;

-- In House Task (TID 403) → intentionally unassigned (renders gray on calendar).
-- Estimate (TID 391) → skip, pending deletion.
