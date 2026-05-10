

DROP TABLE IF EXISTS payment_transactions;
DROP TABLE IF EXISTS payment_demands;

CREATE TABLE payment_demands (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    admin_id INT NOT NULL,
    payment_type ENUM('room_rent', 'mess_fee', 'maintenance_fee', 'security_deposit', 'fine', 'other') NOT NULL,
    payment_label VARCHAR(100) NOT NULL,
    amount DECIMAL(10,2) NOT NULL,
    due_date DATE NOT NULL,
    month VARCHAR(20) NOT NULL,
    year INT NOT NULL,
    description TEXT,
    status ENUM('unpaid', 'paid', 'overdue', 'cancelled') DEFAULT 'unpaid',
    qr_token VARCHAR(64) NOT NULL UNIQUE,
    secure_hash VARCHAR(128) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (admin_id) REFERENCES admins(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;


CREATE TABLE payment_transactions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    demand_id INT NOT NULL,
    user_id INT NOT NULL,
    amount DECIMAL(10,2) NOT NULL,
    payment_method ENUM('qr_scan', 'online') DEFAULT 'qr_scan',
    transaction_ref VARCHAR(100) NOT NULL UNIQUE,
    ip_address VARCHAR(45),
    user_agent TEXT,
    paid_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    receipt_number VARCHAR(30) NOT NULL UNIQUE,
    FOREIGN KEY (demand_id) REFERENCES payment_demands(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;