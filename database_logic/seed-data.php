<?php
/**
 * =================================================================
 * DVUT SEED DATA — Dữ liệu mẫu ban đầu
 * =================================================================
 * Chạy sau khi install database để tạo dữ liệu danh mục.
 * Gọi: DVUT_Seed::run();
 *
 * CHỈ CHẠY MỘT LẦN khi hệ thống mới cài đặt.
 * Kiểm tra option 'dvut_seeded' trước khi chạy lại.
 * =================================================================
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class DVUT_Seed {

    /**
     * Chạy seed data đầy đủ.
     * Tự kiểm tra đã seed chưa — an toàn để gọi lại nhiều lần.
     */
    public static function run() {
        if ( get_option( 'dvut_seeded', false ) ) {
            // Đã seed rồi — nhưng vẫn kiểm tra user_roles
            // (phòng trường hợp đổi máy / import DB thiếu dữ liệu roles)
            self::seed_admin_role();
            return;
        }

        self::seed_khoa();
        self::seed_chi_doan();
        self::seed_dot_xet();
        self::seed_admin_role();

        update_option( 'dvut_seeded', true );
    }

    /**
     * Seed dữ liệu Khoa.
     */
    private static function seed_khoa() {
        global $wpdb;
        $table = $wpdb->prefix . 'khoa';

        // Kiểm tra bảng đã có dữ liệu chưa
        $count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );
        if ( $count > 0 ) {
            return;
        }

        $khoa_data = array(
            array( 'ten' => 'Khoa Hệ thống Thông tin',      'ma_khoa' => 'HTTT',  'chi_bo_id' => 4 ),
            array( 'ten' => 'Khoa Kinh tế',                 'ma_khoa' => 'KT',    'chi_bo_id' => 1 ),
            array( 'ten' => 'Khoa Kế toán - Kiểm toán',     'ma_khoa' => 'KT-KT', 'chi_bo_id' => 3 ),
            array( 'ten' => 'Khoa Kinh tế Đối ngoại',       'ma_khoa' => 'KTDN',  'chi_bo_id' => 2 ),
            array( 'ten' => 'Khoa Luật Kinh tế',            'ma_khoa' => 'LKT',   'chi_bo_id' => 6 ),
            array( 'ten' => 'Khoa Luật',                    'ma_khoa' => 'LUAT',  'chi_bo_id' => 5 ),
            array( 'ten' => 'Khoa Quản trị Kinh doanh',     'ma_khoa' => 'QTKD',  'chi_bo_id' => 7 ),
            array( 'ten' => 'Khoa Tài chính - Ngân hàng',   'ma_khoa' => 'TC-NH', 'chi_bo_id' => 3 ),
            array( 'ten' => 'Khoa Toán Kinh tế',            'ma_khoa' => 'TKT',   'chi_bo_id' => 4 ),
        );

        foreach ( $khoa_data as $row ) {
            $wpdb->insert( $table, $row, array( '%s', '%s', '%d' ) );
        }
    }

    /**
     * Seed dữ liệu Chi Đoàn — đầy đủ 193 chi đoàn từ file chi-doan.csv.
     * Import với ID cố định (khớp với chi_doan_id trong hop-den.csv / blackbox).
     */
    private static function seed_chi_doan() {
        global $wpdb;
        $table = $wpdb->prefix . 'chi_doan';

        $count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );
        if ( $count > 0 ) {
            return;
        }

        // Đọc file chi-doan.csv (nguồn sự thật duy nhất cho danh mục chi đoàn)
        $csv_path = get_template_directory() . '/data/chi-doan.csv';
        if ( ! file_exists( $csv_path ) ) {
            error_log( '[DVUT Seed] chi-doan.csv not found — skipping chi đoàn seed.' );
            return;
        }

        $handle = fopen( $csv_path, 'r' );
        if ( ! $handle ) {
            return;
        }

        // Bỏ BOM nếu có
        $bom = fread( $handle, 3 );
        if ( $bom !== "\xEF\xBB\xBF" ) {
            rewind( $handle );
        }

        // Đọc header: id,ten,khoa_id,khoa
        $header = fgetcsv( $handle );
        if ( ! $header ) {
            fclose( $handle );
            return;
        }
        $header = array_map( 'trim', $header );

        $inserted = 0;
        while ( ( $row = fgetcsv( $handle ) ) !== false ) {
            if ( count( $row ) < 3 ) {
                continue;
            }
            $mapped = array_combine( $header, array_pad( $row, count( $header ), '' ) );

            $id      = absint( $mapped['id'] );
            $ten     = sanitize_text_field( trim( $mapped['ten'] ) );
            $khoa_id = absint( $mapped['khoa_id'] );

            if ( ! $id || ! $ten ) {
                continue;
            }

            // INSERT với ID cố định để khớp với hop-den.csv
            $wpdb->query( $wpdb->prepare(
                "INSERT INTO {$table} (id, ten, ma_chi_doan, khoa_id, nien_khoa)
                 VALUES (%d, %s, %s, %d, %s)
                 ON DUPLICATE KEY UPDATE ten = VALUES(ten), khoa_id = VALUES(khoa_id)",
                $id, $ten, $ten, $khoa_id, '2025-2026'
            ) );
            $inserted++;
        }
        fclose( $handle );

        if ( $inserted > 0 ) {
            error_log( "[DVUT Seed] Imported {$inserted} chi đoàn from chi-doan.csv." );
        }
    }

    /**
     * Seed đợt xét duyệt mẫu.
     */
    private static function seed_dot_xet() {
        global $wpdb;
        $table = $wpdb->prefix . 'dvut_dot_xet';

        $count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );
        if ( $count > 0 ) {
            return;
        }

        $wpdb->insert(
            $table,
            array(
                'ten_dot'       => 'Đợt 1 — HK2 năm học 2025-2026',
                'nien_khoa'     => '2025-2026',
                'ngay_bat_dau'  => '2026-01-01',
                'ngay_ket_thuc' => '2026-06-30',
                'han_nop_ho_so' => '2026-03-15',
                'trang_thai'    => 'DANG_MO',
            ),
            array( '%s', '%s', '%s', '%s', '%s', '%s' )
        );
    }

    /**
     * Reset seed (xóa flag) — chỉ dùng khi debug.
     */
    public static function reset() {
        delete_option( 'dvut_seeded' );
    }

    /**
     * Seed vai trò admin cho user WordPress có quyền manage_options.
     * Chạy mỗi lần load — nếu bảng user_roles trống thì tự gán.
     * Phòng trường hợp đổi máy / import DB thiếu dữ liệu roles.
     */
    private static function seed_admin_role() {
        global $wpdb;
        $table = $wpdb->prefix . 'dvut_user_roles';

        // Chỉ chạy khi bảng hoàn toàn trống
        $count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );
        if ( $count > 0 ) {
            return;
        }

        // Tìm tất cả user WordPress có quyền administrator
        $admins = get_users( array(
            'role'   => 'administrator',
            'fields' => array( 'ID' ),
        ) );

        foreach ( $admins as $admin ) {
            $wpdb->insert(
                $table,
                array(
                    'user_id'     => $admin->ID,
                    'role'        => 'admin_doan_truong',
                    'khoa_id'     => 0,
                    'chi_doan_id' => 0,
                    'is_active'   => 1,
                ),
                array( '%d', '%s', '%d', '%d', '%d' )
            );
        }

        if ( ! empty( $admins ) ) {
            error_log( '[DVUT Seed] Auto-seeded admin_doan_truong role for ' . count( $admins ) . ' WordPress admin(s).' );
        }
    }
}
