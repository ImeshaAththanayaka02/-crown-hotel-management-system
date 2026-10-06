-- database.sql
-- Crown Hotel Management System (CHMS) Database Schema

CREATE DATABASE IF NOT EXISTS `crown_hotel_db` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE `crown_hotel_db`;

-- Disable foreign key checks to prevent drop ordering issues
SET FOREIGN_KEY_CHECKS = 0;

DROP TABLE IF EXISTS `contact_messages`;
DROP TABLE IF EXISTS `reports`;
DROP TABLE IF EXISTS `food_orders`;
DROP TABLE IF EXISTS `food_menu`;
DROP TABLE IF EXISTS `inventory`;
DROP TABLE IF EXISTS `attendance`;
DROP TABLE IF EXISTS `staff`;
DROP TABLE IF EXISTS `housekeeping`;
DROP TABLE IF EXISTS `payments`;
DROP TABLE IF EXISTS `invoices`;
DROP TABLE IF EXISTS `checkouts`;
DROP TABLE IF EXISTS `checkins`;
DROP TABLE IF EXISTS `reservations`;
DROP TABLE IF EXISTS `guests`;
DROP TABLE IF EXISTS `rooms`;
DROP TABLE IF EXISTS `room_types`;
DROP TABLE IF EXISTS `users`;
DROP TABLE IF EXISTS `roles`;

SET FOREIGN_KEY_CHECKS = 1;

-- 1. Roles Table
CREATE TABLE `roles` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `name` VARCHAR(50) NOT NULL UNIQUE,
    `description` TEXT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 2. Users Table
CREATE TABLE `users` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `username` VARCHAR(50) NOT NULL UNIQUE,
    `email` VARCHAR(100) NOT NULL UNIQUE,
    `password` VARCHAR(255) NOT NULL,
    `role_id` INT NOT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `status` ENUM('Active', 'Inactive') DEFAULT 'Active',
    FOREIGN KEY (`role_id`) REFERENCES `roles`(`id`) ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 3. Room Types Table
CREATE TABLE `room_types` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `name` VARCHAR(50) NOT NULL UNIQUE,
    `description` TEXT,
    `base_price` DECIMAL(10, 2) NOT NULL,
    `max_occupancy` INT NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 4. Rooms Table
CREATE TABLE `rooms` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `room_number` VARCHAR(10) NOT NULL UNIQUE,
    `type_id` INT NOT NULL,
    `status` ENUM('Available', 'Occupied', 'Maintenance', 'Dirty') DEFAULT 'Available',
    `price` DECIMAL(10, 2) NOT NULL,
    `image_path` VARCHAR(255) DEFAULT NULL,
    FOREIGN KEY (`type_id`) REFERENCES `room_types`(`id`) ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 5. Guests Table
CREATE TABLE `guests` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `user_id` INT DEFAULT NULL UNIQUE,
    `first_name` VARCHAR(50) NOT NULL,
    `last_name` VARCHAR(50) NOT NULL,
    `email` VARCHAR(100) NOT NULL UNIQUE,
    `phone` VARCHAR(20) NOT NULL,
    `address` TEXT,
    `passport_id` VARCHAR(50) DEFAULT NULL,
    FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 6. Reservations Table
CREATE TABLE `reservations` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `guest_id` INT NOT NULL,
    `room_id` INT NOT NULL,
    `check_in_date` DATE NOT NULL,
    `check_out_date` DATE NOT NULL,
    `status` ENUM('Pending', 'Confirmed', 'Cancelled', 'Completed') DEFAULT 'Pending',
    `total_price` DECIMAL(10, 2) NOT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (`guest_id`) REFERENCES `guests`(`id`) ON DELETE CASCADE ON UPDATE CASCADE,
    FOREIGN KEY (`room_id`) REFERENCES `rooms`(`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 7. Checkins Table
CREATE TABLE `checkins` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `reservation_id` INT DEFAULT NULL,
    `guest_id` INT NOT NULL,
    `room_id` INT NOT NULL,
    `check_in_time` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `actual_check_out_time` TIMESTAMP NULL DEFAULT NULL,
    `status` ENUM('Active', 'Completed') DEFAULT 'Active',
    FOREIGN KEY (`reservation_id`) REFERENCES `reservations`(`id`) ON DELETE SET NULL ON UPDATE CASCADE,
    FOREIGN KEY (`guest_id`) REFERENCES `guests`(`id`) ON DELETE CASCADE ON UPDATE CASCADE,
    FOREIGN KEY (`room_id`) REFERENCES `rooms`(`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 8. Checkouts Table
CREATE TABLE `checkouts` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `checkin_id` INT NOT NULL UNIQUE,
    `check_out_time` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `total_amount` DECIMAL(10, 2) NOT NULL,
    `discount` DECIMAL(10, 2) DEFAULT 0.00,
    `tax` DECIMAL(10, 2) DEFAULT 0.00,
    `net_amount` DECIMAL(10, 2) NOT NULL,
    FOREIGN KEY (`checkin_id`) REFERENCES `checkins`(`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 9. Invoices Table
CREATE TABLE `invoices` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `checkin_id` INT NOT NULL,
    `invoice_number` VARCHAR(50) NOT NULL UNIQUE,
    `total_amount` DECIMAL(10, 2) NOT NULL,
    `paid_amount` DECIMAL(10, 2) DEFAULT 0.00,
    `status` ENUM('Unpaid', 'Partially Paid', 'Paid') DEFAULT 'Unpaid',
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (`checkin_id`) REFERENCES `checkins`(`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 10. Payments Table
CREATE TABLE `payments` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `invoice_id` INT NOT NULL,
    `payment_method` ENUM('Cash', 'Card') NOT NULL,
    `amount` DECIMAL(10, 2) NOT NULL,
    `transaction_id` VARCHAR(100) DEFAULT NULL,
    `payment_date` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (`invoice_id`) REFERENCES `invoices`(`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 11. Housekeeping Table
CREATE TABLE `housekeeping` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `room_id` INT NOT NULL,
    `staff_id` INT DEFAULT NULL,
    `status` ENUM('Dirty', 'Cleaning', 'Clean') DEFAULT 'Dirty',
    `remarks` TEXT,
    `assigned_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `completed_at` TIMESTAMP NULL DEFAULT NULL,
    FOREIGN KEY (`room_id`) REFERENCES `rooms`(`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 12. Staff Table
CREATE TABLE `staff` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `user_id` INT DEFAULT NULL UNIQUE,
    `first_name` VARCHAR(50) NOT NULL,
    `last_name` VARCHAR(50) NOT NULL,
    `department` ENUM('Reception', 'Housekeeping', 'Management') NOT NULL,
    `phone` VARCHAR(20) NOT NULL,
    `salary` DECIMAL(10, 2) NOT NULL,
    `hire_date` DATE NOT NULL,
    FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 13. Attendance Table
CREATE TABLE `attendance` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `staff_id` INT NOT NULL,
    `date` DATE NOT NULL,
    `clock_in` TIME NOT NULL,
    `clock_out` TIME DEFAULT NULL,
    `status` ENUM('Present', 'Absent', 'Late') DEFAULT 'Present',
    UNIQUE KEY `staff_date` (`staff_id`, `date`),
    FOREIGN KEY (`staff_id`) REFERENCES `staff`(`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 14. Inventory Table
CREATE TABLE `inventory` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `item_name` VARCHAR(100) NOT NULL UNIQUE,
    `quantity` INT NOT NULL DEFAULT 0,
    `unit` VARCHAR(20) NOT NULL,
    `min_threshold` INT NOT NULL DEFAULT 10,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 15. Food Menu Table
CREATE TABLE `food_menu` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `item_name` VARCHAR(100) NOT NULL UNIQUE,
    `description` TEXT,
    `price` DECIMAL(10, 2) NOT NULL,
    `category` ENUM('Appetizer', 'Main', 'Beverage', 'Dessert') NOT NULL,
    `is_available` TINYINT(1) DEFAULT 1,
    `image_path` VARCHAR(255) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 16. Food Orders Table
CREATE TABLE `food_orders` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `checkin_id` INT NOT NULL,
    `item_id` INT NOT NULL,
    `quantity` INT NOT NULL,
    `status` ENUM('Pending', 'Preparing', 'Delivered') DEFAULT 'Pending',
    `total_price` DECIMAL(10, 2) NOT NULL,
    `order_time` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (`checkin_id`) REFERENCES `checkins`(`id`) ON DELETE CASCADE ON UPDATE CASCADE,
    FOREIGN KEY (`item_id`) REFERENCES `food_menu`(`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 17. Reports Table
CREATE TABLE `reports` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `report_type` ENUM('Revenue', 'Occupancy', 'Guest') NOT NULL,
    `generated_by` INT NOT NULL,
    `generated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `parameters` TEXT,
    `file_path` VARCHAR(255) DEFAULT NULL,
    FOREIGN KEY (`generated_by`) REFERENCES `users`(`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 18. Contact Messages Table
CREATE TABLE IF NOT EXISTS `contact_messages` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `name` VARCHAR(100) NOT NULL,
    `email` VARCHAR(100) NOT NULL,
    `subject` VARCHAR(150) NOT NULL,
    `message` TEXT NOT NULL,
    `status` ENUM('Unread', 'Read') DEFAULT 'Unread',
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ADD INDEXES FOR FASTER RETRIEVALS
CREATE INDEX idx_users_role ON users(role_id);
CREATE INDEX idx_rooms_status ON rooms(status);
CREATE INDEX idx_rooms_type ON rooms(type_id);
CREATE INDEX idx_reservations_dates ON reservations(check_in_date, check_out_date);
CREATE INDEX idx_reservations_status ON reservations(status);
CREATE INDEX idx_checkins_status ON checkins(status);
CREATE INDEX idx_invoices_number ON invoices(invoice_number);
CREATE INDEX idx_payments_invoice ON payments(invoice_id);
CREATE INDEX idx_attendance_date ON attendance(date);
CREATE INDEX idx_food_orders_checkin ON food_orders(checkin_id);


-- ==========================================
-- SEED DATA
-- ==========================================

-- Seed Roles
INSERT INTO `roles` (`id`, `name`, `description`) VALUES
(1, 'Admin', 'Full system access and configurations'),
(2, 'Manager', 'Access to operational management and reports'),
(3, 'Receptionist', 'Front desk access for bookings, check-ins, check-outs, and billing'),
(4, 'Housekeeping', 'Cleanliness tracking and room maintenance logs'),
(5, 'Guest', 'Booking history and room service request interface');

-- Seed Users (Bcrypt hash of 'admin123' is $2y$10$T4iYLZqIuuCVKbnbbx8AvuSeXAr3j7XHp2cH3I03Z4g9KMwZWIzY6)
INSERT INTO `users` (`id`, `username`, `email`, `password`, `role_id`, `status`) VALUES
(1, 'admin', 'admin@crownhotel.com', '$2y$10$T4iYLZqIuuCVKbnbbx8AvuSeXAr3j7XHp2cH3I03Z4g9KMwZWIzY6', 1, 'Active'),
(2, 'manager', 'manager@crownhotel.com', '$2y$10$T4iYLZqIuuCVKbnbbx8AvuSeXAr3j7XHp2cH3I03Z4g9KMwZWIzY6', 2, 'Active'),
(3, 'reception', 'reception@crownhotel.com', '$2y$10$T4iYLZqIuuCVKbnbbx8AvuSeXAr3j7XHp2cH3I03Z4g9KMwZWIzY6', 3, 'Active'),
(4, 'housekeeper', 'housekeeping@crownhotel.com', '$2y$10$T4iYLZqIuuCVKbnbbx8AvuSeXAr3j7XHp2cH3I03Z4g9KMwZWIzY6', 4, 'Active'),
(5, 'guest1', 'guest1@gmail.com', '$2y$10$T4iYLZqIuuCVKbnbbx8AvuSeXAr3j7XHp2cH3I03Z4g9KMwZWIzY6', 5, 'Active'),
(6, 'johndoe', 'johndoe@gmail.com', '$2y$10$T4iYLZqIuuCVKbnbbx8AvuSeXAr3j7XHp2cH3I03Z4g9KMwZWIzY6', 5, 'Active');

-- Seed Staff
INSERT INTO `staff` (`id`, `user_id`, `first_name`, `last_name`, `department`, `phone`, `salary`, `hire_date`) VALUES
(1, 2, 'David', 'Miller', 'Management', '+1-555-0100', 6500.00, '2024-01-15'),
(2, 3, 'Sarah', 'Connor', 'Reception', '+1-555-0101', 3200.00, '2024-02-10'),
(3, 4, 'James', 'Clean', 'Housekeeping', '+1-555-0102', 2500.00, '2024-03-01');

-- Seed Attendance
INSERT INTO `attendance` (`staff_id`, `date`, `clock_in`, `clock_out`, `status`) VALUES
(1, '2026-06-10', '08:55:00', '17:00:00', 'Present'),
(2, '2026-06-10', '08:00:00', '16:00:00', 'Present'),
(3, '2026-06-10', '09:15:00', '17:30:00', 'Late'),
(1, '2026-06-11', '08:50:00', NULL, 'Present'),
(2, '2026-06-11', '07:58:00', NULL, 'Present'),
(3, '2026-06-11', '08:00:00', NULL, 'Present');

-- Seed Room Types
INSERT INTO `room_types` (`id`, `name`, `description`, `base_price`, `max_occupancy`) VALUES
(1, 'Single Room', 'Cozy single room with a comfortable twin bed, workspace, high-speed Wi-Fi, and private bathroom.', 120.00, 1),
(2, 'Double Room', 'Spacious room with a queen bed, seating area, smart TV, mini-fridge, and scenic balcony view.', 180.00, 2),
(3, 'Deluxe Room', 'Luxury premium room with king-size bed, sofa, automated mini-bar, luxury tub, and panoramic views.', 280.00, 3),
(4, 'Suite Room', 'Ultimate luxury apartment suite with master bedroom, separate living space, private dining, jacuzzi, and dedicated butler service.', 450.00, 4);

-- Seed Rooms
INSERT INTO `rooms` (`id`, `room_number`, `type_id`, `status`, `price`, `image_path`) VALUES
(1, '101', 1, 'Available', 120.00, NULL),
(2, '102', 1, 'Dirty', 120.00, NULL),
(3, '103', 1, 'Available', 125.00, NULL),
(4, '104', 1, 'Maintenance', 120.00, NULL),
(5, '201', 2, 'Available', 180.00, NULL),
(6, '202', 2, 'Occupied', 185.00, NULL),
(7, '203', 2, 'Available', 180.00, NULL),
(8, '204', 2, 'Dirty', 180.00, NULL),
(9, '301', 3, 'Occupied', 280.00, NULL),
(10, '302', 3, 'Available', 290.00, NULL),
(11, '401', 4, 'Occupied', 450.00, NULL),
(12, '402', 4, 'Available', 475.00, NULL);

-- Seed Guests
INSERT INTO `guests` (`id`, `user_id`, `first_name`, `last_name`, `email`, `phone`, `address`, `passport_id`) VALUES
(1, 5, 'Alice', 'Smith', 'guest1@gmail.com', '+1-555-0201', '456 luxury street, California', 'A88299100'),
(2, 6, 'John', 'Doe', 'johndoe@gmail.com', '+1-555-0202', '789 Business Ave, New York', 'B99388102'),
(3, NULL, 'Walkin', 'Guest', 'walkin@gmail.com', '+1-555-0203', 'Hotel Lobby Address', NULL),
(4, NULL, 'Robert', 'Downey', 'robert@tony.com', '+1-555-0204', 'Malibu, California', 'C11223344');

-- Seed Reservations
-- Date logic: 2026-06-11 is today
INSERT INTO `reservations` (`id`, `guest_id`, `room_id`, `check_in_date`, `check_out_date`, `status`, `total_price`, `created_at`) VALUES
(1, 1, 6, '2026-06-08', '2026-06-12', 'Confirmed', 740.00, '2026-06-05 10:00:00'),
(2, 2, 9, '2026-06-10', '2026-06-15', 'Confirmed', 1400.00, '2026-06-06 11:30:00'),
(3, 3, 1, '2026-06-11', '2026-06-13', 'Pending', 240.00, '2026-06-11 08:00:00'),
(4, 4, 11, '2026-06-05', '2026-06-10', 'Completed', 2250.00, '2026-06-01 14:00:00'),
(5, 1, 2, '2026-06-20', '2026-06-25', 'Pending', 600.00, '2026-06-11 12:00:00');

-- Seed Checkins
INSERT INTO `checkins` (`id`, `reservation_id`, `guest_id`, `room_id`, `check_in_time`, `actual_check_out_time`, `status`) VALUES
(1, 1, 1, 6, '2026-06-08 14:00:00', NULL, 'Active'),
(2, 2, 2, 9, '2026-06-10 15:30:00', NULL, 'Active'),
(3, 4, 4, 11, '2026-06-05 14:00:00', '2026-06-10 11:00:00', 'Completed');

-- Seed Checkouts
INSERT INTO `checkouts` (`id`, `checkin_id`, `check_out_time`, `total_amount`, `discount`, `tax`, `net_amount`) VALUES
(1, 3, '2026-06-10 11:00:00', 2250.00, 100.00, 172.00, 2322.00);

-- Seed Invoices
INSERT INTO `invoices` (`id`, `checkin_id`, `invoice_number`, `total_amount`, `paid_amount`, `status`, `created_at`) VALUES
(1, 1, 'INV-2026-0001', 740.00, 0.00, 'Unpaid', '2026-06-08 14:00:00'),
(2, 2, 'INV-2026-0002', 1400.00, 500.00, 'Partially Paid', '2026-06-10 15:30:00'),
(3, 3, 'INV-2026-0003', 2322.00, 2322.00, 'Paid', '2026-06-10 11:00:00');

-- Seed Payments
INSERT INTO `payments` (`invoice_id`, `payment_method`, `amount`, `transaction_id`, `payment_date`) VALUES
(2, 'Card', 500.00, 'TXN99018820', '2026-06-10 15:45:00'),
(3, 'Card', 2322.00, 'TXN99018815', '2026-06-10 11:00:00');

-- Seed Housekeeping
INSERT INTO `housekeeping` (`room_id`, `staff_id`, `status`, `remarks`, `assigned_at`, `completed_at`) VALUES
(2, 3, 'Dirty', 'Guest checked out, needs full clean & sheets exchange.', '2026-06-10 11:00:00', NULL),
(8, 3, 'Cleaning', 'Dusting and cleaning bathroom.', '2026-06-11 09:00:00', NULL),
(3, 3, 'Clean', 'Ready for new check-in.', '2026-06-11 08:00:00', '2026-06-11 08:45:00');

-- Seed Inventory
INSERT INTO `inventory` (`item_name`, `quantity`, `unit`, `min_threshold`) VALUES
('Luxury Linens (King Size)', 45, 'Pieces', 15),
('Luxury Linens (Queen Size)', 60, 'Pieces', 15),
('Cotton Towels (Large)', 120, 'Pieces', 30),
('Hand Towels', 150, 'Pieces', 40),
('Shampoo Bottles (50ml)', 8, 'Bottles', 50), -- triggers low stock alert
('Body Wash Bottles (50ml)', 5, 'Bottles', 50), -- triggers low stock alert
('Dental Kits', 90, 'Kits', 20),
('Instant Coffee Packets', 300, 'Packets', 50);

-- Seed Food Menu
INSERT INTO `food_menu` (`item_name`, `description`, `price`, `category`, `is_available`) VALUES
('Truffle Fries', 'Grated parmesan cheese, white truffle oil, rosemary, served with garlic aioli.', 15.00, 'Appetizer', 1),
('Crown Wagyu Burger', '8oz Wagyu beef patty, aged cheddar, caramelised onions, truffle mayo, brioche bun, fries.', 28.00, 'Main', 1),
('Atlantic Salmon Filet', 'Pan-seared salmon, asparagus spears, saffron potato puree, lemon herb butter.', 32.00, 'Main', 1),
('Fresh Orange Juice', 'Cold pressed fresh oranges.', 8.00, 'Beverage', 1),
('Cappuccino', 'Double espresso shot with steamed milk foam and cocoa powder.', 6.50, 'Beverage', 1),
('Classic Tiramisu', 'Mascarpone cream, espresso-soaked ladyfingers, cocoa powder dusting.', 12.00, 'Dessert', 1);

-- Seed Food Orders
INSERT INTO `food_orders` (`checkin_id`, `item_id`, `quantity`, `status`, `total_price`, `order_time`) VALUES
(1, 2, 2, 'Delivered', 56.00, '2026-06-09 19:00:00'),
(1, 4, 2, 'Delivered', 16.00, '2026-06-09 19:00:00'),
(2, 3, 1, 'Preparing', 32.00, '2026-06-11 13:00:00'),
(2, 5, 1, 'Pending', 6.50, '2026-06-11 13:30:00');
