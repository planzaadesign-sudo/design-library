CREATE TABLE IF NOT EXISTS designs (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(100) NOT NULL,
  plot_width INT NOT NULL,
  plot_length INT NOT NULL,
  facing ENUM('East','West','North','South') NOT NULL,
  floors VARCHAR(10) NOT NULL,
  bhk INT NOT NULL,
  base_price INT NOT NULL,
  delivery_days INT NOT NULL,
  variant TINYINT DEFAULT 0,
  is_active TINYINT(1) DEFAULT 1,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS library_orders (
  id INT AUTO_INCREMENT PRIMARY KEY,
  order_code VARCHAR(20) UNIQUE NOT NULL,
  design_id INT NOT NULL,
  customer_name VARCHAR(100) NOT NULL,
  customer_phone VARCHAR(15) NOT NULL,
  customer_city VARCHAR(100),
  plot_width INT,
  plot_length INT,
  facing VARCHAR(10),
  structural_addon TINYINT(1) DEFAULT 0,
  total_price INT NOT NULL,
  status ENUM('new','confirmed','delivered') DEFAULT 'new',
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (design_id) REFERENCES designs(id)
);

INSERT INTO designs (name, plot_width, plot_length, facing, floors, bhk, base_price, delivery_days, variant) VALUES
('The Courtyard', 30, 40, 'East', 'G+1', 3, 45000, 12, 0),
('The Terrace View', 30, 50, 'North', 'G+1', 3, 52000, 14, 1),
('The Corner Bloom', 20, 30, 'West', 'G', 2, 28000, 8, 2),
('The Skyline', 40, 60, 'South', 'G+2', 4, 78000, 18, 0),
('The Garden House', 30, 40, 'North', 'G', 2, 32000, 9, 1),
('The Urban Duplex', 30, 45, 'East', 'G+1', 3, 58000, 15, 2);
