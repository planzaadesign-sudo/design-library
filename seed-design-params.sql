-- Realistic, distinct similarity parameters for the 6 sample designs (from schema.sql).
-- Run once, after schema-phase5.sql. Safe to run again (it only sets values).
-- Each UPDATE matches both id and name, so it cannot change the wrong design
-- if ids differ on your database. No two of these designs score above 60% similar.

-- Design 1: The Courtyard (30x40, East, G+1, 3BHK) -- the inner courtyard is its signature.
UPDATE designs SET
  plot_shape = 'rectangle', is_corner_plot = 0, stilt_parking = 0,
  entrance_position = 'front_center', staircase_position = 'center',
  kitchen_layout = 'attached_dining', pooja_room = 'yes_ne', balcony_config = 'front_only',
  elevation_style = 'modern_flat', elevation_material = 'texture_paint', roof_type = 'flat',
  target_segment = 'mid_range', vastu_level = 'strict',
  has_courtyard = 1, has_home_office = 0, has_servant_quarter = 0, has_ground_shop = 0,
  built_up_area_sqft = 900, ground_coverage_pct = 75
WHERE id = 1 AND name = 'The Courtyard';

-- Design 2: The Terrace View (30x50, North, G+1, 3BHK)
UPDATE designs SET
  plot_shape = 'rectangle', is_corner_plot = 0, stilt_parking = 0,
  entrance_position = 'front_left', staircase_position = 'rear',
  kitchen_layout = 'separate', pooja_room = 'yes_flexible', balcony_config = 'front_rear',
  elevation_style = 'contemporary', elevation_material = 'stone_cladding', roof_type = 'parapet',
  target_segment = 'mid_range', vastu_level = 'partial',
  has_courtyard = 0, has_home_office = 1, has_servant_quarter = 0, has_ground_shop = 0,
  built_up_area_sqft = 1100, ground_coverage_pct = 73
WHERE id = 2 AND name = 'The Terrace View';

-- Design 3: The Corner Bloom (20x30, West, G, 2BHK) -- compact budget home on a corner plot.
UPDATE designs SET
  plot_shape = 'rectangle', is_corner_plot = 1, stilt_parking = 0,
  entrance_position = 'side', staircase_position = 'front',
  kitchen_layout = 'open_plan', pooja_room = 'no', balcony_config = 'none',
  elevation_style = 'minimalist', elevation_material = 'texture_paint', roof_type = 'single_slope',
  target_segment = 'budget', vastu_level = 'not_required',
  has_courtyard = 0, has_home_office = 0, has_servant_quarter = 0, has_ground_shop = 0,
  built_up_area_sqft = 480, ground_coverage_pct = 80
WHERE id = 3 AND name = 'The Corner Bloom';

-- Design 4: The Skyline (40x60, South, G+2, 4BHK) -- premium, stilt parking.
UPDATE designs SET
  plot_shape = 'rectangle', is_corner_plot = 0, stilt_parking = 1,
  entrance_position = 'front_center', staircase_position = 'center',
  kitchen_layout = 'attached_dining', pooja_room = 'yes_ne', balcony_config = 'front_rear',
  elevation_style = 'contemporary', elevation_material = 'glass_metal', roof_type = 'flat',
  target_segment = 'premium', vastu_level = 'strict',
  has_courtyard = 0, has_home_office = 1, has_servant_quarter = 1, has_ground_shop = 0,
  built_up_area_sqft = 1800, ground_coverage_pct = 75
WHERE id = 4 AND name = 'The Skyline';

-- Design 5: The Garden House (30x40, North, G, 2BHK) -- single floor, so stairs set to 'front'.
UPDATE designs SET
  plot_shape = 'rectangle', is_corner_plot = 0, stilt_parking = 0,
  entrance_position = 'front_right', staircase_position = 'front',
  kitchen_layout = 'attached_dining', pooja_room = 'no', balcony_config = 'front_only',
  elevation_style = 'traditional', elevation_material = 'exposed_brick', roof_type = 'gable',
  target_segment = 'budget', vastu_level = 'partial',
  has_courtyard = 0, has_home_office = 0, has_servant_quarter = 0, has_ground_shop = 0,
  built_up_area_sqft = 720, ground_coverage_pct = 60
WHERE id = 5 AND name = 'The Garden House';

-- Design 6: The Urban Duplex (30x45, East, G+1, 3BHK) -- premium, stilt parking, shop at the front.
UPDATE designs SET
  plot_shape = 'rectangle', is_corner_plot = 0, stilt_parking = 1,
  entrance_position = 'front_left', staircase_position = 'side',
  kitchen_layout = 'open_plan', pooja_room = 'yes_flexible', balcony_config = 'wrap',
  elevation_style = 'modern_flat', elevation_material = 'hpl_panels', roof_type = 'flat',
  target_segment = 'premium', vastu_level = 'partial',
  has_courtyard = 0, has_home_office = 1, has_servant_quarter = 0, has_ground_shop = 1,
  built_up_area_sqft = 1050, ground_coverage_pct = 78
WHERE id = 6 AND name = 'The Urban Duplex';
