-- Phase 6: designer and in-house dashboards.
-- Run once, after schema-phase5.sql (and seed-design-params.sql).

-- Designers can upload a preview image (jpg/png/webp) next to their CAD file,
-- so reviewers can compare it side by side with similar library designs.
ALTER TABLE submissions ADD COLUMN preview_path VARCHAR(255) NULL;
