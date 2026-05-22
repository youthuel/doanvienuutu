<?php
/**
 * =================================================================
 * DVUT DATABASE INSTALLER — Quản lý Database Đoàn viên Ưu tú
 * =================================================================
 * File này chịu trách nhiệm tạo, cập nhật và quản lý toàn bộ
 * cấu trúc database cho phân hệ "Quản lý Đoàn viên Ưu tú & Phát triển Đảng".
 *
 * HƯỚNG DẪN:
 *   - Gọi DVUT_Database::install() khi kích hoạt theme/plugin
 *   - Gọi DVUT_Database::uninstall() khi gỡ bỏ (tuỳ chọn)
 *   - Dùng DVUT_Database::get_tables() để lấy tên bảng kèm prefix
 *
 * DANH SÁCH BẢNG (12 bảng):
 *   1. wp_khoa                — Danh mục Khoa
 *   2. wp_chi_doan            — Danh mục Chi Đoàn
 *   3. wp_doan_vien_uu_tu     — Hồ sơ chính ĐVƯT
 *   4. wp_dvut_blackbox       — Hộp đen (Import Excel)
 *   5. wp_dvut_user_roles     — Phân quyền RBAC mở rộng
 *   6. wp_dvut_dot_xet        — Đợt xét duyệt (niên khóa)
 *   7. wp_dvut_log            — Audit trail (lịch sử thao tác)
 *   8. wp_dvut_files          — Quản lý file upload tập trung
 *   9. wp_dvut_bieu_quyet     — Biểu quyết (tách từ bảng chính)
 *  10. wp_dvut_nhan_xet       — Nhận xét định kỳ (đánh giá quý)
 *  11. wp_dvut_nhan_xet_period — Kỳ đánh giá (admin quản lý mở/khóa)
 *
 * TRẠNG THÁI THỐNG NHẤT (JS ↔ PHP ↔ DB):
 *   GĐ1: CHO_NOP
 *   GĐ2: CHO_CHI_DOAN → CHO_DOAN_KHOA → CHO_DOAN_TRUONG → DA_CONG_NHAN
 *   GĐ3: (Cảm tình Đảng — trường riêng, không phải trạng thái chính)
 *   GĐ4: CHO_DK_GIOI_THIEU → CHO_DT_XAC_NHAN_GT → CHUYEN_GIAO_CHI_BO → CHI_BO_DANG_THEO_DOI → CHO_DANG_UY_TRUONG_XET → DA_CO_QD_KET_NAP → DANG_VIEN_DU_BI
 *   GĐ5: CHO_DK_CHUYEN_DANG → CHO_DT_XAC_NHAN_CD → DANG_VIEN_CHINH_THUC
 *   Ngoại lệ: TU_CHOI | TRA_VE | DA_HUY
 * =================================================================
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class DVUT_Database {

    /** @var string Phiên bản database — tăng khi thay đổi schema */
    const DB_VERSION = '5.7.0';

    /** @var string Option key lưu phiên bản DB hiện tại */
    const DB_VERSION_OPTION = 'dvut_db_version';

    // -----------------------------------------------------------------
    // DANH SÁCH TÊN BẢNG
    // -----------------------------------------------------------------

    /**
     * Trả về mảng tên bảng có prefix WordPress.
     *
     * @return array Associative array: key → tên bảng đầy đủ.
     */
    public static function get_tables() {
        global $wpdb;
        return array(
            'khoa'            => $wpdb->prefix . 'khoa',
            'chi_doan'        => $wpdb->prefix . 'chi_doan',
            'dvut'            => $wpdb->prefix . 'doan_vien_uu_tu',
            'blackbox'        => $wpdb->prefix . 'dvut_blackbox',
            'user_roles'      => $wpdb->prefix . 'dvut_user_roles',
            'dot_xet'         => $wpdb->prefix . 'dvut_dot_xet',
            'log'             => $wpdb->prefix . 'dvut_log',
            'files'           => $wpdb->prefix . 'dvut_files',
            'nhan_xet'        => $wpdb->prefix . 'dvut_nhan_xet',
            'nhan_xet_period' => $wpdb->prefix . 'dvut_nhan_xet_period',
        );
    }

    // -----------------------------------------------------------------
    // TRẠNG THÁI THỐNG NHẤT
    // -----------------------------------------------------------------

    /**
     * Danh sách tất cả trạng thái hợp lệ.
     * Dùng chung cho JS, PHP và DB — NGUỒN DUY NHẤT (Single Source of Truth).
     *
     * @return array Mảng tên trạng thái.
     */
    public static function get_valid_statuses() {
        return array(
            // GĐ1: Sàng lọc & Đề cử
            'CHO_NOP',

            // GĐ2: Công nhận ĐVƯT
            'CHO_CHI_DOAN',
            'CHO_DOAN_KHOA',
            'CHO_DOAN_TRUONG',
            'DA_CONG_NHAN',

            // GĐ4: Giới thiệu vào Đảng
            'CHO_DK_GIOI_THIEU',
            'CHO_DT_XAC_NHAN_GT',

            // GĐ4b: Chi bộ Sinh viên (Bước 5-10)
            'CHUYEN_GIAO_CHI_BO',
            'CHI_BO_DANG_THEO_DOI',
            'CHO_DANG_UY_TRUONG_XET',
            'DA_CO_QD_KET_NAP',
            'DANG_VIEN_DU_BI',

            // GĐ5: Chuyển Đảng chính thức
            'CHO_DK_CHUYEN_DANG',
            'CHO_DT_XAC_NHAN_CD',
            'DANG_VIEN_CHINH_THUC',

            // Ngoại lệ
            'TU_CHOI',
            'TRA_VE',
            'DA_HUY',
        );
    }

    /**
     * Danh sách trạng thái Cảm tình Đảng hợp lệ.
     *
     * @return array
     */
    public static function get_valid_ctd_statuses() {
        return array( 'CHUA_THAM_GIA', 'DANG_HOC', 'DA_HOAN_THANH' );
    }

    // =================================================================
    // CÀI ĐẶT DATABASE
    // =================================================================

    /**
     * Tạo / cập nhật tất cả bảng database.
     * Sử dụng dbDelta() của WordPress — an toàn để chạy lại nhiều lần.
     */
    public static function install() {
        global $wpdb;
        $charset_collate = $wpdb->get_charset_collate();
        $tables          = self::get_tables();

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        // ─── BẢNG 1: Khoa ───
        $sql_khoa = "CREATE TABLE {$tables['khoa']} (
            id       bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            ten      varchar(255) NOT NULL,
            ma_khoa  varchar(20) NOT NULL DEFAULT '',
            chi_bo_id int unsigned NOT NULL DEFAULT 0 COMMENT 'Chi bộ Sinh viên quản lý (1-7)',
            is_active tinyint(1) NOT NULL DEFAULT 1,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            UNIQUE KEY uk_ma_khoa (ma_khoa),
            KEY idx_chi_bo (chi_bo_id)
        ) {$charset_collate};";

        // ─── BẢNG 2: Chi Đoàn ───
        $sql_chi_doan = "CREATE TABLE {$tables['chi_doan']} (
            id          bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            ten         varchar(255) NOT NULL,
            ma_chi_doan varchar(20) NOT NULL DEFAULT '',
            khoa_id     bigint(20) unsigned NOT NULL DEFAULT 0,
            nien_khoa   varchar(20) NOT NULL DEFAULT '',
            is_active   tinyint(1) NOT NULL DEFAULT 1,
            created_at  datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            KEY idx_khoa (khoa_id)
        ) {$charset_collate};";

        // ─── BẢNG 3: Đợt xét duyệt ───
        $sql_dot_xet = "CREATE TABLE {$tables['dot_xet']} (
            id              bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            ten_dot         varchar(255) NOT NULL,
            nien_khoa       varchar(20) NOT NULL DEFAULT '',
            ngay_bat_dau    date NOT NULL,
            ngay_ket_thuc   date NOT NULL,
            han_nop_ho_so   date DEFAULT NULL,
            trang_thai      varchar(20) NOT NULL DEFAULT 'CHUAN_BI',
            nguoi_tao       bigint(20) unsigned DEFAULT 0,
            created_at      datetime DEFAULT CURRENT_TIMESTAMP,
            updated_at      datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            KEY idx_nien_khoa (nien_khoa),
            KEY idx_trang_thai (trang_thai)
        ) {$charset_collate};";

        // ─── BẢNG 4: Hồ sơ Đoàn viên Ưu tú (Bảng chính) ───
        $sql_dvut = "CREATE TABLE {$tables['dvut']} (
            id                      bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            ho_ten                  varchar(255) NOT NULL,
            mssv                    varchar(20) NOT NULL,
            chi_doan_id             bigint(20) unsigned NOT NULL DEFAULT 0,
            chi_doan                varchar(255) DEFAULT '',
            khoa_id                 bigint(20) unsigned NOT NULL DEFAULT 0,
            khoa                    varchar(255) DEFAULT '',
            dot_xet_id              bigint(20) unsigned DEFAULT NULL,
            ngay_de_cu              date DEFAULT NULL,
            nguoi_de_cu             bigint(20) unsigned DEFAULT 0,
            trang_thai              varchar(30) NOT NULL DEFAULT 'CHO_NOP',
            so_luoc_qua_trinh       text DEFAULT NULL,
            bai_cam_nhan_file       varchar(500) DEFAULT '',
            tien_do_cam_tinh_dang   varchar(20) NOT NULL DEFAULT 'CHUA_THAM_GIA',
            file_chung_nhan_ctd     varchar(500) DEFAULT '',
            so_chung_nhan_ctd       varchar(100) NOT NULL DEFAULT '' COMMENT 'Số chứng nhận cảm tình Đảng',
            ngay_chung_nhan_ctd     date DEFAULT NULL COMMENT 'Ngày chứng nhận cảm tình Đảng',
            so_quyet_dinh           varchar(100) DEFAULT '',
            ngay_cong_nhan          date DEFAULT NULL,
            nguoi_cong_nhan         bigint(20) unsigned DEFAULT 0,
            ngay_gioi_thieu_dang    datetime DEFAULT NULL,
            ngay_chuyen_dang        datetime DEFAULT NULL,
            uu_diem                 text DEFAULT NULL,
            khuyet_diem             text DEFAULT NULL,
            tong_so_nguoi           int unsigned DEFAULT 0,
            so_luot_dong_y          int unsigned DEFAULT 0,
            ty_le                   decimal(5,2) DEFAULT 0.00,
            bien_ban_chi_doan_file  varchar(500) DEFAULT '',
            nguoi_duyet_cd          bigint(20) unsigned DEFAULT 0,
            ngay_duyet_cd           datetime DEFAULT NULL,
            tong_so_dk              int unsigned DEFAULT 0,
            so_luot_dong_y_dk       int unsigned DEFAULT 0,
            ty_le_dk                decimal(5,2) DEFAULT 0.00,
            cong_van_dk_file        varchar(500) DEFAULT '',
            bien_ban_dk_file        varchar(500) DEFAULT '',
            nguoi_duyet_dk          bigint(20) unsigned DEFAULT 0,
            ngay_duyet_dk           datetime DEFAULT NULL,
            tong_so_gt              int unsigned DEFAULT 0,
            so_luot_dong_y_gt       int unsigned DEFAULT 0,
            ty_le_gt                decimal(5,2) DEFAULT 0.00,
            bien_ban_gt_file        varchar(500) DEFAULT '',
            nghi_quyet_dk_file      varchar(500) DEFAULT '',
            ngay_nghi_quyet_gt      datetime DEFAULT NULL,
            tong_so_cd_ct           int unsigned DEFAULT 0,
            so_luot_dong_y_cd_ct    int unsigned DEFAULT 0,
            ty_le_cd_ct             decimal(5,2) DEFAULT 0.00,
            bien_ban_cd_ct_file     varchar(500) DEFAULT '',
            y_kien_dk_file          varchar(500) DEFAULT '',
            dang_vien_phu_trach     varchar(255) DEFAULT '' COMMENT 'Đảng viên chính thức được Chi bộ phân công theo dõi',
            ngay_bat_dau_theo_doi   date DEFAULT NULL COMMENT 'Ngày bắt đầu theo dõi (Chi bộ phân công)',
            tong_so_cb              int unsigned DEFAULT 0 COMMENT 'Tổng số đảng viên Chi bộ tham gia biểu quyết',
            so_dong_y_cb            int unsigned DEFAULT 0 COMMENT 'Số đảng viên đồng ý trong Chi bộ',
            ty_le_cb                decimal(5,2) DEFAULT 0.00 COMMENT 'Tỷ lệ biểu quyết Chi bộ (%)',
            nghi_quyet_chi_bo_file  varchar(500) DEFAULT '' COMMENT 'File Nghị quyết giới thiệu kết nạp Đảng của Chi bộ',
            so_qd_ket_nap           varchar(100) DEFAULT '' COMMENT 'Số Quyết định kết nạp của Đảng ủy ĐHQG-HCM',
            file_qd_ket_nap         varchar(500) DEFAULT '' COMMENT 'File scan Quyết định kết nạp',
            ngay_qd_ket_nap         date DEFAULT NULL COMMENT 'Ngày ký Quyết định kết nạp',
            ngay_le_ket_nap         date DEFAULT NULL COMMENT 'Ngày tổ chức Lễ kết nạp',
            ghi_chu                 text DEFAULT NULL,
            trang_thai_truoc_tu_choi varchar(30) DEFAULT NULL COMMENT 'Trạng thái trước khi bị từ chối (Admin gỡ từ chối)',
            mau01_file              varchar(500) DEFAULT '' COMMENT 'File Mẫu 01 — Sơ lược quá trình phấn đấu (PDF/Word)',
            ly_do_tu_choi           text DEFAULT NULL COMMENT 'Lý do từ chối hồ sơ',
            cap_tu_choi             varchar(50) DEFAULT NULL COMMENT 'Cấp đã từ chối (Chi Đoàn / Đoàn Khoa / Đoàn Trường)',
            ngay_tu_choi            datetime DEFAULT NULL COMMENT 'Thời điểm từ chối',
            chi_bo_id               int unsigned DEFAULT 0 COMMENT 'Chi bộ được gán sau khi công nhận ĐVƯT',
            created_at              datetime DEFAULT CURRENT_TIMESTAMP,
            updated_at              datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            KEY idx_trang_thai (trang_thai),
            KEY idx_chi_doan (chi_doan_id),
            KEY idx_khoa (khoa_id),
            KEY idx_mssv (mssv),
            KEY idx_dot_xet (dot_xet_id),
            KEY idx_tien_do_ctd (tien_do_cam_tinh_dang),
            KEY idx_ngay_gioi_thieu (ngay_gioi_thieu_dang)
        ) {$charset_collate};";

        // ─── BẢNG 5: Biểu quyết (tách từ bảng chính) ───
        $sql_bieu_quyet = "CREATE TABLE {$wpdb->prefix}dvut_bieu_quyet (
            id              bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            dvut_id         bigint(20) unsigned NOT NULL,
            giai_doan       varchar(30) NOT NULL,
            tong_so_nguoi   int unsigned DEFAULT 0,
            so_dong_y       int unsigned DEFAULT 0,
            ty_le           decimal(5,2) DEFAULT 0.00,
            ket_qua         varchar(15) DEFAULT NULL,
            nguoi_duyet     bigint(20) unsigned DEFAULT 0,
            ngay_duyet      datetime DEFAULT NULL,
            ghi_chu         text DEFAULT NULL,
            created_at      datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            KEY idx_dvut (dvut_id),
            KEY idx_giai_doan (giai_doan)
        ) {$charset_collate};";

        // ─── BẢNG 6: Hộp đen (Blackbox — Import Excel) ───
        $sql_blackbox = "CREATE TABLE {$tables['blackbox']} (
            id                        bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            mssv                      varchar(20) NOT NULL,
            ho_ten                    varchar(255) NOT NULL,
            email                     varchar(255) DEFAULT '',
            chi_doan                  varchar(255) DEFAULT '',
            chi_doan_id               bigint(20) unsigned DEFAULT 0,
            khoa                      varchar(255) DEFAULT '',
            khoa_id                   bigint(20) unsigned DEFAULT 0,
            ly_luan_chinh_tri     varchar(50) DEFAULT '',
            xep_loai_doan_vien    varchar(50) DEFAULT '',
            diem_tb_tich_luy          decimal(4,2) DEFAULT 0.00,
            diem_ren_luyen            int unsigned DEFAULT 0,
            dot_xet                   varchar(50) DEFAULT '',
            imported_at               datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            UNIQUE KEY uk_mssv (mssv)
        ) {$charset_collate};";

        // ─── BẢNG 7: Phân quyền mở rộng DVUT ───
        $sql_user_roles = "CREATE TABLE {$tables['user_roles']} (
            id          bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            user_id     bigint(20) unsigned NOT NULL,
            role        varchar(30) NOT NULL DEFAULT 'bch_chi_doan',
            khoa_id     bigint(20) unsigned DEFAULT 0,
            chi_doan_id bigint(20) unsigned DEFAULT 0,
            chi_bo_id   int unsigned DEFAULT 0 COMMENT 'Chi bộ Sinh viên (1-7) — dùng cho role chi_bo_sinh_vien',
            is_active   tinyint(1) DEFAULT 1,
            created_at  datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            KEY idx_user (user_id),
            KEY idx_role (role)
        ) {$charset_collate};";

        // ─── BẢNG 8: Audit Log (Lịch sử thao tác + Quản lý hệ thống) ───
        $sql_log = "CREATE TABLE {$tables['log']} (
            id              bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            dvut_id         bigint(20) unsigned DEFAULT 0 COMMENT 'ID hồ sơ ĐVƯT (0 nếu log hệ thống)',
            action          varchar(100) NOT NULL,
            old_status      varchar(30) DEFAULT NULL,
            new_status      varchar(30) DEFAULT NULL,
            target_type     varchar(50) DEFAULT NULL COMMENT 'Loại đối tượng: role, blackbox, record, account',
            target_id       bigint(20) unsigned DEFAULT 0 COMMENT 'ID đối tượng',
            performed_by    bigint(20) unsigned NOT NULL DEFAULT 0,
            ip_address      varchar(45) DEFAULT NULL,
            note            text DEFAULT NULL,
            extra_data      longtext DEFAULT NULL COMMENT 'JSON chi tiết thay đổi',
            created_at      datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            KEY idx_dvut (dvut_id),
            KEY idx_user (performed_by),
            KEY idx_action (action),
            KEY idx_target (target_type, target_id),
            KEY idx_created (created_at)
        ) {$charset_collate};";

        // ─── BẢNG 9: File Upload tập trung ───
        $sql_files = "CREATE TABLE {$tables['files']} (
            id          bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            dvut_id     bigint(20) unsigned NOT NULL,
            loai_file   varchar(30) NOT NULL,
            file_url    varchar(500) NOT NULL,
            file_name   varchar(255) DEFAULT '',
            file_size   int unsigned DEFAULT 0,
            uploaded_by bigint(20) unsigned DEFAULT 0,
            uploaded_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            KEY idx_dvut (dvut_id),
            KEY idx_loai (loai_file)
        ) {$charset_collate};";

        // ─── BẢNG 10: Nhận xét định kỳ (Đánh giá quý) ───
        // BCH Chi Đoàn nhận xét đoàn viên ưu tú theo quý.
        // Chỉ nhận xét khi admin mở kỳ đánh giá (period) tương ứng.
        $sql_nhan_xet = "CREATE TABLE {$tables['nhan_xet']} (
            id              bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            dvut_id         bigint(20) unsigned NOT NULL COMMENT 'ID hồ sơ ĐVƯT',
            period_id       bigint(20) unsigned NOT NULL COMMENT 'ID kỳ đánh giá',
            pham_chat       text DEFAULT NULL COMMENT 'Nhận xét về phẩm chất đạo đức',
            nang_luc        text DEFAULT NULL COMMENT 'Nhận xét về năng lực',
            quan_he         text DEFAULT NULL COMMENT 'Nhận xét về quan hệ quần chúng',
            tong_hop        text DEFAULT NULL COMMENT 'Nhận xét tổng hợp / kết luận',
            nguoi_nhan_xet  bigint(20) unsigned NOT NULL DEFAULT 0,
            chi_doan_id     bigint(20) unsigned NOT NULL DEFAULT 0,
            created_at      datetime DEFAULT CURRENT_TIMESTAMP,
            updated_at      datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            KEY idx_dvut (dvut_id),
            KEY idx_period (period_id),
            KEY idx_chi_doan (chi_doan_id),
            UNIQUE KEY uk_dvut_period (dvut_id, period_id)
        ) {$charset_collate};";

        // ─── BẢNG 11: Kỳ đánh giá (Admin quản lý) ───
        // Admin Đoàn Trường tạo kỳ đánh giá theo quý & năm học.
        // Khi is_open = 1: BCH Chi Đoàn có thể nhận xét/chỉnh sửa.
        // Khi is_open = 0: Nhận xét bị khóa, chỉ admin mới sửa được.
        $sql_nhan_xet_period = "CREATE TABLE {$tables['nhan_xet_period']} (
            id              bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            ten_ky          varchar(255) NOT NULL COMMENT 'Tên kỳ, vd: Quý 1 năm học 2025-2026',
            quy             tinyint(1) NOT NULL COMMENT 'Quý (1-4)',
            nam_hoc         varchar(20) NOT NULL COMMENT 'Năm học, vd: 2025-2026',
            is_open         tinyint(1) NOT NULL DEFAULT 0 COMMENT '1=đang mở, 0=đã khóa',
            ngay_mo         datetime DEFAULT NULL COMMENT 'Ngày mở kỳ đánh giá',
            ngay_khoa       datetime DEFAULT NULL COMMENT 'Ngày khóa kỳ đánh giá',
            nguoi_tao       bigint(20) unsigned NOT NULL DEFAULT 0,
            created_at      datetime DEFAULT CURRENT_TIMESTAMP,
            updated_at      datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            KEY idx_nam_hoc (nam_hoc),
            KEY idx_is_open (is_open),
            UNIQUE KEY uk_quy_nam (quy, nam_hoc)
        ) {$charset_collate};";

        // ─── Chạy dbDelta cho tất cả bảng ───
        dbDelta( $sql_khoa );
        dbDelta( $sql_chi_doan );
        dbDelta( $sql_dot_xet );
        dbDelta( $sql_dvut );
        dbDelta( $sql_bieu_quyet );
        dbDelta( $sql_blackbox );
        dbDelta( $sql_user_roles );
        dbDelta( $sql_log );
        dbDelta( $sql_files );
        dbDelta( $sql_nhan_xet );
        dbDelta( $sql_nhan_xet_period );

        // Chạy migration ALTER TABLE cho các cột mới (đảm bảo an toàn với DB đang có dữ liệu)
        self::run_migrations();

        // Lưu phiên bản DB
        update_option( self::DB_VERSION_OPTION, self::DB_VERSION );
    }

    // =================================================================
    // MIGRATIONS (ALTER TABLE cho cột mới)
    // =================================================================

    /**
     * Thêm các cột mới vào bảng đã tồn tại (chạy an toàn nhiều lần).
     * Dùng SHOW COLUMNS để kiểm tra trước khi ALTER.
     */
    public static function run_migrations() {
        global $wpdb;
        $table = $wpdb->prefix . 'doan_vien_uu_tu';

        $existing = $wpdb->get_col( "SHOW COLUMNS FROM {$table}" ); // phpcs:ignore

        $to_add = array(
            'mau01_file'         => "ALTER TABLE {$table} ADD COLUMN mau01_file varchar(500) NOT NULL DEFAULT '' COMMENT 'File Mẫu 01 — Sơ lược quá trình phấn đấu' AFTER bai_cam_nhan_file",
            'ly_do_tu_choi'      => "ALTER TABLE {$table} ADD COLUMN ly_do_tu_choi TEXT DEFAULT NULL COMMENT 'Lý do từ chối hồ sơ' AFTER trang_thai_truoc_tu_choi",
            'cap_tu_choi'        => "ALTER TABLE {$table} ADD COLUMN cap_tu_choi varchar(50) DEFAULT NULL COMMENT 'Cấp đã từ chối (Chi Đoàn / Đoàn Khoa / Đoàn Trường)' AFTER ly_do_tu_choi",
            'ngay_tu_choi'       => "ALTER TABLE {$table} ADD COLUMN ngay_tu_choi datetime DEFAULT NULL COMMENT 'Thời điểm từ chối' AFTER cap_tu_choi",
            'chi_bo_id'          => "ALTER TABLE {$table} ADD COLUMN chi_bo_id int unsigned NOT NULL DEFAULT 0 COMMENT 'Chi bộ được gán sau khi công nhận ĐVƯT' AFTER ngay_tu_choi",
            'file_qd_cong_nhan'  => "ALTER TABLE {$table} ADD COLUMN file_qd_cong_nhan varchar(500) NOT NULL DEFAULT '' COMMENT 'File Quyết định công nhận ĐVƯT' AFTER so_quyet_dinh",
            'so_chung_nhan_ctd'  => "ALTER TABLE {$table} ADD COLUMN so_chung_nhan_ctd varchar(100) NOT NULL DEFAULT '' COMMENT 'Số chứng nhận cảm tình Đảng' AFTER file_chung_nhan_ctd",
            'ngay_chung_nhan_ctd' => "ALTER TABLE {$table} ADD COLUMN ngay_chung_nhan_ctd date DEFAULT NULL COMMENT 'Ngày chứng nhận cảm tình Đảng' AFTER so_chung_nhan_ctd",
        );

        foreach ( $to_add as $col => $sql ) {
            if ( ! in_array( $col, $existing, true ) ) {
                $wpdb->query( $sql ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
            }
        }
    }

    // =================================================================
    // GỠ CÀI ĐẶT
    // =================================================================

    /**
     * Xóa tất cả bảng DVUT khỏi database.
     * CHỈ GỌI KHI GỠ BỎ HOÀN TOÀN — dữ liệu sẽ mất vĩnh viễn!
     */
    public static function uninstall() {
        global $wpdb;
        $tables = self::get_tables();

        // Thêm bảng bieu_quyet vào danh sách xóa
        $all_tables   = array_values( $tables );
        $all_tables[] = $wpdb->prefix . 'dvut_bieu_quyet';

        // Xóa theo thứ tự ngược (bảng con trước, bảng cha sau)
        $drop_order = array(
            $tables['log'],
            $tables['files'],
            $tables['nhan_xet'],
            $tables['nhan_xet_period'],
            $wpdb->prefix . 'dvut_bieu_quyet',
            $tables['blackbox'],
            $tables['user_roles'],
            $tables['dvut'],
            $tables['dot_xet'],
            $tables['chi_doan'],
            $tables['khoa'],
        );

        foreach ( $drop_order as $table ) {
            $wpdb->query( "DROP TABLE IF EXISTS {$table}" ); // phpcs:ignore
        }

        delete_option( self::DB_VERSION_OPTION );
    }

    // =================================================================
    // KIỂM TRA & CẬP NHẬT
    // =================================================================

    /**
     * Kiểm tra có cần cập nhật schema không.
     * So sánh phiên bản DB lưu trong options với DB_VERSION.
     *
     * @return bool True nếu cần chạy install() lại.
     */
    public static function needs_update() {
        $current = get_option( self::DB_VERSION_OPTION, '0' );
        return version_compare( $current, self::DB_VERSION, '<' );
    }

    /**
     * Kiểm tra và tự động cập nhật nếu cần.
     * Gọi hàm này ở hook 'init' hoặc 'admin_init'.
     */
    public static function maybe_update() {
        if ( self::needs_update() ) {
            self::install();
        }
    }

    // =================================================================
    // AUDIT LOG HELPER
    // =================================================================

    /**
     * Ghi một dòng audit log.
     *
     * @param int    $dvut_id      ID hồ sơ ĐVƯT.
     * @param string $action       Tên hành động (vd: 'de_cu', 'chi_doan_duyet', 'tu_choi').
     * @param string $old_status   Trạng thái cũ (hoặc null).
     * @param string $new_status   Trạng thái mới (hoặc null).
     * @param string $note         Ghi chú bổ sung.
     * @param array  $extra_data   Dữ liệu mở rộng (sẽ được JSON encode).
     */
    public static function write_log( $dvut_id, $action, $old_status = null, $new_status = null, $note = '', $extra_data = array() ) {
        global $wpdb;
        $tables = self::get_tables();

        $wpdb->insert(
            $tables['log'],
            array(
                'dvut_id'      => absint( $dvut_id ),
                'action'       => sanitize_text_field( $action ),
                'old_status'   => $old_status ? sanitize_text_field( $old_status ) : null,
                'new_status'   => $new_status ? sanitize_text_field( $new_status ) : null,
                'performed_by' => get_current_user_id(),
                'ip_address'   => isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( $_SERVER['REMOTE_ADDR'] ) : '',
                'note'         => sanitize_textarea_field( $note ),
                'extra_data'   => ! empty( $extra_data ) ? wp_json_encode( $extra_data ) : null,
            ),
            array( '%d', '%s', '%s', '%s', '%d', '%s', '%s', '%s' )
        );
    }

    // =================================================================
    // FILE UPLOAD HELPER
    // =================================================================

    /**
     * Lưu thông tin file upload vào bảng dvut_files.
     *
     * @param int    $dvut_id   ID hồ sơ ĐVƯT.
     * @param string $loai_file Loại file (MAU_01, MAU_02, ..., MAU_09, CHUNG_NHAN_CTD, KHAC).
     * @param string $file_url  URL file đã upload.
     * @param string $file_name Tên file gốc.
     * @param int    $file_size Kích thước file (bytes).
     * @return int|false Insert ID hoặc false nếu thất bại.
     */
    public static function save_file( $dvut_id, $loai_file, $file_url, $file_name = '', $file_size = 0 ) {
        global $wpdb;
        $tables = self::get_tables();

        $result = $wpdb->insert(
            $tables['files'],
            array(
                'dvut_id'     => absint( $dvut_id ),
                'loai_file'   => sanitize_text_field( $loai_file ),
                'file_url'    => esc_url( $file_url ),
                'file_name'   => sanitize_file_name( $file_name ),
                'file_size'   => absint( $file_size ),
                'uploaded_by' => get_current_user_id(),
            ),
            array( '%d', '%s', '%s', '%s', '%d', '%d' )
        );

        return $result ? $wpdb->insert_id : false;
    }

    // =================================================================
    // BIỂU QUYẾT HELPER
    // =================================================================

    /**
     * Lưu kết quả biểu quyết vào bảng dvut_bieu_quyet.
     *
     * @param int    $dvut_id       ID hồ sơ ĐVƯT.
     * @param string $giai_doan     Giai đoạn BQ (GD2_CHI_DOAN, GD2_DOAN_KHOA, GD4_GIOI_THIEU, GD5_CHUYEN_DANG).
     * @param int    $tong_so_nguoi Tổng số người tham gia.
     * @param int    $so_dong_y     Số lượt đồng ý.
     * @param string $ghi_chu       Ghi chú (tuỳ chọn).
     * @return array { id, ty_le, ket_qua }
     */
    public static function save_bieu_quyet( $dvut_id, $giai_doan, $tong_so_nguoi, $so_dong_y, $ghi_chu = '' ) {
        global $wpdb;

        $ty_le   = $tong_so_nguoi > 0 ? round( ( $so_dong_y / $tong_so_nguoi ) * 100, 2 ) : 0;
        $ket_qua = $ty_le > 50 ? 'DAT' : 'KHONG_DAT';

        $wpdb->insert(
            $wpdb->prefix . 'dvut_bieu_quyet',
            array(
                'dvut_id'       => absint( $dvut_id ),
                'giai_doan'     => sanitize_text_field( $giai_doan ),
                'tong_so_nguoi' => absint( $tong_so_nguoi ),
                'so_dong_y'     => absint( $so_dong_y ),
                'ty_le'         => $ty_le,
                'ket_qua'       => $ket_qua,
                'nguoi_duyet'   => get_current_user_id(),
                'ngay_duyet'    => current_time( 'mysql' ),
                'ghi_chu'       => sanitize_textarea_field( $ghi_chu ),
            ),
            array( '%d', '%s', '%d', '%d', '%f', '%s', '%d', '%s', '%s' )
        );

        return array(
            'id'      => $wpdb->insert_id,
            'ty_le'   => $ty_le,
            'ket_qua' => $ket_qua,
        );
    }
}
