<?php
/**
 * =================================================================
 * FUNCTIONS.PHP — Tuổi trẻ UEL Theme
 * =================================================================
 * Tải các module phân hệ Đoàn viên Ưu tú:
 *   1. Database installer & helpers  (database_logic/class-dvut-database.php)
 *   2. Seed data                     (database_logic/seed-data.php)
 *   3. Backend AJAX handler          (class-ajax-doan-vien.php)
 *
 * KIẾN TRÚC TÍCH HỢP:
 *   Phân hệ ĐVƯT "ký sinh" vào hệ thống chính thông qua user_id.
 *   Hàm get_user_dvut_profile() là điểm nối DUY NHẤT.
 *   Khi ráp vào hệ thống anh Văn: chỉ cần sửa ruột hàm đó
 *   để trỏ vào wp_usermeta (don_vi_id, chuc_vu từ Onboarding).
 * =================================================================
 */

// ─── 1. Database ───
require_once get_template_directory() . '/database_logic/class-dvut-database.php';
require_once get_template_directory() . '/database_logic/seed-data.php';

// ─── 2. Backend AJAX ───
require_once get_template_directory() . '/class-ajax-doan-vien.php';
new Ajax_Doan_Vien_Uu_Tu();

// ─── 2b. Ẩn admin bar và bỏ khoảng trắng trên giao diện trang chủ ĐVƯT ───
add_filter( 'show_admin_bar', '__return_false' );
remove_action( 'wp_head', '_admin_bar_bump_cb' );

// ─── 3. Tự động cài đặt / cập nhật database khi cần ───
add_action( 'after_setup_theme', function() {
    if ( DVUT_Database::needs_update() ) {
        DVUT_Database::install();
    }
    DVUT_Seed::run();

    // ─── Migration v3: Đồng bộ chi_doan từ chi-doan.csv (193 chi đoàn) ───
    // Bảng chi_doan cũ chỉ có 20 bản ghi test với ID tự động (1-20).
    // Hộp đen (hop-den.csv) dùng chi_doan_id cố định (1-193) từ chi-doan.csv.
    // Migration này truncate bảng chi_doan cũ và import đầy đủ với ID chuẩn.
    if ( ! get_option( 'dvut_chi_doan_sync_v3' ) ) {
        global $wpdb;
        $tables   = DVUT_Database::get_tables();
        $csv_path = get_template_directory() . '/data/chi-doan.csv';

        if ( file_exists( $csv_path ) ) {
            // Bước 1: Xóa dữ liệu cũ (chi_doan test, ID không khớp blackbox)
            $wpdb->query( "TRUNCATE TABLE {$tables['chi_doan']}" );

            // Bước 2: Import đầy đủ từ chi-doan.csv
            $handle = fopen( $csv_path, 'r' );
            if ( $handle ) {
                $bom = fread( $handle, 3 );
                if ( $bom !== "\xEF\xBB\xBF" ) {
                    rewind( $handle );
                }
                $header = fgetcsv( $handle );
                if ( $header ) {
                    $header   = array_map( 'trim', $header );
                    $inserted = 0;
                    while ( ( $row = fgetcsv( $handle ) ) !== false ) {
                        if ( count( $row ) < 3 ) continue;
                        $mapped  = array_combine( $header, array_pad( $row, count( $header ), '' ) );
                        $id      = absint( $mapped['id'] );
                        $ten     = sanitize_text_field( trim( $mapped['ten'] ) );
                        $khoa_id = absint( $mapped['khoa_id'] );
                        if ( ! $id || ! $ten ) continue;

                        $wpdb->query( $wpdb->prepare(
                            "INSERT INTO {$tables['chi_doan']} (id, ten, ma_chi_doan, khoa_id, nien_khoa)
                             VALUES (%d, %s, %s, %d, %s)",
                            $id, $ten, $ten, $khoa_id, '2025-2026'
                        ) );
                        $inserted++;
                    }
                    error_log( "[DVUT Migration v3] Re-seeded {$inserted} chi đoàn from CSV." );
                }
                fclose( $handle );
            }

            // Bước 3: Cập nhật tên khoa trong bảng khoa cho khớp với CSV
            // CSV dùng tên ngắn ("Kinh tế"), seed dùng tên đầy đủ ("Khoa Kinh tế")
            // Thống nhất: dùng tên có tiền tố "Khoa" (chuẩn hành chính)
            $khoa_name_map = array(
                1 => 'Khoa Hệ thống Thông tin',
                2 => 'Khoa Kinh tế',
                3 => 'Khoa Kế toán - Kiểm toán',
                4 => 'Khoa Kinh tế Đối ngoại',
                5 => 'Khoa Luật Kinh tế',
                6 => 'Khoa Luật',
                7 => 'Khoa Quản trị Kinh doanh',
                8 => 'Khoa Tài chính - Ngân hàng',
                9 => 'Khoa Toán Kinh tế',
            );
            foreach ( $khoa_name_map as $kid => $kname ) {
                $wpdb->update( $tables['khoa'], array( 'ten' => $kname ), array( 'id' => $kid ), array( '%s' ), array( '%d' ) );
            }

            // Bước 4: Sửa chi_doan_id trong doan_vien_uu_tu nếu bản ghi cũ
            // dùng ID tự động (1-20) → khớp lại với ID chuẩn từ CSV theo tên chi đoàn
            $wpdb->query(
                "UPDATE {$tables['dvut']} dv
                 INNER JOIN {$tables['chi_doan']} cd ON dv.chi_doan = cd.ten
                 SET dv.chi_doan_id = cd.id
                 WHERE dv.chi_doan != '' AND dv.chi_doan IS NOT NULL"
            );

            // Bước 5: Sửa khoa_id + tên khoa dựa trên chi_doan_id chuẩn
            $wpdb->query(
                "UPDATE {$tables['dvut']} dv
                 INNER JOIN {$tables['chi_doan']} cd ON dv.chi_doan_id = cd.id
                 INNER JOIN {$tables['khoa']} k ON cd.khoa_id = k.id
                 SET dv.khoa_id = cd.khoa_id, dv.khoa = k.ten
                 WHERE dv.chi_doan_id > 0"
            );
        }

        update_option( 'dvut_chi_doan_sync_v3', true );
    }

    // ─── Data integrity: sửa khoa_id = 0 trong doan_vien_uu_tu ───
    // Bản ghi có chi_doan_id hợp lệ nhưng khoa_id = 0 do bug cũ (thiếu khoa_id khi đề cử).
    // Chạy 1 lần duy nhất, đánh dấu bằng option.
    if ( ! get_option( 'dvut_khoa_id_fix_v2' ) ) {
        global $wpdb;
        $tables = DVUT_Database::get_tables();

        // Bước 1: Sửa khoa_id qua quan hệ chi_doan_id → chi_doan.khoa_id
        $wpdb->query(
            "UPDATE {$tables['dvut']} dv
             INNER JOIN {$tables['chi_doan']} cd ON dv.chi_doan_id = cd.id
             SET dv.khoa_id = cd.khoa_id
             WHERE dv.chi_doan_id > 0 AND (dv.khoa_id = 0 OR dv.khoa_id IS NULL)"
        );

        // Bước 2: Fallback — sửa khoa_id qua tên khoa (text matching)
        $wpdb->query(
            "UPDATE {$tables['dvut']} dv
             INNER JOIN {$tables['khoa']} k ON dv.khoa = k.ten
             SET dv.khoa_id = k.id
             WHERE (dv.khoa_id = 0 OR dv.khoa_id IS NULL) AND dv.khoa != '' AND dv.khoa IS NOT NULL"
        );

        // Bước 3: Cập nhật tên khoa cho bản ghi đã có khoa_id nhưng thiếu tên
        $wpdb->query(
            "UPDATE {$tables['dvut']} dv
             INNER JOIN {$tables['khoa']} k ON dv.khoa_id = k.id
             SET dv.khoa = k.ten
             WHERE dv.khoa_id > 0 AND (dv.khoa = '' OR dv.khoa IS NULL)"
        );

        // Bước 4: Cập nhật tên chi đoàn cho bản ghi thiếu
        $wpdb->query(
            "UPDATE {$tables['dvut']} dv
             INNER JOIN {$tables['chi_doan']} cd ON dv.chi_doan_id = cd.id
             SET dv.chi_doan = cd.ten
             WHERE dv.chi_doan_id > 0 AND (dv.chi_doan = '' OR dv.chi_doan IS NULL)"
        );

        update_option( 'dvut_khoa_id_fix_v2', true );
    }

    // ─── Migration v4: Cập nhật chi_bo_id cho bảng Khoa ───
    // Mỗi Khoa thuộc 1 Chi bộ Sinh viên (CBSV).
    // Mapping: HTTT+TKT → CBSV4, KT → CBSV1, KTDN → CBSV2, KT-KT+TC-NH → CBSV3,
    //          Luật → CBSV5, LKT → CBSV6, QTKD → CBSV7
    if ( ! get_option( 'dvut_chi_bo_id_fix_v4' ) ) {
        global $wpdb;
        $tables = DVUT_Database::get_tables();

        $chi_bo_map = array(
            1 => 4,  // Khoa HTTT → Chi bộ SV 4
            2 => 1,  // Khoa Kinh tế → Chi bộ SV 1
            3 => 3,  // Khoa KT-KT → Chi bộ SV 3
            4 => 2,  // Khoa KTDN → Chi bộ SV 2
            5 => 6,  // Khoa Luật KT → Chi bộ SV 6
            6 => 5,  // Khoa Luật → Chi bộ SV 5
            7 => 7,  // Khoa QTKD → Chi bộ SV 7
            8 => 3,  // Khoa TC-NH → Chi bộ SV 3 (chung với KT-KT)
            9 => 4,  // Khoa TKT → Chi bộ SV 4 (chung với HTTT)
        );
        foreach ( $chi_bo_map as $khoa_id => $chi_bo_id ) {
            $wpdb->update(
                $tables['khoa'],
                array( 'chi_bo_id' => $chi_bo_id ),
                array( 'id' => $khoa_id ),
                array( '%d' ),
                array( '%d' )
            );
        }
        error_log( '[DVUT Migration v4] Updated chi_bo_id for all 9 Khoa.' );
        update_option( 'dvut_chi_bo_id_fix_v4', true );
    }
});

// ─── 3a. Thiết lập permalink structure (chạy ở init, sau khi WP sẵn sàng) ───
add_action( 'init', function() {
    // Bật permalink "Post name" nếu đang dùng Plain (mặc định WP)
    $current_structure = get_option( 'permalink_structure' );
    if ( empty( $current_structure ) ) {
        global $wp_rewrite;
        $wp_rewrite->set_permalink_structure( '/%postname%/' );
        flush_rewrite_rules( true );
    }
}, 20 );

// =================================================================
// HÀM WRAPPER TRUNG GIAN — Điểm nối DUY NHẤT với hệ thống chính
// =================================================================
/**
 * Lấy thông tin phân quyền ĐVƯT của một user.
 *
 * CHIẾN LƯỢC TÍCH HỢP:
 *   Hiện tại (dev): Query bảng wp_dvut_user_roles riêng của phân hệ.
 *   Khi ráp vào hệ thống chính: Sửa ruột hàm này để:
 *     - Đọc meta_key 'don_vi_id', 'chuc_vu' từ wp_usermeta
 *       (do luồng Onboarding/Google Login đã lưu sẵn)
 *     - Map chuc_vu → role ĐVƯT (Bí thư → bch_chi_doan, v.v.)
 *     - Map don_vi_id → chi_doan_id / khoa_id
 *
 * @param  int         $user_id  WordPress user ID.
 * @return array|false           { role, khoa_id, chi_doan_id } hoặc false.
 */
function get_user_dvut_profile( $user_id = 0 ) {
    // Cho phép gọi không truyền $user_id — tự lấy user hiện tại
    if ( ! $user_id ) {
        $user_id = get_current_user_id();
    }
    if ( ! $user_id ) {
        return false;
    }

    // Lấy thông tin WordPress user (mssv = user_login, email = user_email)
    $wp_user = get_userdata( $user_id );
    $base_info = array(
        'mssv'  => $wp_user ? $wp_user->user_login : '',
        'email' => $wp_user ? $wp_user->user_email : '',
    );

    // ─── PHASE 1 (Hiện tại): Query bảng RBAC riêng của phân hệ ĐVƯT ───
    if ( class_exists( 'DVUT_Database' ) ) {
        global $wpdb;
        $tables = DVUT_Database::get_tables();

        $role_data = $wpdb->get_row( $wpdb->prepare(
            "SELECT role, khoa_id, chi_doan_id, chi_bo_id FROM {$tables['user_roles']} WHERE user_id = %d AND is_active = 1",
            $user_id
        ), ARRAY_A );

        if ( $role_data ) {
            // Quản trị viên (full quyền, xem hồ sơ cá nhân) vs Ban thường vụ đoàn trường (chỉ duyệt, không xem hồ sơ cá nhân)
            $role_data['is_admin'] = ( $role_data['role'] === 'admin_doan_truong' && user_can( $user_id, 'manage_options' ) );
            return array_merge( $base_info, $role_data );
        }
    }

    // ─── PHASE 2 (Khi ráp hệ thống chính): Đọc wp_usermeta ───
    // TODO: Uncomment và hoàn thiện khi tích hợp vào hệ thống anh Văn.

    // ─── FALLBACK 1: WP Administrator → tự động admin_doan_truong ───
    if ( user_can( $user_id, 'manage_options' ) ) {
        return array_merge( $base_info, array(
            'role'        => 'admin_doan_truong',
            'khoa_id'     => 0,
            'chi_doan_id' => 0,
            'chi_bo_id'   => 0,
            'is_admin'    => true,
        ) );
    }

    // ─── FALLBACK 2: WordPress role dvut_* → map sang DVUT role ───
    if ( $wp_user ) {
        $wp_role_map = array(
            'dvut_doan_vien'        => 'doan_vien',
            'dvut_bch_chi_doan'     => 'bch_chi_doan',
            'dvut_can_bo_doan_khoa' => 'can_bo_doan_khoa',
            'dvut_can_bo_doan_truong' => 'admin_doan_truong',
            'dvut_chi_bo_sinh_vien' => 'chi_bo_sinh_vien',
        );
        foreach ( $wp_user->roles as $wp_role ) {
            if ( isset( $wp_role_map[ $wp_role ] ) ) {
                // Đọc thêm meta dvut_khoa_id, dvut_chi_doan_id, dvut_chi_bo_id nếu có
                return array_merge( $base_info, array(
                    'role'        => $wp_role_map[ $wp_role ],
                    'khoa_id'     => absint( get_user_meta( $user_id, 'dvut_khoa_id', true ) ),
                    'chi_doan_id' => absint( get_user_meta( $user_id, 'dvut_chi_doan_id', true ) ),
                    'chi_bo_id'   => absint( get_user_meta( $user_id, 'dvut_chi_bo_id', true ) ),
                    'is_admin'    => false,
                ) );
            }
        }
    }

    return array_merge( $base_info, array(
        'role'        => 'doan_vien',
        'khoa_id'     => 0,
        'chi_doan_id' => 0,
        'chi_bo_id'   => 0,
        'is_admin'    => false,
    ) );
}

// ─── 4. Enqueue Scripts ───
// Helper: kiểm tra URL có phải trang ĐVƯT không (kể cả khi is_page() chưa work)
function dvut_is_dvut_page() {
    return is_front_page() || is_home();
}

add_action('wp_enqueue_scripts', function() {
    // Trên page ĐVƯT: deregister WP jQuery, dùng CDN tránh xung đột
    if ( dvut_is_dvut_page() ) {
        wp_deregister_script( 'jquery' );
        wp_register_script( 'jquery', 'https://cdn.jsdelivr.net/npm/jquery@3.6.0/dist/jquery.min.js', array(), '3.6.0', false );
    }

    wp_enqueue_script('doan-vien-js', get_template_directory_uri() . '/doan-vien-app.js', array('jquery'), filemtime(get_template_directory() . '/doan-vien-app.js'), true);

    global $wpdb;
    $prefix = $wpdb->prefix;

    // Lấy danh sách Khoa và Chi Đoàn từ DB để truyền xuống JS
    // Chi Đoàn LEFT JOIN khoa: lấy cd.* + k.ten AS khoa (chi_doan chỉ lưu khoa_id, cần JOIN để có tên khoa)
    $khoa_list     = $wpdb->get_results("SELECT * FROM {$prefix}khoa ORDER BY id", ARRAY_A) ?: array();
    $chi_doan_list = $wpdb->get_results(
        "SELECT cd.*, k.ten AS khoa
         FROM {$prefix}chi_doan cd
         LEFT JOIN {$prefix}khoa k ON cd.khoa_id = k.id
         ORDER BY cd.id",
        ARRAY_A
    ) ?: array();

    $localize_data = array(
        'ajax_url'      => admin_url('admin-ajax.php'),
        'nonce'         => wp_create_nonce('dvut_nonce'),
        'theme_url'     => get_template_directory_uri(),
        'khoa_list'     => $khoa_list,
        'chi_doan_list' => $chi_doan_list,
    );

    // Nếu user đã đăng nhập → truyền thông tin phân quyền DVUT xuống JS
    if ( is_user_logged_in() ) {
        $user_id   = get_current_user_id();
        $profile   = get_user_dvut_profile( $user_id );

        $localize_data['user'] = array(
            'id'          => $user_id,
            'display_name'=> wp_get_current_user()->display_name,
            'email'       => wp_get_current_user()->user_email,
            'role'        => $profile ? $profile['role'] : 'doan_vien',
            'chi_doan_id' => $profile ? absint( $profile['chi_doan_id'] ) : 0,
            'khoa_id'     => $profile ? absint( $profile['khoa_id'] ) : 0,
            'chi_bo_id'   => $profile ? absint( $profile['chi_bo_id'] ) : 0,
            'is_admin'    => $profile ? ! empty( $profile['is_admin'] ) : false,
            'mssv'        => get_user_meta( $user_id, 'dvut_mssv', true ) ?: ( get_user_meta( $user_id, 'mssv', true ) ?: '' ),
        );
    }

    // Truyền xuống JS (biến phải là DVUT_AJAX để khớp với doan-vien-app.js)
    wp_localize_script('doan-vien-js', 'DVUT_AJAX', $localize_data);

    // Localize dvut_globals theo yêu cầu refactor trang chủ
    wp_localize_script('doan-vien-js', 'dvut_globals', array(
        'ajax_url' => admin_url('admin-ajax.php'),
        'home_url' => home_url('/'),
    ));
});

/**
 * 1. ĐĂNG KÝ CÁC VAI TRÒ (ROLES) CHO HỆ THỐNG ĐVƯT
 */
function dvut_register_custom_roles() {
    // Chỉ thêm nếu role chưa tồn tại
    if ( ! get_role( 'dvut_doan_vien' ) ) {
        add_role( 'dvut_doan_vien', '1. Đoàn viên', array( 'read' => true ) );
    }
    if ( ! get_role( 'dvut_bch_chi_doan' ) ) {
        add_role( 'dvut_bch_chi_doan', '2. BCH Chi Đoàn', array( 'read' => true ) );
    }
    if ( ! get_role( 'dvut_can_bo_doan_khoa' ) ) {
        add_role( 'dvut_can_bo_doan_khoa', '3. Cán bộ Đoàn Khoa', array( 'read' => true ) );
    }
    if ( ! get_role( 'dvut_can_bo_doan_truong' ) ) {
        add_role( 'dvut_can_bo_doan_truong', '4. Cán bộ Đoàn Trường', array( 'read' => true ) );
    }
    if ( ! get_role( 'dvut_chi_bo_sinh_vien' ) ) {
        add_role( 'dvut_chi_bo_sinh_vien', '6. Chi bộ Sinh viên', array( 'read' => true ) );
    }
    // Role 5 là Administrator (Quản lý) đã có sẵn trong WP
}
// Chạy hàm này khi hệ thống khởi tạo
add_action( 'init', 'dvut_register_custom_roles' );

/**
 * 2. THÊM TRƯỜNG DỮ LIỆU ĐƠN VỊ (KHOA, CHI ĐOÀN, MSSV) VÀO TRANG QUẢN LÝ USER
 */
// Hiển thị trường nhập liệu ở trang sửa User và trang thêm User mới
add_action( 'show_user_profile', 'dvut_extra_user_profile_fields' );
add_action( 'edit_user_profile', 'dvut_extra_user_profile_fields' );
add_action( 'user_new_form', 'dvut_extra_user_profile_fields' );

function dvut_extra_user_profile_fields( $user ) {
    // Nếu là trang tạo mới, $user có thể là chuỗi 'add-new-user'
    $user_id = is_object( $user ) ? $user->ID : 0;
    
    $mssv        = $user_id ? get_user_meta( $user_id, 'dvut_mssv', true ) : '';
    $khoa_id     = $user_id ? get_user_meta( $user_id, 'dvut_khoa_id', true ) : '';
    $chi_doan_id = $user_id ? get_user_meta( $user_id, 'dvut_chi_doan_id', true ) : '';
    $chi_bo_id   = $user_id ? get_user_meta( $user_id, 'dvut_chi_bo_id', true ) : '';
    ?>
    <h3>Thông tin Phân quyền Đoàn viên Ưu tú</h3>
    <table class="form-table">
        <tr>
            <th><label for="dvut_mssv">Mã số Sinh viên (MSSV)</label></th>
            <td>
                <input type="text" name="dvut_mssv" id="dvut_mssv" value="<?php echo esc_attr( $mssv ); ?>" class="regular-text" /><br />
                <span class="description">Bắt buộc đối với role Đoàn viên.</span>
            </td>
        </tr>
        <tr>
            <th><label for="dvut_khoa_id">ID Khoa</label></th>
            <td>
                <input type="number" name="dvut_khoa_id" id="dvut_khoa_id" value="<?php echo esc_attr( $khoa_id ); ?>" class="regular-text" /><br />
                <span class="description">Nhập ID của Khoa (Xem trong CSDL). Dùng cho role Đoàn Khoa/Chi Đoàn.</span>
            </td>
        </tr>
        <tr>
            <th><label for="dvut_chi_doan_id">ID Chi Đoàn</label></th>
            <td>
                <input type="number" name="dvut_chi_doan_id" id="dvut_chi_doan_id" value="<?php echo esc_attr( $chi_doan_id ); ?>" class="regular-text" /><br />
                <span class="description">Nhập ID của Chi Đoàn. Dùng cho role BCH Chi Đoàn.</span>
            </td>
        </tr>
        <tr>
            <th><label for="dvut_chi_bo_id">ID Chi bộ Sinh viên</label></th>
            <td>
                <input type="number" name="dvut_chi_bo_id" id="dvut_chi_bo_id" value="<?php echo esc_attr( $chi_bo_id ); ?>" class="regular-text" /><br />
                <span class="description">Nhập ID của Chi bộ (Từ 1 đến 7). Dùng riêng cho role Chi bộ.</span>
            </td>
        </tr>
    </table>
    <?php
}

/**
 * 3. LƯU DỮ LIỆU KHI UPDATE HOẶC TẠO MỚI USER
 */
add_action( 'personal_options_update', 'dvut_save_extra_user_profile_fields' );
add_action( 'edit_user_profile_update', 'dvut_save_extra_user_profile_fields' );
add_action( 'user_register', 'dvut_save_extra_user_profile_fields' );

function dvut_save_extra_user_profile_fields( $user_id ) {
    if ( ! current_user_can( 'edit_user', $user_id ) ) {
        return false;
    }
    update_user_meta( $user_id, 'dvut_mssv', sanitize_text_field( $_POST['dvut_mssv'] ) );
    update_user_meta( $user_id, 'dvut_khoa_id', absint( $_POST['dvut_khoa_id'] ) );
    update_user_meta( $user_id, 'dvut_chi_doan_id', absint( $_POST['dvut_chi_doan_id'] ) );
    update_user_meta( $user_id, 'dvut_chi_bo_id', absint( $_POST['dvut_chi_bo_id'] ) );
}

// =================================================================
// 5. TẠO MENU "CÔNG CỤ ADMIN" TRONG TRANG QUẢN TRỊ WORDPRESS
// =================================================================
add_action( 'admin_menu', 'dvut_register_admin_tools_menu' );

function dvut_register_admin_tools_menu() {
    // Tạo menu chính dành riêng cho Admin
    add_menu_page(
        'Công cụ Admin ĐVƯT',       // Tiêu đề trang
        'Công cụ ĐVƯT',             // Tên hiển thị trên menu
        'manage_options',           // Quyền: Chỉ Admin (manage_options) mới thấy
        'dvut-admin-tools',         // Slug (đường dẫn) của menu
        'dvut_render_admin_tools_ui', // Hàm vẽ giao diện (sẽ viết bên dưới)
        'dashicons-database',       // Icon hình database
        50                          // Vị trí trên menu
    );
}

// Hàm vẽ khung Giao diện HTML cơ bản cho Công cụ Admin
function dvut_render_admin_tools_ui() {
    ?>
    <div class="wrap">
        <h1>⚙️ Công cụ Quản trị Hệ thống Đoàn viên Ưu tú</h1>
        <p>Bảng điều khiển dành riêng cho Admin Đoàn Trường.</p>

        <h2 class="nav-tab-wrapper">
            <a href="#tab-roles" class="nav-tab nav-tab-active" onclick="switchDvutTab(event, 'tab-roles')">👤 Quản lý Phân quyền</a>
            <a href="#tab-import" class="nav-tab" onclick="switchDvutTab(event, 'tab-import')">📥 Import Dữ liệu</a>
            <a href="#tab-database" class="nav-tab" onclick="switchDvutTab(event, 'tab-database')">🗄️ Quản lý Database</a>
        </h2>

        <div id="tab-roles" class="dvut-tab-content" style="display:block; background:#fff; padding:20px; margin-top:15px; border:1px solid #ccd0d4; border-radius:4px;">
            <h3>Quản lý Phân quyền (Role)</h3>
            <p>Tính năng đang chờ kết nối JS...</p>
            </div>

        <div id="tab-import" class="dvut-tab-content" style="display:none; background:#fff; padding:20px; margin-top:15px; border:1px solid #ccd0d4; border-radius:4px;">
            <h3>Import Dữ liệu Hộp đen (Excel/CSV)</h3>
            <form id="frm-import-blackbox" enctype="multipart/form-data">
                <input type="file" name="csv_file" accept=".csv" required>
                <input type="text" name="dot_xet" placeholder="Nhập tên đợt xét (VD: Đợt 1 - 2026)">
                <button type="submit" class="button button-primary">Bắt đầu Import</button>
            </form>
        </div>

        <div id="tab-database" class="dvut-tab-content" style="display:none; background:#fff; padding:20px; margin-top:15px; border:1px solid #ccd0d4; border-radius:4px;">
            <h3>Xem & Chỉnh sửa Database trực tiếp</h3>
            <p>Tính năng đang chờ kết nối JS...</p>
             </div>
    </div>

    <script>
        // Script chuyển tab đơn giản
        function switchDvutTab(e, tabId) {
            e.preventDefault();
            document.querySelectorAll('.nav-tab').forEach(t => t.classList.remove('nav-tab-active'));
            e.target.classList.add('nav-tab-active');
            document.querySelectorAll('.dvut-tab-content').forEach(c => c.style.display = 'none');
            document.getElementById(tabId).style.display = 'block';
        }
    </script>
    <?php
}

// ─── 6. Tự động định tuyến /login hoặc /login/ sang tệp page-login.php ───
add_action( 'template_redirect', function() {
    $request_path = trim( parse_url( $_SERVER['REQUEST_URI'], PHP_URL_PATH ), '/' );
    $segments     = explode( '/', $request_path );
    $last_segment = end( $segments );
    
    if ( $last_segment === 'login' ) {
        $login_template = get_template_directory() . '/page-login.php';
        if ( file_exists( $login_template ) ) {
            include $login_template;
            exit;
        }
    }
} );

// ─── 7. Thêm Favicon (Logo trường) cho tất cả các trang WordPress chuẩn, Admin và Login mặc định ───
function dvut_add_global_favicon() {
    $favicon_url = esc_url( get_template_directory_uri() . '/assets/uel_logo.png' );
    echo '<link rel="shortcut icon" type="image/png" href="' . $favicon_url . '" />' . "\n";
    echo '<link rel="apple-touch-icon" href="' . $favicon_url . '" />' . "\n";
}
add_action( 'wp_head', 'dvut_add_global_favicon' );
add_action( 'admin_head', 'dvut_add_global_favicon' );
add_action( 'login_head', 'dvut_add_global_favicon' );