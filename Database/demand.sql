
ALTER TABLE users
  ADD COLUMN diet_type ENUM('veg','non_veg','vegan','any') DEFAULT 'any' AFTER gender,
  ADD COLUMN non_veg_preference SET('chicken','mutton','fish','egg','all') DEFAULT 'all' AFTER diet_type,
  ADD COLUMN group_tag VARCHAR(100) DEFAULT NULL AFTER non_veg_preference,
  ADD COLUMN floor_number INT DEFAULT NULL AFTER group_tag;

CREATE TABLE IF NOT EXISTS payment_groups (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    description TEXT,
    filter_type ENUM('all','diet','non_veg_pref','floor','room_type','custom') NOT NULL DEFAULT 'custom',
    filter_value VARCHAR(255) DEFAULT NULL,
    admin_id INT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (admin_id) REFERENCES admins(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS demand_batches (
    id INT AUTO_INCREMENT PRIMARY KEY,
    batch_ref VARCHAR(30) NOT NULL UNIQUE,
    admin_id INT NOT NULL,
    payment_type ENUM('room_rent','mess_fee','maintenance_fee','security_deposit','fine','other') NOT NULL,
    payment_label VARCHAR(100) NOT NULL,
    amount DECIMAL(10,2) NOT NULL,
    due_date DATE NOT NULL,
    month VARCHAR(20) NOT NULL,
    year INT NOT NULL,
    description TEXT,
    total_demands INT DEFAULT 0,
    filter_used VARCHAR(255) DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (admin_id) REFERENCES admins(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

ALTER TABLE payment_demands
  ADD COLUMN batch_id INT DEFAULT NULL AFTER id,
  ADD FOREIGN KEY (batch_id) REFERENCES demand_batches(id) ON DELETE SET NULL;