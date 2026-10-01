-- Reminder CSV source foundation
-- Creates only new tables. Does not alter peserta/pembayaran.
CREATE TABLE IF NOT EXISTS crm_csv_imports (
 id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 label VARCHAR(100) NOT NULL,
 source_filename VARCHAR(255) NOT NULL,
 row_count INT UNSIGNED NOT NULL DEFAULT 0,
 matched_count INT UNSIGNED NOT NULL DEFAULT 0,
 unmatched_count INT UNSIGNED NOT NULL DEFAULT 0,
 duplicate_count INT UNSIGNED NOT NULL DEFAULT 0,
 imported_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 INDEX idx_crm_csv_imported_at (imported_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS crm_csv_participants (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 import_id INT UNSIGNED NOT NULL,
 source_row INT UNSIGNED NOT NULL,
 source_id VARCHAR(100) NULL,
 program VARCHAR(150) NULL,
 periode_level VARCHAR(150) NULL,
 kelas_grup VARCHAR(150) NULL,
 tutor_pengajar VARCHAR(255) NULL,
 nama_murid VARCHAR(255) NOT NULL,
 jenis_kelamin VARCHAR(30) NULL,
 nama_wali VARCHAR(255) NULL,
 whatsapp_wali VARCHAR(50) NULL,
 email_wali VARCHAR(255) NULL,
 status_siswa VARCHAR(100) NULL,
 jatah_per_minggu VARCHAR(50) NULL,
 jatah_per_hari VARCHAR(50) NULL,
 sesi_selesai VARCHAR(50) NULL,
 total_sesi_program VARCHAR(50) NULL,
 progress VARCHAR(50) NULL,
 normalized_wa VARCHAR(30) NULL,
 peserta_id INT UNSIGNED NULL,
 match_status ENUM('matched','unmatched','ambiguous') NOT NULL DEFAULT 'unmatched',
 imported_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 UNIQUE KEY uq_crm_csv_import_row (import_id, source_row),
 INDEX idx_crm_csv_import (import_id),
 INDEX idx_crm_csv_peserta (peserta_id),
 INDEX idx_crm_csv_wa (normalized_wa),
 INDEX idx_crm_csv_match (import_id, match_status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;