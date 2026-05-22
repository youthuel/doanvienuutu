<?php
/**
 * =================================================================
 * CLASS AJAX ĐOÀN VIÊN ƯU TÚ - PHIÊN BẢN ĐẦY ĐỦ (SRS v2)
 * =================================================================
 * Backend API cho phân hệ "Quản lý Đoàn viên Ưu tú & Phát triển Đảng".
 *
 * Luồng nghiệp vụ 5 giai đoạn:
 *   GĐ1: Sàng lọc & Đề cử (Check MSSV vs Hộp đen)
 *   GĐ2: Công nhận ĐVƯT (Chi Đoàn → Đoàn Khoa → Đoàn Trường)
 *   GĐ3: Theo dõi Lớp Cảm tình Đảng
 *   GĐ4: Giới thiệu vào Đảng
 *   GĐ5: Chuyển Đảng chính thức
 *
 * Phân quyền (RBAC):
 *   - admin_doan_truong : Cấp cao nhất, toàn quyền + Import Excel
 *   - can_bo_doan_khoa  : Phê duyệt trung gian
 *   - bch_chi_doan      : Cấp cơ sở, nhập liệu chính
 *
 * GHI CHÚ KIẾN TRÚC:
 *   - Tất cả dữ liệu biểu quyết, file URL, người duyệt, ngày duyệt
 *     được lưu TRỰC TIẾP vào bảng chính (dvut) bằng $wpdb->update().
 *   - KHÔNG sử dụng bảng phụ normalized cho biểu quyết / file.
 *   - KHÔNG gọi DVUT_Database::write_log(), save_file(), save_bieu_quyet()
 *     hay $this->get_current_status() — chỉ dùng $wpdb trực tiếp.
 *
 * HƯỚNG DẪN TÍCH HỢP:
 * 1. require_once get_template_directory() . '/class-ajax-doan-vien.php';
 *    new Ajax_Doan_Vien_Uu_Tu();
 * 2. Bảng database được tạo tự động bởi DVUT_Database::install() (xem database/).
 * 3. wp_localize_script() truyền DVUT_AJAX { ajax_url, nonce, user_role, user_khoa_id, user_chi_doan_id }.
 * =================================================================
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit; // Ngăn truy cập trực tiếp
}

class Ajax_Doan_Vien_Uu_Tu {

    // -----------------------------------------------------------------
    // THUỘC TÍNH
    // -----------------------------------------------------------------

    /** @var string Bảng chính — Hồ sơ Đoàn viên Ưu tú */
    private $table_dvut;

    /** @var string Bảng Chi Đoàn */
    private $table_chi_doan;

    /** @var string Bảng Khoa */
    private $table_khoa;

    /** @var string Bảng Hộp đen (Blackbox) — Dữ liệu gốc Import từ Excel */
    private $table_blackbox;

    /** @var string Bảng phân quyền mở rộng DVUT */
    private $table_user_roles;

    /** @var string Bảng đợt xét duyệt */
    private $table_dot_xet;

    /** @var string Bảng audit log */
    private $table_log;

    /** @var string Bảng file upload */
    private $table_files;


    /** @var string Bảng nhận xét định kỳ (đánh giá quý ĐVƯT) */
    private $table_nhan_xet;

    /** @var string Bảng kỳ đánh giá (admin quản lý mở/khóa) */
    private $table_nhan_xet_period;

    // -----------------------------------------------------------------
    // CONSTRUCTOR — Đăng ký AJAX Hooks
    // -----------------------------------------------------------------

    public function __construct() {
        // Lấy tên bảng từ DVUT_Database (Single Source of Truth)
        $tables = DVUT_Database::get_tables();

        $this->table_dvut        = $tables['dvut'];
        $this->table_chi_doan    = $tables['chi_doan'];
        $this->table_khoa        = $tables['khoa'];
        $this->table_blackbox    = $tables['blackbox'];
        $this->table_user_roles  = $tables['user_roles'];
        $this->table_dot_xet     = $tables['dot_xet'];
        $this->table_log         = $tables['log'];
        $this->table_files       = $tables['files'];
        $this->table_nhan_xet    = $tables['nhan_xet'];
        $this->table_nhan_xet_period = $tables['nhan_xet_period'];

        // ============================================================
        // GĐ1: SÀNG LỌC & ĐỀ CỬ
        // ============================================================
        add_action( 'wp_ajax_dvut_get_list',        array( $this, 'handle_get_list' ) );
        add_action( 'wp_ajax_dvut_check_mssv',      array( $this, 'handle_check_mssv' ) );
        add_action( 'wp_ajax_dvut_get_blackbox_profile', array( $this, 'handle_get_blackbox_profile' ) );
        add_action( 'wp_ajax_dvut_search_blackbox', array( $this, 'handle_search_blackbox' ) );
        add_action( 'wp_ajax_dvut_add_de_cu',       array( $this, 'handle_add_de_cu' ) );
        add_action( 'wp_ajax_dvut_nop_ho_so',       array( $this, 'handle_nop_ho_so' ) );
        add_action( 'wp_ajax_dvut_get_chi_doan_member_count', array( $this, 'handle_get_chi_doan_member_count' ) );

        // ============================================================
        // GĐ2: CÔNG NHẬN ĐVƯT
        // ============================================================
        add_action( 'wp_ajax_dvut_chi_doan_duyet',  array( $this, 'handle_chi_doan_duyet' ) );
        add_action( 'wp_ajax_dvut_doan_khoa_duyet', array( $this, 'handle_doan_khoa_duyet' ) );
        add_action( 'wp_ajax_dvut_doan_truong_cong_nhan', array( $this, 'handle_doan_truong_cong_nhan' ) );

        // ============================================================
        // GĐ3: THEO DÕI LỚP CẢM TÌNH ĐẢNG
        // ============================================================
        add_action( 'wp_ajax_dvut_update_cam_tinh_dang', array( $this, 'handle_update_cam_tinh_dang' ) );

        // ============================================================
        // GĐ4: GIỚI THIỆU VÀO ĐẢNG
        // ============================================================
        add_action( 'wp_ajax_dvut_gioi_thieu_dang',       array( $this, 'handle_gioi_thieu_dang' ) );
        add_action( 'wp_ajax_dvut_dk_duyet_gioi_thieu',   array( $this, 'handle_dk_duyet_gioi_thieu' ) );

        // ============================================================
        // GĐ4b: CHI BỘ SINH VIÊN
        // ============================================================
        add_action( 'wp_ajax_dvut_cb_cap_nhat_ket_nap',   array( $this, 'handle_cb_cap_nhat_ket_nap' ) );

        // ============================================================
        // GĐ5: CHUYỂN ĐẢNG CHÍNH THỨC
        // ============================================================
        add_action( 'wp_ajax_dvut_chuyen_dang_chinh_thuc',    array( $this, 'handle_chuyen_dang_chinh_thuc' ) );
        add_action( 'wp_ajax_dvut_chuyen_dang',                 array( $this, 'handle_chuyen_dang_chinh_thuc' ) );
        add_action( 'wp_ajax_dvut_dk_duyet_chuyen_dang',      array( $this, 'handle_dk_duyet_chuyen_dang' ) );

        // ============================================================
        // TIỆN ÍCH CHUNG
        // ============================================================
        add_action( 'wp_ajax_dvut_delete',              array( $this, 'handle_delete' ) );
        add_action( 'wp_ajax_dvut_tra_ve',              array( $this, 'handle_tra_ve' ) );
        add_action( 'wp_ajax_dvut_tu_choi',             array( $this, 'handle_tu_choi' ) );
        add_action( 'wp_ajax_dvut_admin_go_tu_choi',    array( $this, 'handle_admin_go_tu_choi' ) );
        add_action( 'wp_ajax_dvut_admin_ban_hanh',      array( $this, 'handle_doan_truong_cong_nhan' ) );
        add_action( 'wp_ajax_dvut_import_excel',        array( $this, 'handle_import_excel' ) );
        add_action( 'wp_ajax_dvut_get_dashboard_stats', array( $this, 'handle_get_dashboard_stats' ) );

        // ============================================================
        // BATCH: HÀNH ĐỘNG HÀNG LOẠT
        // ============================================================
        add_action( 'wp_ajax_dvut_batch_chi_doan_duyet',        array( $this, 'handle_batch_chi_doan_duyet' ) );
        add_action( 'wp_ajax_dvut_batch_doan_khoa_duyet',       array( $this, 'handle_batch_doan_khoa_duyet' ) );
        add_action( 'wp_ajax_dvut_batch_doan_truong_cong_nhan', array( $this, 'handle_batch_doan_truong_cong_nhan' ) );
        add_action( 'wp_ajax_dvut_batch_dk_duyet_gioi_thieu',   array( $this, 'handle_batch_dk_duyet_gioi_thieu' ) );
        add_action( 'wp_ajax_dvut_batch_dk_duyet_chuyen_dang',  array( $this, 'handle_batch_dk_duyet_chuyen_dang' ) );

        // ============================================================
        // LỊCH SỬ CHỈNH SỬA
        // ============================================================
        add_action( 'wp_ajax_dvut_get_edit_history',    array( $this, 'handle_get_edit_history' ) );


        // ============================================================
        // QUẢN LÝ HỆ THỐNG (Admin only)
        // ============================================================
        add_action( 'wp_ajax_dvut_sys_list_roles',      array( $this, 'handle_sys_list_roles' ) );
        add_action( 'wp_ajax_dvut_sys_add_role',        array( $this, 'handle_sys_add_role' ) );
        add_action( 'wp_ajax_dvut_sys_update_role',     array( $this, 'handle_sys_update_role' ) );
        add_action( 'wp_ajax_dvut_sys_delete_role',     array( $this, 'handle_sys_delete_role' ) );
        add_action( 'wp_ajax_dvut_sys_import_blackbox', array( $this, 'handle_sys_import_blackbox' ) );
        add_action( 'wp_ajax_dvut_sys_browse_table',    array( $this, 'handle_sys_browse_table' ) );
        add_action( 'wp_ajax_dvut_sys_edit_record',     array( $this, 'handle_sys_edit_record' ) );
        add_action( 'wp_ajax_dvut_sys_get_logs',        array( $this, 'handle_sys_get_logs' ) );
        add_action( 'wp_ajax_dvut_sys_create_account',  array( $this, 'handle_sys_create_account' ) );

        // ============================================================
        // NHẬN XÉT ĐỊNH KỲ (Đánh giá quý)
        // ============================================================
        add_action( 'wp_ajax_dvut_nhan_xet_list',         array( $this, 'handle_nhan_xet_list' ) );
        add_action( 'wp_ajax_dvut_nhan_xet_save',         array( $this, 'handle_nhan_xet_save' ) );
        add_action( 'wp_ajax_dvut_nhan_xet_periods',      array( $this, 'handle_nhan_xet_periods' ) );
        add_action( 'wp_ajax_dvut_nhan_xet_period_save',  array( $this, 'handle_nhan_xet_period_save' ) );
        add_action( 'wp_ajax_dvut_nhan_xet_period_toggle', array( $this, 'handle_nhan_xet_period_toggle' ) );

        // ============================================================
        // REFACTORED LEGACY APIS
        // ============================================================
        add_action( 'wp_ajax_dvut_save_doan_vien', array( $this, 'handle_save_doan_vien' ) );
        add_action( 'wp_ajax_dvut_append_lich_su', array( $this, 'handle_append_lich_su' ) );
        add_action( 'wp_ajax_dvut_check_user',      array( $this, 'handle_check_user' ) );

        // ============================================================
        // UNIFIED LOGIN SYSTEM
        // ============================================================
        add_action( 'wp_ajax_nopriv_dhs_unified_password_login', array( $this, 'handle_unified_password_login' ) );
        add_action( 'wp_ajax_dhs_unified_password_login',        array( $this, 'handle_unified_password_login' ) );
        add_action( 'wp_ajax_nopriv_dhs_unified_google_login',   array( $this, 'handle_unified_google_login' ) );
        add_action( 'wp_ajax_dhs_unified_google_login',          array( $this, 'handle_unified_google_login' ) );
    }

    // =================================================================
    // PRIVATE HELPERS
    // =================================================================

    /**
     * Kiểm tra nonce bảo mật.
     */
    private function verify_nonce() {
        $nonce = '';
        if ( isset( $_POST['nonce'] ) ) {
            $nonce = sanitize_text_field( $_POST['nonce'] );
        } elseif ( isset( $_POST['_ajax_nonce'] ) ) {
            $nonce = sanitize_text_field( $_POST['_ajax_nonce'] );
        } elseif ( isset( $_REQUEST['nonce'] ) ) {
            $nonce = sanitize_text_field( $_REQUEST['nonce'] );
        }
        if ( ! wp_verify_nonce( $nonce, 'dvut_nonce' ) ) {
            wp_send_json_error( array( 'message' => 'Phiên làm việc hết hạn. Vui lòng tải lại trang.' ) );
            wp_die();
        }
    }

    /**
     * Kiểm tra quyền truy cập theo capability WordPress.
     */
    private function check_permission( $capability = 'read' ) {
        if ( ! current_user_can( $capability ) ) {
            wp_send_json_error( array( 'message' => 'Bạn không có quyền thực hiện thao tác này.' ) );
            wp_die();
        }
    }

    /**
     * Kiểm tra role hiện tại có nằm trong danh sách được phép không.
     * Admin (is_admin = true) luôn được phép.
     *
     * @param  array $allowed_roles  Danh sách role được phép.
     * @return array  Role data của user hiện tại.
     */
    private function require_role( $allowed_roles ) {
        $role_data = $this->get_user_dvut_role();
        if ( ! $role_data ) {
            wp_send_json_error( array( 'message' => 'Không xác định được vai trò của bạn trong hệ thống ĐVƯT.' ) );
            wp_die();
        }
        // Admin (is_admin) luôn được bypass trừ khi gọi riêng handler chuyên biệt
        if ( ! empty( $role_data['is_admin'] ) ) {
            return $role_data;
        }
        if ( ! in_array( $role_data['role'], (array) $allowed_roles, true ) ) {
            wp_send_json_error( array( 'message' => 'Vai trò của bạn không được phép thực hiện thao tác này.' ) );
            wp_die();
        }
        return $role_data;
    }

    /**
     * Lấy vai trò DVUT của người dùng hiện tại.
     * Delegate tới hàm wrapper trung gian get_user_dvut_profile()
     * để sau này chỉ cần sửa 1 chỗ khi ráp vào hệ thống chính.
     *
     * @return array|false  { role, khoa_id, chi_doan_id } hoặc false.
     */
    private function get_user_dvut_role() {
        $user_id = get_current_user_id();
        if ( ! $user_id ) {
            return false;
        }

        // Delegate tới hàm wrapper đã định nghĩa trong functions.php
        if ( function_exists( 'get_user_dvut_profile' ) ) {
            return get_user_dvut_profile( $user_id );
        }

        // Fallback trực tiếp nếu wrapper chưa load
        global $wpdb;
        $role_data = $wpdb->get_row( $wpdb->prepare(
            "SELECT role, khoa_id, chi_doan_id, chi_bo_id FROM {$this->table_user_roles} WHERE user_id = %d AND is_active = 1",
            $user_id
        ), ARRAY_A );

        return $role_data ?: false;
    }

    /**
     * Lọc danh sách theo phân quyền (RBAC).
     *
     * - can_bo_doan_khoa: dùng subquery chi_doan_id IN (SELECT id FROM chi_doan WHERE khoa_id = ?)
     *   kèm fallback dvut.khoa_id cho bản ghi legacy (trước khi sửa bug thiếu khoa_id khi đề cử).
     * - chi_bo_sinh_vien: tương tự — tìm khoa_ids thuộc chi bộ, rồi lọc qua chi_doan subquery + fallback dvut.khoa_id.
     * - Fallback cần thiết vì dữ liệu cũ có thể chưa có khoa_id chính xác (đã được migration dvut_khoa_id_fix_v2 xử lý).
     */
    private function apply_rbac_filter( $where_clause, $role_data ) {
        if ( ! $role_data ) {
            return $where_clause . " AND 1=0";
        }

        switch ( $role_data['role'] ) {
            case 'admin_doan_truong':
                break;
            case 'can_bo_doan_khoa':
                // Lọc theo khoa: ưu tiên dùng chi_doan_id → chi_doan.khoa_id (quan hệ chuẩn),
                // kèm fallback dvut.khoa_id cho bản ghi đã có khoa_id chính xác.
                $khoa_id      = absint( $role_data['khoa_id'] );
                $where_clause .= " AND (chi_doan_id IN (SELECT id FROM {$this->table_chi_doan} WHERE khoa_id = {$khoa_id}) OR khoa_id = {$khoa_id})";
                break;
            case 'bch_chi_doan':
                $chi_doan_id  = absint( $role_data['chi_doan_id'] );
                $where_clause .= " AND chi_doan_id = {$chi_doan_id}";
                break;
            case 'chi_bo_sinh_vien':
                $chi_bo_id = absint( $role_data['chi_bo_id'] );
                if ( $chi_bo_id > 0 ) {
                    global $wpdb;
                    $khoa_ids = $wpdb->get_col( $wpdb->prepare(
                        "SELECT id FROM {$this->table_khoa} WHERE chi_bo_id = %d",
                        $chi_bo_id
                    ) );
                    if ( ! empty( $khoa_ids ) ) {
                        $ids_str       = implode( ',', array_map( 'absint', $khoa_ids ) );
                        // Lọc qua chi_doan relationship (chuẩn) + fallback dvut.khoa_id
                        $where_clause .= " AND (chi_doan_id IN (SELECT id FROM {$this->table_chi_doan} WHERE khoa_id IN ({$ids_str})) OR khoa_id IN ({$ids_str}))";
                    } else {
                        $where_clause .= " AND 1=0";
                    }
                } else {
                    $where_clause .= " AND 1=0";
                }
                break;
            case 'doan_vien':
                $user = wp_get_current_user();
                if ( $user && $user->user_login ) {
                    global $wpdb;
                    $where_clause .= $wpdb->prepare( " AND mssv = %s", $user->user_login );
                } else {
                    $where_clause .= " AND 1=0";
                }
                break;
            default:
                $where_clause .= " AND 1=0";
                break;
        }

        return $where_clause;
    }

    /**
     * Xử lý upload file an toàn.
     *
     * @param  string $file_key  Tên key trong $_FILES.
     * @return string URL file hoặc chuỗi rỗng.
     */
    private function handle_file_upload( $file_key ) {
        if ( empty( $_FILES[ $file_key ] ) || $_FILES[ $file_key ]['error'] !== UPLOAD_ERR_OK ) {
            return '';
        }

        require_once ABSPATH . 'wp-admin/includes/file.php';

        $upload = wp_handle_upload( $_FILES[ $file_key ], array( 'test_form' => false ) );

        if ( isset( $upload['url'] ) ) {
            return esc_url( $upload['url'] );
        }

        return '';
    }

    // =================================================================
    // GĐ1: SÀNG LỌC & ĐỀ CỬ
    // =================================================================

    /**
     * ACTION: dvut_get_list
     * Lấy danh sách Đoàn viên ưu tú, có lọc theo phân quyền.
     */
    public function handle_get_list() {
        $this->verify_nonce();
        $this->check_permission();

        $role_data = $this->get_user_dvut_role();

        global $wpdb;

        $where = "WHERE trang_thai != 'DA_HUY'";
        $where = $this->apply_rbac_filter( $where, $role_data );

        $items = $wpdb->get_results(
            "SELECT * FROM {$this->table_dvut} {$where} ORDER BY created_at DESC",
            ARRAY_A
        );

        // LEFT JOIN chi_doan với khoa: lấy cd.id, cd.ten, cd.khoa_id + k.ten AS khoa
        // (bảng chi_doan chỉ lưu khoa_id, cần JOIN để trả tên khoa cho front-end)
        $chi_doan_list = $wpdb->get_results(
            "SELECT cd.id, cd.ten, cd.khoa_id, k.ten AS khoa
             FROM {$this->table_chi_doan} cd
             LEFT JOIN {$this->table_khoa} k ON cd.khoa_id = k.id
             ORDER BY cd.ten ASC",
            ARRAY_A
        );

        $khoa_list = $wpdb->get_results(
            "SELECT id, ten FROM {$this->table_khoa} ORDER BY ten ASC",
            ARRAY_A
        );

        wp_send_json_success( array(
            'items'         => $items ?: array(),
            'chi_doan_list' => $chi_doan_list ?: array(),
            'khoa_list'     => $khoa_list ?: array(),
            'user_role'     => $role_data,
        ) );
    }

    /**
     * ACTION: dvut_check_mssv
     * GĐ1 – Kiểm tra MSSV có đạt chuẩn đầu vào ĐVƯT không.
     */
    public function handle_check_mssv() {
        $this->verify_nonce();
        $this->check_permission();
        $this->require_role( array( 'bch_chi_doan' ) );

        $mssv = isset( $_POST['mssv'] ) ? sanitize_text_field( $_POST['mssv'] ) : '';

        if ( empty( $mssv ) ) {
            wp_send_json_error( array( 'message' => 'Vui lòng nhập MSSV.' ) );
            return;
        }

        global $wpdb;

        $sv = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$this->table_blackbox} WHERE mssv = %s",
            $mssv
        ), ARRAY_A );

        if ( ! $sv ) {
            wp_send_json_error( array( 'message' => 'Không tìm thấy MSSV trong hệ thống.' ) );
            return;
        }

        $criteria = array(
            'ly_luan_chinh_tri' => array(
                'value' => $sv['ly_luan_chinh_tri'],
                'pass'  => $sv['ly_luan_chinh_tri'] === 'Hoàn thành',
            ),
            'xep_loai_doan_vien' => array(
                'value' => $sv['xep_loai_doan_vien'],
                'pass'  => in_array( $sv['xep_loai_doan_vien'], array( 'Hoàn thành xuất sắc', 'Hoàn thành tốt' ), true ),
            ),
            'diem_tb' => array(
                'value' => floatval( $sv['diem_tb_tich_luy'] ),
                'pass'  => floatval( $sv['diem_tb_tich_luy'] ) >= 7.0,
            ),
            'diem_ren_luyen' => array(
                'value' => intval( $sv['diem_ren_luyen'] ),
                'pass'  => intval( $sv['diem_ren_luyen'] ) >= 80,
            ),
        );

        $all_pass = true;
        foreach ( $criteria as $c ) {
            if ( ! $c['pass'] ) { $all_pass = false; break; }
        }

        wp_send_json_success( array(
            'eligible'    => $all_pass,
            'ho_ten'      => $sv['ho_ten'],
            'chi_doan'    => $sv['chi_doan'],
            'chi_doan_id' => intval( $sv['chi_doan_id'] ),
            'khoa'        => $sv['khoa'],
            'khoa_id'     => intval( $sv['khoa_id'] ),
            'criteria'    => array_map( function( $c ) { return array( 'pass' => $c['pass'] ); }, $criteria ),
            'message'     => $all_pass ? 'Đạt tất cả tiêu chí.' : 'Không đạt một hoặc nhiều tiêu chí.',
        ) );
    }

    /**
     * ACTION: dvut_get_blackbox_profile
     * Lấy thông tin sinh viên từ hộp đen theo email hoặc MSSV.
     */
    public function handle_get_blackbox_profile() {
        $this->verify_nonce();
        $this->check_permission();

        $mssv  = isset( $_POST['mssv'] )  ? sanitize_text_field( $_POST['mssv'] )  : '';
        $email = isset( $_POST['email'] ) ? sanitize_email( $_POST['email'] )      : '';

        if ( empty( $mssv ) && empty( $email ) ) {
            wp_send_json_error( array( 'message' => 'Cần cung cấp MSSV hoặc Email.' ) );
            return;
        }

        global $wpdb;

        $sv = null;
        if ( ! empty( $mssv ) ) {
            $sv = $wpdb->get_row( $wpdb->prepare(
                "SELECT * FROM {$this->table_blackbox} WHERE mssv = %s",
                $mssv
            ), ARRAY_A );
        }
        if ( ! $sv && ! empty( $email ) ) {
            $sv = $wpdb->get_row( $wpdb->prepare(
                "SELECT * FROM {$this->table_blackbox} WHERE email = %s",
                $email
            ), ARRAY_A );
        }

        if ( ! $sv ) {
            wp_send_json_error( array( 'message' => 'Không tìm thấy sinh viên trong hệ thống.' ) );
            return;
        }

        // Thêm alias để JS có thể dùng cả tên gốc lẫn tên rút gọn
        $sv['diem_tb'] = floatval( $sv['diem_tb_tich_luy'] ?? 0 );
        $sv['diem_rl'] = intval( $sv['diem_ren_luyen'] ?? 0 );

        wp_send_json_success( $sv );
    }

    /**
     * ACTION: dvut_search_blackbox
     * Tìm kiếm sinh viên trong hộp đen theo tên hoặc MSSV.
     */
    public function handle_search_blackbox() {
        $this->verify_nonce();
        $this->check_permission();

        $query = isset( $_POST['query'] ) ? sanitize_text_field( $_POST['query'] ) : '';
        $limit = isset( $_POST['limit'] ) ? absint( $_POST['limit'] ) : 20;

        if ( strlen( $query ) < 2 ) {
            wp_send_json_success( array() );
            return;
        }

        global $wpdb;
        $like = '%' . $wpdb->esc_like( $query ) . '%';

        $results = $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM {$this->table_blackbox} WHERE ho_ten LIKE %s OR mssv LIKE %s LIMIT %d",
            $like, $like, $limit
        ), ARRAY_A );

        // Thêm alias diem_tb, diem_rl cho JS
        if ( $results ) {
            foreach ( $results as &$row ) {
                $row['diem_tb'] = floatval( $row['diem_tb_tich_luy'] ?? 0 );
                $row['diem_rl'] = intval( $row['diem_ren_luyen'] ?? 0 );
            }
            unset( $row );
        }

        wp_send_json_success( $results ?: array() );
    }

    /**
     * ACTION: dvut_add_de_cu
     * GĐ1 – Thêm đề cử ĐVƯT mới (CHO_NOP).
     */
    public function handle_add_de_cu() {
        $this->verify_nonce();
        $this->check_permission();
        $this->require_role( array( 'doan_vien' ) );

        $ho_ten      = isset( $_POST['ho_ten'] )      ? sanitize_text_field( $_POST['ho_ten'] ) : '';
        $mssv        = isset( $_POST['mssv'] )         ? sanitize_text_field( $_POST['mssv'] ) : '';
        $chi_doan_id = isset( $_POST['chi_doan_id'] )  ? absint( $_POST['chi_doan_id'] ) : 0;
        $khoa_id     = isset( $_POST['khoa_id'] )      ? absint( $_POST['khoa_id'] ) : 0;

        if ( empty( $ho_ten ) || empty( $mssv ) || empty( $chi_doan_id ) ) {
            wp_send_json_error( array( 'message' => 'Vui lòng điền đầy đủ thông tin.' ) );
            return;
        }

        global $wpdb;

        // Kiểm tra trùng MSSV — bỏ qua hồ sơ đã bị từ chối/trả về/huỷ
        $existing = $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$this->table_dvut}
             WHERE mssv = %s AND trang_thai NOT IN ('TU_CHOI','TRA_VE','DA_HUY')",
            $mssv
        ) );
        if ( $existing > 0 ) {
            wp_send_json_error( array( 'message' => 'MSSV này đã có hồ sơ đang xử lý trong hệ thống.' ) );
            return;
        }

        // Khi tạo lại hồ sơ mới, đánh dấu hồ sơ cũ (TU_CHOI / TRA_VE) là DA_HUY
        $wpdb->query( $wpdb->prepare(
            "UPDATE {$this->table_dvut}
             SET trang_thai = 'DA_HUY', updated_at = %s
             WHERE mssv = %s AND trang_thai IN ('TU_CHOI','TRA_VE')",
            current_time( 'mysql' ),
            $mssv
        ) );

        // Lấy tên Chi Đoàn & Khoa (snapshot)
        $chi_doan_ten = $wpdb->get_var( $wpdb->prepare(
            "SELECT ten FROM {$this->table_chi_doan} WHERE id = %d", $chi_doan_id
        ) );
        $khoa_ten = $wpdb->get_var( $wpdb->prepare(
            "SELECT ten FROM {$this->table_khoa} WHERE id = %d", $khoa_id
        ) );

        $result = $wpdb->insert(
            $this->table_dvut,
            array(
                'ho_ten'       => $ho_ten,
                'mssv'         => $mssv,
                'chi_doan_id'  => $chi_doan_id,
                'chi_doan'     => $chi_doan_ten ?: '',
                'khoa_id'      => $khoa_id,
                'khoa'         => $khoa_ten ?: '',
                'ngay_de_cu'   => current_time( 'Y-m-d' ),
                'trang_thai'   => 'CHO_NOP',
                'nguoi_de_cu'  => get_current_user_id(),
                'created_at'   => current_time( 'mysql' ),
            ),
            array( '%s', '%s', '%d', '%s', '%d', '%s', '%s', '%s', '%d', '%s' )
        );

        if ( false === $result ) {
            wp_send_json_error( array( 'message' => 'Lỗi khi lưu dữ liệu.' ) );
            return;
        }

        $new_id = $wpdb->insert_id;
        $item   = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$this->table_dvut} WHERE id = %d", $new_id
        ), ARRAY_A );

        wp_send_json_success( array(
            'item'    => $item,
            'message' => 'Đề cử thành công!',
        ) );
    }

    /**
     * ACTION: dvut_nop_ho_so
     * GĐ1 – Nộp hồ sơ (Mẫu 01 + Mẫu 02).  CHO_NOP → CHO_CHI_DOAN
     */
    public function handle_nop_ho_so() {
        $this->verify_nonce();
        $this->check_permission();
        $this->require_role( array( 'doan_vien' ) );

        $id                = isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0;
        $so_luoc_qua_trinh = isset( $_POST['so_luoc_qua_trinh'] ) ? sanitize_textarea_field( $_POST['so_luoc_qua_trinh'] ) : '';

        // Mẫu 01: nhận file upload (PDF/Word) hoặc text (backward compat)
        $mau01_file = $this->handle_file_upload( 'mau01' );
        // Mẫu 02: nhận file upload
        $bai_cam_nhan_file = $this->handle_file_upload( 'bai_cam_nhan' );

        if ( empty( $id ) ) {
            wp_send_json_error( array( 'message' => 'Dữ liệu không hợp lệ.' ) );
            return;
        }

        // Phải có ít nhất một trong: Mẫu 01 file (mới) hoặc text (cũ)
        if ( empty( $mau01_file ) && empty( $so_luoc_qua_trinh ) ) {
            wp_send_json_error( array( 'message' => 'Vui lòng upload file Mẫu 01 hoặc nhập sơ lược quá trình phấn đấu.' ) );
            return;
        }

        global $wpdb;

        $update_data = array(
            'trang_thai' => 'CHO_CHI_DOAN',
            'updated_at' => current_time( 'mysql' ),
        );
        $formats = array( '%s', '%s' );

        if ( ! empty( $so_luoc_qua_trinh ) ) {
            $update_data['so_luoc_qua_trinh'] = $so_luoc_qua_trinh;
            $formats[] = '%s';
        }
        if ( ! empty( $mau01_file ) ) {
            $update_data['mau01_file'] = $mau01_file;
            $formats[] = '%s';
        }
        if ( ! empty( $bai_cam_nhan_file ) ) {
            $update_data['bai_cam_nhan_file'] = $bai_cam_nhan_file;
            $formats[] = '%s';
        }

        $wpdb->update(
            $this->table_dvut,
            $update_data,
            array( 'id' => $id ),
            $formats,
            array( '%d' )
        );

        wp_send_json_success( array(
            'message' => 'Nộp hồ sơ thành công! Chuyển sang chờ Chi Đoàn duyệt.',
        ) );
    }

    // =================================================================
    // GĐ2: CÔNG NHẬN ĐOÀN VIÊN ƯU TÚ
    // =================================================================

    /**
     * ACTION: dvut_get_chi_doan_member_count
     * Đếm số đoàn viên trong chi đoàn từ bảng Hộp đen.
     * Dùng để validate kết quả biểu quyết Chi Đoàn.
     */
    public function handle_get_chi_doan_member_count() {
        $this->verify_nonce();
        $this->check_permission();

        $chi_doan_id = isset( $_POST['chi_doan_id'] ) ? absint( $_POST['chi_doan_id'] ) : 0;
        if ( ! $chi_doan_id ) {
            wp_send_json_error( array( 'message' => 'chi_doan_id không hợp lệ.' ) );
            return;
        }

        global $wpdb;
        $count = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$this->table_blackbox} WHERE chi_doan_id = %d",
            $chi_doan_id
        ) );

        wp_send_json_success( array(
            'chi_doan_id'  => $chi_doan_id,
            'total_members' => $count,
        ) );
    }

    /**
     * ACTION: dvut_chi_doan_duyet
     * GĐ2 – BCH Chi Đoàn biểu quyết.  > 50% → CHO_DOAN_KHOA, ≤ 50% → TU_CHOI.
     * Dữ liệu BQ + file Mẫu 03 lưu trực tiếp vào bảng chính.
     */
    public function handle_chi_doan_duyet() {
        $this->verify_nonce();
        $this->check_permission();
        $this->require_role( array( 'bch_chi_doan' ) );

        $id             = isset( $_POST['id'] )             ? absint( $_POST['id'] ) : 0;
        $tong_so_nguoi  = isset( $_POST['tong_so_nguoi'] )  ? absint( $_POST['tong_so_nguoi'] ) : 0;
        $so_luot_dong_y = isset( $_POST['so_luot_dong_y'] ) ? absint( $_POST['so_luot_dong_y'] ) : 0;

        if ( empty( $id ) || $tong_so_nguoi < 1 ) {
            wp_send_json_error( array( 'message' => 'Dữ liệu không hợp lệ.' ) );
            return;
        }

        if ( $so_luot_dong_y > $tong_so_nguoi ) {
            wp_send_json_error( array( 'message' => 'Số lượt đồng ý không thể lớn hơn tổng số người.' ) );
            return;
        }

        $bien_ban_file = $this->handle_file_upload( 'bien_ban_chi_doan' );

        $ty_le = round( ( $so_luot_dong_y / $tong_so_nguoi ) * 100, 2 );

        if ( $ty_le > 50 ) {
            $trang_thai_moi = 'CHO_DOAN_KHOA';
            $ghi_chu        = sprintf( 'Chi Đoàn duyệt: %s%% (%d/%d)', $ty_le, $so_luot_dong_y, $tong_so_nguoi );
            $message        = 'Đã duyệt! Chuyển lên Đoàn Khoa.';
        } else {
            $trang_thai_moi = 'TU_CHOI';
            $ghi_chu        = sprintf( 'Chi Đoàn không đạt tỷ lệ > 50%% (%s%%)', $ty_le );
            $message        = 'Không đạt tỷ lệ biểu quyết. Hồ sơ bị từ chối.';
        }

        global $wpdb;

        $update_data = array(
            'tong_so_nguoi'          => $tong_so_nguoi,
            'so_luot_dong_y'         => $so_luot_dong_y,
            'ty_le'                  => $ty_le,
            'bien_ban_chi_doan_file' => $bien_ban_file,
            'trang_thai'             => $trang_thai_moi,
            'ghi_chu'                => $ghi_chu,
            'nguoi_duyet_cd'         => get_current_user_id(),
            'ngay_duyet_cd'          => current_time( 'mysql' ),
            'updated_at'             => current_time( 'mysql' ),
        );
        if ( $trang_thai_moi === 'TU_CHOI' ) {
            $update_data['trang_thai_truoc_tu_choi'] = 'CHO_CHI_DOAN';
            $update_data['ly_do_tu_choi']            = sprintf( 'Không đạt tỷ lệ biểu quyết > 50%% (%s%%)', $ty_le );
            $update_data['cap_tu_choi']              = 'Chi Đoàn';
            $update_data['ngay_tu_choi']             = current_time( 'mysql' );
        }
        $formats = array( '%d', '%d', '%f', '%s', '%s', '%s', '%d', '%s', '%s' );
        if ( isset( $update_data['trang_thai_truoc_tu_choi'] ) ) {
            $formats[] = '%s';
            $formats[] = '%s';
            $formats[] = '%s';
            $formats[] = '%s';
        }
        $wpdb->update(
            $this->table_dvut,
            $update_data,
            array( 'id' => $id ),
            $formats,
            array( '%d' )
        );

        $item = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$this->table_dvut} WHERE id = %d", $id
        ), ARRAY_A );

        wp_send_json_success( array(
            'item'    => $item,
            'message' => $message,
        ) );
    }

    /**
     * ACTION: dvut_doan_khoa_duyet
     * GĐ2 – Đoàn Khoa xét duyệt.  Duyệt → CHO_DOAN_TRUONG, Từ chối → TRA_VE.
     * BQ ĐK + file Mẫu 04/05 + ưu/khuyết điểm lưu trực tiếp vào bảng chính.
     */
    public function handle_doan_khoa_duyet() {
        $this->verify_nonce();
        $this->check_permission();
        $this->require_role( array( 'can_bo_doan_khoa' ) );

        $id                = isset( $_POST['id'] )                ? absint( $_POST['id'] ) : 0;
        $approved          = isset( $_POST['approved'] )          ? filter_var( $_POST['approved'], FILTER_VALIDATE_BOOLEAN ) : false;
        $ly_do             = isset( $_POST['ly_do'] )             ? sanitize_textarea_field( $_POST['ly_do'] ) : '';
        $tong_so_dk        = isset( $_POST['tong_so_dk'] )        ? absint( $_POST['tong_so_dk'] )
                           : ( isset( $_POST['tong_so_uy_vien'] ) ? absint( $_POST['tong_so_uy_vien'] ) : 0 );
        $so_luot_dong_y_dk = isset( $_POST['so_luot_dong_y_dk'] ) ? absint( $_POST['so_luot_dong_y_dk'] )
                           : ( isset( $_POST['so_phieu_dong_y'] ) ? absint( $_POST['so_phieu_dong_y'] ) : 0 );
        $uu_diem           = isset( $_POST['uu_diem'] )           ? sanitize_textarea_field( $_POST['uu_diem'] ) : '';
        $khuyet_diem       = isset( $_POST['khuyet_diem'] )       ? sanitize_textarea_field( $_POST['khuyet_diem'] ) : '';

        if ( empty( $id ) ) {
            wp_send_json_error( array( 'message' => 'ID không hợp lệ.' ) );
            return;
        }

        $cong_van_file = $this->handle_file_upload( 'cong_van_dk' );
        $bien_ban_file = $this->handle_file_upload( 'bien_ban_dk' );

        $ty_le_dk = 0;
        if ( $tong_so_dk > 0 ) {
            $ty_le_dk = round( ( $so_luot_dong_y_dk / $tong_so_dk ) * 100, 2 );
        }

        global $wpdb;

        if ( $approved ) {
            $trang_thai = 'CHO_DOAN_TRUONG';
            $ghi_chu    = sprintf(
                'Đoàn Khoa phê duyệt ngày %s — BQ: %s%% (%d/%d)',
                current_time( 'd/m/Y' ), $ty_le_dk, $so_luot_dong_y_dk, $tong_so_dk
            );
            $message = 'Đã phê duyệt! Trình lên Đoàn Trường.';
        } else {
            $trang_thai           = 'TRA_VE';
            $ghi_chu              = sprintf( 'Đoàn Khoa trả về: %s', $ly_do );
            $message              = 'Đã trả hồ sơ về Chi Đoàn.';
            $trang_thai_hien_tai  = $wpdb->get_var( $wpdb->prepare(
                "SELECT trang_thai FROM {$this->table_dvut} WHERE id = %d",
                $id
            ) );
        }

        $update_data   = array(
            'tong_so_dk'         => $tong_so_dk,
            'so_luot_dong_y_dk'  => $so_luot_dong_y_dk,
            'ty_le_dk'           => $ty_le_dk,
            'uu_diem'            => $uu_diem,
            'khuyet_diem'        => $khuyet_diem,
            'cong_van_dk_file'   => $cong_van_file,
            'bien_ban_dk_file'   => $bien_ban_file,
            'trang_thai'         => $trang_thai,
            'ghi_chu'            => $ghi_chu,
            'nguoi_duyet_dk'     => get_current_user_id(),
            'ngay_duyet_dk'      => current_time( 'mysql' ),
            'updated_at'         => current_time( 'mysql' ),
        );
        $update_format = array( '%d', '%d', '%f', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s' );

        if ( ! $approved ) {
            $update_data['trang_thai_truoc_tu_choi'] = isset( $trang_thai_hien_tai ) ? $trang_thai_hien_tai : '';
            $update_data['ly_do_tu_choi']            = $ly_do;
            $update_data['cap_tu_choi']              = 'Đoàn Khoa';
            $update_data['ngay_tu_choi']             = current_time( 'mysql' );
            $update_format[]                         = '%s';
            $update_format[]                         = '%s';
            $update_format[]                         = '%s';
            $update_format[]                         = '%s';
        }

        $wpdb->update(
            $this->table_dvut,
            $update_data,
            array( 'id' => $id ),
            $update_format,
            array( '%d' )
        );

        wp_send_json_success( array(
            'message' => $message,
        ) );
    }

    /**
     * ACTION: dvut_doan_truong_cong_nhan
     * GĐ2 – Đoàn Trường ban hành Quyết định công nhận ĐVƯT.
     * CHO_DOAN_TRUONG → DA_CONG_NHAN.  Kiểm tra ràng buộc 15 ngày.
     */
    public function handle_doan_truong_cong_nhan() {
        $this->verify_nonce();
        $this->check_permission();
        $this->require_role( array( 'admin_doan_truong' ) );

        $id             = isset( $_POST['id'] )             ? absint( $_POST['id'] ) : 0;
        $so_quyet_dinh  = isset( $_POST['so_quyet_dinh'] )  ? sanitize_text_field( $_POST['so_quyet_dinh'] ) : '';

        if ( empty( $id ) ) {
            wp_send_json_error( array( 'message' => 'ID không hợp lệ.' ) );
            return;
        }

        $file_qd = $this->handle_file_upload( 'file_qd_cong_nhan' );
        if ( empty( $file_qd ) ) {
            wp_send_json_error( array( 'message' => 'Vui lòng đính kèm file Quyết định công nhận.' ) );
            return;
        }

        global $wpdb;

        // Kiểm tra ràng buộc 15 ngày làm việc (~21 ngày lịch)
        $canh_bao_tre  = '';
        $ngay_duyet_dk = $wpdb->get_var( $wpdb->prepare(
            "SELECT ngay_duyet_dk FROM {$this->table_dvut} WHERE id = %d",
            $id
        ) );

        if ( $ngay_duyet_dk ) {
            $ngay_nhan      = strtotime( $ngay_duyet_dk );
            $ngay_hien_tai  = current_time( 'timestamp' );
            $so_ngay_da_qua = ( $ngay_hien_tai - $ngay_nhan ) / DAY_IN_SECONDS;
            if ( $so_ngay_da_qua > 21 ) {
                $canh_bao_tre = sprintf( ' [CẢNH BÁO: Trễ hạn %d ngày]', round( $so_ngay_da_qua ) );
            }
        }

        $ghi_chu = sprintf(
            'Đoàn Trường công nhận ĐVƯT ngày %s — QĐ: %s%s',
            current_time( 'd/m/Y' ), $so_quyet_dinh, $canh_bao_tre
        );

        $wpdb->update(
            $this->table_dvut,
            array(
                'trang_thai'         => 'DA_CONG_NHAN',
                'so_quyet_dinh'      => $so_quyet_dinh,
                'file_qd_cong_nhan'  => $file_qd,
                'ngay_cong_nhan'     => current_time( 'Y-m-d' ),
                'nguoi_cong_nhan'    => get_current_user_id(),
                'ghi_chu'            => $ghi_chu,
                'updated_at'         => current_time( 'mysql' ),
            ),
            array( 'id' => $id ),
            array( '%s', '%s', '%s', '%s', '%d', '%s', '%s' ),
            array( '%d' )
        );

        // Tự động gán chi_bo_id từ khoa tương ứng
        $khoa_id = $wpdb->get_var( $wpdb->prepare(
            "SELECT khoa_id FROM {$this->table_dvut} WHERE id = %d",
            $id
        ) );
        if ( $khoa_id ) {
            $chi_bo_id = $wpdb->get_var( $wpdb->prepare(
                "SELECT chi_bo_id FROM {$this->table_khoa} WHERE id = %d",
                (int) $khoa_id
            ) );
            if ( $chi_bo_id ) {
                $wpdb->update(
                    $this->table_dvut,
                    array( 'chi_bo_id' => (int) $chi_bo_id ),
                    array( 'id'        => $id ),
                    array( '%d' ),
                    array( '%d' )
                );
            }
        }

        wp_send_json_success( array(
            'message' => 'Đã ban hành Quyết định công nhận Đoàn viên Ưu tú!',
        ) );
    }

    // =================================================================
    // GĐ3: THEO DÕI LỚP CẢM TÌNH ĐẢNG
    // =================================================================

    /**
     * ACTION: dvut_update_cam_tinh_dang
     * GĐ3 – Cập nhật tiến độ bồi dưỡng nhận thức về Đảng.
     */
    public function handle_update_cam_tinh_dang() {
        $this->verify_nonce();
        $this->check_permission();
        $this->require_role( array( 'chi_bo_sinh_vien', 'admin_doan_truong' ) );

        $id      = isset( $_POST['id'] )      ? absint( $_POST['id'] ) : 0;
        $tien_do = isset( $_POST['tien_do'] ) ? sanitize_text_field( $_POST['tien_do'] ) : '';

        $allowed_values = array( 'CHUA_THAM_GIA', 'DANG_HOC', 'DA_HOAN_THANH' );
        if ( empty( $id ) || ! in_array( $tien_do, $allowed_values, true ) ) {
            wp_send_json_error( array( 'message' => 'Dữ liệu không hợp lệ.' ) );
            return;
        }

        global $wpdb;

        $so_chung_nhan = isset( $_POST['so_chung_nhan_ctd'] ) ? sanitize_text_field( $_POST['so_chung_nhan_ctd'] ) : '';
        $ngay_chung_nhan = isset( $_POST['ngay_chung_nhan_ctd'] ) ? sanitize_text_field( $_POST['ngay_chung_nhan_ctd'] ) : '';

        if ( 'DA_HOAN_THANH' === $tien_do ) {
            if ( empty( $so_chung_nhan ) ) {
                wp_send_json_error( array( 'message' => 'Vui lòng nhập Số chứng nhận Cảm tình Đảng.' ) );
                return;
            }
            if ( empty( $ngay_chung_nhan ) ) {
                wp_send_json_error( array( 'message' => 'Vui lòng chọn Ngày chứng nhận Cảm tình Đảng.' ) );
                return;
            }
        }

        $file_chung_nhan = $this->handle_file_upload( 'file_chung_nhan_ctd' );
        if ( 'DA_HOAN_THANH' === $tien_do && empty( $file_chung_nhan ) ) {
            $existing_file = $wpdb->get_var( $wpdb->prepare(
                "SELECT file_chung_nhan_ctd FROM {$this->table_dvut} WHERE id = %d", $id
            ) );
            if ( empty( $existing_file ) ) {
                wp_send_json_error( array( 'message' => 'Vui lòng đính kèm ảnh Giấy chứng nhận hoàn thành lớp bồi dưỡng.' ) );
                return;
            }
        }

        $update_data = array(
            'tien_do_cam_tinh_dang' => $tien_do,
            'so_chung_nhan_ctd'     => 'DA_HOAN_THANH' === $tien_do ? $so_chung_nhan : '',
            'ngay_chung_nhan_ctd'   => 'DA_HOAN_THANH' === $tien_do ? ($ngay_chung_nhan ?: null) : null,
            'updated_at'            => current_time( 'mysql' ),
        );
        if ( ! empty( $file_chung_nhan ) ) {
            $update_data['file_chung_nhan_ctd'] = $file_chung_nhan;
        }

        $wpdb->update(
            $this->table_dvut,
            $update_data,
            array( 'id' => $id ),
            null,
            array( '%d' )
        );

        $labels = array(
            'CHUA_THAM_GIA' => 'Chưa tham gia',
            'DANG_HOC'      => 'Đang theo học',
            'DA_HOAN_THANH' => 'Đã hoàn thành',
        );

        wp_send_json_success( array(
            'message' => sprintf( 'Đã cập nhật tiến độ cảm tình Đảng: %s.', $labels[ $tien_do ] ),
        ) );
    }

    // =================================================================
    // GĐ4: GIỚI THIỆU VÀO ĐẢNG
    // =================================================================

    /**
     * ACTION: dvut_gioi_thieu_dang
     * GĐ4 – Chi Đoàn biểu quyết giới thiệu vào Đảng.
     * Tiên quyết: tien_do_cam_tinh_dang = 'DA_HOAN_THANH'.
     * > 50% → CHO_DK_GIOI_THIEU, ≤ 50% → TU_CHOI.
     */
    public function handle_gioi_thieu_dang() {
        $this->verify_nonce();
        $this->check_permission();
        $this->require_role( array( 'bch_chi_doan' ) );

        $id                = isset( $_POST['id'] )                ? absint( $_POST['id'] ) : 0;
        $tong_so_gt        = isset( $_POST['tong_so_gt'] )        ? absint( $_POST['tong_so_gt'] )
                           : ( isset( $_POST['tong_so_nguoi'] )   ? absint( $_POST['tong_so_nguoi'] ) : 0 );
        $so_luot_dong_y_gt = isset( $_POST['so_luot_dong_y_gt'] ) ? absint( $_POST['so_luot_dong_y_gt'] )
                           : ( isset( $_POST['so_luot_dong_y'] )  ? absint( $_POST['so_luot_dong_y'] ) : 0 );

        if ( empty( $id ) || $tong_so_gt < 1 ) {
            wp_send_json_error( array( 'message' => 'Dữ liệu không hợp lệ.' ) );
            return;
        }

        if ( $so_luot_dong_y_gt > $tong_so_gt ) {
            wp_send_json_error( array( 'message' => 'Số lượt đồng ý không thể lớn hơn tổng số người.' ) );
            return;
        }

        global $wpdb;

        // Kiểm tra điều kiện tiên quyết
        $ho_so = $wpdb->get_row( $wpdb->prepare(
            "SELECT trang_thai, tien_do_cam_tinh_dang FROM {$this->table_dvut} WHERE id = %d",
            $id
        ), ARRAY_A );

        if ( ! $ho_so || $ho_so['trang_thai'] !== 'DA_CONG_NHAN' ) {
            wp_send_json_error( array( 'message' => 'Hồ sơ chưa được công nhận ĐVƯT.' ) );
            return;
        }
        if ( $ho_so['tien_do_cam_tinh_dang'] !== 'DA_HOAN_THANH' ) {
            wp_send_json_error( array( 'message' => 'Đoàn viên chưa hoàn thành lớp bồi dưỡng nhận thức về Đảng.' ) );
            return;
        }

        $bien_ban_gt = $this->handle_file_upload( 'bien_ban_gt' );

        $ty_le_gt = round( ( $so_luot_dong_y_gt / $tong_so_gt ) * 100, 2 );

        if ( $ty_le_gt > 50 ) {
            $trang_thai = 'CHO_DK_GIOI_THIEU';
            $message    = 'Đã giới thiệu vào Đảng. Chuyển lên Đoàn Khoa.';
        } else {
            $trang_thai = 'TU_CHOI';
            $message    = 'Không đạt tỷ lệ biểu quyết giới thiệu vào Đảng.';
        }

        $ghi_chu = sprintf( 'BQ giới thiệu Đảng: %s%% (%d/%d)', $ty_le_gt, $so_luot_dong_y_gt, $tong_so_gt );

        $wpdb->update(
            $this->table_dvut,
            array(
                'tong_so_gt'           => $tong_so_gt,
                'so_luot_dong_y_gt'    => $so_luot_dong_y_gt,
                'ty_le_gt'             => $ty_le_gt,
                'bien_ban_gt_file'     => $bien_ban_gt,
                'trang_thai'           => $trang_thai,
                'ngay_gioi_thieu_dang' => current_time( 'mysql' ),
                'ghi_chu'              => $ghi_chu,
                'updated_at'           => current_time( 'mysql' ),
            ),
            array( 'id' => $id ),
            array( '%d', '%d', '%f', '%s', '%s', '%s', '%s', '%s' ),
            array( '%d' )
        );

        wp_send_json_success( array(
            'message' => $message,
        ) );
    }

    /**
     * ACTION: dvut_dk_duyet_gioi_thieu
     * GĐ4 – Đoàn Khoa ra Nghị quyết giới thiệu vào Đảng (Mẫu 07).
     * CHO_DK_GIOI_THIEU → CHO_DT_XAC_NHAN_GT hoặc TRA_VE.
     */
    public function handle_dk_duyet_gioi_thieu() {
        $this->verify_nonce();
        $this->check_permission();
        $this->require_role( array( 'can_bo_doan_khoa' ) );

        $id       = isset( $_POST['id'] )       ? absint( $_POST['id'] ) : 0;
        $approved = isset( $_POST['approved'] )  ? filter_var( $_POST['approved'], FILTER_VALIDATE_BOOLEAN ) : false;
        $ly_do    = isset( $_POST['ly_do'] )     ? sanitize_textarea_field( $_POST['ly_do'] ) : '';

        if ( empty( $id ) ) {
            wp_send_json_error( array( 'message' => 'ID không hợp lệ.' ) );
            return;
        }

        $nghi_quyet_file = $this->handle_file_upload( 'nghi_quyet_dk' );

        global $wpdb;

        if ( $approved ) {
            $trang_thai = 'CHUYEN_GIAO_CHI_BO';
            $ghi_chu    = sprintf( 'Đoàn Khoa ra Nghị quyết giới thiệu vào Đảng ngày %s', current_time( 'd/m/Y' ) );
            $message    = 'Đã ban hành Nghị quyết giới thiệu vào Đảng! Hồ sơ đã chuyển giao cho Chi bộ Sinh viên.';
        } else {
            $trang_thai = 'TRA_VE';
            $ghi_chu    = sprintf( 'Đoàn Khoa trả về giới thiệu Đảng: %s', $ly_do );
            $message    = 'Đã trả hồ sơ về Chi Đoàn.';
        }

        $update_data = array(
            'trang_thai' => $trang_thai,
            'ghi_chu'    => $ghi_chu,
            'updated_at' => current_time( 'mysql' ),
        );
        if ( ! empty( $nghi_quyet_file ) ) {
            $update_data['nghi_quyet_dk_file'] = $nghi_quyet_file;
        }
        if ( $approved ) {
            $update_data['ngay_nghi_quyet_gt'] = current_time( 'mysql' );
        }

        $wpdb->update(
            $this->table_dvut,
            $update_data,
            array( 'id' => $id ),
            null,
            array( '%d' )
        );

        wp_send_json_success( array(
            'message' => $message,
        ) );
    }



    // =================================================================
    // GĐ5: CHUYỂN ĐẢNG CHÍNH THỨC
    // =================================================================

    /**
     * ACTION: dvut_chuyen_dang_chinh_thuc
     * GĐ5 – Chi Đoàn biểu quyết chuyển Đảng chính thức.
     * > 50% → CHO_DK_CHUYEN_DANG, ≤ 50% → TU_CHOI.
     */
    public function handle_chuyen_dang_chinh_thuc() {
        $this->verify_nonce();
        $this->check_permission();
        $this->require_role( array( 'bch_chi_doan' ) );

        $id                   = isset( $_POST['id'] )                   ? absint( $_POST['id'] ) : 0;
        $tong_so_cd_ct        = isset( $_POST['tong_so_cd_ct'] )        ? absint( $_POST['tong_so_cd_ct'] )
                              : ( isset( $_POST['tong_so_nguoi'] )      ? absint( $_POST['tong_so_nguoi'] ) : 0 );
        $so_luot_dong_y_cd_ct = isset( $_POST['so_luot_dong_y_cd_ct'] ) ? absint( $_POST['so_luot_dong_y_cd_ct'] )
                              : ( isset( $_POST['so_luot_dong_y'] )     ? absint( $_POST['so_luot_dong_y'] ) : 0 );

        if ( empty( $id ) || $tong_so_cd_ct < 1 ) {
            wp_send_json_error( array( 'message' => 'Dữ liệu không hợp lệ.' ) );
            return;
        }

        if ( $so_luot_dong_y_cd_ct > $tong_so_cd_ct ) {
            wp_send_json_error( array( 'message' => 'Số lượt đồng ý không thể lớn hơn tổng số người.' ) );
            return;
        }

        $bien_ban_cd_ct = $this->handle_file_upload( 'bien_ban_cd_ct' );

        $ty_le_cd_ct = round( ( $so_luot_dong_y_cd_ct / $tong_so_cd_ct ) * 100, 2 );

        if ( $ty_le_cd_ct > 50 ) {
            $trang_thai = 'CHO_DK_CHUYEN_DANG';
            $message    = 'Đã gửi đề nghị chuyển Đảng chính thức lên Đoàn Khoa.';
        } else {
            $trang_thai = 'TU_CHOI';
            $message    = 'Không đạt tỷ lệ biểu quyết chuyển Đảng chính thức.';
        }

        $ghi_chu = sprintf( 'BQ chuyển Đảng CT: %s%% (%d/%d)', $ty_le_cd_ct, $so_luot_dong_y_cd_ct, $tong_so_cd_ct );

        global $wpdb;

        $update_cd = array(
            'tong_so_cd_ct'        => $tong_so_cd_ct,
            'so_luot_dong_y_cd_ct' => $so_luot_dong_y_cd_ct,
            'ty_le_cd_ct'          => $ty_le_cd_ct,
            'bien_ban_cd_ct_file'  => $bien_ban_cd_ct,
            'trang_thai'           => $trang_thai,
            'ghi_chu'              => $ghi_chu,
            'updated_at'           => current_time( 'mysql' ),
        );
        if ( $trang_thai === 'TU_CHOI' ) {
            $update_cd['trang_thai_truoc_tu_choi'] = 'DANG_VIEN_DU_BI';
        }
        $formats_cd = array( '%d', '%d', '%f', '%s', '%s', '%s', '%s' );
        if ( isset( $update_cd['trang_thai_truoc_tu_choi'] ) ) {
            $formats_cd[] = '%s';
        }
        $wpdb->update(
            $this->table_dvut,
            $update_cd,
            array( 'id' => $id ),
            $formats_cd,
            array( '%d' )
        );

        wp_send_json_success( array(
            'message' => $message,
        ) );
    }

    /**
     * ACTION: dvut_dk_duyet_chuyen_dang
     * GĐ5 – Đoàn Khoa ban hành Ý kiến nhận xét (Mẫu 09).
     * CHO_DK_CHUYEN_DANG → CHO_DT_XAC_NHAN_CD hoặc TRA_VE.
     */
    public function handle_dk_duyet_chuyen_dang() {
        $this->verify_nonce();
        $this->check_permission();
        $this->require_role( array( 'can_bo_doan_khoa' ) );

        $id       = isset( $_POST['id'] )       ? absint( $_POST['id'] ) : 0;
        $approved = isset( $_POST['approved'] )  ? filter_var( $_POST['approved'], FILTER_VALIDATE_BOOLEAN ) : false;
        $ly_do    = isset( $_POST['ly_do'] )     ? sanitize_textarea_field( $_POST['ly_do'] ) : '';

        if ( empty( $id ) ) {
            wp_send_json_error( array( 'message' => 'ID không hợp lệ.' ) );
            return;
        }

        $y_kien_file = $this->handle_file_upload( 'y_kien_dk' );

        global $wpdb;

        if ( $approved ) {
            $trang_thai = 'DANG_VIEN_CHINH_THUC';
            $ghi_chu    = sprintf( 'Đoàn Khoa xác nhận chuyển Đảng chính thức ngày %s', current_time( 'd/m/Y' ) );
            $message    = 'Ý kiến nhận xét đã được ban hành! Đoàn viên ưu tú đã trở thành Đảng viên chính thức.';
        } else {
            $trang_thai = 'TRA_VE';
            $ghi_chu    = sprintf( 'Đoàn Khoa trả về chuyển Đảng: %s', $ly_do );
            $message    = 'Đã trả hồ sơ về Chi Đoàn.';
        }

        $update_data = array(
            'trang_thai' => $trang_thai,
            'ghi_chu'    => $ghi_chu,
            'updated_at' => current_time( 'mysql' ),
        );
        if ( ! empty( $y_kien_file ) ) {
            $update_data['y_kien_dk_file'] = $y_kien_file;
        }
        if ( $approved ) {
            $update_data['ngay_chuyen_dang'] = current_time( 'mysql' );
        }

        $wpdb->update(
            $this->table_dvut,
            $update_data,
            array( 'id' => $id ),
            null,
            array( '%d' )
        );

        wp_send_json_success( array(
            'message' => $message,
        ) );
    }

    // =================================================================
    // TIỆN ÍCH CHUNG
    // =================================================================

    /**
     * ACTION: dvut_delete
     * Xóa hồ sơ (chỉ ở trạng thái CHO_NOP, TU_CHOI, DA_HUY).
     */
    public function handle_delete() {
        $this->verify_nonce();
        $this->check_permission();
        $role_data = $this->require_role( array( 'bch_chi_doan', 'admin_doan_truong' ) );

        $id = isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0;

        if ( empty( $id ) ) {
            wp_send_json_error( array( 'message' => 'ID không hợp lệ.' ) );
            return;
        }

        global $wpdb;

        $ho_so = $wpdb->get_row( $wpdb->prepare(
            "SELECT trang_thai, nguoi_de_cu FROM {$this->table_dvut} WHERE id = %d", $id
        ), ARRAY_A );

        if ( ! $ho_so ) {
            wp_send_json_error( array( 'message' => 'Hồ sơ không tồn tại.' ) );
            return;
        }

        // BCH Chi Đoàn chỉ xóa được hồ sơ CHO_NOP
        if ( $role_data['role'] === 'bch_chi_doan' && $ho_so['trang_thai'] !== 'CHO_NOP' ) {
            wp_send_json_error( array( 'message' => 'BCH Chi Đoàn chỉ được xóa hồ sơ ở trạng thái Chờ nộp.' ) );
            return;
        }
        // Cán bộ Đoàn Trường (is_admin=false) không được xóa
        if ( $role_data['role'] === 'admin_doan_truong' && empty( $role_data['is_admin'] ) ) {
            wp_send_json_error( array( 'message' => 'Chỉ Quản trị viên (Admin) mới có quyền xóa hồ sơ.' ) );
            return;
        }

        $allowed_delete_statuses = array( 'CHO_NOP', 'TU_CHOI', 'DA_HUY' );
        if ( ! in_array( $ho_so['trang_thai'], $allowed_delete_statuses, true ) ) {
            wp_send_json_error( array( 'message' => 'Không thể xóa hồ sơ đang trong quy trình duyệt.' ) );
            return;
        }

        $wpdb->delete(
            $this->table_dvut,
            array( 'id' => $id ),
            array( '%d' )
        );

        wp_send_json_success( array(
            'message' => 'Đã xóa hồ sơ.',
        ) );
    }

    /**
     * ACTION: dvut_tra_ve
     * Trả hồ sơ về cấp dưới kèm lý do.
     */
    public function handle_tra_ve() {
        $this->verify_nonce();
        $this->check_permission();
        $this->require_role( array( 'can_bo_doan_khoa', 'admin_doan_truong' ) );

        $id         = isset( $_POST['id'] )         ? absint( $_POST['id'] ) : 0;
        $ly_do      = isset( $_POST['ly_do'] )      ? sanitize_textarea_field( $_POST['ly_do'] ) : '';
        $tra_ve_cap = isset( $_POST['tra_ve_cap'] ) ? sanitize_text_field( $_POST['tra_ve_cap'] ) : '';

        if ( empty( $id ) || empty( $ly_do ) ) {
            wp_send_json_error( array( 'message' => 'Vui lòng nhập lý do trả về.' ) );
            return;
        }

        switch ( $tra_ve_cap ) {
            case 'chi_doan':
                $trang_thai_moi = 'CHO_NOP';
                break;
            case 'doan_khoa':
                $trang_thai_moi = 'CHO_DOAN_KHOA';
                break;
            default:
                $trang_thai_moi = 'TRA_VE';
        }

        global $wpdb;

        $wpdb->update(
            $this->table_dvut,
            array(
                'trang_thai' => $trang_thai_moi,
                'ghi_chu'    => sprintf( 'Trả về: %s', $ly_do ),
                'updated_at' => current_time( 'mysql' ),
            ),
            array( 'id' => $id ),
            array( '%s', '%s', '%s' ),
            array( '%d' )
        );

        wp_send_json_success( array(
            'message' => 'Đã trả hồ sơ về. Lý do: ' . $ly_do,
        ) );
    }

    /**
     * ACTION: dvut_tu_choi
     * Từ chối hồ sơ — chuyển trạng thái sang TU_CHOI.
     */
    public function handle_tu_choi() {
        $this->verify_nonce();
        $this->check_permission();
        $this->require_role( array( 'bch_chi_doan', 'can_bo_doan_khoa', 'admin_doan_truong' ) );

        $id    = isset( $_POST['id'] )    ? absint( $_POST['id'] ) : 0;
        $cap   = isset( $_POST['cap'] )   ? sanitize_text_field( $_POST['cap'] ) : '';
        $ly_do = isset( $_POST['ly_do'] ) ? sanitize_textarea_field( $_POST['ly_do'] ) : '';

        if ( ! $id || empty( $ly_do ) ) {
            wp_send_json_error( array( 'message' => 'Vui lòng cung cấp ID và lý do từ chối.' ) );
            return;
        }

        global $wpdb;

        $ho_so = $wpdb->get_row( $wpdb->prepare(
            "SELECT id, trang_thai FROM {$this->table_dvut} WHERE id = %d",
            $id
        ), ARRAY_A );

        if ( ! $ho_so ) {
            wp_send_json_error( array( 'message' => 'Không tìm thấy hồ sơ.' ) );
            return;
        }

        $cap_label = array(
            'chi_doan'    => 'Chi Đoàn',
            'doan_khoa'   => 'Đoàn Khoa',
            'doan_truong' => 'Đoàn Trường',
        );
        $cap_display = isset( $cap_label[ $cap ] ) ? $cap_label[ $cap ] : $cap;

        $wpdb->update(
            $this->table_dvut,
            array(
                'trang_thai'                 => 'TU_CHOI',
                'trang_thai_truoc_tu_choi'   => $ho_so['trang_thai'],
                'ghi_chu'                    => sprintf( 'Từ chối (%s): %s', $cap_display ?: 'N/A', $ly_do ),
                'ly_do_tu_choi'              => $ly_do,
                'cap_tu_choi'                => $cap_display,
                'ngay_tu_choi'               => current_time( 'mysql' ),
                'updated_at'                 => current_time( 'mysql' ),
            ),
            array( 'id' => $id ),
            array( '%s', '%s', '%s', '%s', '%s', '%s', '%s' ),
            array( '%d' )
        );

        wp_send_json_success( array(
            'message' => 'Đã từ chối hồ sơ. Lý do: ' . $ly_do,
        ) );
    }

    /**
     * ACTION: dvut_admin_go_tu_choi
     * [CHỈ ADMIN] Gỡ trạng thái từ chối — khôi phục hồ sơ về trạng thái trước khi bị từ chối.
     */
    public function handle_admin_go_tu_choi() {
        $this->verify_nonce();
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => 'Chỉ Quản trị viên (Admin) mới có quyền gỡ trạng thái từ chối.' ) );
            return;
        }

        $id = isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0;
        if ( ! $id ) {
            wp_send_json_error( array( 'message' => 'ID không hợp lệ.' ) );
            return;
        }

        global $wpdb;

        $ho_so = $wpdb->get_row( $wpdb->prepare(
            "SELECT id, trang_thai, trang_thai_truoc_tu_choi, ghi_chu FROM {$this->table_dvut} WHERE id = %d",
            $id
        ), ARRAY_A );

        if ( ! $ho_so || $ho_so['trang_thai'] !== 'TU_CHOI' ) {
            wp_send_json_error( array( 'message' => 'Chỉ có thể gỡ từ chối đối với hồ sơ đang ở trạng thái Từ chối.' ) );
            return;
        }

        $trang_thai_phuc_hoi = ! empty( $ho_so['trang_thai_truoc_tu_choi'] )
            ? $ho_so['trang_thai_truoc_tu_choi']
            : 'CHO_CHI_DOAN';

        $wpdb->update(
            $this->table_dvut,
            array(
                'trang_thai'                 => $trang_thai_phuc_hoi,
                'trang_thai_truoc_tu_choi'   => null,
                'ghi_chu'                    => ( $ho_so['ghi_chu'] ? $ho_so['ghi_chu'] . ' — ' : '' ) . 'Admin gỡ từ chối, khôi phục về ' . $trang_thai_phuc_hoi . ' (' . current_time( 'd/m/Y H:i' ) . ')',
                'updated_at'                 => current_time( 'mysql' ),
            ),
            array( 'id' => $id ),
            array( '%s', '%s', '%s', '%s' ),
            array( '%d' )
        );

        $item = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$this->table_dvut} WHERE id = %d", $id
        ), ARRAY_A );

        wp_send_json_success( array(
            'item'    => $item,
            'message' => 'Đã gỡ trạng thái từ chối. Hồ sơ đã được khôi phục.',
        ) );
    }

    /**
     * ACTION: dvut_import_excel
     * [ADMIN ĐOÀN TRƯỜNG] Import Excel Master vào Hộp đen.
     * NOTE: Giữ tạm mock — cần tích hợp PhpSpreadsheet.
     */
    public function handle_import_excel() {
        $this->verify_nonce();
        $this->check_permission( 'manage_options' );

        $role_data = $this->get_user_dvut_role();
        if ( ! $role_data || $role_data['role'] !== 'admin_doan_truong' ) {
            wp_send_json_error( array( 'message' => 'Chỉ Admin Đoàn Trường mới có quyền import Excel.' ) );
            return;
        }

        if ( empty( $_FILES['excel_file'] ) || $_FILES['excel_file']['error'] !== UPLOAD_ERR_OK ) {
            wp_send_json_error( array( 'message' => 'Vui lòng chọn file Excel.' ) );
            return;
        }

        // TODO: Tích hợp PhpSpreadsheet khi có thư viện
        wp_send_json_success( array(
            'imported' => 0,
            'message'  => 'Chức năng Import Excel sẽ được kích hoạt khi tích hợp thư viện PhpSpreadsheet.',
        ) );
    }

    // =================================================================
    // GĐ4b: CHI BỘ SINH VIÊN
    // =================================================================

    /**
     * ACTION: dvut_cb_cap_nhat_ket_nap
     * Chi bộ cập nhật Quyết định & Ngày Lễ kết nạp Đảng → Chuyển thẳng sang Đảng viên dự bị.
     */
    public function handle_cb_cap_nhat_ket_nap() {
        $this->verify_nonce();
        $this->check_permission();
        $this->require_role( array( 'chi_bo_sinh_vien', 'admin_doan_truong' ) );

        $id      = absint( $_POST['id'] ?? 0 );
        $so_qd   = sanitize_text_field( $_POST['so_qd_ket_nap'] ?? '' );
        $ngay_qd = sanitize_text_field( $_POST['ngay_qd_ket_nap'] ?? '' );
        $ngay_le = sanitize_text_field( $_POST['ngay_le_ket_nap'] ?? '' );

        if ( ! $id ) {
            wp_send_json_error( array( 'message' => 'Dữ liệu không hợp lệ.' ) );
            return;
        }

        if ( empty( $so_qd ) || empty( $ngay_qd ) || empty( $ngay_le ) ) {
            wp_send_json_error( array( 'message' => 'Vui lòng nhập đầy đủ thông tin: Số quyết định, Ngày quyết định và Ngày lễ kết nạp.' ) );
            return;
        }

        global $wpdb;
        $item = $wpdb->get_row( $wpdb->prepare(
            "SELECT id, trang_thai, ho_ten FROM {$this->table_dvut} WHERE id = %d",
            $id
        ) );

        if ( ! $item ) {
            wp_send_json_error( array( 'message' => 'Hồ sơ không tồn tại.' ) );
            return;
        }

        $allowed_statuses = array( 'DA_CONG_NHAN', 'CHUYEN_GIAO_CHI_BO', 'CHI_BO_DANG_THEO_DOI', 'CHO_DANG_UY_TRUONG_XET', 'DA_CO_QD_KET_NAP' );
        if ( ! in_array( $item->trang_thai, $allowed_statuses, true ) ) {
            wp_send_json_error( array( 'message' => 'Hồ sơ phải ở trạng thái Đoàn viên ưu tú hoặc các trạng thái liên quan của Chi bộ.' ) );
            return;
        }

        $file_qd = $this->handle_file_upload( 'file_qd_ket_nap' );
        if ( empty( $file_qd ) ) {
            $existing_file = $wpdb->get_var( $wpdb->prepare(
                "SELECT file_qd_ket_nap FROM {$this->table_dvut} WHERE id = %d", $id
            ) );
            if ( empty( $existing_file ) ) {
                wp_send_json_error( array( 'message' => 'Vui lòng tải lên file scan Quyết định kết nạp.' ) );
                return;
            }
        }

        $update_data = array(
            'trang_thai'      => 'DANG_VIEN_DU_BI',
            'so_qd_ket_nap'   => $so_qd,
            'ngay_qd_ket_nap' => $ngay_qd,
            'ngay_le_ket_nap' => $ngay_le,
            'updated_at'      => current_time( 'mysql' ),
        );
        if ( ! empty( $file_qd ) ) {
            $update_data['file_qd_ket_nap'] = $file_qd;
        }

        $wpdb->update(
            $this->table_dvut,
            $update_data,
            array( 'id' => $id ),
            null,
            array( '%d' )
        );

        wp_send_json_success( array(
            'message' => sprintf( 'Đã hoàn tất cập nhật kết nạp Đảng cho %s. Hồ sơ đã chuyển sang trạng thái Đảng viên!', $item->ho_ten ),
        ) );
    }

    /**
     * ACTION: dvut_get_dashboard_stats
     * Lấy số liệu thống kê tổng quan cho Dashboard.
     */
    public function handle_get_dashboard_stats() {
        $this->verify_nonce();
        $this->check_permission();

        $role_data = $this->get_user_dvut_role();

        global $wpdb;

        $where = "WHERE 1=1";
        $where = $this->apply_rbac_filter( $where, $role_data );

        $counts   = array();
        $statuses = array(
            'cho_nop'              => 'CHO_NOP',
            'cho_chi_doan'         => 'CHO_CHI_DOAN',
            'cho_doan_khoa'        => 'CHO_DOAN_KHOA',
            'cho_doan_truong'      => 'CHO_DOAN_TRUONG',
            'da_cong_nhan'         => 'DA_CONG_NHAN',
            'chuyen_giao_chi_bo'   => 'CHUYEN_GIAO_CHI_BO',
            'chi_bo_dang_theo_doi' => 'CHI_BO_DANG_THEO_DOI',
            'cho_dang_uy_truong_xet' => 'CHO_DANG_UY_TRUONG_XET',
            'da_co_qd_ket_nap'     => 'DA_CO_QD_KET_NAP',
            'dang_vien_du_bi'      => 'DANG_VIEN_DU_BI',
            'dang_vien_chinh_thuc' => 'DANG_VIEN_CHINH_THUC',
            'tu_choi'              => 'TU_CHOI',
            'tra_ve'               => 'TRA_VE',
        );

        foreach ( $statuses as $key => $status ) {
            $counts[ $key ] = (int) $wpdb->get_var(
                "SELECT COUNT(*) FROM {$this->table_dvut} {$where} AND trang_thai = '{$status}'"
            );
        }

        $counts['dang_hoc_ctd'] = (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM {$this->table_dvut} {$where} AND tien_do_cam_tinh_dang = 'DANG_HOC'"
        );
        $counts['da_hoan_thanh_ctd'] = (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM {$this->table_dvut} {$where} AND tien_do_cam_tinh_dang = 'DA_HOAN_THANH'"
        );

        $counts['canh_bao_12_thang'] = (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM {$this->table_dvut}
             {$where}
             AND trang_thai = 'DANG_VIEN_DU_BI'
             AND ngay_gioi_thieu_dang < DATE_SUB( NOW(), INTERVAL 12 MONTH )"
        );

        $total = (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM {$this->table_dvut} {$where}"
        );

        $past_cong_nhan = "'DA_CONG_NHAN','CHO_DK_GIOI_THIEU','CHO_DT_XAC_NHAN_GT','CHUYEN_GIAO_CHI_BO','CHI_BO_DANG_THEO_DOI','CHO_DANG_UY_TRUONG_XET','DA_CO_QD_KET_NAP','DANG_VIEN_DU_BI','CHO_DK_CHUYEN_DANG','CHO_DT_XAC_NHAN_CD','DANG_VIEN_CHINH_THUC'";
        $cong_nhan = (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM {$this->table_dvut} {$where} AND trang_thai IN ({$past_cong_nhan})"
        );

        $hoan_thanh_ctd = $counts['da_hoan_thanh_ctd'];

        $past_gioi_thieu = "'CHUYEN_GIAO_CHI_BO','CHI_BO_DANG_THEO_DOI','CHO_DANG_UY_TRUONG_XET','DA_CO_QD_KET_NAP','DANG_VIEN_DU_BI','CHO_DK_CHUYEN_DANG','CHO_DT_XAC_NHAN_CD','DANG_VIEN_CHINH_THUC'";
        $gioi_thieu = (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM {$this->table_dvut} {$where} AND trang_thai IN ({$past_gioi_thieu})"
        );

        $funnel = array(
            array( 'label' => 'Đề cử',           'count' => $total ),
            array( 'label' => 'Công nhận ĐVƯT',   'count' => $cong_nhan ),
            array( 'label' => 'Hoàn thành CTĐ',   'count' => $hoan_thanh_ctd ),
            array( 'label' => 'Giới thiệu Đảng',  'count' => $gioi_thieu ),
            array( 'label' => 'Đảng viên CT',      'count' => $counts['dang_vien_chinh_thuc'] ),
        );

        wp_send_json_success( array(
            'user_role' => $role_data ? $role_data['role'] : '',
            'counts'    => $counts,
            'funnel'    => $funnel,
        ) );
    }

    // =================================================================
    // LỊCH SỬ CHỈNH SỬA
    // =================================================================

    /**
     * ACTION: dvut_get_edit_history
     * Lấy lịch sử chỉnh sửa (audit log) có phân quyền.
     */
    public function handle_get_edit_history() {
        $this->verify_nonce();
        $this->check_permission();

        $role_data = $this->get_user_dvut_role();

        // Role đoàn viên (hoặc user không có quyền DVUT) không được xem lịch sử chỉnh sửa — chỉ role cao (BCH, Đoàn Khoa, Đoàn Trường) mới được xem
        if ( ! $role_data || ( isset( $role_data['role'] ) && $role_data['role'] === 'doan_vien' ) ) {
            wp_send_json_error( array( 'message' => 'Bạn không có quyền xem lịch sử chỉnh sửa.' ) );
            return;
        }

        $filter_role    = isset( $_POST['filter_role'] )    ? sanitize_text_field( $_POST['filter_role'] ) : '';
        $filter_action  = isset( $_POST['filter_action'] )  ? sanitize_text_field( $_POST['filter_action'] ) : '';
        $filter_keyword = isset( $_POST['filter_keyword'] ) ? sanitize_text_field( $_POST['filter_keyword'] ) : '';
        $filter_from    = isset( $_POST['filter_from'] )    ? sanitize_text_field( $_POST['filter_from'] ) : '';
        $filter_to      = isset( $_POST['filter_to'] )      ? sanitize_text_field( $_POST['filter_to'] ) : '';
        $page           = isset( $_POST['page'] )           ? max( 1, absint( $_POST['page'] ) ) : 1;
        $per_page       = isset( $_POST['per_page'] )       ? min( 100, max( 10, absint( $_POST['per_page'] ) ) ) : 20;

        global $wpdb;

        $users_table = $wpdb->users;

        $from_clause = "FROM {$this->table_log} l
            LEFT JOIN {$this->table_dvut} d ON l.dvut_id = d.id
            LEFT JOIN {$users_table} u ON l.performed_by = u.ID
            LEFT JOIN {$this->table_user_roles} ur ON l.performed_by = ur.user_id AND ur.is_active = 1";

        $where = "WHERE 1=1";

        // RBAC cho log: dùng cùng pattern chi_doan subquery + fallback như apply_rbac_filter()
        if ( $role_data ) {
            switch ( $role_data['role'] ) {
                case 'admin_doan_truong':
                    break;
                case 'can_bo_doan_khoa':
                    $khoa_id = absint( $role_data['khoa_id'] );
                    $where .= " AND (d.chi_doan_id IN (SELECT id FROM {$this->table_chi_doan} WHERE khoa_id = {$khoa_id}) OR d.khoa_id = {$khoa_id})";
                    break;
                case 'bch_chi_doan':
                    $chi_doan_id = absint( $role_data['chi_doan_id'] );
                    $where .= " AND d.chi_doan_id = {$chi_doan_id}";
                    break;
            }
        } else {
            $where .= " AND 1=0";
        }

        if ( $filter_role ) {
            $where .= $wpdb->prepare( " AND ur.role = %s", $filter_role );
        }
        if ( $filter_action ) {
            $where .= $wpdb->prepare( " AND l.action = %s", $filter_action );
        }
        if ( $filter_keyword ) {
            $like = '%' . $wpdb->esc_like( $filter_keyword ) . '%';
            $where .= $wpdb->prepare( " AND (d.ho_ten LIKE %s OR d.mssv LIKE %s)", $like, $like );
        }
        if ( $filter_from ) {
            $where .= $wpdb->prepare( " AND l.created_at >= %s", $filter_from . ' 00:00:00' );
        }
        if ( $filter_to ) {
            $where .= $wpdb->prepare( " AND l.created_at <= %s", $filter_to . ' 23:59:59' );
        }

        $total  = (int) $wpdb->get_var( "SELECT COUNT(*) {$from_clause} {$where}" );
        $offset = ( $page - 1 ) * $per_page;

        $items = $wpdb->get_results(
            "SELECT
                l.id,
                l.created_at       AS thoi_gian,
                COALESCE(u.display_name, 'Hệ thống') AS nguoi_thuc_hien,
                COALESCE(ur.role, '')   AS vai_tro,
                l.action               AS hanh_dong,
                COALESCE(d.ho_ten, '')  AS doi_tuong,
                COALESCE(d.mssv, '')    AS mssv,
                COALESCE(d.chi_doan, '') AS chi_doan,
                l.note                 AS mo_ta,
                l.extra_data           AS chi_tiet
            {$from_clause}
            {$where}
            ORDER BY l.created_at DESC
            LIMIT {$per_page} OFFSET {$offset}",
            ARRAY_A
        );

        wp_send_json_success( array(
            'items'       => $items ?: array(),
            'total'       => $total,
            'page'        => $page,
            'per_page'    => $per_page,
            'total_pages' => $total > 0 ? (int) ceil( $total / $per_page ) : 0,
        ) );
    }

    /* =========================================================================
     *  SYSTEM MANAGEMENT HANDLERS
     * ========================================================================= */

    /**
     * Write an entry to the admin log table.
     */
    private function write_admin_log( $action, $target_type = null, $target_id = null, $details = null ) {
        global $wpdb;
        $wpdb->insert(
            $this->table_log,
            array(
                'dvut_id'       => 0,
                'action'        => $action,
                'target_type'   => $target_type,
                'target_id'     => $target_id ? absint( $target_id ) : 0,
                'performed_by'  => get_current_user_id(),
                'ip_address'    => isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '',
                'extra_data'    => $details,
                'created_at'    => current_time( 'mysql' ),
            ),
            array( '%d', '%s', '%s', '%d', '%d', '%s', '%s', '%s' )
        );
    }

    /* ----- 1. List roles -------------------------------------------------- */
    public function handle_sys_list_roles() {
        $this->verify_nonce();
        $this->check_permission( 'read' );
        $this->require_role( array( 'admin_doan_truong' ) );
        global $wpdb;

        $search   = isset( $_POST['search'] ) ? sanitize_text_field( wp_unslash( $_POST['search'] ) ) : '';
        $page     = isset( $_POST['page'] ) ? absint( $_POST['page'] ) : 1;
        $per_page = isset( $_POST['per_page'] ) ? absint( $_POST['per_page'] ) : 20;
        if ( $page < 1 ) { $page = 1; }
        if ( $per_page < 1 ) { $per_page = 20; }
        $offset = ( $page - 1 ) * $per_page;

        $base_sql = "FROM {$this->table_user_roles} r
                     LEFT JOIN {$wpdb->users} u ON r.user_id = u.ID
                     LEFT JOIN {$this->table_khoa} k ON r.khoa_id = k.id
                     LEFT JOIN {$this->table_chi_doan} cd ON r.chi_doan_id = cd.id";

        $where  = '';
        $params = array();
        if ( $search !== '' ) {
            $like   = '%' . $wpdb->esc_like( $search ) . '%';
            $where  = " WHERE ( u.user_login LIKE %s OR u.user_email LIKE %s OR r.role LIKE %s )";
            $params = array( $like, $like, $like );
        }

        $count_sql = "SELECT COUNT(*) {$base_sql}{$where}";
        $total     = $params ? (int) $wpdb->get_var( $wpdb->prepare( $count_sql, $params ) )
                             : (int) $wpdb->get_var( $count_sql );

        $select_sql = "SELECT r.*, u.user_login, u.user_email, u.display_name,
                              k.ten AS khoa_name, cd.ten AS chi_doan_name
                       {$base_sql}{$where}
                       ORDER BY r.id DESC LIMIT %d OFFSET %d";

        $query_params = array_merge( $params, array( $per_page, $offset ) );
        $rows         = $wpdb->get_results( $wpdb->prepare( $select_sql, $query_params ), ARRAY_A );

        wp_send_json_success( array(
            'items'       => $rows,
            'total'       => $total,
            'page'        => $page,
            'per_page'    => $per_page,
            'total_pages' => (int) ceil( $total / $per_page ),
        ) );
    }

    /* ----- 2. Add role ---------------------------------------------------- */
    public function handle_sys_add_role() {
        $this->verify_nonce();
        $this->check_permission( 'read' );
        $this->require_role( array( 'admin_doan_truong' ) );
        global $wpdb;

        $user_id     = isset( $_POST['user_id'] ) ? absint( $_POST['user_id'] ) : 0;
        $role        = isset( $_POST['role'] ) ? sanitize_text_field( wp_unslash( $_POST['role'] ) ) : '';
        $khoa_id     = isset( $_POST['khoa_id'] ) ? intval( $_POST['khoa_id'] ) : null;
        $chi_doan_id = isset( $_POST['chi_doan_id'] ) ? intval( $_POST['chi_doan_id'] ) : null;
        $chi_bo_id   = isset( $_POST['chi_bo_id'] ) ? intval( $_POST['chi_bo_id'] ) : null;

        if ( ! $user_id ) {
            wp_send_json_error( array( 'message' => 'user_id is required.' ) );
        }

        $valid_roles = array( 'doan_vien', 'bch_chi_doan', 'can_bo_doan_khoa', 'admin_doan_truong', 'chi_bo_sinh_vien' );
        if ( ! in_array( $role, $valid_roles, true ) ) {
            wp_send_json_error( array( 'message' => 'Invalid role.' ) );
        }

        $wpdb->insert(
            $this->table_user_roles,
            array(
                'user_id'     => $user_id,
                'role'        => $role,
                'khoa_id'     => $khoa_id,
                'chi_doan_id' => $chi_doan_id,
                'chi_bo_id'   => $chi_bo_id,
                'is_active'   => 1,
                'created_at'  => current_time( 'mysql' ),
            ),
            array( '%d', '%s', '%d', '%d', '%d', '%d', '%s' )
        );

        $new_id = $wpdb->insert_id;
        $this->write_admin_log( 'add_role', 'user_role', $new_id, wp_json_encode( compact( 'user_id', 'role', 'khoa_id', 'chi_doan_id', 'chi_bo_id' ) ) );

        wp_send_json_success( array( 'message' => 'Role added.', 'id' => $new_id ) );
    }

    /* ----- 3. Update role ------------------------------------------------- */
    public function handle_sys_update_role() {
        $this->verify_nonce();
        $this->check_permission( 'read' );
        $this->require_role( array( 'admin_doan_truong' ) );
        global $wpdb;

        $id = isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0;
        if ( ! $id ) {
            wp_send_json_error( array( 'message' => 'id is required.' ) );
        }

        $valid_roles = array( 'doan_vien', 'bch_chi_doan', 'can_bo_doan_khoa', 'admin_doan_truong', 'chi_bo_sinh_vien' );
        $data    = array();
        $formats = array();

        if ( isset( $_POST['role'] ) ) {
            $role = sanitize_text_field( wp_unslash( $_POST['role'] ) );
            if ( ! in_array( $role, $valid_roles, true ) ) {
                wp_send_json_error( array( 'message' => 'Invalid role.' ) );
            }
            $data['role'] = $role;
            $formats[]    = '%s';
        }
        if ( isset( $_POST['khoa_id'] ) ) {
            $data['khoa_id'] = intval( $_POST['khoa_id'] );
            $formats[]       = '%d';
        }
        if ( isset( $_POST['chi_doan_id'] ) ) {
            $data['chi_doan_id'] = intval( $_POST['chi_doan_id'] );
            $formats[]           = '%d';
        }
        if ( isset( $_POST['chi_bo_id'] ) ) {
            $data['chi_bo_id'] = intval( $_POST['chi_bo_id'] );
            $formats[]         = '%d';
        }
        if ( isset( $_POST['is_active'] ) ) {
            $data['is_active'] = intval( $_POST['is_active'] );
            $formats[]         = '%d';
        }

        if ( empty( $data ) ) {
            wp_send_json_error( array( 'message' => 'No fields to update.' ) );
        }

        $wpdb->update( $this->table_user_roles, $data, array( 'id' => $id ), $formats, array( '%d' ) );
        $this->write_admin_log( 'update_role', 'user_role', $id, wp_json_encode( $data ) );

        wp_send_json_success( array( 'message' => 'Role updated.' ) );
    }

    /* ----- 4. Delete role ------------------------------------------------- */
    public function handle_sys_delete_role() {
        $this->verify_nonce();
        $this->check_permission( 'read' );
        $this->require_role( array( 'admin_doan_truong' ) );
        global $wpdb;

        $id = isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0;
        if ( ! $id ) {
            wp_send_json_error( array( 'message' => 'id is required.' ) );
        }

        $old = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$this->table_user_roles} WHERE id = %d", $id ), ARRAY_A );
        if ( ! $old ) {
            wp_send_json_error( array( 'message' => 'Record not found.' ) );
        }

        $wpdb->delete( $this->table_user_roles, array( 'id' => $id ), array( '%d' ) );
        $this->write_admin_log( 'delete_role', 'user_role', $id, wp_json_encode( $old ) );

        wp_send_json_success( array( 'message' => 'Role deleted.' ) );
    }

    /* ----- 5. Import blackbox CSV ----------------------------------------- */
    public function handle_sys_import_blackbox() {
        $this->verify_nonce();
        $this->check_permission( 'read' );
        $this->require_role( array( 'admin_doan_truong' ) );
        global $wpdb;

        if ( empty( $_FILES['csv_file'] ) || $_FILES['csv_file']['error'] !== UPLOAD_ERR_OK ) {
            wp_send_json_error( array( 'message' => 'No CSV file uploaded or upload error.' ) );
        }

        $dot_xet = isset( $_POST['dot_xet'] ) ? sanitize_text_field( wp_unslash( $_POST['dot_xet'] ) ) : '';

        $file_path = $_FILES['csv_file']['tmp_name'];
        $handle    = fopen( $file_path, 'r' );
        if ( ! $handle ) {
            wp_send_json_error( array( 'message' => 'Cannot open uploaded file.' ) );
        }

        // Handle UTF-8 BOM.
        $bom = fread( $handle, 3 );
        if ( $bom !== "\xEF\xBB\xBF" ) {
            rewind( $handle );
        }

        $header = fgetcsv( $handle );
        if ( ! $header ) {
            fclose( $handle );
            wp_send_json_error( array( 'message' => 'CSV file is empty or invalid.' ) );
        }
        $header = array_map( 'trim', $header );

        $mssv_col = array_search( 'mssv', $header, true );
        if ( $mssv_col === false ) {
            fclose( $handle );
            wp_send_json_error( array( 'message' => 'CSV must contain "mssv" column.' ) );
        }

        // Cột DB cho phép ghi vào wp_dvut_blackbox
        $allowed_cols = array( 'mssv', 'ho_ten', 'email', 'chi_doan', 'chi_doan_id', 'khoa', 'khoa_id',
                               'ly_luan_chinh_tri', 'xep_loai_doan_vien', 'diem_tb_tich_luy', 'diem_ren_luyen', 'dot_xet' );

        // Mapping: tên cột CSV → tên cột DB (cho trường hợp CSV dùng tên khác)
        $col_aliases = array(
            'diem_tb'  => 'diem_tb_tich_luy',
            'diem_rl'  => 'diem_ren_luyen',
            'llct'     => 'ly_luan_chinh_tri',
            'lop'      => 'chi_doan',              // fallback: nếu CSV có 'lop' nhưng không có 'chi_doan'
        );

        // Chuẩn hóa header: áp dụng alias
        $header = array_map( function( $h ) use ( $col_aliases ) {
            return isset( $col_aliases[ $h ] ) ? $col_aliases[ $h ] : $h;
        }, $header );

        $count = 0;

        while ( ( $row = fgetcsv( $handle ) ) !== false ) {
            if ( count( $row ) < count( $header ) ) {
                $row = array_pad( $row, count( $header ), '' );
            }
            $mapped = array_combine( $header, $row );
            $mssv   = sanitize_text_field( trim( $mapped['mssv'] ) );
            if ( $mssv === '' ) {
                continue;
            }

            $data = array();
            foreach ( $allowed_cols as $col ) {
                if ( $col === 'mssv' ) {
                    $data['mssv'] = $mssv;
                } elseif ( $col === 'dot_xet' ) {
                    $data['dot_xet'] = $dot_xet !== '' ? $dot_xet : ( isset( $mapped['dot_xet'] ) ? sanitize_text_field( $mapped['dot_xet'] ) : '' );
                } elseif ( isset( $mapped[ $col ] ) ) {
                    $data[ $col ] = sanitize_text_field( trim( $mapped[ $col ] ) );
                }
            }

            $existing = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$this->table_blackbox} WHERE mssv = %s", $mssv ) );
            if ( $existing ) {
                $result = $wpdb->update( $this->table_blackbox, $data, array( 'mssv' => $mssv ) );
            } else {
                $result = $wpdb->insert( $this->table_blackbox, $data );
            }
            if ( $result !== false ) {
                $count++;
            }
        }
        fclose( $handle );

        $this->write_admin_log( 'import_blackbox', 'blackbox', null, "Imported {$count} rows" );
        wp_send_json_success( array( 'message' => "Imported {$count} rows.", 'count' => $count ) );
    }

    /* ----- 6. Browse table ------------------------------------------------ */
    public function handle_sys_browse_table() {
        $this->verify_nonce();
        $this->check_permission( 'read' );
        $this->require_role( array( 'admin_doan_truong' ) );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => 'Forbidden.' ) );
        }
        global $wpdb;

        $allowed = array(
            'wp_khoa'              => $this->table_khoa,
            'wp_chi_doan'          => $this->table_chi_doan,
            'wp_dvut_dot_xet'      => $this->table_dot_xet,
            'wp_doan_vien_uu_tu'   => $this->table_dvut,
            'wp_dvut_blackbox'     => $this->table_blackbox,
            'wp_dvut_user_roles'   => $this->table_user_roles,
            'wp_dvut_log'          => $this->table_log,
            'wp_dvut_files'        => $this->table_files,
        );

        $table_key = isset( $_POST['table'] ) ? sanitize_text_field( wp_unslash( $_POST['table'] ) ) : '';
        if ( ! isset( $allowed[ $table_key ] ) ) {
            wp_send_json_error( array( 'message' => 'Table not allowed.' ) );
        }
        $table = $allowed[ $table_key ];

        $page     = isset( $_POST['page'] ) ? absint( $_POST['page'] ) : 1;
        $per_page = isset( $_POST['per_page'] ) ? absint( $_POST['per_page'] ) : 25;
        if ( $page < 1 ) { $page = 1; }
        if ( $per_page < 1 ) { $per_page = 25; }
        $offset = ( $page - 1 ) * $per_page;

        $search = isset( $_POST['search'] ) ? sanitize_text_field( wp_unslash( $_POST['search'] ) ) : '';

        if ( $search !== '' ) {
            $like       = '%' . $wpdb->esc_like( $search ) . '%';
            $cols       = $wpdb->get_results( "SHOW COLUMNS FROM {$table}", ARRAY_A );
            $conditions = array();
            $params     = array();
            foreach ( $cols as $col ) {
                $conditions[] = "`{$col['Field']}` LIKE %s";
                $params[]     = $like;
            }
            $where_clause = ' WHERE ' . implode( ' OR ', $conditions );
            $total        = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table}{$where_clause}", $params ) );
            $params[]     = $per_page;
            $params[]     = $offset;
            $rows         = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table}{$where_clause} ORDER BY id DESC LIMIT %d OFFSET %d", $params ), ARRAY_A );
        } else {
            $total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );
            $rows  = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} ORDER BY id DESC LIMIT %d OFFSET %d", $per_page, $offset ), ARRAY_A );
        }

        $columns = ! empty( $rows ) ? array_keys( $rows[0] ) : array();

        wp_send_json_success( array(
            'columns'     => $columns,
            'items'       => $rows,
            'total'       => $total,
            'page'        => $page,
            'per_page'    => $per_page,
            'total_pages' => (int) ceil( $total / $per_page ),
        ) );
    }

    /* ----- 7. Edit record ------------------------------------------------- */
    public function handle_sys_edit_record() {
        $this->verify_nonce();
        $this->check_permission( 'read' );
        $this->require_role( array( 'admin_doan_truong' ) );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => 'Forbidden.' ) );
        }
        global $wpdb;

        $allowed = array(
            'wp_khoa'              => $this->table_khoa,
            'wp_chi_doan'          => $this->table_chi_doan,
            'wp_dvut_dot_xet'      => $this->table_dot_xet,
            'wp_doan_vien_uu_tu'   => $this->table_dvut,
            'wp_dvut_blackbox'     => $this->table_blackbox,
            'wp_dvut_user_roles'   => $this->table_user_roles,
            'wp_dvut_log'          => $this->table_log,
            'wp_dvut_files'        => $this->table_files,
        );

        $table_key = isset( $_POST['table'] ) ? sanitize_text_field( wp_unslash( $_POST['table'] ) ) : '';
        if ( ! isset( $allowed[ $table_key ] ) ) {
            wp_send_json_error( array( 'message' => 'Table not allowed.' ) );
        }
        $table = $allowed[ $table_key ];

        $id    = isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0;
        $field = isset( $_POST['field'] ) ? sanitize_text_field( wp_unslash( $_POST['field'] ) ) : '';
        $value = isset( $_POST['value'] ) ? sanitize_text_field( wp_unslash( $_POST['value'] ) ) : '';

        if ( ! $id || $field === '' ) {
            wp_send_json_error( array( 'message' => 'id and field are required.' ) );
        }

        if ( ! preg_match( '/^[a-zA-Z0-9_]+$/', $field ) ) {
            wp_send_json_error( array( 'message' => 'Invalid field name.' ) );
        }

        $old_value = $wpdb->get_var( $wpdb->prepare( "SELECT `{$field}` FROM {$table} WHERE id = %d", $id ) );

        $wpdb->update( $table, array( $field => $value ), array( 'id' => $id ) );

        $this->write_admin_log(
            'edit_record',
            $table_key,
            $id,
            wp_json_encode( array( 'field' => $field, 'old_value' => $old_value, 'new_value' => $value ) )
        );

        wp_send_json_success( array( 'message' => 'Record updated.' ) );
    }

    /* ----- 8. Get logs ---------------------------------------------------- */
    public function handle_sys_get_logs() {
        $this->verify_nonce();
        $this->check_permission( 'read' );
        $this->require_role( array( 'admin_doan_truong' ) );
        global $wpdb;

        $search   = isset( $_POST['search'] ) ? sanitize_text_field( wp_unslash( $_POST['search'] ) ) : '';
        $page     = isset( $_POST['page'] ) ? absint( $_POST['page'] ) : 1;
        $per_page = isset( $_POST['per_page'] ) ? absint( $_POST['per_page'] ) : 30;
        if ( $page < 1 ) { $page = 1; }
        if ( $per_page < 1 ) { $per_page = 30; }
        $offset = ( $page - 1 ) * $per_page;

        $base_sql = "FROM {$this->table_log} l LEFT JOIN {$wpdb->users} u ON l.performed_by = u.ID";

        $where  = '';
        $params = array();
        if ( $search !== '' ) {
            $like   = '%' . $wpdb->esc_like( $search ) . '%';
            $where  = " WHERE ( l.action LIKE %s OR l.target_type LIKE %s OR l.extra_data LIKE %s OR u.user_login LIKE %s )";
            $params = array( $like, $like, $like, $like );
        }

        $count_sql = "SELECT COUNT(*) {$base_sql}{$where}";
        $total     = $params ? (int) $wpdb->get_var( $wpdb->prepare( $count_sql, $params ) )
                             : (int) $wpdb->get_var( $count_sql );

        $select_sql = "SELECT l.*, l.extra_data AS details, u.user_login, u.display_name
                       {$base_sql}{$where}
                       ORDER BY l.id DESC LIMIT %d OFFSET %d";

        $query_params = array_merge( $params, array( $per_page, $offset ) );
        $rows         = $wpdb->get_results( $wpdb->prepare( $select_sql, $query_params ), ARRAY_A );

        wp_send_json_success( array(
            'items'       => $rows,
            'total'       => $total,
            'page'        => $page,
            'per_page'    => $per_page,
            'total_pages' => (int) ceil( $total / $per_page ),
        ) );
    }

    /* ----- 9. Create account ---------------------------------------------- */
    public function handle_sys_create_account() {
        $this->verify_nonce();
        $this->check_permission( 'read' );
        $this->require_role( array( 'admin_doan_truong' ) );
        global $wpdb;

        $username     = isset( $_POST['username'] ) ? sanitize_user( wp_unslash( $_POST['username'] ) ) : '';
        $email        = isset( $_POST['email'] ) ? sanitize_email( wp_unslash( $_POST['email'] ) ) : '';
        $password     = isset( $_POST['password'] ) ? $_POST['password'] : '';
        $display_name = isset( $_POST['display_name'] ) ? sanitize_text_field( wp_unslash( $_POST['display_name'] ) ) : '';
        $role         = isset( $_POST['role'] ) ? sanitize_text_field( wp_unslash( $_POST['role'] ) ) : '';
        $khoa_id      = isset( $_POST['khoa_id'] ) ? intval( $_POST['khoa_id'] ) : null;
        $chi_doan_id  = isset( $_POST['chi_doan_id'] ) ? intval( $_POST['chi_doan_id'] ) : null;
        $chi_bo_id    = isset( $_POST['chi_bo_id'] ) ? intval( $_POST['chi_bo_id'] ) : null;

        if ( $username === '' ) {
            wp_send_json_error( array( 'message' => 'Vui lòng nhập tên tài khoản (username).' ) );
        }
        if ( $password === '' ) {
            wp_send_json_error( array( 'message' => 'Vui lòng nhập mật khẩu.' ) );
        }
        // Auto-generate email nếu để trống
        if ( $email === '' ) {
            $email = $username . '@dvut.local';
        }

        $valid_roles = array( 'doan_vien', 'bch_chi_doan', 'can_bo_doan_khoa', 'admin_doan_truong', 'chi_bo_sinh_vien' );
        if ( $role !== '' && ! in_array( $role, $valid_roles, true ) ) {
            wp_send_json_error( array( 'message' => 'Invalid role.' ) );
        }

        $wp_user_id = wp_create_user( $username, $password, $email );
        if ( is_wp_error( $wp_user_id ) ) {
            wp_send_json_error( array( 'message' => $wp_user_id->get_error_message() ) );
        }

        if ( $display_name !== '' ) {
            wp_update_user( array( 'ID' => $wp_user_id, 'display_name' => $display_name ) );
        }

        if ( $role !== '' ) {
            $wpdb->insert(
                $this->table_user_roles,
                array(
                    'user_id'     => $wp_user_id,
                    'role'        => $role,
                    'khoa_id'     => $khoa_id,
                    'chi_doan_id' => $chi_doan_id,
                    'chi_bo_id'   => $chi_bo_id,
                    'is_active'   => 1,
                    'created_at'  => current_time( 'mysql' ),
                ),
                array( '%d', '%s', '%d', '%d', '%d', '%d', '%s' )
            );
        }

        $this->write_admin_log( 'create_account', 'user', $wp_user_id, wp_json_encode( compact( 'username', 'email', 'role', 'khoa_id', 'chi_doan_id', 'chi_bo_id' ) ) );

        wp_send_json_success( array( 'message' => 'Account created.', 'user_id' => $wp_user_id ) );
    }

    // =================================================================
    // NHẬN XÉT ĐỊNH KỲ — Đánh giá quý ĐVƯT
    // =================================================================

    /**
     * Lấy danh sách nhận xét định kỳ cho 1 đoàn viên hoặc tất cả.
     * Params: dvut_id (optional — nếu bỏ trống, lấy theo chi_doan_id hoặc tất cả)
     * Roles: tất cả (đoàn viên chỉ xem của mình)
     */
    public function handle_nhan_xet_list() {
        $this->verify_nonce();
        global $wpdb;

        $dvut_id = absint( $_POST['dvut_id'] ?? 0 );
        $period_id = absint( $_POST['period_id'] ?? 0 );

        $where = array();
        $params = array();

        // Đoàn viên chỉ xem nhận xét của chính mình
        $profile = get_user_dvut_profile();
        if ( $profile && $profile['role'] === 'doan_vien' ) {
            // Tìm dvut_id của đoàn viên này
            $my_mssv = $profile['mssv'] ?? '';
            $my_email = $profile['email'] ?? '';
            $my_dvut = $wpdb->get_var( $wpdb->prepare(
                "SELECT id FROM {$this->table_dvut} WHERE mssv = %s OR mssv IN (SELECT mssv FROM {$this->table_blackbox} WHERE email = %s) LIMIT 1",
                $my_mssv, $my_email
            ) );
            if ( $my_dvut ) {
                $where[] = 'nx.dvut_id = %d';
                $params[] = $my_dvut;
            } else {
                wp_send_json_success( array( 'items' => array() ) );
            }
        } elseif ( $dvut_id > 0 ) {
            $where[] = 'nx.dvut_id = %d';
            $params[] = $dvut_id;
        }

        if ( $period_id > 0 ) {
            $where[] = 'nx.period_id = %d';
            $params[] = $period_id;
        }

        // BCH Chi Đoàn: chỉ xem nhận xét thuộc chi đoàn mình
        if ( $profile && $profile['role'] === 'bch_chi_doan' && ! empty( $profile['chi_doan_id'] ) ) {
            $where[] = 'nx.chi_doan_id = %d';
            $params[] = $profile['chi_doan_id'];
        }

        $where_sql = $where ? 'WHERE ' . implode( ' AND ', $where ) : '';

        $sql = "SELECT nx.*, p.ten_ky, p.quy, p.nam_hoc, p.is_open,
                       dv.ho_ten, dv.mssv, dv.chi_doan
                FROM {$this->table_nhan_xet} nx
                LEFT JOIN {$this->table_nhan_xet_period} p ON nx.period_id = p.id
                LEFT JOIN {$this->table_dvut} dv ON nx.dvut_id = dv.id
                {$where_sql}
                ORDER BY p.nam_hoc DESC, p.quy DESC, dv.ho_ten ASC";

        if ( $params ) {
            $results = $wpdb->get_results( $wpdb->prepare( $sql, ...$params ), ARRAY_A );
        } else {
            $results = $wpdb->get_results( $sql, ARRAY_A );
        }

        wp_send_json_success( array( 'items' => $results ?: array() ) );
    }

    /**
     * Lưu/cập nhật nhận xét định kỳ cho 1 đoàn viên.
     * Params: dvut_id, period_id, pham_chat, nang_luc, quan_he, tong_hop
     * Roles: bch_chi_doan (khi kỳ đang mở), admin_doan_truong (luôn có quyền)
     */
    public function handle_nhan_xet_save() {
        $this->verify_nonce();
        $profile = get_user_dvut_profile();
        $role = $profile['role'] ?? '';

        if ( ! in_array( $role, array( 'bch_chi_doan', 'admin_doan_truong' ), true ) ) {
            wp_send_json_error( array( 'message' => 'Không có quyền nhận xét.' ) );
        }

        global $wpdb;

        $dvut_id     = absint( $_POST['dvut_id'] ?? 0 );
        $period_id   = absint( $_POST['period_id'] ?? 0 );
        $pham_chat   = sanitize_textarea_field( $_POST['pham_chat'] ?? '' );
        $nang_luc    = sanitize_textarea_field( $_POST['nang_luc'] ?? '' );
        $quan_he     = sanitize_textarea_field( $_POST['quan_he'] ?? '' );
        $tong_hop    = sanitize_textarea_field( $_POST['tong_hop'] ?? '' );

        if ( ! $dvut_id || ! $period_id ) {
            wp_send_json_error( array( 'message' => 'Thiếu thông tin đoàn viên hoặc kỳ đánh giá.' ) );
        }

        // Kiểm tra kỳ đánh giá
        $period = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$this->table_nhan_xet_period} WHERE id = %d", $period_id
        ), ARRAY_A );

        if ( ! $period ) {
            wp_send_json_error( array( 'message' => 'Kỳ đánh giá không tồn tại.' ) );
        }

        // BCH Chi Đoàn chỉ được nhận xét khi kỳ đang mở
        if ( $role === 'bch_chi_doan' && ! $period['is_open'] ) {
            wp_send_json_error( array( 'message' => 'Kỳ đánh giá đã khóa. Chỉ Admin mới có thể chỉnh sửa.' ) );
        }

        // Kiểm tra đoàn viên thuộc chi đoàn (BCH chỉ nhận xét đoàn viên của mình)
        if ( $role === 'bch_chi_doan' && ! empty( $profile['chi_doan_id'] ) ) {
            $dv_chi_doan = $wpdb->get_var( $wpdb->prepare(
                "SELECT chi_doan_id FROM {$this->table_dvut} WHERE id = %d", $dvut_id
            ) );
            if ( (int) $dv_chi_doan !== (int) $profile['chi_doan_id'] ) {
                wp_send_json_error( array( 'message' => 'Đoàn viên không thuộc Chi Đoàn của bạn.' ) );
            }
        }

        $chi_doan_id = $profile['chi_doan_id'] ?? 0;

        // Upsert: INSERT ... ON DUPLICATE KEY UPDATE
        $existing = $wpdb->get_var( $wpdb->prepare(
            "SELECT id FROM {$this->table_nhan_xet} WHERE dvut_id = %d AND period_id = %d",
            $dvut_id, $period_id
        ) );

        if ( $existing ) {
            $wpdb->update(
                $this->table_nhan_xet,
                array(
                    'pham_chat'      => $pham_chat,
                    'nang_luc'       => $nang_luc,
                    'quan_he'        => $quan_he,
                    'tong_hop'       => $tong_hop,
                    'nguoi_nhan_xet' => get_current_user_id(),
                ),
                array( 'id' => $existing ),
                array( '%s', '%s', '%s', '%s', '%d' ),
                array( '%d' )
            );
        } else {
            $wpdb->insert(
                $this->table_nhan_xet,
                array(
                    'dvut_id'        => $dvut_id,
                    'period_id'      => $period_id,
                    'pham_chat'      => $pham_chat,
                    'nang_luc'       => $nang_luc,
                    'quan_he'        => $quan_he,
                    'tong_hop'       => $tong_hop,
                    'nguoi_nhan_xet' => get_current_user_id(),
                    'chi_doan_id'    => $chi_doan_id,
                ),
                array( '%d', '%d', '%s', '%s', '%s', '%s', '%d', '%d' )
            );
        }

        $this->write_admin_log( 'nhan_xet_save', 'nhan_xet', $dvut_id, 'Nhận xét cho kỳ: ' . $period['ten_ky'] );

        wp_send_json_success( array( 'message' => 'Lưu nhận xét thành công.' ) );
    }

    /**
     * Lấy danh sách kỳ đánh giá (periods).
     * Tất cả roles đều xem được; BCH cần biết kỳ nào đang mở.
     */
    public function handle_nhan_xet_periods() {
        $this->verify_nonce();
        global $wpdb;

        $results = $wpdb->get_results(
            "SELECT * FROM {$this->table_nhan_xet_period} ORDER BY nam_hoc DESC, quy DESC",
            ARRAY_A
        );

        wp_send_json_success( array( 'periods' => $results ?: array() ) );
    }

    /**
     * Tạo/cập nhật kỳ đánh giá.
     * Params: ten_ky, quy (1-4), nam_hoc (vd: 2025-2026)
     * Roles: admin_doan_truong only
     */
    public function handle_nhan_xet_period_save() {
        $this->verify_nonce();
        $this->require_role( array( 'admin_doan_truong' ) );
        global $wpdb;

        $id       = absint( $_POST['id'] ?? 0 );
        $ten_ky   = sanitize_text_field( $_POST['ten_ky'] ?? '' );
        $quy      = absint( $_POST['quy'] ?? 0 );
        $nam_hoc  = sanitize_text_field( $_POST['nam_hoc'] ?? '' );

        if ( ! $ten_ky || ! $quy || ! $nam_hoc || $quy > 4 ) {
            wp_send_json_error( array( 'message' => 'Vui lòng nhập đầy đủ: tên kỳ, quý (1-4), năm học.' ) );
        }

        if ( $id > 0 ) {
            $wpdb->update(
                $this->table_nhan_xet_period,
                array( 'ten_ky' => $ten_ky, 'quy' => $quy, 'nam_hoc' => $nam_hoc ),
                array( 'id' => $id ),
                array( '%s', '%d', '%s' ),
                array( '%d' )
            );
        } else {
            // Kiểm tra trùng lặp
            $exists = $wpdb->get_var( $wpdb->prepare(
                "SELECT id FROM {$this->table_nhan_xet_period} WHERE quy = %d AND nam_hoc = %s",
                $quy, $nam_hoc
            ) );
            if ( $exists ) {
                wp_send_json_error( array( 'message' => "Kỳ Quý {$quy} năm học {$nam_hoc} đã tồn tại." ) );
            }

            $wpdb->insert(
                $this->table_nhan_xet_period,
                array(
                    'ten_ky'    => $ten_ky,
                    'quy'       => $quy,
                    'nam_hoc'   => $nam_hoc,
                    'is_open'   => 0,
                    'nguoi_tao' => get_current_user_id(),
                ),
                array( '%s', '%d', '%s', '%d', '%d' )
            );
        }

        $this->write_admin_log( 'nhan_xet_period_save', 'period', $id ?: $wpdb->insert_id, "Kỳ: {$ten_ky}" );

        wp_send_json_success( array( 'message' => 'Lưu kỳ đánh giá thành công.' ) );
    }

    /**
     * Mở/khóa kỳ đánh giá.
     * Params: id, action ('open' | 'lock')
     * Roles: admin_doan_truong only
     */
    public function handle_nhan_xet_period_toggle() {
        $this->verify_nonce();
        $this->require_role( array( 'admin_doan_truong' ) );
        global $wpdb;

        $id     = absint( $_POST['id'] ?? 0 );
        $action = sanitize_text_field( $_POST['toggle_action'] ?? '' );

        if ( ! $id || ! in_array( $action, array( 'open', 'lock' ), true ) ) {
            wp_send_json_error( array( 'message' => 'Tham số không hợp lệ.' ) );
        }

        $is_open  = ( $action === 'open' ) ? 1 : 0;
        $date_col = ( $action === 'open' ) ? 'ngay_mo' : 'ngay_khoa';

        $wpdb->update(
            $this->table_nhan_xet_period,
            array( 'is_open' => $is_open, $date_col => current_time( 'mysql' ) ),
            array( 'id' => $id ),
            array( '%d', '%s' ),
            array( '%d' )
        );

        $label = ( $action === 'open' ) ? 'Mở' : 'Khóa';
        $this->write_admin_log( "nhan_xet_period_{$action}", 'period', $id, "{$label} kỳ đánh giá" );

        wp_send_json_success( array( 'message' => "{$label} kỳ đánh giá thành công." ) );
    }

    // =================================================================
    // BATCH: HÀNH ĐỘNG HÀNG LOẠT
    // =================================================================

    /**
     * Helper: lấy mảng ID an toàn từ $_POST['ids'].
     * Chỉ trả về các số nguyên dương.
     */
    private function parse_batch_ids() {
        $raw = isset( $_POST['ids'] ) && is_array( $_POST['ids'] ) ? $_POST['ids'] : array();
        return array_values( array_filter( array_map( 'absint', $raw ) ) );
    }

    /**
     * BATCH: dvut_batch_chi_doan_duyet
     * GĐ2 – Chi Đoàn biểu quyết nhiều hồ sơ cùng lúc (shared BQ results).
     * CHO_CHI_DOAN → CHO_DOAN_KHOA (nếu >50%) hoặc TU_CHOI.
     */
    public function handle_batch_chi_doan_duyet() {
        $this->verify_nonce();
        $this->check_permission();
        $this->require_role( array( 'bch_chi_doan' ) );

        $ids       = $this->parse_batch_ids();
        $tong_so   = isset( $_POST['tong_so_nguoi'] )  ? absint( $_POST['tong_so_nguoi'] )  : 0;
        $so_dong_y = isset( $_POST['so_luot_dong_y'] ) ? absint( $_POST['so_luot_dong_y'] ) : 0;

        if ( empty( $ids ) || $tong_so < 1 ) {
            wp_send_json_error( array( 'message' => 'Dữ liệu không hợp lệ.' ) );
            return;
        }
        if ( $so_dong_y > $tong_so ) {
            wp_send_json_error( array( 'message' => 'Số lượt đồng ý không thể lớn hơn tổng số người.' ) );
            return;
        }

        $bien_ban_file = $this->handle_file_upload( 'bien_ban_chi_doan' );
        $ty_le         = round( ( $so_dong_y / $tong_so ) * 100, 2 );
        $trang_thai    = $ty_le > 50 ? 'CHO_DOAN_KHOA' : 'TU_CHOI';
        $ghi_chu       = sprintf( 'Chi Đoàn duyệt hàng loạt: %s%% (%d/%d)', $ty_le, $so_dong_y, $tong_so );
        $nguoi_duyet   = get_current_user_id();

        global $wpdb;
        $success = 0;
        $failed  = 0;

        foreach ( $ids as $id ) {
            $hs = $wpdb->get_row( $wpdb->prepare(
                "SELECT id, trang_thai FROM {$this->table_dvut} WHERE id = %d", $id
            ), ARRAY_A );

            if ( ! $hs || $hs['trang_thai'] !== 'CHO_CHI_DOAN' ) {
                $failed++;
                continue;
            }

            $update_data = array(
                'tong_so_nguoi'          => $tong_so,
                'so_luot_dong_y'         => $so_dong_y,
                'ty_le'                  => $ty_le,
                'bien_ban_chi_doan_file' => $bien_ban_file,
                'trang_thai'             => $trang_thai,
                'ghi_chu'                => $ghi_chu,
                'nguoi_duyet_cd'         => $nguoi_duyet,
                'ngay_duyet_cd'          => current_time( 'mysql' ),
                'updated_at'             => current_time( 'mysql' ),
            );
            if ( $trang_thai === 'TU_CHOI' ) {
                $update_data['trang_thai_truoc_tu_choi'] = 'CHO_CHI_DOAN';
                $update_data['ly_do_tu_choi']            = sprintf( 'Không đạt tỷ lệ BQ > 50%% (%s%%)', $ty_le );
                $update_data['cap_tu_choi']              = 'Chi Đoàn';
                $update_data['ngay_tu_choi']             = current_time( 'mysql' );
            }

            $result = $wpdb->update( $this->table_dvut, $update_data, array( 'id' => $id ) );
            if ( $result !== false ) {
                $success++;
            } else {
                $failed++;
            }
        }

        wp_send_json_success( array(
            'message'       => sprintf( 'Đã xử lý %d hồ sơ thành công%s.', $success,
                $failed > 0 ? ", {$failed} hồ sơ không hợp lệ (bỏ qua)" : '' ),
            'success_count' => $success,
            'failed_count'  => $failed,
        ) );
    }

    /**
     * BATCH: dvut_batch_doan_khoa_duyet
     * GĐ2 – Đoàn Khoa duyệt nhiều hồ sơ CHO_DOAN_KHOA → CHO_DOAN_TRUONG.
     */
    public function handle_batch_doan_khoa_duyet() {
        $this->verify_nonce();
        $this->check_permission();
        $this->require_role( array( 'can_bo_doan_khoa' ) );

        $ids          = $this->parse_batch_ids();
        $tong_so_dk   = isset( $_POST['tong_so_dk'] )        ? absint( $_POST['tong_so_dk'] )        : 0;
        $so_dong_y_dk = isset( $_POST['so_luot_dong_y_dk'] ) ? absint( $_POST['so_luot_dong_y_dk'] ) : 0;
        $uu_diem      = isset( $_POST['uu_diem'] )      ? sanitize_textarea_field( $_POST['uu_diem'] )      : '';
        $khuyet_diem  = isset( $_POST['khuyet_diem'] )  ? sanitize_textarea_field( $_POST['khuyet_diem'] )  : '';

        if ( empty( $ids ) || $tong_so_dk < 1 ) {
            wp_send_json_error( array( 'message' => 'Dữ liệu không hợp lệ.' ) );
            return;
        }

        $cong_van_file = $this->handle_file_upload( 'cong_van_dk' );
        $bien_ban_file = $this->handle_file_upload( 'bien_ban_dk' );
        $ty_le_dk      = round( ( $so_dong_y_dk / $tong_so_dk ) * 100, 2 );
        $ghi_chu       = sprintf( 'Đoàn Khoa duyệt hàng loạt ngày %s — BQ: %s%% (%d/%d)',
            current_time( 'd/m/Y' ), $ty_le_dk, $so_dong_y_dk, $tong_so_dk );
        $nguoi_duyet   = get_current_user_id();

        global $wpdb;
        $success = 0;
        $failed  = 0;

        foreach ( $ids as $id ) {
            $hs = $wpdb->get_row( $wpdb->prepare(
                "SELECT id, trang_thai FROM {$this->table_dvut} WHERE id = %d", $id
            ), ARRAY_A );

            if ( ! $hs || $hs['trang_thai'] !== 'CHO_DOAN_KHOA' ) {
                $failed++;
                continue;
            }

            $result = $wpdb->update(
                $this->table_dvut,
                array(
                    'tong_so_dk'        => $tong_so_dk,
                    'so_luot_dong_y_dk' => $so_dong_y_dk,
                    'ty_le_dk'          => $ty_le_dk,
                    'uu_diem'           => $uu_diem,
                    'khuyet_diem'       => $khuyet_diem,
                    'cong_van_dk_file'  => $cong_van_file,
                    'bien_ban_dk_file'  => $bien_ban_file,
                    'trang_thai'        => 'CHO_DOAN_TRUONG',
                    'ghi_chu'           => $ghi_chu,
                    'nguoi_duyet_dk'    => $nguoi_duyet,
                    'ngay_duyet_dk'     => current_time( 'mysql' ),
                    'updated_at'        => current_time( 'mysql' ),
                ),
                array( 'id' => $id )
            );
            if ( $result !== false ) {
                $success++;
            } else {
                $failed++;
            }
        }

        wp_send_json_success( array(
            'message'       => sprintf( 'Đã phê duyệt %d hồ sơ thành công%s.', $success,
                $failed > 0 ? ", {$failed} hồ sơ không hợp lệ (bỏ qua)" : '' ),
            'success_count' => $success,
            'failed_count'  => $failed,
        ) );
    }

    /**
     * BATCH: dvut_batch_doan_truong_cong_nhan
     * GĐ2 – Đoàn Trường ban hành một QĐ chung cho nhiều hồ sơ.
     * CHO_DOAN_TRUONG → DA_CONG_NHAN.
     */
    public function handle_batch_doan_truong_cong_nhan() {
        $this->verify_nonce();
        $this->check_permission();
        $this->require_role( array( 'admin_doan_truong' ) );

        $ids           = $this->parse_batch_ids();
        $so_quyet_dinh = isset( $_POST['so_quyet_dinh'] ) ? sanitize_text_field( $_POST['so_quyet_dinh'] ) : '';

        if ( empty( $ids ) ) {
            wp_send_json_error( array( 'message' => 'Chưa chọn hồ sơ nào.' ) );
            return;
        }

        $file_qd = $this->handle_file_upload( 'file_qd_cong_nhan' );
        if ( empty( $file_qd ) ) {
            wp_send_json_error( array( 'message' => 'Vui lòng đính kèm file Quyết định công nhận.' ) );
            return;
        }

        global $wpdb;
        $ghi_chu         = sprintf( 'Đoàn Trường công nhận ĐVƯT hàng loạt ngày %s — QĐ: %s',
            current_time( 'd/m/Y' ), $so_quyet_dinh );
        $nguoi_cong_nhan = get_current_user_id();
        $success         = 0;
        $failed          = 0;

        foreach ( $ids as $id ) {
            $hs = $wpdb->get_row( $wpdb->prepare(
                "SELECT id, trang_thai, khoa_id FROM {$this->table_dvut} WHERE id = %d", $id
            ), ARRAY_A );

            if ( ! $hs || $hs['trang_thai'] !== 'CHO_DOAN_TRUONG' ) {
                $failed++;
                continue;
            }

            $wpdb->update(
                $this->table_dvut,
                array(
                    'trang_thai'        => 'DA_CONG_NHAN',
                    'so_quyet_dinh'     => $so_quyet_dinh,
                    'file_qd_cong_nhan' => $file_qd,
                    'ngay_cong_nhan'    => current_time( 'Y-m-d' ),
                    'nguoi_cong_nhan'   => $nguoi_cong_nhan,
                    'ghi_chu'           => $ghi_chu,
                    'updated_at'        => current_time( 'mysql' ),
                ),
                array( 'id' => $id )
            );

            // Tự động gán chi_bo_id từ khoa
            if ( ! empty( $hs['khoa_id'] ) ) {
                $chi_bo_id = $wpdb->get_var( $wpdb->prepare(
                    "SELECT chi_bo_id FROM {$this->table_khoa} WHERE id = %d",
                    (int) $hs['khoa_id']
                ) );
                if ( $chi_bo_id ) {
                    $wpdb->update(
                        $this->table_dvut,
                        array( 'chi_bo_id' => (int) $chi_bo_id ),
                        array( 'id' => $id )
                    );
                }
            }

            $success++;
        }

        wp_send_json_success( array(
            'message'       => sprintf( 'Đã ban hành Quyết định công nhận %d Đoàn viên Ưu tú%s!', $success,
                $failed > 0 ? " ({$failed} hồ sơ không hợp lệ đã bỏ qua)" : '' ),
            'success_count' => $success,
            'failed_count'  => $failed,
        ) );
    }



    /**
     * BATCH: dvut_batch_dk_duyet_gioi_thieu
     * GĐ4 – Đoàn Khoa ra Nghị quyết giới thiệu vào Đảng hàng loạt.
     * CHO_DK_GIOI_THIEU → CHO_DT_XAC_NHAN_GT.
     */
    public function handle_batch_dk_duyet_gioi_thieu() {
        $this->verify_nonce();
        $this->check_permission();
        $this->require_role( array( 'can_bo_doan_khoa' ) );

        $ids = $this->parse_batch_ids();
        if ( empty( $ids ) ) {
            wp_send_json_error( array( 'message' => 'Chưa chọn hồ sơ nào.' ) );
            return;
        }

        $nghi_quyet_file = $this->handle_file_upload( 'nghi_quyet_dk' );

        global $wpdb;
        $ghi_chu = sprintf( 'Đoàn Khoa ra Nghị quyết GT vào Đảng hàng loạt ngày %s', current_time( 'd/m/Y' ) );
        $success = 0;
        $failed  = 0;

        foreach ( $ids as $id ) {
            $hs = $wpdb->get_row( $wpdb->prepare(
                "SELECT id, trang_thai FROM {$this->table_dvut} WHERE id = %d", $id
            ), ARRAY_A );

            if ( ! $hs || $hs['trang_thai'] !== 'CHO_DK_GIOI_THIEU' ) {
                $failed++;
                continue;
            }

            $update_data = array(
                'trang_thai'         => 'CHUYEN_GIAO_CHI_BO',
                'ngay_nghi_quyet_gt' => current_time( 'mysql' ),
                'ghi_chu'            => $ghi_chu,
                'updated_at'         => current_time( 'mysql' ),
            );
            if ( ! empty( $nghi_quyet_file ) ) {
                $update_data['nghi_quyet_dk_file'] = $nghi_quyet_file;
            }

            $result = $wpdb->update( $this->table_dvut, $update_data, array( 'id' => $id ) );
            if ( $result !== false ) {
                $success++;
            } else {
                $failed++;
            }
        }

        wp_send_json_success( array(
            'message'       => sprintf( 'Đã ban hành Nghị quyết GT vào Đảng và chuyển giao cho Chi bộ %d hồ sơ%s!', $success,
                $failed > 0 ? ", {$failed} hồ sơ không hợp lệ (bỏ qua)" : '' ),
            'success_count' => $success,
            'failed_count'  => $failed,
        ) );
    }

    /**
     * BATCH: dvut_batch_dk_duyet_chuyen_dang
     * GĐ5 – Đoàn Khoa xác nhận chuyển Đảng chính thức hàng loạt.
     * CHO_DK_CHUYEN_DANG → CHO_DT_XAC_NHAN_CD.
     */
    public function handle_batch_dk_duyet_chuyen_dang() {
        $this->verify_nonce();
        $this->check_permission();
        $this->require_role( array( 'can_bo_doan_khoa' ) );

        $ids = $this->parse_batch_ids();
        if ( empty( $ids ) ) {
            wp_send_json_error( array( 'message' => 'Chưa chọn hồ sơ nào.' ) );
            return;
        }

        $y_kien_file = $this->handle_file_upload( 'y_kien_dk' );

        global $wpdb;
        $ghi_chu = sprintf( 'Đoàn Khoa xác nhận Chuyển Đảng CT hàng loạt ngày %s', current_time( 'd/m/Y' ) );
        $success = 0;
        $failed  = 0;

        foreach ( $ids as $id ) {
            $hs = $wpdb->get_row( $wpdb->prepare(
                "SELECT id, trang_thai FROM {$this->table_dvut} WHERE id = %d", $id
            ), ARRAY_A );

            if ( ! $hs || $hs['trang_thai'] !== 'CHO_DK_CHUYEN_DANG' ) {
                $failed++;
                continue;
            }

            $update_data = array(
                'trang_thai'       => 'DANG_VIEN_CHINH_THUC',
                'ngay_chuyen_dang' => current_time( 'mysql' ),
                'ghi_chu'          => $ghi_chu,
                'updated_at'       => current_time( 'mysql' ),
            );
            if ( ! empty( $y_kien_file ) ) {
                $update_data['y_kien_dk_file'] = $y_kien_file;
            }

            $result = $wpdb->update( $this->table_dvut, $update_data, array( 'id' => $id ) );
            if ( $result !== false ) {
                $success++;
            } else {
                $failed++;
            }
        }

        wp_send_json_success( array(
            'message'       => sprintf( 'Đã duyệt chuyển Đảng chính thức %d hồ sơ%s!', $success,
                $failed > 0 ? ", {$failed} hồ sơ không hợp lệ (bỏ qua)" : '' ),
            'success_count' => $success,
            'failed_count'  => $failed,
        ) );
    }

    /**
     * AJAX handler: dvut_save_doan_vien
     * Legacy CSV-based API converted to standard AJAX (Deprecated/Placeholder).
     */
    public function handle_save_doan_vien() {
        $this->verify_nonce();
        $this->check_permission();
        wp_send_json_error( array( 'message' => 'API này đã dừng hoạt động. Hệ thống hiện tại sử dụng cơ sở dữ liệu MySQL trực tiếp qua các luồng nghiệp vụ chuẩn.' ) );
    }

    /**
     * AJAX handler: dvut_append_lich_su
     * Legacy CSV-based history API converted to standard AJAX (Deprecated/Placeholder).
     */
    public function handle_append_lich_su() {
        $this->verify_nonce();
        $this->check_permission();
        wp_send_json_error( array( 'message' => 'API này đã dừng hoạt động. Lịch sử chỉnh sửa được ghi tự động vào cơ sở dữ liệu MySQL thông qua bảng log hệ thống.' ) );
    }

    /**
     * AJAX handler: dvut_check_user
     * Debug and utility helper to check tables (Admin only).
     */
    public function handle_check_user() {
        $this->verify_nonce();
        $this->check_permission( 'manage_options' ); // Chỉ Admin/Administrator WP mới có quyền này
        
        global $wpdb;
        
        $roles = $wpdb->get_results( "SELECT * FROM {$this->table_user_roles}", ARRAY_A ) ?: array();
        $khoas = $wpdb->get_results( "SELECT * FROM {$this->table_khoa}", ARRAY_A ) ?: array();
        $counts = $wpdb->get_results( "SELECT trang_thai, count(*) as count FROM {$this->table_dvut} GROUP BY trang_thai", ARRAY_A ) ?: array();
        
        wp_send_json_success( array(
            'message' => 'Lấy dữ liệu cấu trúc hệ thống thành công.',
            'roles'   => $roles,
            'khoas'   => $khoas,
            'counts'  => $counts,
        ) );
    }

    /**
     * AJAX handler: dhs_unified_password_login
     * Xác thực tài khoản qua Mã số sinh viên (MSSV / Username) hoặc Email và Password.
     */
    public function handle_unified_password_login() {
        $username = isset( $_POST['username'] ) ? sanitize_text_field( wp_unslash( $_POST['username'] ) ) : '';
        $password = isset( $_POST['password'] ) ? $_POST['password'] : '';

        if ( empty( $username ) || empty( $password ) ) {
            wp_send_json( array(
                'success' => false,
                'message' => 'Vui lòng nhập đầy đủ Mã số/Email và Mật khẩu.'
            ) );
        }

        // Hỗ trợ đăng nhập bằng Email: tìm kiếm user_login tương ứng nếu đầu vào là địa chỉ email
        if ( is_email( $username ) ) {
            $user = get_user_by( 'email', $username );
            if ( $user ) {
                $username = $user->user_login;
            } else {
                wp_send_json( array(
                    'success' => false,
                    'message' => 'Địa chỉ email này chưa được liên kết với bất kỳ tài khoản nào.'
                ) );
            }
        }

        // Cấu hình thông tin đăng nhập cho wp_signon()
        $creds = array(
            'user_login'    => $username,
            'user_password' => $password,
            'remember'      => true,
        );

        // Đăng nhập qua wp_signon của WordPress
        $secure_cookie = is_ssl();
        $user_signon   = wp_signon( $creds, $secure_cookie );

        if ( is_wp_error( $user_signon ) ) {
            // Trả về lỗi chi tiết cho Frontend
            wp_send_json( array(
                'success' => false,
                'message' => 'Mã số sinh viên/Email hoặc Mật khẩu không chính xác.'
            ) );
        }

        // Xác thực bổ sung: Kiểm tra trạng thái kích hoạt tài khoản trong bảng user_roles của hệ thống
        $user_id = $user_signon->ID;
        
        // Admin WordPress mặc định luôn được kích hoạt
        if ( ! user_can( $user_id, 'manage_options' ) ) {
            global $wpdb;
            $tables = DVUT_Database::get_tables();
            $role_active = $wpdb->get_var( $wpdb->prepare(
                "SELECT is_active FROM {$tables['user_roles']} WHERE user_id = %d",
                $user_id
            ) );
            
            // Nếu tài khoản bị khóa hoặc chưa được kích hoạt vai trò
            if ( $role_active !== null && intval( $role_active ) === 0 ) {
                wp_logout(); // Đăng xuất lập tức để bảo mật
                wp_send_json( array(
                    'success' => false,
                    'message' => 'Tài khoản của bạn hiện đang bị khóa hoặc chưa được kích hoạt vai trò xét duyệt.'
                ) );
            }
        }

        // Trả về thành công
        wp_send_json( array(
            'success' => true,
            'message' => 'Xác thực tài khoản thành công.'
        ) );
    }

    /**
     * AJAX handler: dhs_unified_google_login
     * Tự động đăng ký và gán Chi Đoàn khi đăng nhập bằng Google OAuth.
     */
    public function handle_unified_google_login() {
        $credential = isset( $_POST['credential'] ) ? $_POST['credential'] : '';

        if ( empty( $credential ) ) {
            wp_send_json( array(
                'success' => false,
                'message' => 'Không tìm thấy thông tin xác thực Google Token (Credential).'
            ) );
        }

        // 1. Phân tách và giải mã Base64 phần Payload của JWT
        $jwt_parts = explode( '.', $credential );
        if ( count( $jwt_parts ) < 3 ) {
            wp_send_json( array(
                'success' => false,
                'message' => 'Mã xác thực Google (JWT Token) không đúng định dạng.'
            ) );
        }

        $payload_encoded = $jwt_parts[1];
        $payload_decoded = base64_decode( strtr( $payload_encoded, '-_', '+/' ) );
        if ( ! $payload_decoded ) {
            wp_send_json( array(
                'success' => false,
                'message' => 'Không thể giải mã Payload của mã xác thực Google.'
            ) );
        }

        $payload = json_decode( $payload_decoded, true );
        if ( empty( $payload ) || empty( $payload['email'] ) ) {
            wp_send_json( array(
                'success' => false,
                'message' => 'Thông tin giải mã từ Google không hợp lệ hoặc thiếu địa chỉ email.'
            ) );
        }

        $email = sanitize_email( $payload['email'] );

        // 2. Đối chiếu dữ liệu "Hộp đen" (Bảng wp_dvut_blackbox)
        global $wpdb;
        
        $blackbox = $wpdb->get_row( $wpdb->prepare(
            "SELECT mssv, ho_ten, chi_doan_id, khoa_id FROM {$this->table_blackbox} WHERE email = %s",
            $email
        ), ARRAY_A );

        if ( ! $blackbox ) {
            wp_send_json( array(
                'success' => false,
                'message' => 'Email (' . esc_html( $email ) . ') của bạn không nằm trong danh sách dữ liệu xét duyệt của nhà trường.'
            ) );
        }

        $mssv        = $blackbox['mssv'];
        $ho_ten      = $blackbox['ho_ten']; // Dùng tên chuẩn từ hòm đen
        $chi_doan_id = intval( $blackbox['chi_doan_id'] );
        $khoa_id     = intval( $blackbox['khoa_id'] );

        // 3. Kiểm tra và Tự động tạo Tài khoản WordPress nếu chưa tồn tại
        $user_id = username_exists( $mssv );
        if ( ! $user_id ) {
            $user_id = email_exists( $email );
        }

        if ( ! $user_id ) {
            // Tạo mật khẩu ngẫu nhiên an toàn
            $random_password = wp_generate_password( 16, false );
            
            $user_data = array(
                'user_login'   => $mssv,
                'user_email'   => $email,
                'display_name' => $ho_ten,
                'nickname'     => $ho_ten,
                'user_pass'    => $random_password,
                'role'         => 'subscriber', // WordPress role mặc định
            );

            $user_id = wp_insert_user( $user_data );

            if ( is_wp_error( $user_id ) ) {
                wp_send_json( array(
                    'success' => false,
                    'message' => 'Không thể khởi tạo tài khoản trên hệ thống: ' . $user_id->get_error_message()
                ) );
            }

            // Ghi nhận các trường metadata cơ bản của WordPress
            update_user_meta( $user_id, 'dvut_mssv', $mssv );
            update_user_meta( $user_id, 'dvut_khoa_id', $khoa_id );
            update_user_meta( $user_id, 'dvut_chi_doan_id', $chi_doan_id );
        }

        $user = get_userdata( $user_id );
        if ( ! $user ) {
            wp_send_json( array(
                'success' => false,
                'message' => 'Không thể lấy thông tin tài khoản sau khi tạo/xác thực.'
            ) );
        }

        // 4. Đồng bộ Phân quyền Hệ thống (Bảng wp_dvut_user_roles)
        $role_record = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$this->table_user_roles} WHERE user_id = %d",
            $user_id
        ), ARRAY_A );

        $default_role = 'doan_vien'; // Mặc định cho đoàn viên tự đăng ký học tập/đề cử

        if ( ! $role_record ) {
            $inserted = $wpdb->insert(
                $this->table_user_roles,
                array(
                    'user_id'     => $user_id,
                    'role'        => $default_role,
                    'khoa_id'     => $khoa_id,
                    'chi_doan_id' => $chi_doan_id,
                    'is_active'   => 1,
                ),
                array( '%d', '%s', '%d', '%d', '%d' )
            );

            if ( $inserted === false ) {
                wp_send_json( array(
                    'success' => false,
                    'message' => 'Lỗi đồng bộ phân quyền Đoàn viên vào cơ sở dữ liệu.'
                ) );
            }

            // Cập nhật phân quyền phụ trên WP Role
            $user_wp = new WP_User( $user_id );
            $user_wp->set_role( 'dvut_doan_vien' );
        } else {
            // Đồng bộ lại Chi Đoàn, Khoa và tự động kích hoạt lại vai trò nếu bị khóa khi đăng nhập qua Google chính chủ
            $update_data = array();
            $update_formats = array();
            
            if ( intval( $role_record['khoa_id'] ) !== $khoa_id ) {
                $update_data['khoa_id'] = $khoa_id;
                $update_formats[] = '%d';
            }
            if ( intval( $role_record['chi_doan_id'] ) !== $chi_doan_id ) {
                $update_data['chi_doan_id'] = $chi_doan_id;
                $update_formats[] = '%d';
            }
            if ( intval( $role_record['is_active'] ) === 0 ) {
                $update_data['is_active'] = 1;
                $update_formats[] = '%d';
            }
            
            if ( ! empty( $update_data ) ) {
                $wpdb->update(
                    $this->table_user_roles,
                    $update_data,
                    array( 'user_id' => $user_id ),
                    $update_formats,
                    array( '%d' )
                );
            }

            // Đảm bảo WP role của họ đúng ít nhất là dvut_doan_vien nếu họ chưa có role nào
            $user_wp = new WP_User( $user_id );
            if ( empty( $user_wp->roles ) ) {
                $user_wp->set_role( 'dvut_doan_vien' );
            }
        }

        // 5. Đăng nhập chương trình (Programmatic Login)
        wp_clear_auth_cookie();
        wp_set_current_user( $user_id );
        wp_set_auth_cookie( $user_id, true );
        do_action( 'wp_login', $user->user_login, $user );

        // Phản hồi kết quả thành công về cho Frontend
        wp_send_json( array(
            'success' => true,
            'message' => 'Đăng nhập và đồng bộ thông tin Google thành công!'
        ) );
    }
}

