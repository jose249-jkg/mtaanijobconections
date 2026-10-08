CREATE DATABASE IF NOT EXISTS mtaani_work_connections
  CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;

USE mtaani_work_connections;

CREATE TABLE IF NOT EXISTS users (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  name VARCHAR(100) NOT NULL,
  email VARCHAR(254) NOT NULL,
  password_hash VARCHAR(255) NOT NULL,
  role ENUM('customer', 'provider', 'admin') NOT NULL DEFAULT 'customer',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_users_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS services (
  id SMALLINT UNSIGNED NOT NULL AUTO_INCREMENT,
  name VARCHAR(80) NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_services_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS locations (
  id SMALLINT UNSIGNED NOT NULL AUTO_INCREMENT,
  name VARCHAR(100) NOT NULL,
  county VARCHAR(100) NOT NULL DEFAULT 'Kenya',
  PRIMARY KEY (id),
  UNIQUE KEY uq_locations_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS service_providers (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id BIGINT UNSIGNED NOT NULL,
  business_name VARCHAR(120) NOT NULL DEFAULT '',
  service_id SMALLINT UNSIGNED NOT NULL,
  location_id SMALLINT UNSIGNED NOT NULL,
  phone VARCHAR(24) NOT NULL,
  description TEXT NOT NULL,
  status ENUM('pending', 'approved', 'rejected') NOT NULL DEFAULT 'pending',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_provider_user (user_id),
  KEY ix_provider_service_location_status (service_id, location_id, status),
  CONSTRAINT fk_provider_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE,
  CONSTRAINT fk_provider_service FOREIGN KEY (service_id) REFERENCES services (id),
  CONSTRAINT fk_provider_location FOREIGN KEY (location_id) REFERENCES locations (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS skill_submissions (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id BIGINT UNSIGNED NOT NULL,
  business_name VARCHAR(120) NOT NULL DEFAULT '',
  service_id SMALLINT UNSIGNED NOT NULL,
  location_id SMALLINT UNSIGNED NOT NULL,
  phone VARCHAR(24) NOT NULL,
  skill VARCHAR(100) NOT NULL,
  headline VARCHAR(140) NOT NULL,
  details TEXT NOT NULL,
  available_until DATE NULL,
  status ENUM('pending', 'approved', 'rejected') NOT NULL DEFAULT 'pending',
  submitted_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  reviewed_at DATETIME NULL,
  reviewed_by BIGINT UNSIGNED NULL,
  PRIMARY KEY (id),
  KEY ix_skill_status_date (status, submitted_at),
  CONSTRAINT fk_skill_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE,
  CONSTRAINT fk_skill_service FOREIGN KEY (service_id) REFERENCES services (id),
  CONSTRAINT fk_skill_location FOREIGN KEY (location_id) REFERENCES locations (id),
  CONSTRAINT fk_skill_reviewer FOREIGN KEY (reviewed_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS jobs (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  employer_name VARCHAR(100) NOT NULL,
  title VARCHAR(140) NOT NULL,
  service_id SMALLINT UNSIGNED NOT NULL,
  location_id SMALLINT UNSIGNED NOT NULL,
  job_type VARCHAR(40) NOT NULL,
  pay VARCHAR(80) NOT NULL DEFAULT '',
  phone VARCHAR(24) NOT NULL,
  email VARCHAR(254) NOT NULL DEFAULT '',
  description TEXT NOT NULL,
  status ENUM('published', 'closed', 'removed') NOT NULL DEFAULT 'published',
  posted_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  expires_at DATETIME NULL,
  PRIMARY KEY (id),
  KEY ix_jobs_status_date (status, posted_at),
  KEY ix_jobs_service_location (service_id, location_id),
  CONSTRAINT fk_job_service FOREIGN KEY (service_id) REFERENCES services (id),
  CONSTRAINT fk_job_location FOREIGN KEY (location_id) REFERENCES locations (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS promotions (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  provider_id BIGINT UNSIGNED NOT NULL,
  skill VARCHAR(100) NOT NULL,
  headline VARCHAR(140) NOT NULL,
  details TEXT NOT NULL,
  active TINYINT(1) NOT NULL DEFAULT 1,
  expires_at DATE NULL,
  created_by BIGINT UNSIGNED NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY ix_promotions_active_expiry (active, expires_at),
  CONSTRAINT fk_promotion_provider FOREIGN KEY (provider_id) REFERENCES service_providers (id) ON DELETE CASCADE,
  CONSTRAINT fk_promotion_admin FOREIGN KEY (created_by) REFERENCES users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS community_updates (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  admin_id BIGINT UNSIGNED NOT NULL,
  update_type ENUM('Upcoming work', 'Community update') NOT NULL,
  title VARCHAR(140) NOT NULL,
  location VARCHAR(100) NOT NULL DEFAULT '',
  event_date DATE NULL,
  details TEXT NOT NULL,
  published_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY ix_updates_published (published_at),
  CONSTRAINT fk_update_admin FOREIGN KEY (admin_id) REFERENCES users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS messages (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id BIGINT UNSIGNED NULL,
  name VARCHAR(100) NOT NULL,
  email VARCHAR(254) NOT NULL,
  subject VARCHAR(100) NOT NULL,
  message TEXT NOT NULL,
  status ENUM('new', 'reviewed', 'closed') NOT NULL DEFAULT 'new',
  received_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY ix_messages_status_date (status, received_at),
  CONSTRAINT fk_message_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS login_logs (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id BIGINT UNSIGNED NOT NULL,
  email VARCHAR(254) NOT NULL,
  role VARCHAR(20) NOT NULL,
  ip_address VARBINARY(16) NULL,
  signed_in_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY ix_logins_date (signed_in_at),
  KEY ix_logins_email (email),
  CONSTRAINT fk_login_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS bookings (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  customer_id BIGINT UNSIGNED NOT NULL,
  provider_id BIGINT UNSIGNED NOT NULL,
  requested_for DATETIME NULL,
  notes TEXT NOT NULL,
  status ENUM('requested', 'accepted', 'declined', 'completed', 'cancelled') NOT NULL DEFAULT 'requested',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY ix_bookings_customer (customer_id, created_at),
  KEY ix_bookings_provider (provider_id, created_at),
  CONSTRAINT fk_booking_customer FOREIGN KEY (customer_id) REFERENCES users (id) ON DELETE CASCADE,
  CONSTRAINT fk_booking_provider FOREIGN KEY (provider_id) REFERENCES service_providers (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS reviews (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  booking_id BIGINT UNSIGNED NOT NULL,
  customer_id BIGINT UNSIGNED NOT NULL,
  provider_id BIGINT UNSIGNED NOT NULL,
  rating TINYINT UNSIGNED NOT NULL,
  comment TEXT NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_review_booking (booking_id),
  KEY ix_reviews_provider (provider_id, created_at),
  CONSTRAINT fk_review_booking FOREIGN KEY (booking_id) REFERENCES bookings (id) ON DELETE CASCADE,
  CONSTRAINT fk_review_customer FOREIGN KEY (customer_id) REFERENCES users (id) ON DELETE CASCADE,
  CONSTRAINT fk_review_provider FOREIGN KEY (provider_id) REFERENCES service_providers (id) ON DELETE CASCADE,
  CONSTRAINT chk_review_rating CHECK (rating BETWEEN 1 AND 5)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO services (name) VALUES
  ('Plumber'), ('Electrician'), ('Cleaning'), ('Mechanic'), ('Building & fundi'),
  ('Phone & computer repair'), ('Delivery'), ('Barber & salon'), ('Tutor'), ('House moving');

INSERT IGNORE INTO locations (name, county) VALUES
  ('Ongata Rongai', 'Kajiado'), ('Kiserian', 'Kajiado'), ('Karen', 'Nairobi'),
  ('Kibera', 'Nairobi'), ('Ngong', 'Kajiado'), ('Nairobi CBD', 'Nairobi'),
  ('Lang’ata', 'Nairobi'), ('Kitengela', 'Kajiado'), ('Thika', 'Kiambu'), ('Mombasa', 'Mombasa');