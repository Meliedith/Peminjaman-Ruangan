CREATE DATABASE IF NOT EXISTS db_peminjaman_lab;
USE db_peminjaman_lab;

-- Drop tables if they exist to allow clean re-runs
DROP TABLE IF EXISTS tool_bookings;
DROP TABLE IF EXISTS room_bookings;
DROP TABLE IF EXISTS tools;
DROP TABLE IF EXISTS tables;
DROP TABLE IF EXISTS users;

-- 1. Table users
CREATE TABLE users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(255) NOT NULL,
    email VARCHAR(255) NOT NULL UNIQUE,
    password VARCHAR(255) NOT NULL,
    role ENUM('user', 'admin') NOT NULL DEFAULT 'user',
    phone VARCHAR(20) NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

-- Seed initial Admin user (Password: admin123)
-- The password needs to be hashed. Using default PHP password_hash for 'admin123'
INSERT INTO users (name, email, password, role) VALUES 
('Administrator', 'admin@lab.com', '$2y$10$4t2A3Fwn5uyevWMJmMTkjejKmpx3KOCRCv7nMFSgwxuEF/Oc0DeZS', 'admin');


-- 2. Table tables (Meja)
CREATE TABLE tables (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    is_active TINYINT(1) DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Seed 5 data awal (Meja 1-5)
INSERT INTO tables (name) VALUES 
('Meja 1'), ('Meja 2'), ('Meja 3'), ('Meja 4'), ('Meja 5');


-- 3. Table tools (Alat Lab)
CREATE TABLE tools (
    id INT AUTO_INCREMENT PRIMARY KEY,
    kode VARCHAR(50) NULL,
    name VARCHAR(255) NOT NULL,
    jenis VARCHAR(100) NULL,
    lokasi VARCHAR(100) NULL,
    total_stock INT NOT NULL DEFAULT 0,
    available_stock INT NOT NULL DEFAULT 0,
    kondisi VARCHAR(50) NULL,
    is_active TINYINT(1) DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);



-- 4. Table room_bookings (Peminjaman Ruangan/Meja)
CREATE TABLE room_bookings (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    table_id INT NOT NULL,
    booking_date DATE NOT NULL,
    start_time TIME NOT NULL,
    end_time TIME NOT NULL,
    status ENUM('pending', 'approved', 'rejected', 'expired', 'no_show', 'cancelled') DEFAULT 'pending',
    rejection_reason TEXT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (table_id) REFERENCES tables(id) ON DELETE CASCADE
);

-- Add index for overlap checks
CREATE INDEX idx_room_booking_overlap ON room_bookings (table_id, booking_date);


-- 5. Table tool_bookings (Peminjaman Alat)
CREATE TABLE tool_bookings (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    tool_id INT NOT NULL,
    quantity INT NOT NULL,
    booking_date DATE NOT NULL,
    start_time TIME NOT NULL,
    end_time TIME NOT NULL,
    status ENUM('pending', 'approved', 'rejected', 'expired', 'completed', 'no_show', 'cancelled') DEFAULT 'pending',
    rejection_reason TEXT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (tool_id) REFERENCES tools(id) ON DELETE CASCADE
);

-- Add index for overlap checks
CREATE INDEX idx_tool_booking_overlap ON tool_bookings (tool_id, booking_date);
