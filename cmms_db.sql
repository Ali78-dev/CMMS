-- =====================================================
-- نظام إدارة صيانة الحاسوب (CMMS)
-- قاعدة البيانات: cmms_db
-- الترميز: utf8mb4 (دعم اللغة العربية)
-- =====================================================

CREATE DATABASE IF NOT EXISTS cmms_db
  CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

USE cmms_db;

-- -----------------------------------------------------
-- جدول: users (المستخدمون)
-- -----------------------------------------------------
CREATE TABLE IF NOT EXISTS users (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(100) NOT NULL,
  username VARCHAR(50) NOT NULL UNIQUE,
  password VARCHAR(255) NOT NULL,
  role ENUM('admin','technician','user') NOT NULL DEFAULT 'user'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- -----------------------------------------------------
-- جدول: equipment (الأجهزة / المعدات)
-- -----------------------------------------------------
CREATE TABLE IF NOT EXISTS equipment (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(100) NOT NULL,
  location VARCHAR(100) NOT NULL,
  status ENUM('working','faulty','under_maintenance') NOT NULL DEFAULT 'working'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- -----------------------------------------------------
-- جدول: requests (طلبات الصيانة)
-- -----------------------------------------------------
CREATE TABLE IF NOT EXISTS requests (
  id INT AUTO_INCREMENT PRIMARY KEY,
  title VARCHAR(150) NOT NULL,
  description TEXT,
  repair_notes TEXT NULL,
  equipment_id INT NULL,
  requested_by INT NOT NULL,
  assigned_to INT NULL,
  status ENUM('new','in_progress','completed','closed') NOT NULL DEFAULT 'new',
  priority ENUM('low','medium','high') NOT NULL DEFAULT 'medium',
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (equipment_id) REFERENCES equipment(id) ON DELETE SET NULL,
  FOREIGN KEY (requested_by) REFERENCES users(id) ON DELETE CASCADE,
  FOREIGN KEY (assigned_to) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- =====================================================
-- بيانات تجريبية (Seed Data)
-- كلمات المرور مشفرة بـ password_hash (bcrypt)
-- admin123 / tech123 / user123
-- =====================================================

INSERT INTO users (name, username, password, role) VALUES
('المدير العام', 'admin', '$2b$12$f8jwJAtuRQUUXLkltchquOm.y8laBe0xuLToPWeAwwje1QXG.gUY6', 'admin'),
('فني الصيانة', 'tech', '$2b$12$BOtz6HzP9BCPO4/mHJ9ixeAHFrQZtrnch61Rb9q7CMJUqMXN4Xg1i', 'technician'),
('مستخدم عادي', 'user', '$2b$12$Q38OLuJOefQGKy/E3nbd6eXxkTiQ5C0aVnpLPAfE9SPyJs2VJ7ReO', 'user');

INSERT INTO equipment (name, location, status) VALUES
('حاسوب مكتبي HP - رقم 1', 'مختبر 1', 'working'),
('طابعة Canon - رقم 2', 'مكتب الإدارة', 'faulty'),
('حاسوب محمول Dell - رقم 3', 'قاعة الاجتماعات', 'under_maintenance');

INSERT INTO requests (title, description, equipment_id, requested_by, assigned_to, status, priority) VALUES
('الطابعة لا تعمل', 'الطابعة لا تستجيب لأوامر الطباعة وتظهر ضوء أحمر.', 2, 3, 2, 'in_progress', 'high'),
('بطء في الحاسوب المحمول', 'الجهاز بطيء جداً عند التشغيل ويحتاج فحص.', 3, 3, 2, 'new', 'medium'),
('فحص دوري للحاسوب المكتبي', 'فحص دوري وتنظيف الجهاز رقم 1.', 1, 3, NULL, 'new', 'low');
