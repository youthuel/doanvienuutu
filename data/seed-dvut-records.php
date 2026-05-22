<?php
/**
 * =================================================================
 * DVUT SEED — Dữ liệu mô phỏng vận hành thực tế
 * =================================================================
 * Script tạo ~200 hồ sơ ĐVƯT từ sinh viên đủ điều kiện trong Hộp đen.
 *
 * Chạy một lần qua trình duyệt:
 *   http://localhost/wp-content/themes/doanvienuutu/data/seed-dvut-records.php?key=dvut_seed_2026
 *
 * Hoặc qua CLI:
 *   php seed-dvut-records.php cli
 *
 * Điều kiện đủ (khớp với handle_check_mssv):
 *   - ly_luan_chinh_tri = 'Hoàn thành'
 *   - xep_loai_doan_vien IN ('Hoàn thành xuất sắc', 'Hoàn thành tốt')
 *   - diem_tb_tich_luy >= 7.0
 *   - diem_ren_luyen   >= 80
 * =================================================================
 */

// ── Security ──────────────────────────────────────────────────────
$is_cli = php_sapi_name() === 'cli' || (isset($argv[1]) && $argv[1] === 'cli');
if (!$is_cli) {
    $key = $_GET['key'] ?? '';
    if ($key !== 'dvut_seed_2026') {
        http_response_code(403);
        die('<b>403 Forbidden</b>');
    }
    header('Content-Type: text/plain; charset=utf-8');
}

// ── Bootstrap WordPress ───────────────────────────────────────────
$wp_load = dirname(__FILE__, 5) . '/wp-load.php';
if (!file_exists($wp_load)) {
    die("wp-load.php not found at: $wp_load\n");
}
require_once $wp_load;

// ── Config ────────────────────────────────────────────────────────
/** Số hồ sơ tối đa cần tạo (sẽ dừng nếu hết sinh viên đủ điều kiện chưa seed). */
const SEED_TARGET = 200;

/** Nếu đã có >= ngưỡng này thì từ chối chạy lại (bảo vệ DB thực). */
const SEED_GUARD = 50;

/** User WP sẽ được dùng làm nguoi_de_cu / nguoi_duyet. */
const SEED_USER_ID = 1;

/** Giả lập domain để tạo URL file placeholder. */
const FAKE_FILE_BASE = 'https://dvut.placeholder/uploads/seed/';

// ── Phân phối trạng thái ─────────────────────────────────────────
/**
 * Phân phối ~200 hồ sơ qua tất cả các giai đoạn để mô phỏng thực tế.
 * Tổng = SEED_TARGET.
 */
const STATUS_DISTRIBUTION = [
    'CHO_NOP'                 => 24,  // GĐ1 — vừa đề cử, chờ nộp hồ sơ
    'CHO_CHI_DOAN'            => 20,  // GĐ2 — đã nộp, chờ Chi Đoàn biểu quyết
    'CHO_DOAN_KHOA'           => 30,  // GĐ2 — Chi Đoàn duyệt, chờ Đoàn Khoa
    'CHO_DOAN_TRUONG'         => 22,  // GĐ2 — Đoàn Khoa duyệt, chờ Đoàn Trường
    'DA_CONG_NHAN'            => 32,  // GĐ2 — Đoàn Trường đã ban hành QĐ
    'CHO_DK_GIOI_THIEU'       => 14,  // GĐ4 — đã CN, chờ ĐK giới thiệu Đảng
    'CHO_DT_XAC_NHAN_GT'      => 10,  // GĐ4 — ĐK đã GT, chờ ĐT xác nhận
    'CHUYEN_GIAO_CHI_BO'      =>  8,  // GĐ4 — ĐT xác nhận, bàn giao Chi bộ SV
    'CHI_BO_DANG_THEO_DOI'    =>  8,  // GĐ4 — Chi bộ đang theo dõi
    'CHO_DANG_UY_TRUONG_XET'  =>  6,  // GĐ4 — Chi bộ BQ xong, chờ ĐUTrường
    'DA_CO_QD_KET_NAP'        =>  5,  // GĐ4 — có QĐ kết nạp
    'DANG_VIEN_DU_BI'         =>  5,  // GĐ4 — lễ kết nạp đã diễn ra
    'CHO_DK_CHUYEN_DANG'      =>  4,  // GĐ5 — chờ ĐK BQ chuyển Đảng CT
    'CHO_DT_XAC_NHAN_CD'      =>  3,  // GĐ5 — ĐK BQ xong, chờ ĐT xác nhận
    'DANG_VIEN_CHINH_THUC'    =>  3,  // GĐ5 — đã chuyển Đảng chính thức
    'TU_CHOI'                 =>  4,  // Bị Chi Đoàn/ĐK từ chối
    'TRA_VE'                  =>  2,  // Đoàn Khoa trả về Chi Đoàn
    // DA_HUY không seed — sẽ bị filter out bởi get_list
];

// Kiểm tra tổng
$dist_total = array_sum(STATUS_DISTRIBUTION);
assert($dist_total === SEED_TARGET, "STATUS_DISTRIBUTION tổng phải = " . SEED_TARGET . " (hiện: $dist_total)");

// ── Helpers ───────────────────────────────────────────────────────

function log_msg(string $msg): void {
    echo $msg . "\n";
    if (function_exists('ob_flush')) { ob_flush(); flush(); }
}

/**
 * Tạo ngày ngẫu nhiên trong khoảng, trả về chuỗi 'Y-m-d' hoặc 'Y-m-d H:i:s'.
 */
function rand_date(string $from, string $to, bool $with_time = false): string {
    $ts = mt_rand(strtotime($from), strtotime($to));
    return $with_time ? date('Y-m-d H:i:s', $ts) : date('Y-m-d', $ts);
}

/** Trả về ngày sau $days ngày (±$jitter ngày ngẫu nhiên). */
function date_add_days(string $base, int $days, int $jitter = 3): string {
    $ts = strtotime($base) + ($days + mt_rand(-$jitter, $jitter)) * 86400;
    return date('Y-m-d', $ts);
}

/** Datetime version. */
function datetime_add_days(string $base, int $days, int $jitter = 3): string {
    $ts = strtotime($base) + ($days + mt_rand(-$jitter, $jitter)) * 86400;
    $ts += mt_rand(28800, 61200); // 8:00–17:00
    return date('Y-m-d H:i:s', $ts);
}

/** Tạo số QĐ giả. */
function fake_quyet_dinh(string $type = 'DVUT'): string {
    static $counters = [];
    $counters[$type] = ($counters[$type] ?? 0) + 1;
    return sprintf('QD-%03d/2026/%s', $counters[$type], $type);
}

/** URL file placeholder. */
function fake_file(string $name): string {
    return FAKE_FILE_BASE . $name . '.pdf';
}

/** Biểu quyết Chi Đoàn đạt (>2/3). */
function bq_chi_doan_dat(int $min = 15, int $max = 55): array {
    $total = mt_rand($min, $max);
    $dongY = (int)ceil($total * (mt_rand(67, 100) / 100));
    $dongY = min($dongY, $total);
    $tyLe  = round($dongY / $total * 100, 2);
    return [$total, $dongY, $tyLe];
}

/** Biểu quyết Chi Đoàn không đạt (≤50%). */
function bq_chi_doan_khong_dat(int $min = 15, int $max = 55): array {
    $total = mt_rand($min, $max);
    $dongY = (int)floor($total * (mt_rand(10, 50) / 100));
    $tyLe  = round($dongY / $total * 100, 2);
    return [$total, $dongY, $tyLe];
}

/** Biểu quyết Đoàn Khoa đạt. */
function bq_doan_khoa(int $min = 7, int $max = 15): array {
    $total = mt_rand($min, $max);
    $dongY = (int)ceil($total * (mt_rand(67, 100) / 100));
    $dongY = min($dongY, $total);
    $tyLe  = round($dongY / $total * 100, 2);
    return [$total, $dongY, $tyLe];
}

/** Biểu quyết Giới thiệu (ĐK). */
function bq_gioi_thieu(): array {
    $total = mt_rand(7, 15);
    $dongY = (int)ceil($total * (mt_rand(67, 100) / 100));
    $dongY = min($dongY, $total);
    $tyLe  = round($dongY / $total * 100, 2);
    return [$total, $dongY, $tyLe];
}

/** Biểu quyết Chi bộ SV. */
function bq_chi_bo(): array {
    $total = mt_rand(5, 15);
    $dongY = (int)ceil($total * (mt_rand(67, 100) / 100));
    $dongY = min($dongY, $total);
    $tyLe  = round($dongY / $total * 100, 2);
    return [$total, $dongY, $tyLe];
}

/** Biểu quyết Chuyển Đảng CT. */
function bq_chuyen_dang_ct(): array {
    return bq_chi_bo(); // tương tự
}

$uu_diem_pool = [
    'Sinh viên có tinh thần trách nhiệm cao, chấp hành tốt nội quy Đoàn.',
    'Tích cực tham gia các hoạt động phong trào của chi đoàn và khoa.',
    'Bản lĩnh chính trị vững vàng, phẩm chất đạo đức tốt.',
    'Kết quả học tập xuất sắc, thường xuyên hỗ trợ học tập cho bạn bè.',
    'Năng động, sáng tạo trong công tác Đoàn và phong trào sinh viên.',
    'Hoàn thành tốt các nhiệm vụ được giao, có ý thức kỷ luật tốt.',
    'Luôn tiên phong, gương mẫu trong các hoạt động tập thể.',
];
$khuyet_diem_pool = [
    'Đôi khi chưa chủ động trong việc đề xuất giải pháp mới.',
    'Cần cải thiện kỹ năng trình bày và phản biện.',
    'Chưa tham gia đầy đủ một số buổi sinh hoạt chi đoàn trong học kỳ vừa qua.',
    'Cần chú ý hơn đến công tác báo cáo, ghi chép khi tham gia tổ chức sự kiện.',
    'Kỹ năng làm việc nhóm đôi khi còn thụ động, cần tích cực hơn.',
];
$dang_vien_pool = [
    'Nguyễn Văn Hùng', 'Trần Thị Mai', 'Lê Minh Tuấn', 'Phạm Thị Hoa',
    'Hoàng Văn Đức', 'Ngô Thị Lan', 'Vũ Đức Thắng', 'Đỗ Thị Hương',
    'Bùi Quang Minh', 'Đặng Thị Thu', 'Lý Minh Khoa', 'Phan Thị Ngọc',
];

// ── Database setup ────────────────────────────────────────────────
global $wpdb;
$t_dvut  = $wpdb->prefix . 'doan_vien_uu_tu';
$t_bb    = $wpdb->prefix . 'dvut_blackbox';
$t_khoa  = $wpdb->prefix . 'khoa';

// Guard
$existing = (int)$wpdb->get_var("SELECT COUNT(*) FROM $t_dvut WHERE trang_thai != 'DA_HUY'");
if ($existing >= SEED_GUARD) {
    log_msg("⛔ Database đã có $existing hồ sơ (ngưỡng bảo vệ = " . SEED_GUARD . ").");
    log_msg("   Để chạy lại, xóa hồ sơ cũ hoặc tăng hằng số SEED_GUARD.");
    exit(0);
}

// Lấy mapping khoa_id → chi_bo_id
$khoa_chi_bo = [];
$rows = $wpdb->get_results("SELECT id, chi_bo_id FROM $t_khoa", ARRAY_A);
foreach ($rows as $r) {
    $khoa_chi_bo[(int)$r['id']] = (int)$r['chi_bo_id'];
}

// Lấy MSSV đã có trong dvut (bao gồm DA_HUY - không seed trùng)
$existing_mssv = $wpdb->get_col("SELECT mssv FROM $t_dvut");
$existing_mssv = array_flip($existing_mssv);

// Lấy toàn bộ sinh viên đủ điều kiện từ Hộp đen
$eligible = $wpdb->get_results(
    "SELECT mssv, ho_ten, chi_doan, chi_doan_id, khoa, khoa_id
     FROM $t_bb
     WHERE ly_luan_chinh_tri = 'Hoàn thành'
       AND xep_loai_doan_vien IN ('Hoàn thành xuất sắc', 'Hoàn thành tốt')
       AND diem_tb_tich_luy >= 7.0
       AND diem_ren_luyen   >= 80
     ORDER BY RAND()
     LIMIT " . (SEED_TARGET + 20),  // lấy dư để phòng trùng
    ARRAY_A
);

// Lọc bỏ MSSV đã có
$candidates = [];
foreach ($eligible as $sv) {
    if (!isset($existing_mssv[$sv['mssv']]) && (int)$sv['chi_doan_id'] > 0) {
        $candidates[] = $sv;
    }
}

if (count($candidates) < SEED_TARGET) {
    log_msg("⚠ Chỉ tìm được " . count($candidates) . " sinh viên đủ điều kiện chưa seed (cần " . SEED_TARGET . "). Tiếp tục với số lượng có.");
}

shuffle($candidates);
$idx = 0;

// ── Seeding ───────────────────────────────────────────────────────
$inserted = 0;
$errors   = 0;
$today    = date('Y-m-d');

log_msg("▶ Bắt đầu seed " . count($candidates) . " hồ sơ ĐVƯT...\n");

foreach (STATUS_DISTRIBUTION as $trang_thai => $count) {
    $seeded_this = 0;

    for ($i = 0; $i < $count; $i++) {
        if (!isset($candidates[$idx])) {
            log_msg("  ⚠ Hết sinh viên đủ điều kiện tại trạng thái $trang_thai (cần $count, đã seed $seeded_this)");
            break;
        }
        $sv = $candidates[$idx++];

        // Base dates
        $ngay_de_cu      = rand_date('2025-10-01', '2026-02-28');
        $ngay_nop_ho_so  = date_add_days($ngay_de_cu, 5, 3);
        $ngay_duyet_cd   = date_add_days($ngay_nop_ho_so, 10, 5);
        $ngay_duyet_dk   = date_add_days($ngay_duyet_cd, 10, 5);
        $ngay_cong_nhan  = date_add_days($ngay_duyet_dk, 14, 5);
        $ngay_gt         = date_add_days($ngay_cong_nhan, 45, 10);
        $ngay_gt_dt      = datetime_add_days($ngay_gt, 7, 3);
        $ngay_chuyen_giao = date_add_days($ngay_cong_nhan, 60, 10);
        $ngay_theo_doi   = date_add_days($ngay_chuyen_giao, 7, 3);
        $ngay_bq_cb      = datetime_add_days($ngay_chuyen_giao, 30, 7);
        $ngay_qd_ket_nap = date_add_days($ngay_chuyen_giao, 60, 10);
        $ngay_le_ket_nap = date_add_days($ngay_qd_ket_nap, 14, 5);
        $ngay_chuyen_dang = date_add_days($ngay_le_ket_nap, 365, 15);
        $ngay_bq_cd_ct   = datetime_add_days($ngay_chuyen_dang, 10, 3);

        $chi_bo_id = $khoa_chi_bo[(int)$sv['khoa_id']] ?? 0;

        // ── Xây dựng record theo stage ──────────────────────────
        $data = [
            'ho_ten'                  => $sv['ho_ten'],
            'mssv'                    => $sv['mssv'],
            'chi_doan_id'             => (int)$sv['chi_doan_id'],
            'chi_doan'                => $sv['chi_doan'],
            'khoa_id'                 => (int)$sv['khoa_id'],
            'khoa'                    => $sv['khoa'],
            'ngay_de_cu'              => $ngay_de_cu,
            'nguoi_de_cu'             => SEED_USER_ID,
            'trang_thai'              => $trang_thai,
            'tien_do_cam_tinh_dang'   => 'CHUA_THAM_GIA',
            'created_at'              => $ngay_de_cu . ' 09:00:00',
            'updated_at'              => $today . ' 00:00:00',
        ];

        // GĐ1 CHO_CHI_DOAN+: đã nộp hồ sơ
        if ($trang_thai !== 'CHO_NOP') {
            $data['so_luoc_qua_trinh'] = 'Sơ lược quá trình phấn đấu của ' . $sv['ho_ten'] . '. Tham gia tích cực các hoạt động Đoàn trong suốt khóa học, hoàn thành tốt nhiệm vụ được giao.';
            $data['mau01_file']        = fake_file('mau01_' . $sv['mssv']);
            $data['bai_cam_nhan_file'] = mt_rand(0, 1) ? fake_file('mau02_' . $sv['mssv']) : '';
        }

        // GĐ2 CHO_DOAN_KHOA+: Chi Đoàn đã biểu quyết đạt
        if (in_array($trang_thai, ['CHO_DOAN_KHOA', 'CHO_DOAN_TRUONG', 'DA_CONG_NHAN',
            'CHO_DK_GIOI_THIEU', 'CHO_DT_XAC_NHAN_GT', 'CHUYEN_GIAO_CHI_BO',
            'CHI_BO_DANG_THEO_DOI', 'CHO_DANG_UY_TRUONG_XET', 'DA_CO_QD_KET_NAP',
            'DANG_VIEN_DU_BI', 'CHO_DK_CHUYEN_DANG', 'CHO_DT_XAC_NHAN_CD', 'DANG_VIEN_CHINH_THUC'])) {
            [$ts, $dy, $tl] = bq_chi_doan_dat();
            $data['tong_so_nguoi']          = $ts;
            $data['so_luot_dong_y']         = $dy;
            $data['ty_le']                  = $tl;
            $data['bien_ban_chi_doan_file'] = fake_file('bienban_cd_' . $sv['mssv']);
            $data['nguoi_duyet_cd']         = SEED_USER_ID;
            $data['ngay_duyet_cd']          = datetime_add_days($ngay_nop_ho_so, 10, 4);
        }

        // GĐ2 CHO_DOAN_TRUONG+: Đoàn Khoa đã duyệt
        if (in_array($trang_thai, ['CHO_DOAN_TRUONG', 'DA_CONG_NHAN',
            'CHO_DK_GIOI_THIEU', 'CHO_DT_XAC_NHAN_GT', 'CHUYEN_GIAO_CHI_BO',
            'CHI_BO_DANG_THEO_DOI', 'CHO_DANG_UY_TRUONG_XET', 'DA_CO_QD_KET_NAP',
            'DANG_VIEN_DU_BI', 'CHO_DK_CHUYEN_DANG', 'CHO_DT_XAC_NHAN_CD', 'DANG_VIEN_CHINH_THUC'])) {
            [$ts, $dy, $tl] = bq_doan_khoa();
            $data['tong_so_dk']          = $ts;
            $data['so_luot_dong_y_dk']   = $dy;
            $data['ty_le_dk']            = $tl;
            $data['cong_van_dk_file']    = fake_file('congvan_dk_' . $sv['mssv']);
            $data['bien_ban_dk_file']    = fake_file('bienban_dk_' . $sv['mssv']);
            $data['nguoi_duyet_dk']      = SEED_USER_ID;
            $data['ngay_duyet_dk']       = datetime_add_days($ngay_duyet_cd, 10, 4);
            $data['uu_diem']             = $uu_diem_pool[array_rand($uu_diem_pool)];
            $data['khuyet_diem']         = $khuyet_diem_pool[array_rand($khuyet_diem_pool)];
        }

        // GĐ2 DA_CONG_NHAN+: Đoàn Trường đã ban hành QĐ
        if (in_array($trang_thai, ['DA_CONG_NHAN',
            'CHO_DK_GIOI_THIEU', 'CHO_DT_XAC_NHAN_GT', 'CHUYEN_GIAO_CHI_BO',
            'CHI_BO_DANG_THEO_DOI', 'CHO_DANG_UY_TRUONG_XET', 'DA_CO_QD_KET_NAP',
            'DANG_VIEN_DU_BI', 'CHO_DK_CHUYEN_DANG', 'CHO_DT_XAC_NHAN_CD', 'DANG_VIEN_CHINH_THUC'])) {
            $data['so_quyet_dinh']     = fake_quyet_dinh('DT');
            $data['file_qd_cong_nhan'] = fake_file('qd_dvut_' . $sv['mssv']);
            $data['ngay_cong_nhan']    = $ngay_cong_nhan;
            $data['nguoi_cong_nhan']   = SEED_USER_ID;
            $data['chi_bo_id']         = $chi_bo_id;
            $data['ghi_chu']           = 'Đoàn Trường công nhận ĐVƯT ngày ' . date('d/m/Y', strtotime($ngay_cong_nhan)) . ' — QĐ: ' . $data['so_quyet_dinh'];
        }

        // GĐ4 CHO_DK_GIOI_THIEU+: đã hoàn thành Cảm tình Đảng
        if (in_array($trang_thai, ['CHO_DK_GIOI_THIEU', 'CHO_DT_XAC_NHAN_GT', 'CHUYEN_GIAO_CHI_BO',
            'CHI_BO_DANG_THEO_DOI', 'CHO_DANG_UY_TRUONG_XET', 'DA_CO_QD_KET_NAP',
            'DANG_VIEN_DU_BI', 'CHO_DK_CHUYEN_DANG', 'CHO_DT_XAC_NHAN_CD', 'DANG_VIEN_CHINH_THUC'])) {
            $data['tien_do_cam_tinh_dang'] = 'DA_HOAN_THANH';
            $data['file_chung_nhan_ctd']   = fake_file('ctd_' . $sv['mssv']);
        }

        // GĐ4 CHO_DT_XAC_NHAN_GT+: Đoàn Khoa đã biểu quyết Giới thiệu
        if (in_array($trang_thai, ['CHO_DT_XAC_NHAN_GT', 'CHUYEN_GIAO_CHI_BO',
            'CHI_BO_DANG_THEO_DOI', 'CHO_DANG_UY_TRUONG_XET', 'DA_CO_QD_KET_NAP',
            'DANG_VIEN_DU_BI', 'CHO_DK_CHUYEN_DANG', 'CHO_DT_XAC_NHAN_CD', 'DANG_VIEN_CHINH_THUC'])) {
            [$ts, $dy, $tl] = bq_gioi_thieu();
            $data['tong_so_gt']          = $ts;
            $data['so_luot_dong_y_gt']   = $dy;
            $data['ty_le_gt']            = $tl;
            $data['bien_ban_gt_file']    = fake_file('bienban_gt_' . $sv['mssv']);
            $data['nghi_quyet_dk_file']  = fake_file('nghiquyet_dk_' . $sv['mssv']);
            $data['ngay_nghi_quyet_gt']  = $ngay_gt_dt;
            $data['ngay_gioi_thieu_dang'] = $ngay_gt_dt;
        }

        // GĐ4 CHUYEN_GIAO_CHI_BO+: ĐT đã xác nhận
        if (in_array($trang_thai, ['CHUYEN_GIAO_CHI_BO', 'CHI_BO_DANG_THEO_DOI',
            'CHO_DANG_UY_TRUONG_XET', 'DA_CO_QD_KET_NAP',
            'DANG_VIEN_DU_BI', 'CHO_DK_CHUYEN_DANG', 'CHO_DT_XAC_NHAN_CD', 'DANG_VIEN_CHINH_THUC'])) {
            // chi_bo_id đã set ở DA_CONG_NHAN
        }

        // GĐ4 CHI_BO_DANG_THEO_DOI+: Chi bộ nhận và theo dõi
        if (in_array($trang_thai, ['CHI_BO_DANG_THEO_DOI', 'CHO_DANG_UY_TRUONG_XET',
            'DA_CO_QD_KET_NAP', 'DANG_VIEN_DU_BI', 'CHO_DK_CHUYEN_DANG',
            'CHO_DT_XAC_NHAN_CD', 'DANG_VIEN_CHINH_THUC'])) {
            $data['dang_vien_phu_trach']    = $dang_vien_pool[array_rand($dang_vien_pool)];
            $data['ngay_bat_dau_theo_doi']  = $ngay_theo_doi;
        }

        // GĐ4 CHO_DANG_UY_TRUONG_XET+: Chi bộ SV đã biểu quyết
        if (in_array($trang_thai, ['CHO_DANG_UY_TRUONG_XET', 'DA_CO_QD_KET_NAP',
            'DANG_VIEN_DU_BI', 'CHO_DK_CHUYEN_DANG', 'CHO_DT_XAC_NHAN_CD', 'DANG_VIEN_CHINH_THUC'])) {
            [$ts, $dy, $tl] = bq_chi_bo();
            $data['tong_so_cb']              = $ts;
            $data['so_dong_y_cb']            = $dy;
            $data['ty_le_cb']                = $tl;
            $data['nghi_quyet_chi_bo_file']  = fake_file('nghiquyet_cb_' . $sv['mssv']);
        }

        // GĐ4 DA_CO_QD_KET_NAP+: Đảng uỷ Trường ra QĐ kết nạp
        if (in_array($trang_thai, ['DA_CO_QD_KET_NAP', 'DANG_VIEN_DU_BI',
            'CHO_DK_CHUYEN_DANG', 'CHO_DT_XAC_NHAN_CD', 'DANG_VIEN_CHINH_THUC'])) {
            $data['so_qd_ket_nap']   = fake_quyet_dinh('KN');
            $data['file_qd_ket_nap'] = fake_file('qd_ketnap_' . $sv['mssv']);
            $data['ngay_qd_ket_nap'] = $ngay_qd_ket_nap;
        }

        // GĐ4 DANG_VIEN_DU_BI+: đã có Lễ kết nạp
        if (in_array($trang_thai, ['DANG_VIEN_DU_BI', 'CHO_DK_CHUYEN_DANG',
            'CHO_DT_XAC_NHAN_CD', 'DANG_VIEN_CHINH_THUC'])) {
            $data['ngay_le_ket_nap'] = $ngay_le_ket_nap;
        }

        // GĐ5 CHO_DK_CHUYEN_DANG+: hết thời gian dự bị, chờ ĐK BQ chuyển
        if (in_array($trang_thai, ['CHO_DK_CHUYEN_DANG', 'CHO_DT_XAC_NHAN_CD', 'DANG_VIEN_CHINH_THUC'])) {
            $data['ngay_chuyen_dang'] = $ngay_bq_cd_ct;
        }

        // GĐ5 CHO_DT_XAC_NHAN_CD+: ĐK đã BQ chuyển Đảng CT
        if (in_array($trang_thai, ['CHO_DT_XAC_NHAN_CD', 'DANG_VIEN_CHINH_THUC'])) {
            [$ts, $dy, $tl] = bq_chuyen_dang_ct();
            $data['tong_so_cd_ct']        = $ts;
            $data['so_luot_dong_y_cd_ct'] = $dy;
            $data['ty_le_cd_ct']          = $tl;
            $data['bien_ban_cd_ct_file']  = fake_file('bienban_cd_ct_' . $sv['mssv']);
            $data['y_kien_dk_file']       = fake_file('ykien_dk_' . $sv['mssv']);
        }

        // TU_CHOI: bị Chi Đoàn từ chối
        if ($trang_thai === 'TU_CHOI') {
            $data['so_luoc_qua_trinh']      = 'Sơ lược quá trình phấn đấu của ' . $sv['ho_ten'] . '.';
            $data['mau01_file']             = fake_file('mau01_' . $sv['mssv']);
            [$ts, $dy, $tl] = bq_chi_doan_khong_dat();
            $data['tong_so_nguoi']          = $ts;
            $data['so_luot_dong_y']         = $dy;
            $data['ty_le']                  = $tl;
            $data['bien_ban_chi_doan_file'] = fake_file('bienban_cd_' . $sv['mssv']);
            $data['nguoi_duyet_cd']         = SEED_USER_ID;
            $data['ngay_duyet_cd']          = datetime_add_days($ngay_nop_ho_so, 10, 4);
            $data['trang_thai_truoc_tu_choi'] = 'CHO_CHI_DOAN';
            $data['ly_do_tu_choi']          = 'Không đạt tỷ lệ biểu quyết > 50% (' . $tl . '%)';
            $data['cap_tu_choi']            = 'Chi Đoàn';
            $data['ngay_tu_choi']           = datetime_add_days($ngay_nop_ho_so, 10, 4);
        }

        // TRA_VE: Đoàn Khoa trả về Chi Đoàn
        if ($trang_thai === 'TRA_VE') {
            // Đã có dữ liệu CHO_CHI_DOAN (nộp hs) + CHO_DOAN_KHOA (CD biểu quyết đạt)
            $data['so_luoc_qua_trinh']      = 'Sơ lược quá trình phấn đấu của ' . $sv['ho_ten'] . '.';
            $data['mau01_file']             = fake_file('mau01_' . $sv['mssv']);
            [$ts, $dy, $tl_cd] = bq_chi_doan_dat();
            $data['tong_so_nguoi']          = $ts;
            $data['so_luot_dong_y']         = $dy;
            $data['ty_le']                  = $tl_cd;
            $data['bien_ban_chi_doan_file'] = fake_file('bienban_cd_' . $sv['mssv']);
            $data['nguoi_duyet_cd']         = SEED_USER_ID;
            $data['ngay_duyet_cd']          = datetime_add_days($ngay_nop_ho_so, 10, 4);
            [$ts, $dy, $tl] = bq_doan_khoa();
            $data['tong_so_dk']             = $ts;
            $data['so_luot_dong_y_dk']      = $dy;
            $data['ty_le_dk']               = $tl;
            $data['cong_van_dk_file']       = fake_file('congvan_dk_' . $sv['mssv']);
            $data['bien_ban_dk_file']       = fake_file('bienban_dk_' . $sv['mssv']);
            $data['nguoi_duyet_dk']         = SEED_USER_ID;
            $data['ngay_duyet_dk']          = datetime_add_days($ngay_duyet_cd, 10, 4);
            $data['trang_thai_truoc_tu_choi'] = 'CHO_DOAN_KHOA';
            $data['ly_do_tu_choi']          = 'Thiếu tài liệu minh chứng hoạt động Đoàn, cần bổ sung trước khi trình lại.';
            $data['cap_tu_choi']            = 'Đoàn Khoa';
            $data['ngay_tu_choi']           = datetime_add_days($ngay_duyet_cd, 12, 4);
        }

        // ── INSERT ───────────────────────────────────────────────
        $result = $wpdb->insert($t_dvut, $data);
        if ($result === false) {
            log_msg("  ✗ Lỗi insert $trang_thai [{$sv['mssv']}]: " . $wpdb->last_error);
            $errors++;
        } else {
            $inserted++;
            $seeded_this++;
        }
    }

    log_msg("  ✔ $trang_thai: $seeded_this hồ sơ");
}

// ── Kết quả ───────────────────────────────────────────────────────
log_msg("\n══════════════════════════════");
log_msg("✅ Hoàn tất seed dữ liệu ĐVƯT");
log_msg("   Đã tạo: $inserted hồ sơ");
if ($errors > 0) {
    log_msg("   Lỗi  : $errors hồ sơ");
}
$total_now = (int)$wpdb->get_var("SELECT COUNT(*) FROM $t_dvut WHERE trang_thai != 'DA_HUY'");
log_msg("   Tổng hiện tại trong DB: $total_now hồ sơ");
log_msg("══════════════════════════════");

// ── Validate sau khi seed ─────────────────────────────────────────
log_msg("\n── Kiểm tra tính hợp lệ ──");

// 1. Không có hồ sơ với MSSV không tồn tại trong blackbox
$orphan = (int)$wpdb->get_var(
    "SELECT COUNT(*) FROM $t_dvut d
     WHERE NOT EXISTS (SELECT 1 FROM $t_bb b WHERE b.mssv = d.mssv)"
);
log_msg($orphan === 0 ? "  ✓ Không có MSSV lạ" : "  ✗ Có $orphan MSSV không tồn tại trong Hộp đen");

// 2. Không có hồ sơ trùng MSSV (active)
$dups = (int)$wpdb->get_var(
    "SELECT COUNT(*) FROM (
        SELECT mssv FROM $t_dvut
        WHERE trang_thai NOT IN ('DA_HUY')
        GROUP BY mssv HAVING COUNT(*) > 1
     ) x"
);
log_msg($dups === 0 ? "  ✓ Không có MSSV trùng lặp" : "  ✗ Có $dups MSSV bị trùng");

// 3. Tất cả sinh viên được seed đều thực sự đủ điều kiện (diem_tb >= 7, diem_rl >= 80)
$ineligible = (int)$wpdb->get_var(
    "SELECT COUNT(*) FROM $t_dvut d
     JOIN $t_bb b ON b.mssv = d.mssv
     WHERE d.trang_thai != 'DA_HUY'
       AND (
           b.ly_luan_chinh_tri != 'Hoàn thành'
           OR b.xep_loai_doan_vien NOT IN ('Hoàn thành xuất sắc', 'Hoàn thành tốt')
           OR b.diem_tb_tich_luy < 7.0
           OR b.diem_ren_luyen < 80
       )"
);
log_msg($ineligible === 0 ? "  ✓ Tất cả hồ sơ đều từ sinh viên đủ điều kiện" : "  ✗ Có $ineligible hồ sơ từ sinh viên KHÔNG đủ điều kiện");

// 4. Không có trạng thái DA_HUY nào lẫn vào (chỉ xuất hiện khi đề cử lại)
$da_huy_seeded = (int)$wpdb->get_var("SELECT COUNT(*) FROM $t_dvut WHERE trang_thai = 'DA_HUY'");
log_msg($da_huy_seeded === 0 ? "  ✓ Không có bản ghi DA_HUY trong dữ liệu seed" : "  ⚠ Có $da_huy_seeded bản ghi DA_HUY (bình thường nếu đã chạy đề cử lại)");

// 5. Phân bố trạng thái
log_msg("\n── Phân bố trạng thái hiện tại ──");
$dist = $wpdb->get_results(
    "SELECT trang_thai, COUNT(*) AS so_luong FROM $t_dvut WHERE trang_thai != 'DA_HUY' GROUP BY trang_thai ORDER BY so_luong DESC",
    ARRAY_A
);
foreach ($dist as $d) {
    log_msg(sprintf("  %-30s %d", $d['trang_thai'], $d['so_luong']));
}

// 6. Phân bố theo khoa
log_msg("\n── Phân bố theo Khoa ──");
$by_khoa = $wpdb->get_results(
    "SELECT khoa, COUNT(*) AS so_luong FROM $t_dvut WHERE trang_thai != 'DA_HUY' GROUP BY khoa ORDER BY so_luong DESC",
    ARRAY_A
);
foreach ($by_khoa as $r) {
    log_msg(sprintf("  %-40s %d", $r['khoa'] ?: '(empty)', $r['so_luong']));
}
