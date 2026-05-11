CREATE DATABASE IF NOT EXISTS hostel;
USE hostel;

CREATE TABLE rooms (
    id INT AUTO_INCREMENT PRIMARY KEY,
    room_number VARCHAR(10) NOT NULL UNIQUE,
    room_type ENUM('double', 'triple') NOT NULL,
    floor INT NOT NULL,
    capacity INT NOT NULL,
    occupied INT DEFAULT 0,
    status ENUM('available', 'full', 'maintenance') DEFAULT 'available',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    full_name VARCHAR(100) NOT NULL,
    email VARCHAR(100) NOT NULL UNIQUE,
    phone VARCHAR(15) NOT NULL,
    student_id VARCHAR(20) NOT NULL UNIQUE,
    password VARCHAR(255) NOT NULL,
    room_id INT,
    bed_number INT,
    gender ENUM('male', 'female', 'other') NOT NULL,
    address TEXT,
    emergency_contact VARCHAR(15),
    profile_photo VARCHAR(255) DEFAULT NULL,
    status ENUM('active', 'inactive', 'pending') DEFAULT 'pending',
    joined_date DATE DEFAULT (CURRENT_DATE),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (room_id) REFERENCES rooms(id) ON DELETE SET NULL
);

CREATE TABLE complaints (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    room_id INT NOT NULL,
    complaint_items JSON NOT NULL,
    description TEXT,
    priority ENUM('low', 'medium', 'high') DEFAULT 'medium',
    status ENUM('pending', 'in_progress', 'resolved', 'rejected') DEFAULT 'pending',
    admin_notes TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    resolved_at TIMESTAMP NULL,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (room_id) REFERENCES rooms(id) ON DELETE CASCADE
);

CREATE TABLE announcements (
    id INT AUTO_INCREMENT PRIMARY KEY,
    title VARCHAR(200) NOT NULL,
    content TEXT NOT NULL,
    type ENUM('info', 'warning', 'urgent') DEFAULT 'info',
    admin_id INT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Admin Table
CREATE TABLE admins (
    id INT AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(50) NOT NULL UNIQUE,
    email VARCHAR(100) NOT NULL UNIQUE,
    password VARCHAR(255) NOT NULL,
    full_name VARCHAR(100) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE payments (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    amount DECIMAL(10,2) NOT NULL,
    month VARCHAR(20) NOT NULL,
    year INT NOT NULL,
    status ENUM('paid', 'pending', 'overdue') DEFAULT 'pending',
    paid_at TIMESTAMP NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);


INSERT INTO admins (username, email, password, full_name) VALUES
('admin', 'admin@residex.com', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'Tej Chinzah');
-- Default admin password: ********

-- Insert Rooms (Double: 2 beds, Triple: 3 beds)
-- Floor 1: Second Floor Rooms 01-08
INSERT INTO rooms (room_number, room_type, floor, capacity) VALUES
('1', 'double', 1, 2), ('2', 'triple', 1, 3), ('3', 'double', 1, 2),
('4', 'triple', 1, 3), ('5', 'double', 1, 2), ('6', 'triple', 1, 3),
('7', 'double', 1, 2), ('8', 'triple', 1, 3),
-- Floor 2: First Floor Rooms 09-16
('9', 'double', 2, 2), ('10', 'triple', 2, 3), ('11', 'double', 2, 2),
('12', 'triple', 2, 3), ('13', 'double', 2, 2), ('14', 'triple', 2, 3),
('15', 'double', 2, 2), ('16', 'triple', 2, 3),
-- Floor 3: Ground Floor Rooms 17-24
('17', 'double', 3, 2), ('18', 'triple', 3, 3), ('19', 'double', 3, 2),
('20', 'triple', 3, 3), ('21', 'double', 3, 2), ('22', 'triple', 3, 3),
('23', 'double', 3, 2), ('24', 'triple', 3, 3),
-- Floor 4: Basement Rooms 25-30
('25', 'double', 4, 2), ('26', 'triple', 4, 3), ('27', 'double', 4, 2),
('28', 'triple', 4, 3), ('29', 'double', 4, 2), ('30', 'triple', 4, 3);

INSERT INTO announcements (title, content, type) VALUES
('Welcome to Residex!', 'Welcome to our hostel management system. Please keep your rooms always clean.', 'info'),
('Water Supply Maintenance', 'Water supply will be turn off except Europeon from Friday Evening to Sunday Evening for maintenance.', 'warning'),
('Mess Timing Change', 'Dinner timing changed to 5:30 PM - 7:30 PM effective immediately.', 'info');
