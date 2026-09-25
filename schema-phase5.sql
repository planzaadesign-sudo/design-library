-- Phase 5: design similarity checks.
-- Run once, after schema-phase4.sql.
-- Adds the design parameters that the similarity check compares, to both briefs and
-- designs. (No extra indexes: the check compares every active design, which is fast.)

-- ---- Briefs -----------------------------------------------------------------------
ALTER TABLE briefs ADD COLUMN plot_shape ENUM('rectangle','l_shape','corner_cut','irregular') DEFAULT 'rectangle';
ALTER TABLE briefs ADD COLUMN is_corner_plot TINYINT(1) DEFAULT 0;
ALTER TABLE briefs ADD COLUMN stilt_parking TINYINT(1) DEFAULT 0;
ALTER TABLE briefs ADD COLUMN entrance_position ENUM('front_center','front_left','front_right','side') DEFAULT 'front_center';
ALTER TABLE briefs ADD COLUMN staircase_position ENUM('front','center','rear','side') DEFAULT 'center';
ALTER TABLE briefs ADD COLUMN kitchen_layout ENUM('attached_dining','separate','open_plan') DEFAULT 'attached_dining';
ALTER TABLE briefs ADD COLUMN pooja_room ENUM('yes_ne','yes_flexible','no') DEFAULT 'no';
ALTER TABLE briefs ADD COLUMN balcony_config ENUM('front_only','front_rear','wrap','none') DEFAULT 'front_only';
ALTER TABLE briefs ADD COLUMN elevation_style ENUM('modern_flat','contemporary','traditional','colonial','minimalist') DEFAULT 'modern_flat';
ALTER TABLE briefs ADD COLUMN elevation_material ENUM('texture_paint','exposed_brick','stone_cladding','glass_metal','hpl_panels','mix') DEFAULT 'texture_paint';
ALTER TABLE briefs ADD COLUMN roof_type ENUM('flat','single_slope','gable','hip','parapet') DEFAULT 'flat';
ALTER TABLE briefs ADD COLUMN target_segment ENUM('budget','mid_range','premium') DEFAULT 'mid_range';
ALTER TABLE briefs ADD COLUMN vastu_level ENUM('strict','partial','not_required') DEFAULT 'partial';
ALTER TABLE briefs ADD COLUMN has_courtyard TINYINT(1) DEFAULT 0;
ALTER TABLE briefs ADD COLUMN has_home_office TINYINT(1) DEFAULT 0;
ALTER TABLE briefs ADD COLUMN has_servant_quarter TINYINT(1) DEFAULT 0;
ALTER TABLE briefs ADD COLUMN has_ground_shop TINYINT(1) DEFAULT 0;
ALTER TABLE briefs ADD COLUMN built_up_area_sqft INT NULL;
ALTER TABLE briefs ADD COLUMN ground_coverage_pct INT NULL;
ALTER TABLE briefs ADD COLUMN differentiation_notes TEXT NULL;

-- Briefs only had a free-text house_type ("G+1, 3BHK"). The similarity check needs floors
-- and bedrooms as real values, so they get their own columns, filled from house_type.
ALTER TABLE briefs ADD COLUMN floors VARCHAR(10) NULL;
ALTER TABLE briefs ADD COLUMN bhk INT NULL;
UPDATE briefs SET floors = TRIM(SUBSTRING_INDEX(house_type, ',', 1))
  WHERE floors IS NULL AND TRIM(SUBSTRING_INDEX(house_type, ',', 1)) IN ('G', 'G+1', 'G+2', 'G+3');
UPDATE briefs SET bhk = CAST(REPLACE(REPLACE(UPPER(REGEXP_SUBSTR(house_type, '[0-9]+ ?[Bb][Hh][Kk]')), 'BHK', ''), ' ', '') AS UNSIGNED)
  WHERE bhk IS NULL AND house_type REGEXP '[0-9]+ ?[Bb][Hh][Kk]';

-- ---- Designs ----------------------------------------------------------------------
ALTER TABLE designs ADD COLUMN plot_shape ENUM('rectangle','l_shape','corner_cut','irregular') DEFAULT 'rectangle';
ALTER TABLE designs ADD COLUMN is_corner_plot TINYINT(1) DEFAULT 0;
ALTER TABLE designs ADD COLUMN stilt_parking TINYINT(1) DEFAULT 0;
ALTER TABLE designs ADD COLUMN entrance_position ENUM('front_center','front_left','front_right','side') DEFAULT 'front_center';
ALTER TABLE designs ADD COLUMN staircase_position ENUM('front','center','rear','side') DEFAULT 'center';
ALTER TABLE designs ADD COLUMN kitchen_layout ENUM('attached_dining','separate','open_plan') DEFAULT 'attached_dining';
ALTER TABLE designs ADD COLUMN pooja_room ENUM('yes_ne','yes_flexible','no') DEFAULT 'no';
ALTER TABLE designs ADD COLUMN balcony_config ENUM('front_only','front_rear','wrap','none') DEFAULT 'front_only';
ALTER TABLE designs ADD COLUMN elevation_style ENUM('modern_flat','contemporary','traditional','colonial','minimalist') DEFAULT 'modern_flat';
ALTER TABLE designs ADD COLUMN elevation_material ENUM('texture_paint','exposed_brick','stone_cladding','glass_metal','hpl_panels','mix') DEFAULT 'texture_paint';
ALTER TABLE designs ADD COLUMN roof_type ENUM('flat','single_slope','gable','hip','parapet') DEFAULT 'flat';
ALTER TABLE designs ADD COLUMN target_segment ENUM('budget','mid_range','premium') DEFAULT 'mid_range';
ALTER TABLE designs ADD COLUMN vastu_level ENUM('strict','partial','not_required') DEFAULT 'partial';
ALTER TABLE designs ADD COLUMN has_courtyard TINYINT(1) DEFAULT 0;
ALTER TABLE designs ADD COLUMN has_home_office TINYINT(1) DEFAULT 0;
ALTER TABLE designs ADD COLUMN has_servant_quarter TINYINT(1) DEFAULT 0;
ALTER TABLE designs ADD COLUMN has_ground_shop TINYINT(1) DEFAULT 0;
ALTER TABLE designs ADD COLUMN built_up_area_sqft INT NULL;
ALTER TABLE designs ADD COLUMN ground_coverage_pct INT NULL;

-- Designs published before phase 5 stored house_type text in floors (e.g. "G+1, 3BHK").
-- Keep just the floors part so customer filters and similarity checks work.
UPDATE designs SET floors = TRIM(SUBSTRING_INDEX(floors, ',', 1))
  WHERE floors LIKE '%,%' AND TRIM(SUBSTRING_INDEX(floors, ',', 1)) IN ('G', 'G+1', 'G+2', 'G+3');
