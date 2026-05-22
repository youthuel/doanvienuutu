-- =================================================================
-- DVUT DATABASE SCHEMA — Phiên bản 5.1.0
-- =================================================================
-- File SQL tham chiếu — Dùng trong phpMyAdmin hoặc MySQL CLI.
-- LƯU Ý: Thay "wp_" bằng prefix thực tế của WordPress.
--
-- TRẠNG THÁI THỐNG NHẤT (JS ↔ PHP ↔ DB):
--   GĐ1: CHO_NOP
--   GĐ2: CHO_CHI_DOAN → CHO_DOAN_KHOA → CHO_DOAN_TRUONG → DA_CONG_NHAN
--   GĐ4: CHO_DK_GIOI_THIEU → CHO_DT_XAC_NHAN_GT
--        → CHUYEN_GIAO_CHI_BO → CHI_BO_DANG_THEO_DOI
--        → CHO_DANG_UY_TRUONG_XET → DA_CO_QD_KET_NAP → DANG_VIEN_DU_BI
--   GĐ5: CHO_DK_CHUYEN_DANG → CHO_DT_XAC_NHAN_CD → DANG_VIEN_CHINH_THUC
--   Ngoại lệ: TU_CHOI | TRA_VE | DA_HUY
-- =================================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;


-- -----------------------------------------------------------------
-- BẢNG 1: wp_khoa — Danh mục Khoa
-- -----------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `wp_khoa` (
    `id`         BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
    `ten`        VARCHAR(255) NOT NULL                    COMMENT 'Tên Khoa',
    `ma_khoa`    VARCHAR(20)  NOT NULL DEFAULT ''         COMMENT 'Mã viết tắt (KT, LUAT, QTKD, TC, KTKT)',
    `chi_bo_id`  INT UNSIGNED DEFAULT 0                   COMMENT 'Mã Chi bộ Sinh viên (1-7) quản lý Khoa này',
    `is_active`  TINYINT(1)   NOT NULL DEFAULT 1          COMMENT '1=Đang hoạt động, 0=Đã ngưng',
    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_ma_khoa` (`ma_khoa`),
    KEY `idx_chi_bo` (`chi_bo_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- -----------------------------------------------------------------
-- BẢNG 2: wp_chi_doan — Danh mục Chi Đoàn
-- -----------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `wp_chi_doan` (
    `id`          BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
    `ten`         VARCHAR(255) NOT NULL                   COMMENT 'Tên Chi Đoàn',
    `ma_chi_doan` VARCHAR(20)  NOT NULL DEFAULT ''        COMMENT 'Mã viết tắt',
    `khoa_id`     BIGINT(20) UNSIGNED NOT NULL DEFAULT 0  COMMENT 'FK → wp_khoa.id',
    `nien_khoa`   VARCHAR(20)  NOT NULL DEFAULT ''        COMMENT 'Niên khóa (2025-2026)',
    `is_active`   TINYINT(1)   NOT NULL DEFAULT 1         COMMENT '1=Đang hoạt động, 0=Đã ngưng',
    `created_at`  DATETIME DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_khoa` (`khoa_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- -----------------------------------------------------------------
-- BẢNG 3: wp_dvut_dot_xet — Đợt xét duyệt (niên khóa)
-- -----------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `wp_dvut_dot_xet` (
    `id`              BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
    `ten_dot`         VARCHAR(255) NOT NULL                   COMMENT 'Tên đợt: Đợt 1 - HK2 2025-2026',
    `nien_khoa`       VARCHAR(20)  NOT NULL DEFAULT ''        COMMENT 'Niên khóa: 2025-2026',
    `ngay_bat_dau`    DATE NOT NULL                           COMMENT 'Ngày bắt đầu đợt xét',
    `ngay_ket_thuc`   DATE NOT NULL                           COMMENT 'Ngày kết thúc đợt xét',
    `han_nop_ho_so`   DATE DEFAULT NULL                       COMMENT 'Hạn chót nộp hồ sơ GĐ1',
    `trang_thai`      VARCHAR(20) NOT NULL DEFAULT 'CHUAN_BI' COMMENT 'CHUAN_BI | DANG_MO | DA_DONG',
    `nguoi_tao`       BIGINT(20) UNSIGNED DEFAULT 0           COMMENT 'FK → wp_users.ID',
    `created_at`      DATETIME DEFAULT CURRENT_TIMESTAMP,
    `updated_at`      DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_nien_khoa` (`nien_khoa`),
    KEY `idx_trang_thai` (`trang_thai`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- -----------------------------------------------------------------
-- BẢNG 4: wp_doan_vien_uu_tu — Hồ sơ chính ĐVƯT
-- -----------------------------------------------------------------
-- Phiên bản 3.0: Denormalized — tất cả biểu quyết, file URL,
-- người duyệt, ngày duyệt lưu trực tiếp vào bảng chính.
-- -----------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `wp_doan_vien_uu_tu` (
    `id`                      BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,

    -- === THÔNG TIN CÁ NHÂN ===
    `ho_ten`                  VARCHAR(255) NOT NULL,
    `mssv`                    VARCHAR(20)  NOT NULL,
    `chi_doan_id`             BIGINT(20) UNSIGNED NOT NULL DEFAULT 0   COMMENT 'FK → wp_chi_doan.id',
    `chi_doan`                VARCHAR(255) DEFAULT ''                  COMMENT 'Snapshot tên tại thời điểm đề cử',
    `khoa_id`                 BIGINT(20) UNSIGNED NOT NULL DEFAULT 0   COMMENT 'FK → wp_khoa.id',
    `khoa`                    VARCHAR(255) DEFAULT ''                  COMMENT 'Snapshot tên tại thời điểm đề cử',

    -- === THÔNG TIN ĐỀ CỬ ===
    `dot_xet_id`              BIGINT(20) UNSIGNED DEFAULT NULL         COMMENT 'FK → wp_dvut_dot_xet.id',
    `ngay_de_cu`              DATE DEFAULT NULL,
    `nguoi_de_cu`             BIGINT(20) UNSIGNED DEFAULT 0            COMMENT 'FK → wp_users.ID',

    -- === TRẠNG THÁI CHÍNH ===
    `trang_thai`              VARCHAR(30) NOT NULL DEFAULT 'CHO_NOP',

    -- === GĐ1: HỒ SƠ ===
    `so_luoc_qua_trinh`       TEXT DEFAULT NULL                        COMMENT 'Mẫu 01',
    `bai_cam_nhan_file`       VARCHAR(500) DEFAULT ''                  COMMENT 'Mẫu 02',

    -- === GĐ3: CẢM TÌNH ĐẢNG ===
    `tien_do_cam_tinh_dang`   VARCHAR(20) NOT NULL DEFAULT 'CHUA_THAM_GIA',
    `file_chung_nhan_ctd`     VARCHAR(500) DEFAULT ''                  COMMENT 'Ảnh/PDF Giấy CN hoàn thành CTĐ',
    `so_chung_nhan_ctd`       VARCHAR(100) NOT NULL DEFAULT ''         COMMENT 'Số chứng nhận cảm tình Đảng',
    `ngay_chung_nhan_ctd`     DATE DEFAULT NULL                        COMMENT 'Ngày chứng nhận cảm tình Đảng',

    -- === GĐ2: ĐOÀN TRƯỜNG QUYẾT ĐỊNH ===
    `so_quyet_dinh`           VARCHAR(100) DEFAULT '',
    `ngay_cong_nhan`          DATE DEFAULT NULL,
    `nguoi_cong_nhan`         BIGINT(20) UNSIGNED DEFAULT 0,

    -- === GĐ4/5: MỐC THỜI GIAN ===
    `ngay_gioi_thieu_dang`    DATETIME DEFAULT NULL,
    `ngay_chuyen_dang`        DATETIME DEFAULT NULL,

    -- === CHI BỘ SINH VIÊN: PHÂN CÔNG & THEO DÕI ===
    `dang_vien_phu_trach`     VARCHAR(255) DEFAULT ''                  COMMENT 'Tên Đảng viên chính thức được phân công',
    `ngay_bat_dau_theo_doi`   DATE DEFAULT NULL                        COMMENT 'Ngày bắt đầu Chi bộ theo dõi',

    -- === CHI BỘ SINH VIÊN: BIỂU QUYẾT NGHỊ QUYẾT ===
    `tong_so_cb`              INT UNSIGNED DEFAULT 0                   COMMENT 'BQ Chi bộ — tổng số',
    `so_dong_y_cb`            INT UNSIGNED DEFAULT 0                   COMMENT 'BQ Chi bộ — đồng ý',
    `ty_le_cb`                DECIMAL(5,2) DEFAULT 0.00                COMMENT 'BQ Chi bộ — tỷ lệ %',
    `nghi_quyet_chi_bo_file`  VARCHAR(500) DEFAULT ''                  COMMENT 'File Nghị quyết Chi bộ',

    -- === CHI BỘ SINH VIÊN: QUYẾT ĐỊNH KẾT NẠP ===
    `so_qd_ket_nap`           VARCHAR(100) DEFAULT ''                  COMMENT 'Số QĐ kết nạp Đảng uỷ ĐHQG',
    `file_qd_ket_nap`         VARCHAR(500) DEFAULT ''                  COMMENT 'Scan Quyết định kết nạp',
    `ngay_qd_ket_nap`         DATE DEFAULT NULL                        COMMENT 'Ngày ký Quyết định kết nạp',

    -- === CHI BỘ SINH VIÊN: LỄ KẾT NẠP ===
    `ngay_le_ket_nap`         DATE DEFAULT NULL                        COMMENT 'Ngày tổ chức Lễ kết nạp',

    -- === NHẬN XÉT ===
    `uu_diem`                 TEXT DEFAULT NULL,
    `khuyet_diem`             TEXT DEFAULT NULL,

    -- === GĐ2: BIỂU QUYẾT CHI ĐOÀN ===
    `tong_so_nguoi`           INT UNSIGNED DEFAULT 0                   COMMENT 'BQ Chi Đoàn — tổng số',
    `so_luot_dong_y`          INT UNSIGNED DEFAULT 0                   COMMENT 'BQ Chi Đoàn — đồng ý',
    `ty_le`                   DECIMAL(5,2) DEFAULT 0.00                COMMENT 'BQ Chi Đoàn — tỷ lệ %',
    `bien_ban_chi_doan_file`  VARCHAR(500) DEFAULT ''                  COMMENT 'Mẫu 03',
    `nguoi_duyet_cd`          BIGINT(20) UNSIGNED DEFAULT 0            COMMENT 'FK → wp_users.ID',
    `ngay_duyet_cd`           DATETIME DEFAULT NULL,

    -- === GĐ2: BIỂU QUYẾT ĐOÀN KHOA ===
    `tong_so_dk`              INT UNSIGNED DEFAULT 0                   COMMENT 'BQ Đoàn Khoa — tổng số',
    `so_luot_dong_y_dk`       INT UNSIGNED DEFAULT 0                   COMMENT 'BQ Đoàn Khoa — đồng ý',
    `ty_le_dk`                DECIMAL(5,2) DEFAULT 0.00                COMMENT 'BQ Đoàn Khoa — tỷ lệ %',
    `cong_van_dk_file`        VARCHAR(500) DEFAULT ''                  COMMENT 'Mẫu 04',
    `bien_ban_dk_file`        VARCHAR(500) DEFAULT ''                  COMMENT 'Mẫu 05',
    `nguoi_duyet_dk`          BIGINT(20) UNSIGNED DEFAULT 0            COMMENT 'FK → wp_users.ID',
    `ngay_duyet_dk`           DATETIME DEFAULT NULL,

    -- === GĐ4: BIỂU QUYẾT GIỚI THIỆU ĐẢNG ===
    `tong_so_gt`              INT UNSIGNED DEFAULT 0                   COMMENT 'BQ Giới thiệu — tổng số',
    `so_luot_dong_y_gt`       INT UNSIGNED DEFAULT 0                   COMMENT 'BQ Giới thiệu — đồng ý',
    `ty_le_gt`                DECIMAL(5,2) DEFAULT 0.00                COMMENT 'BQ Giới thiệu — tỷ lệ %',
    `bien_ban_gt_file`        VARCHAR(500) DEFAULT ''                  COMMENT 'Mẫu 06',
    `nghi_quyet_dk_file`      VARCHAR(500) DEFAULT ''                  COMMENT 'Mẫu 07 - Nghị quyết ĐK',
    `ngay_nghi_quyet_gt`      DATETIME DEFAULT NULL,

    -- === GĐ5: BIỂU QUYẾT CHUYỂN ĐẢNG CHÍNH THỨC ===
    `tong_so_cd_ct`           INT UNSIGNED DEFAULT 0                   COMMENT 'BQ Chuyển Đảng CT — tổng số',
    `so_luot_dong_y_cd_ct`    INT UNSIGNED DEFAULT 0                   COMMENT 'BQ Chuyển Đảng CT — đồng ý',
    `ty_le_cd_ct`             DECIMAL(5,2) DEFAULT 0.00                COMMENT 'BQ Chuyển Đảng CT — tỷ lệ %',
    `bien_ban_cd_ct_file`     VARCHAR(500) DEFAULT ''                  COMMENT 'Mẫu 08',
    `y_kien_dk_file`          VARCHAR(500) DEFAULT ''                  COMMENT 'Mẫu 09 - Ý kiến nhận xét ĐK',

    -- === GHI CHÚ & METADATA ===
    `ghi_chu`                 TEXT DEFAULT NULL,
    `created_at`              DATETIME DEFAULT CURRENT_TIMESTAMP,
    `updated_at`              DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    PRIMARY KEY (`id`),
    KEY `idx_trang_thai` (`trang_thai`),
    KEY `idx_chi_doan` (`chi_doan_id`),
    KEY `idx_khoa` (`khoa_id`),
    KEY `idx_mssv` (`mssv`),
    KEY `idx_dot_xet` (`dot_xet_id`),
    KEY `idx_tien_do_ctd` (`tien_do_cam_tinh_dang`),
    KEY `idx_ngay_gioi_thieu` (`ngay_gioi_thieu_dang`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- -----------------------------------------------------------------
-- BẢNG 5: wp_dvut_bieu_quyet — Kết quả biểu quyết (normalized)
-- -----------------------------------------------------------------
-- Thay thế 15+ cột biểu quyết rải rác trong bảng chính.
-- Mỗi lần biểu quyết = 1 row, dễ truy vấn lịch sử.
-- -----------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `wp_dvut_bieu_quyet` (
    `id`              BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
    `dvut_id`         BIGINT(20) UNSIGNED NOT NULL            COMMENT 'FK → wp_doan_vien_uu_tu.id',
    `giai_doan`       VARCHAR(30) NOT NULL
                      COMMENT 'GD2_CHI_DOAN | GD2_DOAN_KHOA | GD4_GIOI_THIEU | GD5_CHUYEN_DANG',
    `tong_so_nguoi`   INT UNSIGNED DEFAULT 0                  COMMENT 'Tổng số người tham gia BQ',
    `so_dong_y`       INT UNSIGNED DEFAULT 0                  COMMENT 'Số lượt đồng ý',
    `ty_le`           DECIMAL(5,2) DEFAULT 0.00               COMMENT 'Tỷ lệ BQ (%)',
    `ket_qua`         VARCHAR(15) DEFAULT NULL                COMMENT 'DAT | KHONG_DAT',
    `nguoi_duyet`     BIGINT(20) UNSIGNED DEFAULT 0           COMMENT 'FK → wp_users.ID',
    `ngay_duyet`      DATETIME DEFAULT NULL,
    `ghi_chu`         TEXT DEFAULT NULL,
    `created_at`      DATETIME DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_dvut` (`dvut_id`),
    KEY `idx_giai_doan` (`giai_doan`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- -----------------------------------------------------------------
-- BẢNG 6: wp_dvut_blackbox — Hộp đen dữ liệu gốc (Import Excel)
-- -----------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `wp_dvut_blackbox` (
    `id`                        BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
    `mssv`                      VARCHAR(20)  NOT NULL,
    `ho_ten`                    VARCHAR(255) NOT NULL,
    `chi_doan`                  VARCHAR(255) DEFAULT '',
    `chi_doan_id`               BIGINT(20) UNSIGNED DEFAULT 0         COMMENT 'FK → wp_chi_doan.id',
    `khoa`                      VARCHAR(255) DEFAULT '',
    `khoa_id`                   BIGINT(20) UNSIGNED DEFAULT 0         COMMENT 'FK → wp_khoa.id',
    `ly_luan_chinh_tri`        VARCHAR(50)  DEFAULT ''               COMMENT 'Hoàn thành / Đã đăng ký / LLCT',
    `xep_loai_doan_vien`        VARCHAR(50)  DEFAULT ''               COMMENT 'Hoàn thành xuất sắc / tốt / HT / KHT',
    `diem_tb_tich_luy`          DECIMAL(4,2) DEFAULT 0.00             COMMENT 'Điểm TB tích lũy (thang 10)',
    `diem_ren_luyen`            INT UNSIGNED DEFAULT 0                COMMENT 'Điểm rèn luyện (0-100)',
    `imported_at`               DATETIME DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_mssv` (`mssv`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- -----------------------------------------------------------------
-- BẢNG 7: wp_dvut_user_roles — Phân quyền mở rộng DVUT
-- -----------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `wp_dvut_user_roles` (
    `id`          BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
    `user_id`     BIGINT(20) UNSIGNED NOT NULL             COMMENT 'FK → wp_users.ID',
    `role`        VARCHAR(30) NOT NULL DEFAULT 'bch_chi_doan'
                  COMMENT 'admin_doan_truong | can_bo_doan_khoa | bch_chi_doan | chi_bo_sinh_vien',
    `khoa_id`     BIGINT(20) UNSIGNED DEFAULT 0            COMMENT 'Khoa phụ trách (0 = toàn trường)',
    `chi_doan_id` BIGINT(20) UNSIGNED DEFAULT 0            COMMENT 'Chi Đoàn phụ trách (0 = toàn khoa)',
    `chi_bo_id`   INT UNSIGNED DEFAULT 0                   COMMENT 'Chi bộ SV phụ trách (1-7, dùng cho role chi_bo_sinh_vien)',
    `is_active`   TINYINT(1) DEFAULT 1,
    `created_at`  DATETIME DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_user` (`user_id`),
    KEY `idx_role` (`role`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- -----------------------------------------------------------------
-- BẢNG 8: wp_dvut_log — Audit Trail (Lịch sử thao tác)
-- -----------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `wp_dvut_log` (
    `id`            BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
    `dvut_id`       BIGINT(20) UNSIGNED NOT NULL            COMMENT 'FK → wp_doan_vien_uu_tu.id',
    `action`        VARCHAR(50) NOT NULL                    COMMENT 'de_cu | nop_ho_so | chi_doan_duyet | ...',
    `old_status`    VARCHAR(30) DEFAULT NULL                COMMENT 'Trạng thái trước thao tác',
    `new_status`    VARCHAR(30) DEFAULT NULL                COMMENT 'Trạng thái sau thao tác',
    `performed_by`  BIGINT(20) UNSIGNED NOT NULL DEFAULT 0  COMMENT 'FK → wp_users.ID',
    `ip_address`    VARCHAR(45) DEFAULT NULL                COMMENT 'IP người thao tác',
    `note`          TEXT DEFAULT NULL                       COMMENT 'Chi tiết / lý do',
    `extra_data`    LONGTEXT DEFAULT NULL                   COMMENT 'JSON metadata mở rộng',
    `created_at`    DATETIME DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_dvut` (`dvut_id`),
    KEY `idx_user` (`performed_by`),
    KEY `idx_action` (`action`),
    KEY `idx_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- -----------------------------------------------------------------
-- BẢNG 9: wp_dvut_files — Quản lý file upload tập trung
-- -----------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `wp_dvut_files` (
    `id`          BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
    `dvut_id`     BIGINT(20) UNSIGNED NOT NULL              COMMENT 'FK → wp_doan_vien_uu_tu.id',
    `loai_file`   VARCHAR(30) NOT NULL
                  COMMENT 'MAU_01 | MAU_02 | MAU_03 | MAU_04 | MAU_05 | MAU_06 | MAU_07 | MAU_08 | MAU_09 | CHUNG_NHAN_CTD | KHAC',
    `file_url`    VARCHAR(500) NOT NULL                     COMMENT 'URL file trên server',
    `file_name`   VARCHAR(255) DEFAULT ''                   COMMENT 'Tên file gốc',
    `file_size`   INT UNSIGNED DEFAULT 0                    COMMENT 'Kích thước (bytes)',
    `uploaded_by` BIGINT(20) UNSIGNED DEFAULT 0             COMMENT 'FK → wp_users.ID',
    `uploaded_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_dvut` (`dvut_id`),
    KEY `idx_loai` (`loai_file`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


SET FOREIGN_KEY_CHECKS = 1;
