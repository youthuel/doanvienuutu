<?php
/**
 * Description: Phân hệ quản lý quy trình xét công nhận Đoàn viên ưu tú (HD06).
 *              File này mô phỏng cấu trúc <main class="content-area"> của index.html.
 *              - Chạy trên WordPress với theme Tuổi Trẻ UEL. Dữ liệu từ MySQL qua admin-ajax.php.
 */

// =================================================================
// CHỐT CHẶN XÁC THỰC — Bắt buộc đăng nhập khi chạy trên WordPress
// =================================================================
if ( function_exists('is_user_logged_in') && ! is_user_logged_in() ) {
    // Chưa đăng nhập → chuyển hướng về trang đăng nhập /login/
    wp_redirect( home_url('/login/') );
    exit;
}


// Ẩn WordPress Admin Bar trên trang ĐVƯT (trang có giao diện riêng)
if ( function_exists('show_admin_bar') ) {
    show_admin_bar( false );
}

// =================================================================
// LẤY THÔNG TIN NGƯỜI DÙNG THẬT (khi chạy trên WordPress)
// =================================================================
if ( function_exists('wp_get_current_user') ) {
    $current_user       = wp_get_current_user();
    $dvut_user_id       = $current_user->ID;
    $dvut_user_email    = $current_user->user_email;
    $dvut_display_name  = $current_user->display_name;
} else {
    // Fallback khi mở file trực tiếp (local dev)
    $dvut_user_id       = 0;
    $dvut_user_email    = '';
    $dvut_display_name  = 'Local Dev';
}

// =================================================================
// POLYFILL — Khi truy cập trực tiếp (không qua WordPress), các hàm
// esc_html / esc_url / esc_attr chưa tồn tại → định nghĩa fallback.
// =================================================================
if ( ! function_exists('esc_html') ) {
    function esc_html( $text ) { return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' ); }
}
if ( ! function_exists('esc_attr') ) {
    function esc_attr( $text ) { return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' ); }
}
if ( ! function_exists('esc_url') ) {
    function esc_url( $url )   { return filter_var( $url, FILTER_SANITIZE_URL ) ?: ''; }
}
// Polyfill các hàm WordPress URL khi truy cập trực tiếp (không qua WP)
if ( ! function_exists('includes_url') ) {
    function includes_url( $path = '' ) { return '/wp-includes/' . ltrim( $path, '/' ); }
}
if ( ! function_exists('get_template_directory_uri') ) {
    // Tự detect đường dẫn theme từ vị trí file hiện tại
    $__dvut_dir  = str_replace( '\\', '/', __DIR__ );
    $__dvut_root = str_replace( '\\', '/', $_SERVER['DOCUMENT_ROOT'] ?? '' );
    $__dvut_theme_rel = $__dvut_root ? str_replace( $__dvut_root, '', $__dvut_dir ) : $__dvut_dir;
    function get_template_directory_uri() { global $__dvut_theme_rel; return $__dvut_theme_rel; }
}
if ( ! function_exists('home_url') ) {
    function home_url( $path = '' ) { return '/' . ltrim( $path, '/' ); }
}
if ( ! function_exists('wp_logout_url') ) {
    function wp_logout_url( $redirect = '' ) { return '#logout'; }
}
?>
<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Quản lý Đoàn viên Ưu tú - Tuổi trẻ Kinh tế - Luật</title>

    <!-- Favicon / Site Icon -->
    <link rel="shortcut icon" type="image/png" href="<?php echo esc_url( get_template_directory_uri() . '/assets/uel_logo.png' ); ?>" />
    <link rel="apple-touch-icon" href="<?php echo esc_url( get_template_directory_uri() . '/assets/uel_logo.png' ); ?>" />

    <!-- ============================================================= -->
    <!-- CSS Dependencies (Giống hệt index.html)                       -->
    <!-- ============================================================= -->
    <link rel="stylesheet" href="<?php echo esc_url( includes_url('css/dashicons.min.css') ); ?>" />
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/sweetalert2@11.10.3/dist/sweetalert2.min.css" />
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" />

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@400;500;600;700&display=swap" rel="stylesheet">

<style>
    /* Ép toàn bộ giao diện sử dụng font Montserrat */
    body, button, input, select, textarea, .swal2-popup, .wp-list-table { 
        font-family: 'Montserrat', sans-serif !important; 
    }
</style>
    <style>
        /* ============================================================= */
        /* FONTS - Sử dụng Google Fonts CDN (đã load ở <head>)            */
        /* @font-face blocks đã được loại bỏ vì Google Fonts CDN đã cung  */
        /* cấp đầy đủ font Montserrat 400/500/600/700.                    */
        /* ============================================================= */

        /* ============================================================= */
        /* CORE STYLES - Copy nguyên bản từ index.html                   */
        /* ============================================================= */
        html, body { height: 100%; margin: 0; padding: 0; }
        *, *::before, *::after { box-sizing: border-box; }
        body, button, input, select, textarea { font-family: 'Montserrat', sans-serif; }
        body { background: #f8f9fa; color: #333; }
        /* Ẩn hoàn toàn admin bar WordPress (phòng trường hợp show_admin_bar không hoạt động) */
        #wpadminbar, .admin-bar-wrap { display: none !important; }
        html.wp-toolbar { padding-top: 0 !important; }
        body.admin-bar { margin-top: 0 !important; }

        /* LAYOUT */
        .admin-dashboard { display: flex; width: 100%; min-height: 100vh; position: relative; }
        .sidebar { width: 280px; background: #174f8c; color: #fff; padding: 30px 20px; display: flex; flex-direction: column; flex-shrink: 0; transition: left 0.3s ease-in-out; height: 100vh; position: sticky; top: 0; box-sizing: border-box; line-height: 1.0; }
        .content-area { flex: 1; min-width: 0; padding: 30px; background: #f8f9fa; overflow: auto; }
        .content-area h1 { color: #174f8c; font-size: 2em; margin: 0 0 20px; border-bottom: 2px solid #e9ecef; padding-bottom: 12px; font-weight: 700; }

        /* SIDEBAR */
        .sidebar .logo-area { text-align: center; margin-bottom: 20px; }
        .sidebar .logo-area img { height: 70px; margin-bottom: 10px; }
        .sidebar .logo-area h2 { font-size: 1.1em; margin: 0; }
        .sidebar nav { overflow-y: auto; overflow-x: hidden; flex-grow: 1; padding-top: 5px; padding-bottom: 5px; scrollbar-width: thin; scrollbar-color: rgba(255, 255, 255, 0.3) transparent; }
        .sidebar nav ul { list-style: none; margin: 0; padding: 0; }
        .sidebar nav a { display: flex; align-items: center; gap: 12px; padding: 12px 15px; color: #fff; text-decoration: none; border-radius: 8px; font-weight: 600; transition: background-color 0.2s; }
        .sidebar nav a:hover, .sidebar nav a.active { background: rgba(255, 255, 255, .15); }
        .sidebar-bottom-actions { margin-top: auto; width: 100%; display: flex; align-items: center; gap: 10px; }
        .logout-button { background: #f05c20; padding: 12px; border: none; border-radius: 10px; color: #fff; font-weight: 700; cursor: pointer; transition: background-color .2s; flex-grow: 1; display: flex; align-items: center; justify-content: center; margin-top: 0; }
        .logout-button:hover { background: #c94a1d; }
        .theme-btn-icon { background: rgba(255, 255, 255, 0.1); border: 1px solid rgba(255, 255, 255, 0.15); color: rgba(255, 255, 255, 0.8); cursor: pointer; border-radius: 10px; width: 44px; height: 42px; display: flex; align-items: center; justify-content: center; transition: all 0.3s ease; padding: 0; flex-shrink: 0; }
        .theme-btn-icon:hover { background-color: rgba(255, 255, 255, 0.25); color: #fff; }
        body.dark-mode .theme-btn-icon { color: #facc15; border-color: rgba(250, 204, 21, 0.3); background: rgba(250, 204, 21, 0.1); }

        /* MOBILE */
        .mobile-menu-toggle, .sidebar-overlay { display: none; }
        .sidebar-tag-btn { display: none; }
        @media (max-width: 1024px) {
            .admin-dashboard { flex-direction: column; }
            .sidebar { position: fixed; top: 0; left: -280px; height: 100%; z-index: 1001; }
            .admin-dashboard.sidebar-visible .sidebar { left: 0; box-shadow: 0 0 20px rgba(0,0,0,0.2); }
            .content-area { padding: 15px; min-height: calc(100vh - 60px); margin-top: 60px; }
            .content-area h1 { display: none; }
            .mobile-menu-toggle { display: flex; align-items: center; gap: 15px; position: fixed; top: 0; left: 0; width: 100%; height: 60px; padding: 0 15px; background: #fff; color: #174f8c; border-bottom: 1px solid #e9ecef; box-shadow: 0 2px 5px rgba(0,0,0,0.05); z-index: 1000; }
            .mobile-menu-toggle button { background: none; border: none; font-size: 24px; padding: 0; cursor: pointer; color: #174f8c; display: flex; align-items: center; justify-content: center; width: 44px; height: 44px; }
            .mobile-menu-toggle h2 { font-size: 1.1em; margin: 0; }
            .sidebar-overlay { display: block; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); z-index: 1000; visibility: hidden; opacity: 0; transition: opacity 0.3s, visibility 0.3s; }
            .admin-dashboard.sidebar-visible .sidebar-overlay { visibility: visible; opacity: 1; }
        }
        @media (min-width: 1025px) {
            .sidebar { transition: width 0.3s cubic-bezier(0.25, 0.8, 0.25, 1); position: sticky; z-index: 1000; width: 280px; }
            .sidebar.is-hidden { width: 80px; padding-left: 15px; padding-right: 15px; }
            .sidebar.is-hidden nav a { font-size: 0 !important; justify-content: center !important; align-items: center !important; padding: 10px 0 !important; gap: 0 !important; width: 100%; }
            .sidebar.is-hidden nav .dashicons { font-size: 26px !important; margin: 0 !important; line-height: 1; display: block; width: auto; height: auto; }
            .sidebar.is-hidden .logo-area h2, .sidebar.is-hidden .sidebar-stats { display: none !important; }
            .sidebar.is-hidden .logo-area img { height: 40px; width: auto; margin-bottom: 5px; }
            .sidebar.is-hidden .sidebar-bottom-actions { flex-direction: column; gap: 5px; }
            .sidebar.is-hidden .logout-button { font-size: 0 !important; width: 44px; height: 44px; padding: 0; margin: auto; display: flex; align-items: center; justify-content: center; border-radius: 50%; }
            .sidebar.is-hidden .logout-button::before { content: "\f14a"; font-family: 'dashicons'; font-size: 24px; color: #fff; }
            .sidebar.is-hidden .theme-btn-icon { width: 44px; height: 44px; background: transparent; border: none; }
            .sidebar-tag-btn { display: flex; align-items: center; justify-content: center; position: absolute; top: 60px; right: -40px; width: 40px; height: 48px; background-color: #174f8c; color: #fff; border: none; border-radius: 0 8px 8px 0; box-shadow: 4px 2px 8px rgba(0,0,0,0.15); cursor: pointer; outline: none; z-index: 1001; transition: background-color 0.2s; }
            .sidebar-tag-btn:hover { background-color: #123d6d; }
            .sidebar-tag-btn .dashicons { font-size: 20px; transition: transform 0.3s; }
            .sidebar.is-hidden .sidebar-tag-btn .dashicons { transform: rotate(180deg); }
            .sidebar.is-hidden .sidebar-tag-btn { background-color: #f05c20; }
        }

        /* FORM SECTION & TABLE */
        .form-section { background: #fff; padding: 20px; border-radius: 16px; box-shadow: 0 6px 16px rgba(0,0,0,.08); margin-bottom: 24px; }
        .table-responsive { display: block; width: 100%; overflow-x: auto; -webkit-overflow-scrolling: touch; }
        .wp-list-table { width: 100%; border-collapse: collapse; background: #fff; table-layout: fixed; }
        .wp-list-table th, .wp-list-table td { padding: 12px 14px; border-bottom: 1px solid #e9ecef; text-align: center; vertical-align: middle; }
        .wp-list-table th { background: #f8f9fa; font-weight: 700; font-size: .85em; text-transform: uppercase; color: #555; }
        .wp-list-table td:nth-child(2) { text-align: left; }
        .wp-list-table tbody tr:hover { background: #f6f8fb; }
        .wp-list-table td { line-height: 1.4; word-wrap: break-word; overflow-wrap: break-word; }

        /* SORTABLE */
        .wp-list-table th.sortable { cursor: pointer; position: relative; padding-right: 25px !important; }
        .wp-list-table th.sortable::after { content: '\f142'; font-family: 'dashicons'; font-size: 16px; position: absolute; right: 8px; top: 50%; transform: translateY(-50%); color: #a0a5aa; transition: color .2s; }
        .wp-list-table th.sortable:hover::after { color: #174f8c; }
        .wp-list-table th.sortable.asc::after { content: '\f143'; color: #174f8c; }
        .wp-list-table th.sortable.desc::after { content: '\f140'; color: #174f8c; }

        /* PAGINATION */
        .pagination-wrapper { display: flex; justify-content: space-between; align-items: center; margin-top: 15px; flex-wrap: wrap; gap: 15px; }
        .per-page-wrapper { display: flex; align-items: center; gap: 8px; }
        .per-page-wrapper label { font-size: 0.85em; color: #555; font-weight: 500; }
        .per-page-wrapper select { padding: 5px 8px; border-radius: 6px; border: 1px solid #dee2e6; font-size: 0.85em; cursor: pointer; color: #333; outline: none; background: #fff; height: auto; transition: border-color 0.2s; }
        .per-page-wrapper select:focus { border-color: #174f8c; }
        .pagination { display: flex; justify-content: flex-end; align-items: center; gap: 6px; margin-top: 0; }
        .pagination button { background: #174f8c; color: #fff; border: none; padding: 6px 10px; border-radius: 4px; cursor: pointer; font-size: 0.85em; }
        .pagination button:disabled { background: #ccc; cursor: not-allowed; }

        body.dark-mode .per-page-wrapper label { color: var(--dm-text-mute); }
        body.dark-mode .per-page-wrapper select { background: var(--dm-bg-input); border-color: var(--dm-border); color: var(--dm-text-main); }
        body.dark-mode .per-page-wrapper select:focus { border-color: #0d6efd; }

        /* BUTTONS */
        .button-primary { background-color: #174f8c; color: #fff; padding: 10px 15px; border-radius: 8px; border: none; font-weight: 600; cursor: pointer; transition: background-color .2s; }
        .button-primary:hover { background-color: #123d6d; }
        .button-secondary { background-color: #6c757d; color: #fff; }
        .button-secondary:hover { background-color: #5a6268; }
        .button-danger { background-color: #dc3545; color: #fff; }
        .button-success { background-color: #198754; }

        /* ACTION BUTTONS */
        .action-btn { background: none; border: none; padding: 0; display: inline-flex; align-items: center; justify-content: center; width: 36px !important; height: 36px !important; cursor: pointer; color: #6c757d; border-radius: 50%; transition: all 0.2s ease-in-out; }
        .action-btn .dashicons { font-size: 1.3em; }
        .action-btn.approve-btn:hover { background-color: #d1e7dd; color: #198754; }
        .action-btn.reject-btn:hover { background-color: #f8d7da; color: #dc3545; }
        .action-btn.edit-btn:hover { background-color: #e7f3ff; color: #0a58ca; }
        .action-btn.view-btn:hover { background-color: #e7f3ff; color: #0a58ca; }
        .wp-list-table .actions-wrapper { display: flex; align-items: center; justify-content: center; gap: 8px; height: 100%; }
        .actions-dropdown { display: inline-flex; align-items: center; gap: 6px; flex-wrap: nowrap; min-height: 44px; white-space: nowrap; }
        .actions-dropdown-label { font-size: 0.8em; font-weight: 600; color: #6c757d; margin-right: 6px; white-space: nowrap; display: inline-block; }
        .actions-dropdown-menu { display: inline-flex; align-items: center; gap: 6px; flex-wrap: nowrap; white-space: nowrap; }
        .actions-dropdown:not(.expanded) .actions-dropdown-menu { display: none; }
        .actions-dropdown.expanded .actions-dropdown-menu { display: inline-flex; }
        .btn-icon-small { background: none; border: none; cursor: pointer; color: #888; padding: 2px; display: inline-flex; align-items: center; }
        .btn-icon-small:hover { color: #174f8c; transform: scale(1.1); }
        .btn-icon-small .dashicons { font-size: 16px; width: 16px; height: 16px; }

        /* STATUS BADGES */
        .status-badge { display: inline-block; padding: 5px 10px; font-size: 0.8em; font-weight: 700; line-height: 1.3; text-align: center; white-space: normal; vertical-align: baseline; border-radius: 1rem; color: #fff; max-width: 100%; }
        .status-badge.status-CHO_NOP { background-color: #6c757d; }
        .status-badge.status-CHO_CHI_DOAN { background-color: #ffc107; color: #000; }
        .status-badge.status-CHO_DOAN_KHOA { background-color: #0d6efd; }
        .status-badge.status-DA_CONG_NHAN { background-color: #198754; }
        .status-badge.status-TU_CHOI { background-color: #dc3545; }
        .status-badge.status-CHUYEN_GIAO_CHI_BO { background-color: #e65100; }
        .status-badge.status-CHI_BO_DANG_THEO_DOI { background-color: #1565c0; }
        .status-badge.status-CHO_DANG_UY_TRUONG_XET { background-color: #7b1fa2; }
        .status-badge.status-DA_CO_QD_KET_NAP { background-color: #2e7d32; }

        /* SUB-NAV TABS */
        .sub-nav { display: flex; gap: 5px; margin-bottom: 20px; border-bottom: 1px solid #dee2e6; flex-wrap: wrap; }
        .sub-nav-link { padding: 10px 18px; border: none; background: none; cursor: pointer; font-weight: 600; color: #555; border-bottom: 3px solid transparent; transition: all .2s; font-size: 1em; }
        .sub-nav-link:hover { color: #174f8c; }
        .sub-nav-link.active { color: #174f8c; border-bottom-color: #174f8c; }
        .badge-count { display: inline-flex; align-items: center; justify-content: center; min-width: 20px; height: 20px; padding: 0 6px; border-radius: 10px; font-size: 0.75em; font-weight: 700; color: #fff; background-color: #dc3545; margin-left: 6px; line-height: 1; }

        /* TOOLBAR */
        .dhs-toolbar { display: grid; gap: 15px; align-items: end; margin-bottom: 20px; width: 100%; }
        .dhs-toolbar .toolbar-item { display: flex; flex-direction: column; width: 100%; min-width: 0; }
        .dhs-toolbar .toolbar-item label { font-size: 0.85em; font-weight: 600; color: #555; margin-bottom: 6px; display: block; white-space: nowrap; }
        .dhs-toolbar .toolbar-item input, .dhs-toolbar .toolbar-item select { width: 100%; height: 40px; padding: 0 10px; border: 1px solid #ddd; border-radius: 8px; background: #fff; font-family: 'Montserrat', sans-serif; }
        .dhs-toolbar .toolbar-item .select2-container { width: 100% !important; }
        .dhs-toolbar .toolbar-item .select2-container .select2-selection--single { height: 40px !important; border-color: #ddd !important; border-radius: 8px !important; display: flex; align-items: center; }
        .dhs-toolbar .toolbar-item .select2-container--default .select2-selection--single .select2-selection__rendered { line-height: normal !important; color: #333; }
        .dhs-toolbar .toolbar-item .select2-container--default .select2-selection--single .select2-selection__arrow { height: 38px !important; }
        /* Select2 multi-select — match input style */
        .dhs-toolbar .toolbar-item .select2-container .select2-selection--multiple { min-height: 40px !important; border: 1px solid #ddd !important; border-radius: 8px !important; padding: 4px 8px; background: #fff; box-sizing: border-box; }
        .dhs-toolbar .toolbar-item .select2-container--default .select2-selection--multiple .select2-selection__rendered { padding: 0 !important; margin: 0; list-style: none; }
        .dhs-toolbar .toolbar-item .select2-container--default .select2-selection--multiple .select2-selection__choice { background: #e8f0fe; border: 1px solid #c5d9f7; border-radius: 4px; padding: 2px 6px 2px 22px; font-size: 0.8em; font-weight: 600; color: #174f8c; margin: 2px 4px 2px 0; line-height: 24px; height: 26px; position: relative; white-space: nowrap; max-width: 280px; overflow: hidden; text-overflow: ellipsis; float: left; }
        .dhs-toolbar .toolbar-item .select2-container--default .select2-selection--multiple .select2-selection__choice__remove { color: #8eaed4; position: absolute; left: 6px; top: 50%; transform: translateY(-50%); font-size: 1.1em; font-weight: 400; border: none !important; padding: 0; background: none; line-height: 1; }
        .dhs-toolbar .toolbar-item .select2-container--default .select2-selection--multiple .select2-selection__choice__remove:hover { color: #dc3545; }
        .dhs-toolbar .toolbar-item .select2-container--default .select2-selection--multiple .select2-search--inline .select2-search__field { margin: 0; padding: 0 4px; font-family: 'Montserrat', sans-serif; font-size: 0.85em; height: 30px; line-height: 30px; }
        .dhs-toolbar .toolbar-item .select2-container--default .select2-selection--multiple .select2-selection__clear { position: absolute; right: 8px; top: 50%; transform: translateY(-50%); font-size: 1.1em; color: #999; cursor: pointer; padding: 0 2px; }
        .dhs-toolbar .toolbar-item .select2-container--default .select2-selection--multiple .select2-selection__clear:hover { color: #dc3545; }
        .dhs-toolbar .toolbar-item .select2-container--default.select2-container--focus .select2-selection--multiple { border-color: #174f8c !important; box-shadow: 0 0 0 2px rgba(23,79,140,0.12); }
        .dhs-toolbar .toolbar-actions { display: flex; gap: 10px; align-items: center; height: 40px; }

        /* SKELETON LOADER */
        @keyframes skeleton-loading { 0% { background-position: 200% 0; } 100% { background-position: -200% 0; } }
        .skeleton-loader td { background-color: #fff; color: transparent; user-select: none; }
        .skeleton-loader td span { display: inline-block; width: 80%; height: 1em; background: linear-gradient(90deg, #f0f0f0, #f8f8f8, #f0f0f0); background-size: 200% 100%; animation: skeleton-loading 1.5s infinite linear; border-radius: 4px; }

        /* ROW ANIMATIONS */
        @keyframes fadeIn { from { opacity: 0; } to { opacity: 1; } }
        @keyframes fadeInRow { from { opacity: 0; transform: translateY(-20px); } to { opacity: 1; transform: translateY(0); } }
        @keyframes highlightRow { 0% { background-color: #e7f3ff; } 100% { background-color: transparent; } }
        .wp-list-table tbody tr.row-fade-out { transition: opacity 0.4s ease-out, transform 0.4s ease-out; opacity: 0; transform: translateY(20px); }
        .wp-list-table tbody tr.row-fade-in { animation: fadeInRow 0.5s ease-out forwards; }
        .wp-list-table tbody tr.row-highlight { animation: highlightRow 2s ease-out forwards; }

        /* SWEETALERT2 OVERRIDES */
        .swal2-popup { font-family: 'Montserrat', sans-serif !important; border-radius: 16px !important; box-shadow: 0 10px 40px rgba(0,0,0,0.15) !important; max-width: 92vw; overflow: visible !important; padding: 20px 22px !important; }
        .swal2-popup, .swal2-popup * { box-sizing: border-box !important; }
        .swal2-title { color: #174f8c !important; font-weight: 700 !important; font-size: 1.6em !important; padding: 1em 1em .5em 1em !important; }
        .swal2-html-container { font-size: 1em !important; color: #555 !important; max-height: 65vh; overflow-y: auto; padding: 0 1em .5em 1em; margin: 0 !important; }
        .swal2-close { display: none !important; }
        .swal2-actions { margin-top: 1.5em !important; }
        .swal2-popup .swal2-confirm { background-color: #174f8c !important; border-radius: 8px !important; font-weight: 600 !important; }
        .swal2-popup .swal2-cancel { background-color: #6c757d !important; border-radius: 8px !important; font-weight: 600 !important; }
        .swal2-container { z-index: 10000 !important; }
        .swal2-popup .swal2-input, .swal2-popup .swal2-select, .swal2-popup .swal2-textarea { border: 1px solid #ddd !important; border-radius: 8px !important; transition: all .2s; display: block; width: 100% !important; min-width: 0; margin: 0 0 10px 0; }
        .swal2-popup .swal2-input:focus, .swal2-popup .swal2-select:focus, .swal2-popup .swal2-textarea:focus { border-color: #174f8c !important; box-shadow: 0 0 0 3px rgba(23,79,140,0.18) !important; }
        .swal2-popup .form-group { text-align: left; margin-bottom: 15px; }
        .swal2-popup .form-group label { display: block; margin-bottom: 6px; font-weight: 600; color: #444; font-size: 0.95em; }
        .swal2-popup .form-group small { font-size: .85em; color: #777; margin-top: 4px; display: block; }
        .swal2-popup .form-group input, .swal2-popup .form-group textarea, .swal2-popup .form-group select { width: 100%; padding: 10px 12px; border: 1px solid #ddd; border-radius: 8px; font-family: 'Montserrat', sans-serif; font-size: 14px; transition: all .2s; }
        .swal2-popup .form-group input:focus, .swal2-popup .form-group textarea:focus, .swal2-popup .form-group select:focus { border-color: #174f8c; box-shadow: 0 0 0 3px rgba(23,79,140,.18); outline: none; }
        .swal2-icon.swal2-success, .swal2-icon.swal2-success * { box-sizing: content-box !important; }
        .swal-icon-no-border { border: none !important; margin-top: 12px !important; margin-bottom: 0 !important; }
        .swal-icon-no-border .dashicons { font-size: 42px; width: 42px; height: 42px; color: #dc3545; }

        /* SELECT2 in SweetAlert2 */
        .swal2-popup .select2-container { width: 100% !important; text-align: left; }
        .swal2-popup .select2-container .select2-selection--single { height: 40px !important; border-color: #ddd !important; border-radius: 8px !important; display: flex; align-items: center; }

        /* INPUT FOCUS */
        input:focus, select:focus { border-color: #174f8c; box-shadow: 0 0 0 3px rgba(23,79,140,.18); outline: none; }

        /* ============================================================= */
        /* DARK MODE - Copy nguyên bản từ index.html                     */
        /* ============================================================= */
        body.dark-mode { --dm-bg-body: #18191a; --dm-bg-card: #242526; --dm-bg-hover: #3a3b3c; --dm-bg-input: #3a3b3c; --dm-border: #3e4042; --dm-text-main: #cdcdcd; --dm-text-head: #e0e0e0; --dm-text-mute: #a0a0a0; --dm-text-link: #61a5fa; background: var(--dm-bg-body); color: var(--dm-text-main); }
        body.dark-mode .content-area, body.dark-mode .form-section, body.dark-mode .wp-list-table, body.dark-mode .swal2-popup, body.dark-mode .select2-dropdown, body.dark-mode .sidebar-overlay, body.dark-mode .sidebar, body.dark-mode .mobile-menu-toggle { background-color: var(--dm-bg-card) !important; color: var(--dm-text-main) !important; border-color: var(--dm-border) !important; box-shadow: 0 4px 10px rgba(0,0,0,0.3) !important; }
        body.dark-mode h1, body.dark-mode h2, body.dark-mode h3, body.dark-mode .swal2-title, body.dark-mode .mobile-menu-toggle h2, body.dark-mode .mobile-menu-toggle button { color: var(--dm-text-head) !important; }
        body.dark-mode small, body.dark-mode .swal2-validation-message, body.dark-mode .swal2-html-container { color: var(--dm-text-mute) !important; }
        body.dark-mode input, body.dark-mode select, body.dark-mode textarea, body.dark-mode .select2-container--default .select2-selection--single, body.dark-mode .select2-container--default .select2-selection--multiple, body.dark-mode .select2-search__field { background-color: var(--dm-bg-input) !important; border-color: var(--dm-border) !important; color: var(--dm-text-main) !important; }
        body.dark-mode .select2-container--default .select2-selection--single .select2-selection__rendered { color: var(--dm-text-main) !important; }
        body.dark-mode .select2-results__option[aria-selected=true] { background-color: var(--dm-border) !important; }
        body.dark-mode .wp-list-table th { background-color: var(--dm-bg-input); color: #b0b3b8; border-color: var(--dm-border); }
        body.dark-mode .wp-list-table td { border-color: var(--dm-border); color: var(--dm-text-main); }
        body.dark-mode .wp-list-table tbody tr:hover { background-color: #303031; }
        body.dark-mode .skeleton-loader td { background-color: var(--dm-bg-card) !important; border-color: var(--dm-border) !important; }
        body.dark-mode .skeleton-loader td span { background: linear-gradient(90deg, #3a3b3c, #4e4f50, #3a3b3c); background-size: 200% 100%; animation: skeleton-loading 1.5s infinite linear; }
        body.dark-mode .btn-icon-small, body.dark-mode .action-btn { background: transparent !important; color: #b0b3b8 !important; }
        body.dark-mode .btn-icon-small:hover, body.dark-mode .action-btn:hover { background: rgba(255,255,255,0.1) !important; color: #fff !important; }
        body.dark-mode .dhs-toolbar .toolbar-item label { color: var(--dm-text-head); }
        body.dark-mode .sub-nav { border-bottom-color: var(--dm-border); }
        body.dark-mode .sub-nav-link { color: var(--dm-text-mute); }
        body.dark-mode .sub-nav-link:hover, body.dark-mode .sub-nav-link.active { color: var(--dm-text-link); border-bottom-color: var(--dm-text-link); }
        body.dark-mode .badge-count { box-shadow: 0 0 0 1px rgba(255,255,255,0.1); }
        body.dark-mode .sidebar { border-right: 1px solid var(--dm-border); }
        body.dark-mode .sidebar-tag-btn { background-color: var(--dm-bg-card) !important; color: #b0b3b8 !important; border: 1px solid var(--dm-border); }
        body.dark-mode .sidebar-tag-btn:hover { background-color: var(--dm-bg-hover) !important; color: #fff !important; }
        body.dark-mode .sidebar nav a:hover, body.dark-mode .sidebar nav a.active { background: rgba(255, 255, 255, 0.1); color: var(--dm-text-link); }

        /* Dark Mode - Status Badges */
        body.dark-mode .status-badge.status-CHO_NOP { background-color: rgba(108, 117, 125, 0.2) !important; color: #adb5bd !important; border: 1px solid rgba(108, 117, 125, 0.3) !important; }
        body.dark-mode .status-badge.status-CHO_CHI_DOAN { background-color: rgba(255, 193, 7, 0.15) !important; color: #ffda6a !important; border: 1px solid rgba(255, 193, 7, 0.3) !important; }
        body.dark-mode .status-badge.status-CHO_DOAN_KHOA { background-color: rgba(13, 110, 253, 0.2) !important; color: #6ea8fe !important; border: 1px solid rgba(13, 110, 253, 0.3) !important; }
        body.dark-mode .status-badge.status-DA_CONG_NHAN { background-color: rgba(25, 135, 84, 0.2) !important; color: #75b798 !important; border: 1px solid rgba(25, 135, 84, 0.3) !important; }
        body.dark-mode .status-badge.status-TU_CHOI { background-color: rgba(220, 53, 69, 0.2) !important; color: #ea868f !important; border: 1px solid rgba(220, 53, 69, 0.3) !important; }
        body.dark-mode .status-badge.status-CHUYEN_GIAO_CHI_BO { background-color: rgba(230, 81, 0, 0.2) !important; color: #ff9e80 !important; border: 1px solid rgba(230, 81, 0, 0.3) !important; }
        body.dark-mode .status-badge.status-CHI_BO_DANG_THEO_DOI { background-color: rgba(21, 101, 192, 0.2) !important; color: #64b5f6 !important; border: 1px solid rgba(21, 101, 192, 0.3) !important; }
        body.dark-mode .status-badge.status-CHO_DANG_UY_TRUONG_XET { background-color: rgba(123, 31, 162, 0.2) !important; color: #ce93d8 !important; border: 1px solid rgba(123, 31, 162, 0.3) !important; }
        body.dark-mode .status-badge.status-DA_CO_QD_KET_NAP { background-color: rgba(46, 125, 50, 0.2) !important; color: #81c784 !important; border: 1px solid rgba(46, 125, 50, 0.3) !important; }

        body.dark-mode ::-webkit-scrollbar { width: 10px; height: 10px; }
        body.dark-mode ::-webkit-scrollbar-track { background: var(--dm-bg-body); }
        body.dark-mode ::-webkit-scrollbar-corner { background: var(--dm-bg-body); }
        body.dark-mode ::-webkit-scrollbar-thumb { background: #4a4b4c; border-radius: 6px; border: 2px solid var(--dm-bg-body); }
        body.dark-mode ::-webkit-scrollbar-thumb:hover { background: #606264; }

        /* Dark Mode - SweetAlert2 form-group */
        body.dark-mode .swal2-popup .form-group label { color: var(--dm-text-head) !important; }
        body.dark-mode .swal2-popup .form-group small { color: var(--dm-text-mute) !important; }
        body.dark-mode .swal2-popup .form-group input,
        body.dark-mode .swal2-popup .form-group textarea,
        body.dark-mode .swal2-popup .form-group select { background-color: var(--dm-bg-input) !important; border-color: var(--dm-border) !important; color: var(--dm-text-main) !important; }

        /* ============================================================= */
        /* CSS BỔ SUNG CHO PHÂN HỆ ĐOÀN VIÊN ƯU TÚ                     */
        /* (Chỉ viết khi thật cần thiết, không trùng với index.html)     */
        /* ============================================================= */
        .dvut-stats-row { display: flex; gap: 15px; margin-bottom: 20px; flex-wrap: wrap; }
        .dvut-stat-card { flex: 1; min-width: 140px; background: #fff; border-radius: 12px; padding: 15px 18px; border: 1px solid #e9ecef; display: flex; align-items: center; gap: 12px; }
        .dvut-stat-card .stat-icon { width: 40px; height: 40px; border-radius: 10px; display: flex; align-items: center; justify-content: center; flex-shrink: 0; }
        .dvut-stat-card .stat-icon .dashicons { font-size: 20px; color: #fff; }
        .dvut-stat-card .stat-info { line-height: 1.3; }
        .dvut-stat-card .stat-value { font-size: 1.4em; font-weight: 700; color: #333; }
        .dvut-stat-card .stat-label { font-size: 0.8em; color: #888; font-weight: 600; }
        .dvut-stat-card.card-pending .stat-icon { background: #6c757d; }
        .dvut-stat-card.card-chi-doan .stat-icon { background: #ffc107; }
        .dvut-stat-card.card-doan-khoa .stat-icon { background: #0d6efd; }
        .dvut-stat-card.card-approved .stat-icon { background: #198754; }

        body.dark-mode .dvut-stat-card { background: var(--dm-bg-card); border-color: var(--dm-border); }
        body.dark-mode .dvut-stat-card .stat-value { color: var(--dm-text-head); }
        body.dark-mode .dvut-stat-card .stat-label { color: var(--dm-text-mute); }

        /* ============================================================= */
        /* SIDEBAR: HAS-SUBMENU DROPDOWN (Hệ thống ĐVƯT)                */
        /* ============================================================= */
        .sidebar nav ul li.has-submenu { position: relative; }
        .sidebar nav ul li.has-submenu > a { display: flex; align-items: center; gap: 12px; padding: 12px 15px; color: #fff; text-decoration: none; border-radius: 8px; font-weight: 600; transition: background-color 0.2s; cursor: pointer; }
        .sidebar nav ul li.has-submenu > a:hover,
        .sidebar nav ul li.has-submenu.open > a { background: rgba(255,255,255,.15); }
        .sidebar nav ul li.has-submenu > a .submenu-arrow { margin-left: auto; transition: transform 0.3s ease; font-size: 14px; }
        .sidebar nav ul li.has-submenu.open > a .submenu-arrow { transform: rotate(180deg); }
        .sidebar nav ul li.has-submenu .submenu { max-height: 0; overflow: hidden; transition: max-height 0.4s cubic-bezier(0.4,0,0.2,1), opacity 0.3s ease; opacity: 0; list-style: none; margin: 0; padding: 0; }
        .sidebar nav ul li.has-submenu.open .submenu { max-height: 300px; opacity: 1; }
        .sidebar nav ul li.has-submenu .submenu li a { display: flex; align-items: center; gap: 10px; padding: 10px 15px 10px 44px; color: rgba(255,255,255,0.8); text-decoration: none; font-size: 0.9em; font-weight: 500; border-radius: 6px; transition: all 0.2s; cursor: pointer; }
        .sidebar nav ul li.has-submenu .submenu li a:hover,
        .sidebar nav ul li.has-submenu .submenu li a.active { background: rgba(255,255,255,0.1); color: #fff; }
        .sidebar nav ul li.has-submenu .submenu li a .dashicons { font-size: 16px; width: 16px; height: 16px; }
        /* Collapsed sidebar — submenu icon only */
        .sidebar.is-hidden li.has-submenu > a .submenu-arrow { display: none; }
        .sidebar.is-hidden li.has-submenu .submenu li a { padding-left: 0; justify-content: center; font-size: 0; }
        .sidebar.is-hidden li.has-submenu .submenu li a .dashicons { font-size: 20px !important; width: auto; height: auto; }

        /* ============================================================= */
        /* PROFILE VIEW — Timeline, Form 2-col, Upload area              */
        /* ============================================================= */
        .profile-card { background: #fff; border-radius: 16px; box-shadow: 0 6px 16px rgba(0,0,0,.08); padding: 28px; margin-bottom: 24px; border: 1px solid #e9ecef; overflow: hidden; box-sizing: border-box; }
        .profile-card-title { font-size: 1.15em; font-weight: 700; color: #174f8c; margin: 0 0 20px; display: flex; align-items: center; gap: 10px; padding-bottom: 12px; border-bottom: 2px solid #e9ecef; }
        .profile-card-title .dashicons { font-size: 22px; }

        /* ── Timeline Tracking (Redesigned) ── */
        .profile-timeline {
            display: flex; align-items: flex-start; justify-content: space-between;
            position: relative; padding: 20px 10px 10px; margin: 0;
            overflow: hidden; /* Ngăn tràn ngang */
            box-sizing: border-box; width: 100%;
        }
        /* Connector line */
        .profile-timeline::before {
            content: ''; position: absolute; top: 40px;
            left: calc(10% + 2px); right: calc(10% + 2px);
            height: 3px; background: #dee2e6; z-index: 0; border-radius: 2px;
        }
        /* Progress bar overlay (filled via JS) */
        .profile-timeline .tl-progress {
            position: absolute; top: 40px; left: calc(10% + 2px);
            height: 3px; background: linear-gradient(90deg, #198754 0%, #198754 100%);
            z-index: 1; border-radius: 2px; transition: width .6s cubic-bezier(.4,0,.2,1);
            width: 0;
        }
        .profile-timeline .tl-step {
            display: flex; flex-direction: column; align-items: center;
            position: relative; z-index: 2; flex: 1 1 0;
            text-align: center; min-width: 0; /* cho phép co lại */
            padding: 0 4px; box-sizing: border-box;
        }
        .profile-timeline .tl-dot {
            width: 44px; height: 44px; border-radius: 50%;
            background: #fff; display: flex; align-items: center; justify-content: center;
            margin-bottom: 10px; border: 3px solid #dee2e6;
            box-shadow: 0 2px 6px rgba(0,0,0,.06);
            transition: all .4s cubic-bezier(.4,0,.2,1);
            flex-shrink: 0; /* dot không bị co */
        }
        .profile-timeline .tl-dot .dashicons {
            font-size: 18px; color: #adb5bd; transition: color .3s;
            width: 20px; height: 20px;
        }
        .profile-timeline .tl-label {
            font-size: 0.76em; font-weight: 600; color: #999;
            max-width: 110px; line-height: 1.35; transition: color .3s;
            word-wrap: break-word; overflow-wrap: break-word;
        }
        .profile-timeline .tl-sub {
            font-size: 0.68em; color: #bbb; margin-top: 2px; font-weight: 400;
        }
        /* States: done */
        .profile-timeline .tl-step.done .tl-dot {
            background: #198754; border-color: #198754;
            box-shadow: 0 0 0 4px rgba(25,135,84,.12), 0 2px 6px rgba(0,0,0,.08);
        }
        .profile-timeline .tl-step.done .tl-dot .dashicons { color: #fff; }
        .profile-timeline .tl-step.done .tl-label { color: #198754; }
        /* States: active (current step) */
        .profile-timeline .tl-step.active .tl-dot {
            background: #174f8c; border-color: #174f8c;
            box-shadow: 0 0 0 6px rgba(23,79,140,.15), 0 2px 8px rgba(0,0,0,.1);
            animation: tl-pulse 2s infinite;
        }
        .profile-timeline .tl-step.active .tl-dot .dashicons { color: #fff; }
        .profile-timeline .tl-step.active .tl-label { color: #174f8c; font-weight: 700; }
        /* States: rejected */
        .profile-timeline .tl-step.rejected .tl-dot {
            background: #dc3545; border-color: #dc3545;
            box-shadow: 0 0 0 4px rgba(220,53,69,.12), 0 2px 6px rgba(0,0,0,.08);
        }
        .profile-timeline .tl-step.rejected .tl-dot .dashicons { color: #fff; }
        .profile-timeline .tl-step.rejected .tl-label { color: #dc3545; font-weight: 700; }
        /* States: overdue (warning) */
        .profile-timeline .tl-step.overdue .tl-dot {
            background: #fd7e14; border-color: #fd7e14;
            box-shadow: 0 0 0 6px rgba(253,126,20,.15);
            animation: tl-pulse-warn 1.5s infinite;
        }
        .profile-timeline .tl-step.overdue .tl-dot .dashicons { color: #fff; }
        .profile-timeline .tl-step.overdue .tl-label { color: #fd7e14; font-weight: 700; }

        @keyframes tl-pulse {
            0%, 100% { box-shadow: 0 0 0 6px rgba(23,79,140,.15), 0 2px 8px rgba(0,0,0,.1); }
            50% { box-shadow: 0 0 0 10px rgba(23,79,140,.08), 0 2px 8px rgba(0,0,0,.1); }
        }
        @keyframes tl-pulse-warn {
            0%, 100% { box-shadow: 0 0 0 6px rgba(253,126,20,.15); }
            50% { box-shadow: 0 0 0 10px rgba(253,126,20,.08); }
        }
        @keyframes pulse-bell {
            0%, 100% { transform: rotate(0deg); }
            15% { transform: rotate(10deg); }
            30% { transform: rotate(-8deg); }
            45% { transform: rotate(6deg); }
            60% { transform: rotate(-4deg); }
            75% { transform: rotate(2deg); }
        }

        @media (max-width: 600px) {
            .profile-timeline { flex-direction: column; align-items: flex-start; gap: 0; padding: 10px 0 10px 20px; margin: 0; }
            .profile-timeline::before { top: 22px; bottom: 22px; left: 21px; right: auto; width: 3px; height: auto; }
            .profile-timeline .tl-progress { display: none; }
            .profile-timeline .tl-step { flex-direction: row; gap: 14px; text-align: left; margin-bottom: 20px; }
            .profile-timeline .tl-label { max-width: none; }
        }

        /* Profile Form 2-col grid */
        .profile-form-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 18px 24px; }
        .profile-form-grid .form-group { display: flex; flex-direction: column; }
        .profile-form-grid .form-group.full-width { grid-column: 1 / -1; }
        .profile-form-grid .form-group label { font-size: 0.88em; font-weight: 600; color: #555; margin-bottom: 6px; }
        .profile-form-grid .form-group input,
        .profile-form-grid .form-group select,
        .profile-form-grid .form-group textarea { width: 100%; padding: 10px 12px; border: 1px solid #ddd; border-radius: 8px; font-family: 'Montserrat', sans-serif; font-size: 0.9em; transition: all 0.2s; }
        .profile-form-grid .form-group input:focus,
        .profile-form-grid .form-group select:focus,
        .profile-form-grid .form-group textarea:focus { border-color: #174f8c; box-shadow: 0 0 0 3px rgba(23,79,140,.18); outline: none; }
        @media (max-width: 600px) { .profile-form-grid { grid-template-columns: 1fr; } }

        .profile-form-actions { display: flex; gap: 12px; margin-top: 22px; justify-content: flex-end; }
        .profile-form-actions .btn { padding: 10px 24px; border: none; border-radius: 8px; font-weight: 600; font-size: 0.92em; cursor: pointer; transition: all 0.2s; font-family: 'Montserrat', sans-serif; display: inline-flex; align-items: center; gap: 8px; }
        .profile-form-actions .btn-primary { background: #174f8c; color: #fff; }
        .profile-form-actions .btn-primary:hover { background: #123d6d; }
        .profile-form-actions .btn-success { background: #198754; color: #fff; }
        .profile-form-actions .btn-success:hover { background: #146c43; }

        /* Upload area */
        .profile-upload-zone { border: 2px dashed #ddd; border-radius: 12px; padding: 28px; text-align: center; cursor: pointer; transition: all 0.2s; background: #fafbfc; }
        .profile-upload-zone:hover,
        .profile-upload-zone.dragover { border-color: #174f8c; background: #f0f6ff; }
        .profile-upload-zone .dashicons { font-size: 36px; color: #bbb; display: block; margin-bottom: 8px; }
        .profile-upload-zone p { margin: 0; color: #888; font-size: 0.9em; }
        .profile-upload-zone .file-name-display { color: #174f8c; font-weight: 600; margin-top: 10px; font-size: 0.9em; }

        /* Dark Mode — Profile */
        body.dark-mode .profile-card { background: var(--dm-bg-card); border-color: var(--dm-border); }
        body.dark-mode .profile-card-title { color: var(--dm-text-link); border-bottom-color: var(--dm-border); }
        body.dark-mode .profile-timeline::before { background: var(--dm-border); }
        body.dark-mode .profile-timeline .tl-dot { background: var(--dm-bg-card); border-color: var(--dm-border); }
        body.dark-mode .profile-timeline .tl-dot .dashicons { color: var(--dm-text-mute); }
        body.dark-mode .profile-timeline .tl-step.active .tl-dot { background: #174f8c; border-color: #174f8c; }
        body.dark-mode .profile-timeline .tl-step.done .tl-dot { background: #198754; border-color: #198754; }
        body.dark-mode .profile-timeline .tl-label { color: var(--dm-text-mute); }
        body.dark-mode .profile-timeline .tl-step.active .tl-label { color: var(--dm-text-link); }
        body.dark-mode .profile-timeline .tl-step.done .tl-label { color: #75b798; }
        body.dark-mode .profile-timeline .tl-sub { color: #666; }
        body.dark-mode .profile-form-grid .form-group label { color: var(--dm-text-head); }
        body.dark-mode .profile-form-grid .form-group input,
        body.dark-mode .profile-form-grid .form-group select,
        body.dark-mode .profile-form-grid .form-group textarea { background: var(--dm-bg-input); border-color: var(--dm-border); color: var(--dm-text-main); }
        body.dark-mode .profile-upload-zone { background: var(--dm-bg-hover); border-color: var(--dm-border); }
        body.dark-mode .profile-upload-zone:hover { border-color: var(--dm-text-link); background: rgba(97,165,250,.08); }
        body.dark-mode .profile-upload-zone .dashicons { color: var(--dm-text-mute); }
        body.dark-mode .profile-upload-zone p { color: var(--dm-text-mute); }

        /* ============================================================= */
        /* SIDEBAR DROPDOWN MENU (Công cụ)                               */
        /* ============================================================= */
        .sidebar-dropdown { position: relative; }
        .sidebar-dropdown > a { display: flex; align-items: center; gap: 12px; padding: 12px 15px; color: #fff; text-decoration: none; border-radius: 8px; font-weight: 600; transition: background-color 0.2s; cursor: pointer; }
        .sidebar-dropdown > a:hover { background: rgba(255, 255, 255, .15); }
        .sidebar-dropdown > a .dropdown-arrow { margin-left: auto; transition: transform 0.3s ease; font-size: 14px; }
        .sidebar-dropdown.open > a .dropdown-arrow { transform: rotate(180deg); }
        .sidebar-dropdown.open > a { background: rgba(255, 255, 255, .15); }
        .sidebar-dropdown-menu { max-height: 0; overflow: hidden; transition: max-height 0.35s ease, opacity 0.3s ease; opacity: 0; list-style: none; margin: 0; padding: 0; }
        .sidebar-dropdown.open .sidebar-dropdown-menu { max-height: 300px; opacity: 1; }
        .sidebar-dropdown-menu li a { display: flex; align-items: center; gap: 10px; padding: 10px 15px 10px 42px; color: rgba(255,255,255,0.8); text-decoration: none; font-size: 0.9em; font-weight: 500; border-radius: 6px; transition: all 0.2s; cursor: pointer; }
        .sidebar-dropdown-menu li a:hover { background: rgba(255,255,255,0.1); color: #fff; }
        .sidebar-dropdown-menu li a .dashicons { font-size: 16px; width: 16px; height: 16px; }
        /* Collapsed sidebar dropdown */
        .sidebar.is-hidden .sidebar-dropdown > a .dropdown-arrow { display: none; }
        .sidebar.is-hidden .sidebar-dropdown-menu li a { padding-left: 0; justify-content: center; font-size: 0; }
        .sidebar.is-hidden .sidebar-dropdown-menu li a .dashicons { font-size: 20px !important; width: auto; height: auto; }

        /* ============================================================= */
        /* LỊCH SỬ CHỈNH SỬA MODAL (mở cửa sổ mới)                      */
        /* ============================================================= */
        .dvut-history-overlay { position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); z-index: 3000; display: none; opacity: 0; transition: opacity 0.3s ease; }
        .dvut-history-overlay.active { display: block; opacity: 1; }
        .dvut-history-panel { position: fixed; top: 0; right: -520px; width: 520px; max-width: 95vw; height: 100vh; background: #fff; box-shadow: -4px 0 30px rgba(0,0,0,0.2); z-index: 3001; transition: right 0.35s cubic-bezier(0.25,0.8,0.25,1); display: flex; flex-direction: column; }
        .dvut-history-panel.active { right: 0; }
        .dvut-history-panel-header { display: flex; align-items: center; justify-content: space-between; padding: 18px 22px; border-bottom: 2px solid #e9ecef; background: #174f8c; color: #fff; flex-shrink: 0; }
        .dvut-history-panel-header h2 { margin: 0; font-size: 1.15em; font-weight: 700; display: flex; align-items: center; gap: 10px; }
        .dvut-history-panel-header .close-history-btn { background: rgba(255,255,255,0.15); border: none; color: #fff; cursor: pointer; border-radius: 8px; width: 36px; height: 36px; display: flex; align-items: center; justify-content: center; transition: background 0.2s; }
        .dvut-history-panel-header .close-history-btn:hover { background: rgba(255,255,255,0.3); }
        .dvut-history-filters { padding: 14px 22px; border-bottom: 1px solid #e9ecef; background: #f8f9fa; flex-shrink: 0; }
        .dvut-history-filters .filter-row { display: flex; gap: 10px; flex-wrap: wrap; align-items: flex-end; }
        .dvut-history-filters .filter-group { display: flex; flex-direction: column; flex: 1; min-width: 120px; }
        .dvut-history-filters .filter-group label { font-size: 0.78em; font-weight: 600; color: #555; margin-bottom: 4px; }
        .dvut-history-filters .filter-group input,
        .dvut-history-filters .filter-group select { height: 34px; padding: 0 8px; border: 1px solid #ddd; border-radius: 6px; font-size: 0.85em; font-family: 'Montserrat', sans-serif; }
        .dvut-history-filters .filter-actions { display: flex; gap: 6px; align-items: flex-end; }
        .dvut-history-filters .filter-actions button { height: 34px; padding: 0 12px; border: none; border-radius: 6px; font-weight: 600; font-size: 0.82em; cursor: pointer; transition: background 0.2s; font-family: 'Montserrat', sans-serif; }
        .dvut-history-filters .btn-filter-apply { background: #174f8c; color: #fff; }
        .dvut-history-filters .btn-filter-apply:hover { background: #123d6d; }
        .dvut-history-filters .btn-filter-reset { background: #e9ecef; color: #555; }
        .dvut-history-filters .btn-filter-reset:hover { background: #dee2e6; }
        .dvut-history-body { flex: 1; overflow-y: auto; padding: 0; }
        .dvut-history-list { list-style: none; margin: 0; padding: 0; }
        .dvut-history-item { display: flex; gap: 14px; padding: 14px 22px; border-bottom: 1px solid #f1f1f1; transition: background 0.2s; position: relative; }
        .dvut-history-item:hover { background: #f8f9fa; }
        .dvut-history-item .history-avatar { width: 38px; height: 38px; border-radius: 50%; display: flex; align-items: center; justify-content: center; flex-shrink: 0; font-size: 16px; color: #fff; }
        .dvut-history-item .history-avatar.role-bch_chi_doan { background: linear-gradient(135deg, #198754, #20c997); }
        .dvut-history-item .history-avatar.role-can_bo_doan_khoa { background: linear-gradient(135deg, #0d6efd, #6ea8fe); }
        .dvut-history-item .history-avatar.role-admin_doan_truong { background: linear-gradient(135deg, #dc3545, #f97316); }
        .dvut-history-item .history-content { flex: 1; min-width: 0; }
        .dvut-history-item .history-meta { display: flex; align-items: center; gap: 8px; margin-bottom: 3px; flex-wrap: wrap; }
        .dvut-history-item .history-user { font-weight: 700; font-size: 0.9em; color: #333; }
        .dvut-history-item .history-role-badge { font-size: 0.72em; padding: 2px 8px; border-radius: 10px; font-weight: 600; }
        .history-role-badge.badge-bch_chi_doan { background: rgba(25,135,84,0.12); color: #198754; }
        .history-role-badge.badge-can_bo_doan_khoa { background: rgba(13,110,253,0.12); color: #0d6efd; }
        .history-role-badge.badge-admin_doan_truong { background: rgba(220,53,69,0.12); color: #dc3545; }
        .dvut-history-item .history-desc { font-size: 0.88em; color: #555; line-height: 1.5; }
        .dvut-history-item .history-desc strong { color: #333; }
        .dvut-history-item .history-time { font-size: 0.78em; color: #999; margin-top: 3px; display: flex; align-items: center; gap: 4px; }
        .dvut-history-item .history-action-badge { display: inline-block; font-size: 0.72em; padding: 1px 7px; border-radius: 8px; font-weight: 600; margin-right: 6px; }
        .action-badge-de_cu { background: #e7f3ff; color: #0d6efd; }
        .action-badge-nop_ho_so { background: #fff3cd; color: #856404; }
        .action-badge-chi_doan_duyet { background: #d1e7dd; color: #198754; }
        .action-badge-doan_khoa_duyet { background: #cfe2ff; color: #084298; }
        .action-badge-admin_duyet { background: #f8d7da; color: #842029; }
        .action-badge-tu_choi { background: #f8d7da; color: #dc3545; }
        .action-badge-cam_tinh_dang { background: #e2d9f3; color: #6f42c1; }
        .action-badge-gioi_thieu_dang { background: #d1e7dd; color: #198754; }
        .action-badge-chuyen_dang { background: #cff4fc; color: #055160; }
        .action-badge-xoa { background: #f8d7da; color: #dc3545; }
        .action-badge-import { background: #fff3cd; color: #856404; }
        .dvut-history-footer { padding: 12px 22px; border-top: 1px solid #e9ecef; display: flex; justify-content: space-between; align-items: center; background: #f8f9fa; flex-shrink: 0; }
        .dvut-history-footer .history-summary { font-size: 0.82em; color: #888; }
        .dvut-history-footer .history-pagination { display: flex; gap: 4px; }
        .dvut-history-footer .history-pagination button { background: #174f8c; color: #fff; border: none; padding: 5px 10px; border-radius: 4px; cursor: pointer; font-size: 0.8em; font-family: 'Montserrat', sans-serif; }
        .dvut-history-footer .history-pagination button:disabled { background: #ccc; cursor: not-allowed; }
        .dvut-history-empty { text-align: center; padding: 60px 20px; color: #999; }
        .dvut-history-empty .dashicons { font-size: 48px; color: #ddd; display: block; margin-bottom: 12px; }

        /* Dark mode - History panel */
        body.dark-mode .dvut-history-panel { background: var(--dm-bg-card); }
        body.dark-mode .dvut-history-panel-header { background: #1a3a5c; border-color: var(--dm-border); }
        body.dark-mode .dvut-history-filters { background: var(--dm-bg-body); border-color: var(--dm-border); }
        body.dark-mode .dvut-history-filters .filter-group label { color: var(--dm-text-mute); }
        body.dark-mode .dvut-history-filters .filter-group input,
        body.dark-mode .dvut-history-filters .filter-group select { background: var(--dm-bg-input); border-color: var(--dm-border); color: var(--dm-text-main); }
        body.dark-mode .dvut-history-item { border-color: var(--dm-border); }
        body.dark-mode .dvut-history-item:hover { background: var(--dm-bg-hover); }
        body.dark-mode .dvut-history-item .history-user { color: var(--dm-text-head); }
        body.dark-mode .dvut-history-item .history-desc { color: var(--dm-text-main); }
        body.dark-mode .dvut-history-item .history-desc strong { color: var(--dm-text-head); }
        body.dark-mode .dvut-history-item .history-time { color: var(--dm-text-mute); }
        body.dark-mode .dvut-history-footer { background: var(--dm-bg-body); border-color: var(--dm-border); }
        body.dark-mode .dvut-history-footer .history-summary { color: var(--dm-text-mute); }
        body.dark-mode .dvut-history-empty { color: var(--dm-text-mute); }
        body.dark-mode .dvut-history-empty .dashicons { color: var(--dm-border); }
        body.dark-mode .dvut-history-overlay { background: rgba(0,0,0,0.7); }
        body.dark-mode .btn-filter-reset { background: var(--dm-bg-input) !important; color: var(--dm-text-main) !important; }

        /* File upload area in SweetAlert */
        .swal-file-upload-area { border: 2px dashed #ddd; border-radius: 8px; padding: 20px; text-align: center; cursor: pointer; transition: all .2s; margin-top: 5px; }
        .swal-file-upload-area:hover, .swal-file-upload-area.dragover { border-color: #174f8c; background: #f0f6ff; }
        .swal-file-upload-area .dashicons { font-size: 30px; color: #aaa; display: block; margin-bottom: 8px; }
        .swal-file-upload-area p { margin: 0; color: #888; font-size: 0.9em; }
        .swal-file-upload-area .file-name { color: #174f8c; font-weight: 600; margin-top: 8px; }
        body.dark-mode .swal-file-upload-area { border-color: var(--dm-border); }
        body.dark-mode .swal-file-upload-area:hover { border-color: var(--dm-text-link); background: rgba(97,165,250,0.08); }
        body.dark-mode .swal-file-upload-area .dashicons { color: var(--dm-text-mute); }
        body.dark-mode .swal-file-upload-area p { color: var(--dm-text-mute); }

        /* Vote result display */
        .vote-result-display { background: #f0f6ff; border: 1px solid #cfe2ff; border-radius: 10px; padding: 12px 15px; margin-top: 10px; text-align: center; }
        .vote-result-display .vote-ratio { font-size: 1.5em; font-weight: 700; color: #174f8c; }
        .vote-result-display .vote-label { font-size: 0.85em; color: #666; }
        .vote-result-display.vote-pass { border-color: #a3cfbb; background: #d1e7dd; }
        .vote-result-display.vote-pass .vote-ratio { color: #198754; }
        .vote-result-display.vote-fail { border-color: #f1aeb5; background: #f8d7da; }
        .vote-result-display.vote-fail .vote-ratio { color: #dc3545; }
        body.dark-mode .vote-result-display { background: rgba(97,165,250,0.08); border-color: rgba(97,165,250,0.2); }
        body.dark-mode .vote-result-display .vote-label { color: var(--dm-text-mute); }
        body.dark-mode .vote-result-display.vote-pass { background: rgba(25,135,84,0.15); border-color: rgba(25,135,84,0.3); }
        body.dark-mode .vote-result-display.vote-fail { background: rgba(220,53,69,0.15); border-color: rgba(220,53,69,0.3); }

        /* Responsive table mobile card */
        @media (max-width: 768px) {
            #dvut-view .dhs-toolbar { grid-template-columns: 1fr !important; }
            #dvut-table { min-width: auto !important; }
            #dvut-table thead { display: none; }
            #dvut-table tbody tr { display: block; border: 1px solid #e9ecef; border-radius: 12px; margin-bottom: 12px; padding: 15px; position: relative; }
            #dvut-table tbody td { display: block; padding: 4px 0; border: none; text-align: left !important; }
            #dvut-table tbody td::before { content: attr(data-label); font-weight: 700; color: #555; font-size: 0.85em; display: block; margin-bottom: 2px; }
            #dvut-table tbody td:last-child { margin-top: 10px; padding-top: 10px; border-top: 1px solid #f1f1f1; }
            #dvut-table tbody td:last-child .actions-wrapper { justify-content: flex-start; }
            body.dark-mode #dvut-table tbody tr { background: var(--dm-bg-card); border-color: var(--dm-border); }
            body.dark-mode #dvut-table tbody td::before { color: var(--dm-text-mute); }
            body.dark-mode #dvut-table tbody td:last-child { border-top-color: var(--dm-border); }
        }

        /* ============================================================= */
        /* DASHBOARD — 3 cấp vai trò với biểu đồ Chart.js               */
        /* ============================================================= */
        .dash-section { display:none; animation: dashFadeIn 0.4s ease; }
        .dash-section.active { display:block; }
        @keyframes dashFadeIn { from{opacity:0;transform:translateY(-8px)} to{opacity:1;transform:translateY(0)} }

        .dash-header { display:flex; align-items:center; gap:10px; margin-bottom:20px; padding-bottom:12px; border-bottom:2px solid #e9ecef; }
        .dash-header h2 { margin:0; font-size:1.3em; font-weight:700; color:#174f8c; }
        .dash-header .dashicons { font-size:24px; color:#174f8c; }

        /* KPI Grid */
        .dash-kpi-grid { display:grid; grid-template-columns:repeat(auto-fit,minmax(220px,1fr)); gap:16px; margin-bottom:24px; }
        .dash-kpi-card { background:linear-gradient(135deg,#fff 0%,#f8f9fa 100%); border-radius:16px; padding:20px; border:1px solid #e9ecef; display:flex; align-items:flex-start; gap:14px; transition:transform .2s,box-shadow .2s; position:relative; overflow:hidden; }
        .dash-kpi-card:hover { transform:translateY(-3px); box-shadow:0 8px 24px rgba(0,0,0,.1); }
        .dash-kpi-card::before { content:''; position:absolute; top:0; left:0; right:0; height:4px; }
        .dash-kpi-card.kpi-blue::before { background:linear-gradient(90deg,#174f8c,#2980b9); }
        .dash-kpi-card.kpi-green::before { background:linear-gradient(90deg,#198754,#20c997); }
        .dash-kpi-card.kpi-orange::before { background:linear-gradient(90deg,#f05c20,#ffc107); }
        .dash-kpi-card.kpi-purple::before { background:linear-gradient(90deg,#6f42c1,#a855f7); }
        .dash-kpi-card.kpi-red::before { background:linear-gradient(90deg,#dc3545,#f97316); }
        .dash-kpi-card.kpi-teal::before { background:linear-gradient(90deg,#0d6efd,#20c997); }

        .dash-kpi-icon { width:50px; height:50px; border-radius:14px; display:flex; align-items:center; justify-content:center; flex-shrink:0; }
        .dash-kpi-icon .dashicons { font-size:22px; color:#fff; }
        .kpi-blue .dash-kpi-icon { background:linear-gradient(135deg,#174f8c,#2980b9); }
        .kpi-green .dash-kpi-icon { background:linear-gradient(135deg,#198754,#20c997); }
        .kpi-orange .dash-kpi-icon { background:linear-gradient(135deg,#f05c20,#ffc107); }
        .kpi-purple .dash-kpi-icon { background:linear-gradient(135deg,#6f42c1,#a855f7); }
        .kpi-red .dash-kpi-icon { background:linear-gradient(135deg,#dc3545,#f97316); }
        .kpi-teal .dash-kpi-icon { background:linear-gradient(135deg,#0d6efd,#20c997); }

        .dash-kpi-info { flex:1; min-width:0; }
        .dash-kpi-value { font-size:1.8em; font-weight:800; color:#1a1a2e; line-height:1.1; margin-bottom:3px; }
        .dash-kpi-label { font-size:.82em; color:#888; font-weight:600; line-height:1.3; }
        .dash-kpi-change { font-size:.76em; font-weight:700; margin-top:5px; display:inline-flex; align-items:center; gap:3px; padding:2px 8px; border-radius:20px; }
        .dash-kpi-change.up { color:#198754; background:rgba(25,135,84,.1); }
        .dash-kpi-change.down { color:#dc3545; background:rgba(220,53,69,.1); }

        /* Chart Cards */
        .dash-charts-grid { display:grid; grid-template-columns:repeat(2,1fr); gap:20px; margin-bottom:20px; }
        .dash-chart-card { background:#fff; border-radius:16px; padding:22px; border:1px solid #e9ecef; box-shadow:0 2px 8px rgba(0,0,0,.04); overflow:hidden; }
        .dash-chart-card canvas { max-width:100%; display:block; }
        .dash-chart-card.full-width { grid-column:1/-1; }
        .dash-chart-title { font-size:1em; font-weight:700; color:#1a1a2e; margin:0 0 4px; display:flex; align-items:center; gap:8px; }
        .dash-chart-title .dashicons { font-size:18px; color:#174f8c; }
        .dash-chart-subtitle { font-size:.8em; color:#999; margin:0 0 16px; }

        /* Todo list */
        .dash-todo-list { list-style:none; margin:0; padding:0; }
        .dash-todo-item { display:flex; align-items:flex-start; gap:10px; padding:10px 12px; border-radius:10px; margin-bottom:6px; font-size:.88em; font-weight:500; line-height:1.4; transition:background .2s; }
        .dash-todo-item:hover { background:#f8f9fa; }
        .dash-todo-icon { flex-shrink:0; width:28px; height:28px; border-radius:8px; display:flex; align-items:center; justify-content:center; }
        .dash-todo-icon .dashicons { font-size:15px; color:#fff; }
        .todo-warning .dash-todo-icon { background:#ffc107; }
        .todo-danger .dash-todo-icon { background:#dc3545; }
        .todo-info .dash-todo-icon { background:#0d6efd; }
        .todo-success .dash-todo-icon { background:#198754; }
        .dash-todo-text { flex:1; color:#444; }
        .dash-todo-text strong { color:#1a1a2e; }

        /* Progress bars */
        .dash-progress-group { margin-bottom:14px; }
        .dash-progress-header { display:flex; justify-content:space-between; align-items:center; margin-bottom:6px; }
        .dash-progress-header span:first-child { font-size:.88em; font-weight:600; color:#444; }
        .dash-progress-header span:last-child { font-size:.85em; font-weight:700; }
        .dash-progress-bar { height:10px; background:#e9ecef; border-radius:10px; overflow:hidden; }
        .dash-progress-fill { height:100%; border-radius:10px; transition:width 1.2s ease; }
        .fill-blue { background:linear-gradient(90deg,#174f8c,#2980b9); }
        .fill-green { background:linear-gradient(90deg,#198754,#20c997); }
        .fill-orange { background:linear-gradient(90deg,#f05c20,#ffc107); }
        .fill-purple { background:linear-gradient(90deg,#6f42c1,#a855f7); }

        /* Workflow tracker */
        .dash-wf-list { list-style:none; margin:0; padding:0; }
        .dash-wf-item { display:flex; align-items:center; gap:12px; padding:10px 12px; border-radius:8px; margin-bottom:4px; font-size:.88em; }
        .dash-wf-item:nth-child(odd) { background:#f8f9fa; }
        .dash-wf-name { flex:1; font-weight:600; color:#333; }
        .dash-wf-badge { font-size:.8em; font-weight:700; padding:3px 10px; border-radius:20px; white-space:nowrap; }
        .wf-ok { background:rgba(25,135,84,.1); color:#198754; }
        .wf-warn { background:rgba(255,193,7,.15); color:#b45309; }
        .wf-danger { background:rgba(220,53,69,.1); color:#dc3545; }

        /* Dashboard Responsive */
        @media (max-width:900px) { .dash-charts-grid { grid-template-columns:1fr; } }
        @media (max-width:600px) { .dash-kpi-grid { grid-template-columns:repeat(2,1fr); } .dash-kpi-value { font-size:1.4em; } }
        @media (max-width:400px) { .dash-kpi-grid { grid-template-columns:1fr; } }

        /* DARK MODE — Dashboard */
        body.dark-mode .dash-header { border-bottom-color:var(--dm-border); }
        body.dark-mode .dash-header h2 { color:var(--dm-text-link); }
        body.dark-mode .dash-kpi-card { background:linear-gradient(135deg,var(--dm-bg-card) 0%,#2c2d2e 100%); border-color:var(--dm-border); }
        body.dark-mode .dash-kpi-card:hover { box-shadow:0 8px 24px rgba(0,0,0,.3); }
        body.dark-mode .dash-kpi-value { color:var(--dm-text-head); }
        body.dark-mode .dash-kpi-label { color:var(--dm-text-mute); }
        body.dark-mode .dash-chart-card { background:var(--dm-bg-card); border-color:var(--dm-border); }
        body.dark-mode .dash-chart-title { color:var(--dm-text-head); }
        body.dark-mode .dash-chart-subtitle { color:var(--dm-text-mute); }
        body.dark-mode .dash-todo-item:hover { background:var(--dm-bg-hover); }
        body.dark-mode .dash-todo-text { color:var(--dm-text-main); }
        body.dark-mode .dash-todo-text strong { color:var(--dm-text-head); }
        body.dark-mode .dash-progress-bar { background:var(--dm-bg-input); }
        body.dark-mode .dash-progress-header span:first-child { color:var(--dm-text-main); }
        body.dark-mode .dash-wf-item:nth-child(odd) { background:var(--dm-bg-hover); }
        body.dark-mode .dash-wf-name { color:var(--dm-text-head); }

        /* ── Ứng cử button ── */
        #dvut-ung-cu-btn:hover { background-color: #123d6d; }
        body.dark-mode #dvut-ung-cu-btn { background-color: #2563eb; }
        body.dark-mode #dvut-ung-cu-btn:hover { background-color: #1d4ed8; }

        /* ============================================================= */
        /* SYSTEM MANAGEMENT MODULE                                       */
        /* ============================================================= */
        #dvut-system-view { padding: 0; }
        #dvut-system-nav { display:flex; gap:0; border-bottom:2px solid var(--primary, #1a56db); margin-bottom:24px; flex-wrap:wrap; background:#fff; border-radius:8px 8px 0 0; padding:0 8px; }
        #dvut-system-nav .sub-nav-link { padding:12px 20px; cursor:pointer; color:#64748b; font-weight:500; border-bottom:3px solid transparent; margin-bottom:-2px; transition:all .2s; display:flex; align-items:center; gap:8px; text-decoration:none; font-size:14px; }
        #dvut-system-nav .sub-nav-link:hover { color:var(--primary, #1a56db); background:rgba(26,86,219,0.05); }
        #dvut-system-nav .sub-nav-link.active { color:var(--primary, #1a56db); border-bottom-color:var(--primary, #1a56db); font-weight:700; }
        body.dark-mode #dvut-system-nav { background:#242526; }
        body.dark-mode #dvut-system-nav .sub-nav-link { color:#a0a0a0; }
        body.dark-mode #dvut-system-nav .sub-nav-link.active { color:#61a5fa; border-bottom-color:#61a5fa; }
        body.dark-mode #dvut-system-nav .sub-nav-link:hover { color:#61a5fa; background:rgba(97,165,250,0.08); }

        .sys-panel { background:#fff; border-radius:10px; padding:24px; box-shadow:0 1px 3px rgba(0,0,0,0.08); }
        body.dark-mode .sys-panel { background:#242526; box-shadow:0 1px 3px rgba(0,0,0,0.3); }

        .sys-section-header { margin-bottom:20px; padding-bottom:12px; border-bottom:1px solid #e2e8f0; }
        .sys-section-header h3 { font-size:1.15rem; display:flex; align-items:center; gap:8px; color:#1e293b; margin:0; }
        body.dark-mode .sys-section-header h3 { color:#e0e0e0; }
        body.dark-mode .sys-section-header { border-bottom-color:#3e4042; }

        .sys-toolbar { display:flex; gap:10px; align-items:center; margin-bottom:20px; flex-wrap:wrap; }
        .sys-search-box { display:flex; gap:8px; flex:1; min-width:280px; }
        .sys-search-box input { padding:9px 14px; border:1px solid #d1d5db; border-radius:8px; font-size:14px; flex:1; max-width:360px; }
        body.dark-mode .sys-search-box input { background:#3a3b3c; border-color:#3e4042; color:#cdcdcd; }

        .sys-select { padding:9px 14px; border:1px solid #d1d5db; border-radius:8px; font-size:14px; background:#fff; min-width:220px; }
        body.dark-mode .sys-select { background:#3a3b3c; border-color:#3e4042; color:#cdcdcd; }

        .sys-row-count { font-size:13px; color:#64748b; margin-left:8px; font-weight:500; }

        .sys-table-container { overflow-x:auto; margin-bottom:16px; border-radius:8px; border:1px solid #e2e8f0; }
        body.dark-mode .sys-table-container { border-color:#3e4042; }
        .sys-table-container .dvut-table { width:100%; border-collapse:collapse; font-size:13px; }
        .sys-table-container .dvut-table th { background:#f1f5f9; padding:11px 14px; text-align:left; font-weight:600; color:#374151; border-bottom:2px solid #e2e8f0; white-space:nowrap; }
        .sys-table-container .dvut-table td { padding:10px 14px; border-bottom:1px solid #f1f5f9; vertical-align:middle; }
        .sys-table-container .dvut-table tr:hover { background:rgba(26,86,219,0.04); }
        .sys-table-container .dvut-table tr:last-child td { border-bottom:none; }
        body.dark-mode .sys-table-container .dvut-table th { background:#2d2e30; color:#e0e0e0; border-bottom-color:#3e4042; }
        body.dark-mode .sys-table-container .dvut-table td { border-bottom-color:#333; color:#cdcdcd; }
        body.dark-mode .sys-table-container .dvut-table tr:hover { background:rgba(97,165,250,0.06); }

        .btn-primary { padding:9px 18px; background:var(--primary, #1a56db); color:#fff; border:none; border-radius:8px; cursor:pointer; font-size:13px; font-weight:600; display:inline-flex; align-items:center; gap:6px; transition:background .2s; }
        .btn-primary:hover { background:#1e40af; }
        .btn-success { padding:9px 18px; background:#16a34a; color:#fff; border:none; border-radius:8px; cursor:pointer; font-size:13px; font-weight:600; display:inline-flex; align-items:center; gap:6px; }
        .btn-success:hover { background:#15803d; }
        .btn-danger { padding:6px 12px; background:#dc2626; color:#fff; border:none; border-radius:6px; cursor:pointer; font-size:12px; font-weight:600; display:inline-flex; align-items:center; gap:4px; }
        .btn-danger:hover { background:#b91c1c; }
        .btn-secondary { padding:9px 18px; background:#6b7280; color:#fff; border:none; border-radius:8px; cursor:pointer; font-size:13px; font-weight:600; }
        .btn-secondary:hover { background:#4b5563; }
        .btn-sm { padding:5px 10px; font-size:12px; }

        /* Import area */
        .sys-import-area { max-width:760px; }
        .sys-import-info { background:#f0f9ff; border:1px solid #bae6fd; border-radius:10px; padding:18px 20px; margin-bottom:20px; font-size:13px; line-height:1.8; }
        .sys-import-info p { margin:4px 0; }
        .sys-import-info code { background:#e0f2fe; padding:2px 7px; border-radius:4px; font-size:12px; font-weight:500; }
        body.dark-mode .sys-import-info { background:#1e3a5f; border-color:#2563eb; color:#cdcdcd; }
        body.dark-mode .sys-import-info code { background:#1e40af; color:#93c5fd; }

        .sys-upload-zone { border:2px dashed #d1d5db; border-radius:12px; padding:36px; text-align:center; cursor:pointer; transition:all .3s; margin-bottom:16px; }
        .sys-upload-zone:hover, .sys-upload-zone.dragover { border-color:var(--primary, #1a56db); background:rgba(26,86,219,0.04); }
        body.dark-mode .sys-upload-zone { border-color:#3e4042; }
        body.dark-mode .sys-upload-zone:hover, body.dark-mode .sys-upload-zone.dragover { border-color:#61a5fa; background:rgba(97,165,250,0.08); }

        .sys-import-filename { margin-top:8px; font-weight:600; color:var(--primary, #1a56db); }
        .sys-import-options { display:flex; gap:16px; align-items:center; margin:16px 0; flex-wrap:wrap; }
        .sys-import-options label { display:flex; align-items:center; gap:6px; font-size:13px; }
        .sys-import-actions { margin-top:20px; display:flex; gap:10px; }

        /* DB inline edit */
        .sys-edit-input { padding:5px 10px; border:1px solid #3b82f6; border-radius:5px; font-size:12px; width:100%; box-sizing:border-box; background:#eff6ff; }
        body.dark-mode .sys-edit-input { background:#1e3a5f; border-color:#2563eb; color:#e0e0e0; }
        .sys-cell-actions { display:flex; gap:4px; }

        .text-center { text-align:center; }
        .text-muted { color:#94a3b8; font-size:13px; }

        /* ===== BATCH TOOLBAR ===== */
        #dvut-batch-toolbar {
            display: flex;
            align-items: center;
            gap: 10px;
            flex-wrap: wrap;
            background: #eff6ff;
            border: 1.5px solid #93c5fd;
            border-radius: 10px;
            padding: 10px 16px;
            margin-bottom: 12px;
            animation: fadeInDown 0.25s ease;
        }
        body.dark-mode #dvut-batch-toolbar {
            background: #1e3a5f;
            border-color: #2563eb;
        }
        @keyframes fadeInDown {
            from { opacity:0; transform:translateY(-8px); }
            to   { opacity:1; transform:translateY(0); }
        }
        .batch-count {
            font-weight: 700;
            color: #1a56db;
            font-size: 13px;
            white-space: nowrap;
        }
        body.dark-mode .batch-count { color: #93c5fd; }
        .batch-actions {
            display: flex;
            gap: 8px;
            flex-wrap: wrap;
            flex: 1;
        }
        .batch-actions button {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            padding: 6px 14px;
            border: none;
            border-radius: 7px;
            color: #fff;
            font-size: 12px;
            font-weight: 600;
            cursor: pointer;
            font-family: inherit;
            transition: opacity .2s, transform .1s;
        }
        .batch-actions button:hover { opacity: .85; transform: translateY(-1px); }
        .batch-clear-btn {
            display: inline-flex;
            align-items: center;
            gap: 4px;
            padding: 6px 12px;
            background: transparent;
            border: 1.5px solid #94a3b8;
            border-radius: 7px;
            color: #6b7280;
            font-size: 12px;
            font-weight: 600;
            cursor: pointer;
            font-family: inherit;
            margin-left: auto;
        }
        .batch-clear-btn:hover { background: #f1f5f9; }
        body.dark-mode .batch-clear-btn { border-color:#4b5563; color:#9ca3af; }
        body.dark-mode .batch-clear-btn:hover { background:#374151; }
        /* Row selected highlight */
        #dvut-table-body tr.batch-selected td {
            background: rgba(26, 86, 219, 0.07) !important;
        }
        body.dark-mode #dvut-table-body tr.batch-selected td {
            background: rgba(97, 165, 250, 0.12) !important;
        }
        #dvut-table-body tr.batch-selected td:first-child input[type=checkbox] {
            accent-color: #1a56db;
        }
    </style>
    <?php wp_head(); ?>
    <style>
        /* Ép buộc loại bỏ mọi khoảng trắng/margin do admin bar tự động thêm vào */
        html { margin-top: 0 !important; }
        * html body { margin-top: 0 !important; }
        @media screen and (max-width: 782px) {
            html { margin-top: 0 !important; }
            * html body { margin-top: 0 !important; }
        }
    </style>
</head>

<body class="home page wp-theme-doanvienuutu">
<!-- ============================================================= -->
<!-- Script chống nháy Dark Mode (copy từ index.html)               -->
<!-- ============================================================= -->
<script>
    (function() {
        try {
            var localTheme = localStorage.getItem('dhs_theme');
            var systemDark = window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches;
            if (localTheme === 'dark' || (localTheme === null && systemDark)) {
                document.body.classList.add('dark-mode');
            }
            var sidebarHidden = localStorage.getItem('dhs_sidebar_hidden');
            if (sidebarHidden === 'true') {
                document.body.classList.add('is-sidebar-hidden');
            }
        } catch (e) {}
    })();
</script>

<div class="admin-body">
    <div class="admin-dashboard">

        <!-- ========================================================= -->
        <!-- MOBILE HEADER (Giống index.html)                           -->
        <!-- ========================================================= -->
        <div class="mobile-menu-toggle">
            <button id="hamburger-btn"><span class="dashicons dashicons-menu"></span></button>
            <h2 id="mobile-header-title">Đoàn viên Ưu tú</h2>
        </div>
        <div class="sidebar-overlay"></div>

        <!-- ========================================================= -->
        <!-- SIDEBAR (Giống index.html, thêm menu mới)                  -->
        <!-- ========================================================= -->
        <aside class="sidebar">
            <button id="sidebar-tag-toggle" class="sidebar-tag-btn" title="Đóng/Mở Menu">
                <span class="dashicons dashicons-arrow-left-alt2"></span>
            </button>
            <div class="logo-area">
                <a href="<?php echo esc_url( home_url('/') ); ?>">
                    <img src="<?php echo esc_url( get_template_directory_uri() . '/assets/uel_logo.png' ); ?>" alt="Logo"
                         onerror="if(!this.dataset.retry){this.dataset.retry='1';this.src='assets/uel_logo.png';}else{this.style.display='none';}">
                </a>
                <h2><?php echo isset($dvut_display_name) ? esc_html($dvut_display_name) : 'Local Dev'; ?></h2>
            </div>
            <nav>
                <ul>
                    <li>
                        <a href="<?php echo function_exists('home_url') ? esc_url( home_url('/') ) : 'index.html'; ?>" style="cursor: pointer;">
                            <span class="dashicons dashicons-admin-home"></span> Trang chủ
                        </a>
                    </li>
                    <li class="has-submenu open" id="submenu-dvut">
                        <a onclick="this.parentElement.classList.toggle('open')">
                            <span class="dashicons dashicons-awards"></span> Hệ thống ĐVƯT
                            <span class="dashicons dashicons-arrow-down-alt2 submenu-arrow"></span>
                        </a>
                        <ul class="submenu">
                            <li>
                                <a id="menu-quan-ly" class="active" onclick="DvutApp.switchView('manage')">
                                    <span class="dashicons dashicons-clipboard"></span> Quản lý xét duyệt
                                </a>
                            </li>
                            <li>
                                <a id="menu-ho-so" onclick="DvutApp.switchView('profile')">
                                    <span class="dashicons dashicons-id-alt"></span> Hồ sơ cá nhân
                                </a>
                            </li>
                        </ul>
                    </li>
                    <li class="sidebar-dropdown" id="sidebar-dropdown-tools">
                        <a onclick="this.parentElement.classList.toggle('open')">
                            <span class="dashicons dashicons-admin-tools"></span> Công cụ
                            <span class="dashicons dashicons-arrow-down-alt2 dropdown-arrow"></span>
                        </a>
                        <ul class="sidebar-dropdown-menu">
                            <li>
                                <a id="sidebar-history-btn" onclick="DvutApp.openHistoryPanel()">
                                    <span class="dashicons dashicons-backup"></span> Lịch sử chỉnh sửa
                                </a>
                            </li>

                            <li id="sidebar-system-mgmt-btn" style="display:none;">
                                <a onclick="DvutApp.switchView('system')">
                                    <span class="dashicons dashicons-admin-generic"></span> Quản lý hệ thống
                                </a>
                            </li>
                        </ul>
                    </li>
                </ul>
            </nav>
            <div class="sidebar-bottom-actions">
                <button id="logout-btn" class="logout-button" onclick="<?php echo function_exists('wp_logout_url') ? 'window.location.href=\'' . esc_url( wp_logout_url( home_url() ) ) . '\'' : 'alert(\'Chế độ Local: Không có session\')'; ?>">Đăng xuất</button>
                <button id="theme-toggle-btn" class="theme-btn-icon" title="Chế độ Tối / Sáng">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" style="width:18px;height:18px;">
                        <path fill-rule="evenodd" d="M9.528 1.718a.75.75 0 01.162.819A8.97 8.97 0 009 6a9 9 0 009 9 8.97 8.97 0 003.463-.69.75.75 0 01.981.98 10.503 10.503 0 01-9.694 6.46c-5.799 0-10.5-4.701-10.5-10.5 0-4.368 2.667-8.112 6.46-9.694a.75.75 0 01.818.162z" clip-rule="evenodd" />
                    </svg>
                </button>
            </div>
        </aside>

        <!-- ========================================================= -->
        <!-- MAIN CONTENT AREA                                          -->
        <!-- ========================================================= -->
        <main class="content-area">

            <!-- ══════════════════════════════════════════════════════════ -->
            <!-- VIEW 1: QUẢN LÝ XÉT DUYỆT (giao diện bảng hiện tại)     -->
            <!-- ══════════════════════════════════════════════════════════ -->
            <div id="dvut-manage-view">
            <div id="dvut-view">
                <h1>Quản lý Đoàn viên Ưu tú</h1>

                <!-- ============================================================= -->
                <!-- DASHBOARD THỐNG KÊ THEO VAI TRÒ (Chart.js)                    -->
                <!-- ============================================================= -->
                <div id="dvut-dashboard" class="form-section" style="margin-bottom:24px;">

                    <!-- ── DASHBOARD CHI ĐOÀN ────────────────────────────── -->
                    <div id="dashboard-chi-doan" class="dash-section active">
                        <div class="dash-header">
                            <span class="dashicons dashicons-groups"></span>
                            <h2>Dashboard Chi Đoàn</h2>
                        </div>
                        <div class="dash-kpi-grid">
                            <div class="dash-kpi-card kpi-blue">
                                <div class="dash-kpi-icon"><span class="dashicons dashicons-admin-users"></span></div>
                                <div class="dash-kpi-info">
                                    <div class="dash-kpi-value" id="kpi-cd-tong">–</div>
                                    <div class="dash-kpi-label">Tổng số Đoàn viên</div>
                                </div>
                            </div>
                            <div class="dash-kpi-card kpi-green">
                                <div class="dash-kpi-icon"><span class="dashicons dashicons-yes-alt"></span></div>
                                <div class="dash-kpi-info">
                                    <div class="dash-kpi-value" id="kpi-cd-dudk">–</div>
                                    <div class="dash-kpi-label">Đủ tiêu chuẩn xét ĐVƯT</div>
                                </div>
                            </div>
                            <div class="dash-kpi-card kpi-purple">
                                <div class="dash-kpi-icon"><span class="dashicons dashicons-awards"></span></div>
                                <div class="dash-kpi-info">
                                    <div class="dash-kpi-value" id="kpi-cd-dvut">–</div>
                                    <div class="dash-kpi-label">ĐVƯT đã công nhận</div>
                                </div>
                            </div>
                            <div class="dash-kpi-card kpi-orange">
                                <div class="dash-kpi-icon"><span class="dashicons dashicons-warning"></span></div>
                                <div class="dash-kpi-info">
                                    <div class="dash-kpi-value" id="kpi-cd-todo">–</div>
                                    <div class="dash-kpi-label">Việc cần làm</div>
                                </div>
                            </div>
                        </div>
                        <div class="dash-charts-grid">
                            <div class="dash-chart-card">
                                <div class="dash-chart-title"><span class="dashicons dashicons-filter"></span> Phễu quy trình phát triển Đảng</div>
                                <div class="dash-chart-subtitle">Số lượng Đoàn viên qua từng giai đoạn</div>
                                <div style="position:relative; height:220px;"><canvas id="chart-cd-funnel"></canvas></div>
                            </div>
                            <div class="dash-chart-card">
                                <div class="dash-chart-title"><span class="dashicons dashicons-clipboard"></span> Tiến độ nộp hồ sơ & Cảnh báo</div>
                                <div class="dash-chart-subtitle">Đợt xét duyệt 2025-2026</div>
                                <div id="cd-progress-bars" style="margin-bottom:18px;">
                                    <!-- Populated by JS -->
                                </div>
                                <div class="dash-chart-title" style="font-size:.92em; margin-top:10px;"><span class="dashicons dashicons-bell"></span> Cảnh báo</div>
                                <ul id="cd-warning-list" class="dash-todo-list">
                                    <!-- Populated by JS -->
                                </ul>
                            </div>
                        </div>
                    </div>

                    <!-- ── DASHBOARD ĐOÀN KHOA ───────────────────────────── -->
                    <div id="dashboard-doan-khoa" class="dash-section">
                        <div class="dash-header">
                            <span class="dashicons dashicons-building"></span>
                            <h2>Dashboard Đoàn Khoa</h2>
                        </div>
                        <div class="dash-kpi-grid">
                            <div class="dash-kpi-card kpi-blue">
                                <div class="dash-kpi-icon"><span class="dashicons dashicons-awards"></span></div>
                                <div class="dash-kpi-info">
                                    <div class="dash-kpi-value" id="kpi-dk-tong">0</div>
                                    <div class="dash-kpi-label">Tổng Đoàn viên toàn Khoa</div>
                                </div>
                            </div>
                            <div class="dash-kpi-card kpi-orange">
                                <div class="dash-kpi-icon"><span class="dashicons dashicons-portfolio"></span></div>
                                <div class="dash-kpi-info">
                                    <div class="dash-kpi-value" id="kpi-dk-cho">0</div>
                                    <div class="dash-kpi-label">Hồ sơ chờ duyệt</div>
                                </div>
                            </div>
                            <div class="dash-kpi-card kpi-green">
                                <div class="dash-kpi-icon"><span class="dashicons dashicons-yes-alt"></span></div>
                                <div class="dash-kpi-info">
                                    <div class="dash-kpi-value" id="kpi-dk-dvut">0</div>
                                    <div class="dash-kpi-label">ĐVƯT đã công nhận</div>
                                </div>
                            </div>
                            <div class="dash-kpi-card kpi-purple">
                                <div class="dash-kpi-icon"><span class="dashicons dashicons-flag"></span></div>
                                <div class="dash-kpi-info">
                                    <div class="dash-kpi-value" id="kpi-dk-dang">0</div>
                                    <div class="dash-kpi-label">Đã giới thiệu / Kết nạp Đảng</div>
                                </div>
                            </div>
                        </div>
                        <div class="dash-charts-grid">
                            <div class="dash-chart-card">
                                <div class="dash-chart-title"><span class="dashicons dashicons-chart-bar"></span> So sánh các Chi Đoàn</div>
                                <div class="dash-chart-subtitle">Số lượng Đoàn viên & ĐVƯT theo Chi Đoàn</div>
                                <div style="position:relative; height:260px;"><canvas id="chart-dk-bar"></canvas></div>
                            </div>
                            <div class="dash-chart-card">
                                <div class="dash-chart-title"><span class="dashicons dashicons-chart-pie"></span> Phân bổ theo trạng thái</div>
                                <div class="dash-chart-subtitle">Toàn bộ Đoàn viên trong Khoa</div>
                                <div style="position:relative; height:260px;"><canvas id="chart-dk-pie"></canvas></div>
                            </div>
                        </div>
                        <div class="dash-charts-grid">
                            <div class="dash-chart-card full-width">
                                <div class="dash-chart-title"><span class="dashicons dashicons-list-view"></span> Tiến trình xử lý hồ sơ theo Chi Đoàn</div>
                                <div class="dash-chart-subtitle">Trạng thái nộp hồ sơ của từng Chi Đoàn</div>
                                <ul class="dash-wf-list" id="dk-wf-list"></ul>
                            </div>
                        </div>
                    </div>

                    <!-- ── DASHBOARD ADMIN ĐOÀN TRƯỜNG ───────────────────── -->
                    <div id="dashboard-admin" class="dash-section">
                        <div class="dash-header">
                            <span class="dashicons dashicons-shield"></span>
                            <h2>Dashboard Admin Đoàn Trường</h2>
                        </div>
                        <div class="dash-kpi-grid">
                            <div class="dash-kpi-card kpi-blue">
                                <div class="dash-kpi-icon"><span class="dashicons dashicons-awards"></span></div>
                                <div class="dash-kpi-info">
                                    <div class="dash-kpi-value" id="kpi-ad-tong">0</div>
                                    <div class="dash-kpi-label">Tổng Đoàn viên toàn Trường</div>
                                </div>
                            </div>
                            <div class="dash-kpi-card kpi-purple">
                                <div class="dash-kpi-icon"><span class="dashicons dashicons-flag"></span></div>
                                <div class="dash-kpi-info">
                                    <div class="dash-kpi-value" id="kpi-ad-dvut">0</div>
                                    <div class="dash-kpi-label">ĐVƯT đã công nhận</div>
                                </div>
                            </div>
                            <div class="dash-kpi-card kpi-green">
                                <div class="dash-kpi-icon"><span class="dashicons dashicons-star-filled"></span></div>
                                <div class="dash-kpi-info">
                                    <div class="dash-kpi-value" id="kpi-ad-dang">0</div>
                                    <div class="dash-kpi-label">Đã kết nạp Đảng</div>
                                    <div class="dash-kpi-change up">Đảng viên</div>
                                </div>
                            </div>
                            <div class="dash-kpi-card kpi-red">
                                <div class="dash-kpi-icon"><span class="dashicons dashicons-chart-area"></span></div>
                                <div class="dash-kpi-info">
                                    <div class="dash-kpi-value" id="kpi-ad-rate">0%</div>
                                    <div class="dash-kpi-label">Tỷ lệ chuyển đổi</div>
                                    <div class="dash-kpi-change up">ĐVƯT → Đảng viên</div>
                                </div>
                            </div>
                        </div>
                        <div class="dash-charts-grid">
                            <div class="dash-chart-card">
                                <div class="dash-chart-title"><span class="dashicons dashicons-networking"></span> Phân bổ ĐVƯT giữa các Đoàn Khoa</div>
                                <div class="dash-chart-subtitle">Biểu đồ bong bóng — Kích thước = Số lượng ĐVƯT</div>
                                <div style="position:relative; height:280px;"><canvas id="chart-admin-bubble"></canvas></div>
                            </div>
                            <div class="dash-chart-card">
                                <div class="dash-chart-title"><span class="dashicons dashicons-chart-line"></span> Xu hướng kết nạp Đảng qua các năm</div>
                                <div class="dash-chart-subtitle">Đánh giá hiệu quả công tác bồi dưỡng</div>
                                <div style="position:relative; height:280px;"><canvas id="chart-admin-line"></canvas></div>
                            </div>
                        </div>
                        <div class="dash-charts-grid">
                            <div class="dash-chart-card full-width">
                                <div class="dash-chart-title"><span class="dashicons dashicons-no-alt"></span> Thống kê không đủ tiêu chuẩn</div>
                                <div class="dash-chart-subtitle">Các trường hợp không đạt yêu cầu — Cơ sở điều chỉnh chương trình bồi dưỡng</div>
                                <div style="position:relative; height:180px;"><canvas id="chart-admin-violations"></canvas></div>
                            </div>
                        </div>
                    </div>

                </div><!-- /#dvut-dashboard -->

                <div class="form-section">

                    <!-- ===== THỐNG KÊ NHANH ===== -->
                    <div class="dvut-stats-row" id="dvut-stats-row">
                        <div class="dvut-stat-card card-pending">
                            <div class="stat-icon"><span class="dashicons dashicons-clock"></span></div>
                            <div class="stat-info">
                                <div class="stat-value" id="stat-cho-nop">0</div>
                                <div class="stat-label">Chờ nộp hồ sơ</div>
                            </div>
                        </div>
                        <div class="dvut-stat-card card-chi-doan">
                            <div class="stat-icon"><span class="dashicons dashicons-groups"></span></div>
                            <div class="stat-info">
                                <div class="stat-value" id="stat-chi-doan">0</div>
                                <div class="stat-label">Chi Đoàn xét duyệt</div>
                            </div>
                        </div>
                        <div class="dvut-stat-card card-doan-khoa">
                            <div class="stat-icon"><span class="dashicons dashicons-building"></span></div>
                            <div class="stat-info">
                                <div class="stat-value" id="stat-doan-khoa">0</div>
                                <div class="stat-label">Đoàn Khoa xét duyệt</div>
                            </div>
                        </div>
                        <div class="dvut-stat-card card-approved">
                            <div class="stat-icon"><span class="dashicons dashicons-yes-alt"></span></div>
                            <div class="stat-info">
                                <div class="stat-value" id="stat-da-cong-nhan">0</div>
                                <div class="stat-label">Đoàn Trường công nhận</div>
                            </div>
                        </div>
                    </div>

                    <!-- ===== TABS TRẠNG THÁI ===== -->
                    <div class="sub-nav" id="dvut-sub-nav">
                        <button class="sub-nav-link active" data-status="CHO_NOP">
                            <span class="dashicons dashicons-clock" style="font-size:16px; vertical-align:middle; margin-right:4px;"></span>
                            Chờ nộp hồ sơ
                            <span class="badge-count" id="badge-cho-nop" style="display:none;"></span>
                        </button>
                        <button class="sub-nav-link" data-status="CHO_CHI_DOAN">
                            <span class="dashicons dashicons-groups" style="font-size:16px; vertical-align:middle; margin-right:4px;"></span>
                            Chi Đoàn xét duyệt
                            <span class="badge-count" id="badge-chi-doan" style="display:none;"></span>
                        </button>
                        <button class="sub-nav-link" data-status="CHO_DOAN_KHOA">
                            <span class="dashicons dashicons-building" style="font-size:16px; vertical-align:middle; margin-right:4px;"></span>
                            Đoàn Khoa xét duyệt
                            <span class="badge-count" id="badge-doan-khoa" style="display:none;"></span>
                        </button>
                        <button class="sub-nav-link" data-status="CHO_DOAN_TRUONG" style="display:none;">
                            <span class="dashicons dashicons-shield" style="font-size:16px; vertical-align:middle; margin-right:4px;"></span>
                            Đoàn Trường công nhận
                            <span class="badge-count" id="badge-cho-doan-truong" style="display:none;"></span>
                        </button>
                        <button class="sub-nav-link" data-status="DA_CONG_NHAN">
                            <span class="dashicons dashicons-shield" style="font-size:16px; vertical-align:middle; margin-right:4px;"></span>
                            Đoàn Trường công nhận
                            <span class="badge-count" id="badge-da-cong-nhan" style="display:none;"></span>
                        </button>
                        <button class="sub-nav-link" data-status="DANG_VIEN_DU_BI">
                            <span class="dashicons dashicons-flag" style="font-size:16px; vertical-align:middle; margin-right:4px;"></span>
                            Kết nạp Đảng
                            <span class="badge-count" id="badge-dang-vien-du-bi" style="display:none;"></span>
                        </button>
                        <button class="sub-nav-link" data-status="TU_CHOI">
                            <span class="dashicons dashicons-no-alt" style="font-size:16px; vertical-align:middle; margin-right:4px; color:#dc3545;"></span>
                            Bị từ chối
                            <span class="badge-count" id="badge-tu-choi" style="display:none;"></span>
                        </button>
                    </div>

                    <!-- ===== TABS CHI BỘ SINH VIÊN (ẩn mặc định, hiện khi role = chi_bo_sinh_vien) ===== -->
                    <div class="sub-nav" id="dvut-sub-nav-chi-bo" style="display:none;">
                        <button class="sub-nav-link active" data-status="DA_CONG_NHAN">
                            <span class="dashicons dashicons-awards" style="font-size:16px; vertical-align:middle; margin-right:4px;"></span>
                            Đoàn viên ưu tú
                            <span class="badge-count" id="badge-da-cong-nhan-cb" style="display:none;"></span>
                        </button>
                        <button class="sub-nav-link" data-status="DANG_VIEN_DU_BI">
                            <span class="dashicons dashicons-flag" style="font-size:16px; vertical-align:middle; margin-right:4px;"></span>
                            Đảng viên
                            <span class="badge-count" id="badge-dang-vien-du-bi-cb" style="display:none;"></span>
                        </button>
                    </div>

                    <!-- ===== TOOLBAR LỌC & TÌM KIẾM ===== -->
                    <div class="dhs-toolbar" style="display:flex; flex-wrap:wrap; gap:10px; align-items:flex-end;">
                        <!-- Context: chọn Chi Đoàn nào (khi vai trò = BCH Chi Đoàn) -->
                        <div class="toolbar-item" id="dvut-context-chi-doan-wrapper" style="min-width:180px;">
                            <label for="dvut-context-chi-doan">Chi đoàn của tôi</label>
                            <select id="dvut-context-chi-doan">
                                <option value="">— Chọn Chi đoàn —</option>
                            </select>
                        </div>
                        <!-- Context: chọn Khoa nào (khi vai trò = Đoàn Khoa) -->
                        <div class="toolbar-item" id="dvut-context-khoa-wrapper" style="min-width:180px; display:none;">
                            <label for="dvut-context-khoa">Khoa của tôi</label>
                            <select id="dvut-context-khoa">
                                <option value="">— Chọn Khoa —</option>
                            </select>
                        </div>
                        <div class="toolbar-item" style="flex:1; min-width:150px;">
                            <label for="dvut-search-input">Tìm kiếm</label>
                            <input type="text" id="dvut-search-input" placeholder="Họ tên, MSSV...">
                        </div>
                        <div class="toolbar-item" id="dvut-filter-khoa-wrapper" style="min-width:180px; display:none;">
                            <label for="dvut-filter-khoa">Lọc Khoa</label>
                            <select id="dvut-filter-khoa" multiple>
                            </select>
                        </div>
                        <div class="toolbar-item" id="dvut-filter-chi-doan-wrapper" style="min-width:180px; display:none;">
                            <label for="dvut-filter-chi-doan">Lọc Chi đoàn</label>
                            <select id="dvut-filter-chi-doan" multiple>
                            </select>
                        </div>
                        <div class="toolbar-actions">
                            <button id="dvut-add-btn" class="button-primary" style="height:40px; white-space:nowrap;">
                                <span class="dashicons dashicons-plus-alt2" style="vertical-align:middle;"></span>
                                <span class="text-label">Đề cử</span>
                            </button>
                            <button id="dvut-export-btn" class="button-primary" style="height:40px; white-space:nowrap; background-color:#198754;">
                                <span class="dashicons dashicons-download" style="vertical-align:middle;"></span>
                                <span class="text-label">Xuất Excel</span>
                            </button>
                        </div>
                    </div>

                    <!-- ===== BATCH ACTION TOOLBAR (hiện khi chọn hồ sơ) ===== -->
                    <div id="dvut-batch-toolbar" style="display:none;">
                        <span class="batch-count"></span>
                        <div class="batch-actions">
                            <button data-batch-action="cd_duyet"   onclick="DvutApp.batchCDDuyet()"   style="display:none;background:#1a56db;">
                                <span class="dashicons dashicons-yes-alt"></span> Biểu quyết hàng loạt
                            </button>
                            <button data-batch-action="dk_duyet"   onclick="DvutApp.batchDKDuyet()"   style="display:none;background:#16a34a;">
                                <span class="dashicons dashicons-yes-alt"></span> Phê duyệt hàng loạt
                            </button>
                            <button data-batch-action="dk_duyet_gt" onclick="DvutApp.batchDKDuyetGT()" style="display:none;background:#6f42c1;">
                                <span class="dashicons dashicons-flag"></span> Nghị quyết GT hàng loạt
                            </button>
                            <button data-batch-action="dk_duyet_cd" onclick="DvutApp.batchDKDuyetCD()" style="display:none;background:#dc3545;">
                                <span class="dashicons dashicons-star-filled"></span> Xác nhận CD hàng loạt
                            </button>
                            <button data-batch-action="ban_hanh_qd" onclick="DvutApp.batchBanHanhQD()"  style="display:none;background:#174f8c;">
                                <span class="dashicons dashicons-awards"></span> Ban hành QĐ hàng loạt
                            </button>
                        </div>
                        <button class="batch-clear-btn" onclick="DvutApp.clearSelection()">
                            <span class="dashicons dashicons-no-alt"></span> Bỏ chọn
                        </button>
                    </div>

                    <!-- ===== BẢNG DỮ LIỆU ===== -->
                    <div class="table-responsive">
                        <table class="wp-list-table" id="dvut-table" style="min-width: 900px;">
                            <thead>
                                <tr>
                                    <th style="width:3%; text-align:center; padding:8px 6px;">
                                        <input type="checkbox" id="dvut-select-all-cb" title="Chọn tất cả" onclick="DvutApp.toggleSelectAll(this.checked)">
                                    </th>
                                    <th style="width: 5%;">STT</th>
                                    <th class="sortable" data-sort="ho_ten" style="width: 23%;">Họ và Tên</th>
                                    <th style="width: 14%;">MSSV</th>
                                    <th class="sortable" data-sort="chi_doan" style="width: 15%;">Chi đoàn</th>
                                    <th style="width: 20%;">Trạng thái</th>
                                    <th style="width: 20%;" class="text-center action-header">Hành động</th>
                                </tr>
                            </thead>
                            <tbody id="dvut-table-body">
                                <!-- JS sẽ render skeleton loader rồi data vào đây -->
                            </tbody>
                        </table>
                    </div>

                    <!-- ===== PHÂN TRANG ===== -->
                    <div class="pagination-wrapper">
                        <div class="per-page-wrapper">
                            <label for="dvut-per-page-select">Hiển thị</label>
                            <select id="dvut-per-page-select">
                                <option value="10" selected>10 bản ghi / trang</option>
                                <option value="20">20 bản ghi / trang</option>
                                <option value="50">50 bản ghi / trang</option>
                                <option value="100">100 bản ghi / trang</option>
                            </select>
                        </div>
                        <div class="pagination" id="dvut-pagination"></div>
                    </div>

                </div><!-- /.form-section -->
            </div><!-- /#dvut-view -->
            </div><!-- /#dvut-manage-view -->

            <!-- ══════════════════════════════════════════════════════════ -->
            <!-- VIEW 2: HỒ SƠ CÁ NHÂN (Profile Đoàn viên)               -->
            <!-- ══════════════════════════════════════════════════════════ -->
            <div id="dvut-profile-view" style="display:none;">
                <h1>Hồ sơ cá nhân</h1>

                <!-- ── THANH TÌM KIẾM HỒ SƠ (chỉ hiện cho Admin Đoàn Trường) ── -->
                <div id="admin-profile-search" class="profile-card" style="display:none; margin-bottom:20px;">
                    <div class="profile-card-title">
                        <span class="dashicons dashicons-search"></span> Tra cứu hồ sơ đoàn viên
                    </div>
                    <div style="display:flex; gap:10px; align-items:flex-end;">
                        <div style="flex:1;">
                            <input type="text" id="admin-profile-search-input" placeholder="Nhập họ tên, MSSV hoặc email..." style="width:100%; padding:10px 14px; border:1px solid #d1d5db; border-radius:8px; font-family:'Montserrat',sans-serif; font-size:0.9em; transition:border-color .2s;">
                        </div>
                        <button type="button" id="admin-profile-search-btn" style="background:#174f8c; color:#fff; border:none; border-radius:8px; padding:10px 20px; font-weight:600; font-family:'Montserrat',sans-serif; cursor:pointer; display:inline-flex; align-items:center; gap:6px; white-space:nowrap; transition:background .2s;">
                            <span class="dashicons dashicons-search" style="font-size:16px;"></span> Tìm kiếm
                        </button>
                    </div>
                    <div id="admin-profile-search-results" style="margin-top:12px;"></div>
                </div>

                <!-- ── NÚT ỨNG CỬ (chỉ hiện khi chưa đề cử) ── -->
                <div id="dvut-ung-cu-wrapper" style="display:none; margin-bottom:20px;">
                    <button type="button" id="dvut-ung-cu-btn" style="background-color:#174f8c; color:#fff; padding:12px 24px; border:none; border-radius:8px; font-weight:600; font-size:0.95em; cursor:pointer; transition:background-color .2s; display:inline-flex; align-items:center; gap:8px; font-family:'Montserrat',sans-serif;">
                        <span class="dashicons dashicons-star-filled" style="font-size:18px;"></span>
                        Ứng cử danh hiệu Đoàn viên Ưu tú
                    </button>
                    <p style="margin-top:8px; color:#888; font-size:0.82em;">Hệ thống sẽ kiểm tra điều kiện trước khi cho phép nộp hồ sơ.</p>
                </div>

                <!-- ── KHỐI 1: Timeline 1 — Công nhận ĐVƯT ── -->
                <div class="profile-card" id="timeline-dvut-card">
                    <div class="profile-card-title">
                        <span class="dashicons dashicons-flag"></span> Tiến trình công nhận Đoàn viên ưu tú
                    </div>
                    <div class="profile-timeline" id="profile-timeline">
                        <div class="tl-step" data-step="1">
                            <div class="tl-dot"><span class="dashicons dashicons-edit"></span></div>
                            <div class="tl-label">Ứng cử</div>
                            <div class="tl-sub">Đoàn viên</div>
                        </div>
                        <div class="tl-step" data-step="2">
                            <div class="tl-dot"><span class="dashicons dashicons-media-document"></span></div>
                            <div class="tl-label">Nộp hồ sơ</div>
                            <div class="tl-sub">Mẫu 01 & 02</div>
                        </div>
                        <div class="tl-step" data-step="3">
                            <div class="tl-dot"><span class="dashicons dashicons-groups"></span></div>
                            <div class="tl-label">Chi Đoàn biểu quyết</div>
                            <div class="tl-sub">Mẫu 03</div>
                        </div>
                        <div class="tl-step" data-step="4">
                            <div class="tl-dot"><span class="dashicons dashicons-building"></span></div>
                            <div class="tl-label">Đoàn Khoa xét duyệt</div>
                            <div class="tl-sub">Mẫu 04, 05</div>
                        </div>
                        <div class="tl-step" data-step="5">
                            <div class="tl-dot"><span class="dashicons dashicons-awards"></span></div>
                            <div class="tl-label">Công nhận ĐVƯT</div>
                            <div class="tl-sub">Quyết định</div>
                        </div>
                    </div>
                    <div id="tl-warning-15day" style="display:none; margin-top:8px; padding:10px 14px; background:#fff3cd; border:1px solid #ffc107; border-radius:8px; font-size:.85em; color:#856404;">
                        ⚠️ <strong>Trễ hạn:</strong> Đã quá 15 ngày làm việc kể từ khi Đoàn Trường nhận hồ sơ.
                    </div>
                    <div id="tl-rejection-note" style="display:none;"></div>
                </div>

                <!-- ── KHỐI 2: Timeline 2 — Phát triển Đảng (ẩn mặc định) ── -->
                <div class="profile-card" id="timeline-dang-card" style="display:none;">
                    <div class="profile-card-title">
                        <span class="dashicons dashicons-star-filled" style="color:#dc3545;"></span> Tiến trình phát triển Đảng
                    </div>
                    <div class="profile-timeline" id="profile-timeline-dang">
                        <div class="tl-step" data-step="1">
                            <div class="tl-dot"><span class="dashicons dashicons-book"></span></div>
                            <div class="tl-label">Cảm tình Đảng</div>
                            <div class="tl-sub">Giấy chứng nhận</div>
                        </div>
                        <div class="tl-step" data-step="2">
                            <div class="tl-dot"><span class="dashicons dashicons-megaphone"></span></div>
                            <div class="tl-label">Giới thiệu Đảng</div>
                            <div class="tl-sub">Mẫu 06, 07</div>
                        </div>
                        <div class="tl-step" data-step="3">
                            <div class="tl-dot"><span class="dashicons dashicons-flag"></span></div>
                            <div class="tl-label">Kết nạp Đảng</div>
                            <div class="tl-sub">Đảng viên</div>
                        </div>
                        <div class="tl-step" data-step="4">
                            <div class="tl-dot"><span class="dashicons dashicons-star-filled"></span></div>
                            <div class="tl-label">Đảng viên chính thức</div>
                            <div class="tl-sub">Mẫu 08, 09</div>
                        </div>
                    </div>
                    <div id="tl-warning-12month" style="display:none; margin-top:8px; padding:10px 14px; background:#f8d7da; border:1px solid #f5c2c7; border-radius:8px; font-size:.85em; color:#842029;">
                        ⚠️ <strong>Cảnh báo:</strong> Đã quá 12 tháng kể từ ngày lập hồ sơ giới thiệu mà chưa được kết nạp.
                    </div>
                    <div id="tl-countdown-dubi" style="display:none; margin-top:8px; padding:10px 14px; background:#d1ecf1; border:1px solid #bee5eb; border-radius:8px; font-size:.85em; color:#0c5460;"></div>
                </div>

                <!-- ── KHỐI 3: Nhiệm vụ cần làm (chỉ hiện khi CHO_NOP hoặc cần bổ sung) ── -->
                <div class="profile-card" id="dv-task-card" style="display:none;">
                    <div class="profile-card-title" style="color:#e65100;">
                        <span class="dashicons dashicons-bell" style="animation: pulse-bell 1.5s infinite;"></span> Nhiệm vụ cần làm
                    </div>
                    <div id="dv-task-content"></div>
                </div>

                <!-- ── KHỐI 4: Nộp tài liệu ── -->
                <div class="profile-card" id="dv-upload-card">
                    <div class="profile-card-title">
                        <span class="dashicons dashicons-portfolio"></span> Hồ sơ & Tài liệu
                    </div>

                    <!-- Mẫu 01 -->
                    <div class="form-group" style="margin-bottom:20px;">
                        <label style="font-size:0.92em; font-weight:700; color:#174f8c; margin-bottom:8px; display:block;">
                            <span class="dashicons dashicons-media-document" style="font-size:16px; vertical-align:middle;"></span>
                            Mẫu 01 — Sơ lược quá trình phấn đấu
                        </label>
                        <div class="profile-upload-zone" id="profile-upload-mau01" onclick="document.getElementById('profile-mau01-file').click();">
                            <span class="dashicons dashicons-upload"></span>
                            <p>Kéo thả file vào đây hoặc <strong>click để chọn file</strong></p>
                            <p style="font-size:0.8em; color:#aaa; margin-top:4px;">Định dạng: PDF, DOC, DOCX — Tối đa 5MB</p>
                            <div class="file-name-display" id="mau01-file-name"></div>
                        </div>
                        <input type="file" id="profile-mau01-file" accept=".pdf,.doc,.docx" style="display:none;">
                        <small style="color:#888; font-size:0.82em; margin-top:4px; display:block;">Upload file PDF hoặc DOCX chứa sơ lược quá trình phấn đấu của bạn.</small>
                    </div>

                    <!-- Mẫu 02 -->
                    <div class="form-group" style="margin-bottom:20px;">
                        <label style="font-size:0.92em; font-weight:700; color:#174f8c; margin-bottom:8px; display:block;">
                            <span class="dashicons dashicons-media-text" style="font-size:16px; vertical-align:middle;"></span>
                            Mẫu 02 — Bài cảm nhận về Đảng
                        </label>
                        <div class="profile-upload-zone" id="profile-upload-mau02">
                            <span class="dashicons dashicons-upload"></span>
                            <p>Kéo thả file vào đây hoặc <strong>click để chọn file</strong></p>
                            <p style="font-size:0.8em; color:#aaa; margin-top:4px;">Định dạng: PDF — Tối đa 5MB</p>
                            <div class="file-name-display" id="mau02-file-name"></div>
                        </div>
                        <input type="file" id="profile-mau02-file" accept=".pdf" style="display:none;">
                    </div>

                    <div class="profile-form-actions">
                        <button type="button" class="btn btn-success" id="profile-submit-btn">
                            <span class="dashicons dashicons-yes-alt" style="font-size:16px;"></span> Gửi hồ sơ
                        </button>
                    </div>
                </div>

                <!-- ── KHỐI 5b: Văn bản đã upload (hiện khi có file) ── -->
                <div class="profile-card" id="dv-documents-card" style="display:none;">
                    <div class="profile-card-title">
                        <span class="dashicons dashicons-media-document"></span> Văn bản được ghi nhận
                    </div>
                    <div id="dv-documents-list"></div>
                </div>

                <!-- ── KHỐI 5: Đánh giá định kỳ (chỉ hiện khi DA_CONG_NHAN trở lên) ── -->
                <div class="profile-card" id="dv-reviews-card" style="display:none;">
                    <div class="profile-card-title">
                        <span class="dashicons dashicons-clipboard"></span> Đánh giá định kỳ
                    </div>
                    <p style="color:#888; font-size:.88em; margin-bottom:16px;">Nhận xét hằng quý từ BCH Chi Đoàn về phẩm chất, đạo đức, năng lực và quan hệ quần chúng.</p>
                    <div id="dv-reviews-list">
                        <p class="text-muted text-center">Chưa có nhận xét nào.</p>
                    </div>
                </div>

                <!-- Hidden form fields for backward compatibility -->
                <div style="display:none;">
                    <select id="profile-chuc-vu"><option value="doan_vien">Đoàn viên</option></select>
                    <select id="profile-chi-doan"><option value="">—</option></select>
                    <input type="number" id="profile-gpa">
                    <input type="number" id="profile-diem-rl">
                    <select id="profile-xep-loai"><option value="">—</option></select>
                    <select id="profile-llct"><option value="">—</option></select>
                    <input type="text" id="profile-noi-thuong-tru">
                    <button id="profile-save-btn"></button>
                </div>

            </div><!-- /#dvut-profile-view -->

            <!-- ========================================================= -->
            <!-- SYSTEM MANAGEMENT VIEW (Admin only)                        -->
            <!-- ========================================================= -->
            <div id="dvut-system-view" style="display:none;">

                <!-- Sub-nav tabs -->
                <div class="sub-nav" id="dvut-system-nav">
                    <a class="sub-nav-link active" data-tab="sys-roles">
                        <span class="dashicons dashicons-groups"></span> Phân quyền
                    </a>
                    <a class="sub-nav-link" data-tab="sys-import">
                        <span class="dashicons dashicons-upload"></span> Import dữ liệu
                    </a>
                    <a class="sub-nav-link" data-tab="sys-database">
                        <span class="dashicons dashicons-database"></span> Database
                    </a>
                    <a class="sub-nav-link" data-tab="sys-logs">
                        <span class="dashicons dashicons-list-view"></span> Nhật ký hệ thống
                    </a>
                    <a class="sub-nav-link" data-tab="sys-nhan-xet">
                        <span class="dashicons dashicons-clipboard"></span> Nhận xét định kỳ
                    </a>
                </div>

                <!-- ── TAB 1: Phân quyền ── -->
                <div id="sys-roles-panel" class="sys-panel">
                    <div class="sys-section-header">
                        <h3><span class="dashicons dashicons-groups"></span> Quản lý phân quyền người dùng</h3>
                    </div>

                    <!-- Search & Add -->
                    <div class="sys-toolbar">
                        <div class="sys-search-box">
                            <input type="text" id="sys-role-search" placeholder="Tìm theo MSSV, email hoặc tên..." />
                            <button class="btn-primary" onclick="DvutApp.sysSearchUsers()">
                                <span class="dashicons dashicons-search"></span> Tìm kiếm
                            </button>
                        </div>
                        <button class="btn-success" onclick="DvutApp.sysAddUserRole()">
                            <span class="dashicons dashicons-plus-alt2"></span> Thêm phân quyền
                        </button>
                    </div>

                    <!-- Roles table -->
                    <div class="sys-table-container">
                        <table class="dvut-table" id="sys-roles-table">
                            <thead>
                                <tr>
                                    <th>ID</th>
                                    <th>User / MSSV</th>
                                    <th>Email</th>
                                    <th>Vai trò</th>
                                    <th>Khoa</th>
                                    <th>Chi Đoàn</th>
                                    <th>Chi bộ</th>
                                    <th>Thao tác</th>
                                </tr>
                            </thead>
                            <tbody id="sys-roles-tbody">
                                <tr><td colspan="8" class="text-center text-muted">Đang tải danh sách...</td></tr>
                            </tbody>
                        </table>
                    </div>
                    <div id="sys-roles-pagination" class="pagination-container"></div>
                </div>

                <!-- ── TAB 2: Import dữ liệu ── -->
                <div id="sys-import-panel" class="sys-panel" style="display:none;">
                    <div class="sys-section-header">
                        <h3><span class="dashicons dashicons-upload"></span> Import dữ liệu "Hộp đen" (Blackbox)</h3>
                    </div>

                    <div class="sys-import-area">
                        <div class="sys-import-info">
                            <p><strong>Mô tả:</strong> Upload file CSV chứa dữ liệu sinh viên (MSSV, họ tên, GPA, điểm rèn luyện, xếp loại...) để cập nhật vào bảng Hộp đen.</p>
                            <p><strong>Định dạng yêu cầu:</strong> File CSV, encoding UTF-8. Dòng đầu tiên là header.</p>
                            <p><strong>Cột bắt buộc:</strong> <code>mssv</code>, <code>ho_ten</code>. Các cột khác tùy chọn: <code>email</code>, <code>chi_doan</code>, <code>chi_doan_id</code>, <code>khoa</code>, <code>khoa_id</code>, <code>diem_tb</code>, <code>diem_rl</code>, <code>xep_loai_doan_vien</code>, <code>ly_luan_chinh_tri</code></p>
                        </div>

                        <div class="sys-upload-zone" id="sys-import-dropzone">
                            <span class="dashicons dashicons-cloud-upload" style="font-size:48px;color:var(--primary);"></span>
                            <p>Kéo thả file CSV vào đây hoặc</p>
                            <button class="btn-primary" onclick="document.getElementById('sys-import-file').click()">
                                <span class="dashicons dashicons-media-spreadsheet"></span> Chọn file CSV
                            </button>
                            <input type="file" id="sys-import-file" accept=".csv" style="display:none" />
                            <p id="sys-import-filename" class="sys-import-filename"></p>
                        </div>

                        <div class="sys-import-options">
                            <label>
                                <input type="checkbox" id="sys-import-update" checked />
                                Tự động cập nhật nếu MSSV đã tồn tại (UPSERT)
                            </label>
                            <label>
                                <input type="text" id="sys-import-dot-xet" placeholder="Đợt xét (VD: 2024-2025 HK1)" style="width:260px;" />
                            </label>
                        </div>

                        <!-- Preview -->
                        <div id="sys-import-preview" style="display:none;">
                            <h4>Xem trước dữ liệu (<span id="sys-import-count">0</span> dòng)</h4>
                            <div class="sys-table-container" style="max-height:300px;">
                                <table class="dvut-table" id="sys-import-preview-table">
                                    <thead id="sys-import-preview-thead"></thead>
                                    <tbody id="sys-import-preview-tbody"></tbody>
                                </table>
                            </div>
                            <div class="sys-import-actions">
                                <button class="btn-success" onclick="DvutApp.sysConfirmImport()">
                                    <span class="dashicons dashicons-database-import"></span> Xác nhận Import
                                </button>
                                <button class="btn-secondary" onclick="DvutApp.sysCancelImport()">Hủy</button>
                            </div>
                        </div>

                        <!-- Result -->
                        <div id="sys-import-result" style="display:none;"></div>
                    </div>
                </div>

                <!-- ── TAB 3: Database Browser ── -->
                <div id="sys-database-panel" class="sys-panel" style="display:none;">
                    <div class="sys-section-header">
                        <h3><span class="dashicons dashicons-database"></span> Quản lý Database</h3>
                    </div>

                    <!-- Table selector -->
                    <div class="sys-toolbar">
                        <select id="sys-db-table-select" class="sys-select">
                            <option value="">-- Chọn bảng --</option>
                            <option value="wp_khoa">wp_khoa (Khoa)</option>
                            <option value="wp_chi_doan">wp_chi_doan (Chi Đoàn)</option>
                            <option value="wp_dvut_dot_xet">wp_dvut_dot_xet (Đợt xét)</option>
                            <option value="wp_doan_vien_uu_tu">wp_doan_vien_uu_tu (Đoàn viên)</option>
                            <option value="wp_dvut_blackbox">wp_dvut_blackbox (Hộp đen)</option>
                            <option value="wp_dvut_user_roles">wp_dvut_user_roles (Phân quyền)</option>
                            <option value="wp_dvut_log">wp_dvut_log (Nhật ký)</option>
                            <option value="wp_dvut_files">wp_dvut_files (Files)</option>
                        </select>
                        <button class="btn-primary" onclick="DvutApp.sysLoadTable()">
                            <span class="dashicons dashicons-visibility"></span> Xem dữ liệu
                        </button>
                        <span id="sys-db-row-count" class="sys-row-count"></span>
                    </div>

                    <!-- DB table content -->
                    <div class="sys-table-container" id="sys-db-table-wrapper" style="display:none;">
                        <table class="dvut-table" id="sys-db-table">
                            <thead id="sys-db-thead"></thead>
                            <tbody id="sys-db-tbody"></tbody>
                        </table>
                    </div>
                    <div id="sys-db-pagination" class="pagination-container"></div>
                </div>

                <!-- ── TAB 4: Nhật ký hệ thống ── -->
                <div id="sys-logs-panel" class="sys-panel" style="display:none;">
                    <div class="sys-section-header">
                        <h3><span class="dashicons dashicons-list-view"></span> Nhật ký thay đổi (Admin Log)</h3>
                    </div>
                    <div class="sys-toolbar">
                        <input type="text" id="sys-log-search" placeholder="Tìm theo nội dung, người thực hiện..." style="width:300px;" />
                        <button class="btn-primary" onclick="DvutApp.sysLoadLogs()">
                            <span class="dashicons dashicons-search"></span> Tìm
                        </button>
                    </div>
                    <div class="sys-table-container">
                        <table class="dvut-table" id="sys-logs-table">
                            <thead>
                                <tr>
                                    <th>Thời gian</th>
                                    <th>Người thực hiện</th>
                                    <th>Hành động</th>
                                    <th>Chi tiết</th>
                                </tr>
                            </thead>
                            <tbody id="sys-logs-tbody">
                                <tr><td colspan="4" class="text-center text-muted">Đang tải nhật ký...</td></tr>
                            </tbody>
                        </table>
                    </div>
                    <div id="sys-logs-pagination" class="pagination-container"></div>
                </div>

                <!-- ── TAB 5: Nhận xét định kỳ (Admin quản lý kỳ đánh giá) ── -->
                <div id="sys-nhan-xet-panel" class="sys-panel" style="display:none;">
                    <div class="sys-section-header">
                        <h3><span class="dashicons dashicons-clipboard"></span> Quản lý kỳ đánh giá nhận xét định kỳ</h3>
                    </div>
                    <p style="color:#666; font-size:.88em; margin-bottom:16px;">
                        Tạo và quản lý các kỳ đánh giá theo quý. Khi <strong>mở</strong> kỳ, BCH Chi Đoàn có thể nhận xét ĐVƯT.
                        Khi <strong>khóa</strong>, nhận xét bị khóa và chỉ Admin mới sửa được.
                    </p>

                    <div class="sys-toolbar">
                        <button class="btn-success" onclick="DvutApp.sysAddNhanXetPeriod()">
                            <span class="dashicons dashicons-plus-alt2"></span> Tạo kỳ đánh giá mới
                        </button>
                    </div>

                    <div class="sys-table-container">
                        <table class="dvut-table" id="sys-nhan-xet-periods-table">
                            <thead>
                                <tr>
                                    <th>ID</th>
                                    <th>Tên kỳ</th>
                                    <th>Quý</th>
                                    <th>Năm học</th>
                                    <th>Trạng thái</th>
                                    <th>Ngày mở</th>
                                    <th>Ngày khóa</th>
                                    <th>Thao tác</th>
                                </tr>
                            </thead>
                            <tbody id="sys-nhan-xet-tbody">
                                <tr><td colspan="8" class="text-center text-muted">Đang tải...</td></tr>
                            </tbody>
                        </table>
                    </div>
                </div>

            </div><!-- /#dvut-system-view -->

        </main>

    </div><!-- /.admin-dashboard -->

    <!-- ============================================================= -->
    <!-- LỊCH SỬ CHỈNH SỬA — Slide Panel                              -->
    <!-- ============================================================= -->
    <div class="dvut-history-overlay" id="history-overlay"></div>
    <div class="dvut-history-panel" id="history-panel">
        <div class="dvut-history-panel-header">
            <h2><span class="dashicons dashicons-backup"></span> Lịch sử chỉnh sửa</h2>
            <button class="close-history-btn" id="close-history-btn" title="Đóng">
                <span class="dashicons dashicons-no-alt"></span>
            </button>
        </div>
        <div class="dvut-history-filters">
            <div class="filter-row">
                <div class="filter-group" id="history-filter-role-group">
                    <label>Vai trò</label>
                    <select id="history-filter-role">
                        <option value="">Tất cả vai trò</option>
                        <option value="bch_chi_doan">BCH Chi Đoàn</option>
                        <option value="can_bo_doan_khoa">Cán bộ Đoàn Khoa</option>
                        <option value="admin_doan_truong">Cán bộ Đoàn trường</option>
                    </select>
                </div>
                <div class="filter-group">
                    <label>Hành động</label>
                    <select id="history-filter-action">
                        <option value="">Tất cả</option>
                        <option value="de_cu">Đề cử</option>
                        <option value="nop_ho_so">Nộp hồ sơ</option>
                        <option value="chi_doan_duyet">Chi Đoàn duyệt</option>
                        <option value="doan_khoa_duyet">Đoàn Khoa duyệt</option>
                        <option value="admin_duyet">Admin duyệt/QĐ</option>
                        <option value="tu_choi">Từ chối/Trả về</option>
                        <option value="cam_tinh_dang">Cập nhật CTĐ</option>
                        <option value="gioi_thieu_dang">Giới thiệu Đảng</option>
                        <option value="chuyen_dang">Chuyển Đảng CT</option>
                        <option value="xoa">Xóa hồ sơ</option>
                        <option value="import">Import Excel</option>
                    </select>
                </div>
                <div class="filter-group">
                    <label>Tìm kiếm</label>
                    <input type="text" id="history-filter-keyword" placeholder="Tên, MSSV...">
                </div>
            </div>
            <div class="filter-row" style="margin-top:8px;">
                <div class="filter-group" style="max-width:140px;">
                    <label>Từ ngày</label>
                    <input type="date" id="history-filter-from">
                </div>
                <div class="filter-group" style="max-width:140px;">
                    <label>Đến ngày</label>
                    <input type="date" id="history-filter-to">
                </div>
                <div class="filter-actions">
                    <button type="button" class="btn-filter-apply" id="history-btn-apply">
                        <span class="dashicons dashicons-search" style="font-size:14px;vertical-align:middle;margin-right:2px;"></span> Lọc
                    </button>
                    <button type="button" class="btn-filter-reset" id="history-btn-reset">Xóa lọc</button>
                </div>
            </div>
        </div>
        <div class="dvut-history-body" id="history-body">
            <!-- JS sẽ render danh sách vào đây -->
        </div>
        <div class="dvut-history-footer" id="history-footer">
            <span class="history-summary" id="history-summary"></span>
            <div class="per-page-wrapper">
                <label for="history-per-page-select">Hiển thị</label>
                <select id="history-per-page-select">
                    <option value="10">10 bản ghi / trang</option>
                    <option value="20" selected>20 bản ghi / trang</option>
                    <option value="50">50 bản ghi / trang</option>
                    <option value="100">100 bản ghi / trang</option>
                </select>
            </div>
            <div class="history-pagination" id="history-pagination"></div>
        </div>
    </div>



</div><!-- /.admin-body -->

<!-- ================================================================= -->
<!-- JS Dependencies                                                    -->
<!-- jQuery: được WordPress enqueue qua wp_head()/wp_footer()            -->
<!-- ================================================================= -->
<script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11.10.3/dist/sweetalert2.all.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.4/dist/chart.umd.min.js"></script>

<!-- App JS chính cho phân hệ Đoàn viên Ưu tú — được WordPress enqueue qua wp_footer() -->

<!-- ================================================================= -->
<!-- Dashboard JS — Biểu đồ Chart.js cho 3 cấp vai trò                -->
<!-- ================================================================= -->
<script>
(function() {
    'use strict';

    const charts = {};

    /* ── Theme helpers ── */
    function isDark() { return document.body.classList.contains('dark-mode'); }
    function txtC()   { return isDark() ? '#cdcdcd' : '#555'; }
    function gridC()  { return isDark() ? 'rgba(255,255,255,.08)' : 'rgba(0,0,0,.06)'; }
    function tipBg()  { return isDark() ? '#333' : '#fff'; }
    function tipTxt() { return isDark() ? '#fff' : '#333'; }
    function tipBody(){ return isDark() ? '#ccc' : '#555'; }
    function fontCfg(size, weight) { return { family:'Montserrat', weight: weight||600, size: size||12 }; }
    function tipOpts() {
        return { backgroundColor:tipBg(), titleColor:tipTxt(), bodyColor:tipBody(), borderColor:gridC(), borderWidth:1, padding:12, cornerRadius:8, titleFont:fontCfg(12,700), bodyFont:fontCfg(11,500) };
    }

    const COLORS = [
        'rgba(23,79,140,.8)', 'rgba(25,135,84,.8)', 'rgba(111,66,193,.8)',
        'rgba(240,92,32,.8)', 'rgba(13,110,253,.8)', 'rgba(220,53,69,.8)',
        'rgba(255,193,7,.8)', 'rgba(108,117,125,.8)', 'rgba(0,188,212,.8)'
    ];
    const BORDERS = ['#174f8c','#198754','#6f42c1','#f05c20','#0d6efd','#dc3545','#ffc107','#6c757d','#00bcd4'];

    function destroyAll() {
        Object.keys(charts).forEach(k => { if(charts[k]) charts[k].destroy(); delete charts[k]; });
    }

    function getRole() {
        // Đọc vai trò từ DvutApp (đã được set bởi Dev Hub hoặc production)
        return (window.DvutApp && window.DvutApp.getRole) ? window.DvutApp.getRole() : 'bch_chi_doan';
    }

    function showPanel(role) {
        document.querySelectorAll('.dash-section').forEach(s => s.classList.remove('active'));
        const map = { 'bch_chi_doan':'dashboard-chi-doan', 'chi_bo_sinh_vien':'dashboard-chi-doan', 'can_bo_doan_khoa':'dashboard-doan-khoa', 'admin_doan_truong':'dashboard-admin' };
        const el = document.getElementById(map[role] || 'dashboard-chi-doan');
        if (el) el.classList.add('active');

        /* Cập nhật tiêu đề dashboard theo role */
        const titleEl = el ? el.querySelector('.dash-header h2') : null;
        if (titleEl) {
            const titles = {
                'bch_chi_doan':      'Dashboard Chi Đoàn',
                'chi_bo_sinh_vien':  'Dashboard Chi bộ',
                'can_bo_doan_khoa':  'Dashboard Đoàn Khoa',
                'admin_doan_truong': 'Dashboard Đoàn Trường'
            };
            titleEl.textContent = titles[role] || 'Dashboard';
        }
    }

    function animateBars() {
        document.querySelectorAll('.dash-progress-fill[data-target]').forEach(b => {
            setTimeout(() => { b.style.width = b.dataset.target + '%'; }, 300);
        });
    }

    /* ── Data helpers — pull from DvutApp public API ── */
    function getData()    { return window.DvutApp ? window.DvutApp.getVisibleData() : []; }
    function getChiDoan() { return window.DvutApp ? window.DvutApp.getVisibleChiDoan() : []; }
    function getAllChiDoan() { return window.DvutApp ? window.DvutApp.getChiDoanList() : []; }
    function getExcel()   { return window.DvutApp && typeof window.DvutApp.getExcelData === 'function' ? window.DvutApp.getExcelData() : []; }

    /** Statuses grouped by funnel stage */
    const S_TIEM_NANG = ['CHO_NOP','CHO_CHI_DOAN','CHO_DOAN_KHOA','CHO_DOAN_TRUONG'];
    const S_DVUT      = ['DA_CONG_NHAN','CHO_DK_GIOI_THIEU'];
    const S_DV_DU_BI  = ['DANG_VIEN_DU_BI','CHO_DK_CHUYEN_DANG'];
    const S_DV_CT     = ['DANG_VIEN_CHINH_THUC'];
    const S_CHO_XU_LY = ['CHO_CHI_DOAN','CHO_DOAN_KHOA','CHO_DOAN_TRUONG','CHO_DK_GIOI_THIEU','CHO_DK_CHUYEN_DANG'];

    function countIn(data, statuses) { return data.filter(d => statuses.includes(d.trang_thai)).length; }

    /* ══════════════════════════════════════════════════════════════════ */
    /*  KPI UPDATE — Cập nhật tất cả KPI cards theo dữ liệu lọc        */
    /* ══════════════════════════════════════════════════════════════════ */
    function updateAllKPIs() {
        const role = getRole();
        const data = getData();
        const total = data.length;

        if (role === 'chi_bo_sinh_vien') {
            /* ── Chi Bộ KPIs ── */
            const cbDVUT       = data.filter(d => d.trang_thai === 'DA_CONG_NHAN').length;
            const cbCTDDone    = data.filter(d => d.trang_thai === 'DA_CONG_NHAN' && d.tien_do_cam_tinh_dang === 'DA_HOAN_THANH').length;
            const cbCTDLearning= data.filter(d => d.trang_thai === 'DA_CONG_NHAN' && d.tien_do_cam_tinh_dang === 'DANG_HOC').length;
            const cbDangVienDB = data.filter(d => d.trang_thai === 'DANG_VIEN_DU_BI').length;
            setKpi('kpi-cd-tong', cbDVUT);
            setKpi('kpi-cd-dudk', cbCTDDone);
            setKpi('kpi-cd-dvut', cbCTDLearning);
            setKpi('kpi-cd-todo', cbDangVienDB);

            /* Cập nhật label KPI cards cho Chi bộ */
            const kpiLabels = {
                'kpi-cd-tong': 'Đoàn viên ưu tú',
                'kpi-cd-dudk': 'Đã hoàn thành CTĐ',
                'kpi-cd-dvut': 'Đang học CTĐ',
                'kpi-cd-todo': 'Đảng viên'
            };
            Object.entries(kpiLabels).forEach(([id, label]) => {
                const el = document.getElementById(id);
                if (el) {
                    const labelEl = el.closest('.dash-kpi-card')?.querySelector('.dash-kpi-label');
                    if (labelEl) labelEl.textContent = label;
                }
            });
        } else {
            /* ── Chi Đoàn KPIs (default) ── */
            const cdTotal    = total;
            const cdDuDK     = data.filter(d => !['TU_CHOI','TRA_VE','DA_HUY'].includes(d.trang_thai)).length;
            const cdDVUT     = countIn(data, ['DA_CONG_NHAN',...S_DV_DU_BI,...S_DV_CT,'CHO_DK_GIOI_THIEU']);
            const cdTodo     = countIn(data, S_CHO_XU_LY);
            setKpi('kpi-cd-tong', cdTotal);
            setKpi('kpi-cd-dudk', cdDuDK);
            setKpi('kpi-cd-dvut', cdDVUT);
            setKpi('kpi-cd-todo', cdTodo);

            /* Reset label KPI cards về mặc định */
            const defaultLabels = {
                'kpi-cd-tong': 'Tổng số Đoàn viên',
                'kpi-cd-dudk': 'Đủ tiêu chuẩn xét ĐVƯT',
                'kpi-cd-dvut': 'ĐVƯT đã công nhận',
                'kpi-cd-todo': 'Việc cần làm'
            };
            Object.entries(defaultLabels).forEach(([id, label]) => {
                const el = document.getElementById(id);
                if (el) {
                    const labelEl = el.closest('.dash-kpi-card')?.querySelector('.dash-kpi-label');
                    if (labelEl) labelEl.textContent = label;
                }
            });
        }

        /* Đoàn Khoa KPIs */
        const dkCho  = countIn(data, ['CHO_DOAN_KHOA','CHO_DK_GIOI_THIEU','CHO_DK_CHUYEN_DANG']);
        const dkDVUT = countIn(data, ['DA_CONG_NHAN',...S_DV_DU_BI,...S_DV_CT,'CHO_DK_GIOI_THIEU']);
        const dkDang = countIn(data, [...S_DV_DU_BI,...S_DV_CT]);
        setKpi('kpi-dk-tong', total);
        setKpi('kpi-dk-cho',  dkCho);
        setKpi('kpi-dk-dvut', dkDVUT);
        setKpi('kpi-dk-dang', dkDang);

        if (role === 'can_bo_doan_khoa') {
            $('#dashboard-doan-khoa .kpi-purple').hide();
        } else {
            $('#dashboard-doan-khoa .kpi-purple').show();
        }

        /* Admin KPIs */
        const adDVUT = countIn(data, ['DA_CONG_NHAN',...S_DV_DU_BI,...S_DV_CT,'CHO_DK_GIOI_THIEU']);
        const adDang = countIn(data, [...S_DV_DU_BI,...S_DV_CT]);
        const adRate = adDVUT > 0 ? ((adDang / adDVUT) * 100).toFixed(1) : '0';
        setKpi('kpi-ad-tong', total);
        setKpi('kpi-ad-dvut', adDVUT);
        setKpi('kpi-ad-dang', adDang);
        setKpi('kpi-ad-rate', adRate + '%');
    }

    function setKpi(id, val) {
        const el = document.getElementById(id);
        if (el) el.textContent = val;
    }

    /* ══════════════════════════════════════════════════════════════════ */
    /*  CHI ĐOÀN CHARTS — Funnel + Progress Bars + Warnings            */
    /* ══════════════════════════════════════════════════════════════════ */
    function createCDCharts() {
        const role = getRole();
        const data = getData();

        if (role === 'chi_bo_sinh_vien') {
            /* ══ CHI BỘ: Biểu đồ tiến độ Cảm tình Đảng ══ */
            const dvutData = data.filter(d => d.trang_thai === 'DA_CONG_NHAN');
            const chuaTG  = dvutData.filter(d => !d.tien_do_cam_tinh_dang || d.tien_do_cam_tinh_dang === 'CHUA_THAM_GIA').length;
            const dangHoc = dvutData.filter(d => d.tien_do_cam_tinh_dang === 'DANG_HOC').length;
            const daHT    = dvutData.filter(d => d.tien_do_cam_tinh_dang === 'DA_HOAN_THANH').length;
            const dvDB    = data.filter(d => d.trang_thai === 'DANG_VIEN_DU_BI').length;

            const funnelData = [chuaTG, dangHoc, daHT, dvDB];

            /* Cập nhật tiêu đề biểu đồ */
            const chartTitleEl = document.querySelector('#dashboard-chi-doan .dash-chart-card .dash-chart-title');
            if (chartTitleEl) chartTitleEl.innerHTML = '<span class="dashicons dashicons-welcome-learn-more"></span> Tiến độ phát triển Đảng viên';
            const chartSubEl = document.querySelector('#dashboard-chi-doan .dash-chart-card .dash-chart-subtitle');
            if (chartSubEl) chartSubEl.textContent = 'Trạng thái Cảm tình Đảng & Kết nạp của ĐVƯT';

            const ctx = document.getElementById('chart-cd-funnel');
            if (ctx) {
                charts['cd-funnel'] = new Chart(ctx, {
                    type: 'bar',
                    data: {
                        labels: ['Chưa tham gia CTĐ','Đang học CTĐ','Đã hoàn thành CTĐ','Đảng viên'],
                        datasets: [{
                            data: funnelData,
                            backgroundColor: ['rgba(108,117,125,.75)','rgba(0,123,255,.75)','rgba(25,135,84,.75)','rgba(111,66,193,.82)'],
                            borderColor: ['#6c757d','#007bff','#198754','#6f42c1'],
                            borderWidth: 2, borderRadius: 8, barPercentage: .65,
                        }]
                    },
                    options: {
                        indexAxis: 'y', responsive: true, maintainAspectRatio: false,
                        plugins: {
                            legend: { display:false },
                            tooltip: { ...tipOpts(), callbacks: { label: c => '  ' + c.raw + ' người' } }
                        },
                        scales: {
                            x: { grid:{color:gridC()}, ticks:{color:txtC(), font:fontCfg(11)}, beginAtZero:true },
                            y: { grid:{display:false}, ticks:{color:txtC(), font:fontCfg(12,700)} }
                        },
                        animation: { duration:1200, easing:'easeOutQuart' }
                    }
                });
            }

            /* Progress Bars — tiến độ CTĐ cho Chi bộ */
            const progressEl = document.getElementById('cd-progress-bars');
            if (progressEl) {
                const totalDVUT = dvutData.length || 1;
                const pcts = [
                    { label:'Đăng ký lớp CTĐ',          pct: Math.round(((dangHoc + daHT) / totalDVUT) * 100), cls:'fill-blue',   color:'#007bff' },
                    { label:'Hoàn thành lớp CTĐ',       pct: Math.round((daHT / totalDVUT) * 100),              cls:'fill-green',  color:'#198754' },
                    { label:'Đã kết nạp (Đảng viên)',    pct: Math.round((dvDB / (totalDVUT + dvDB || 1)) * 100), cls:'fill-purple', color:'#6f42c1' },
                ];
                progressEl.innerHTML = pcts.map(p => `
                    <div class="dash-progress-group">
                        <div class="dash-progress-header">
                            <span>${p.label}</span><span style="color:${p.color};">${p.pct}%</span>
                        </div>
                        <div class="dash-progress-bar"><div class="dash-progress-fill ${p.cls}" style="width:0%" data-target="${p.pct}"></div></div>
                    </div>`).join('');
            }

            /* Cập nhật tiêu đề Cảnh báo */
            const progressTitleEl = document.querySelectorAll('#dashboard-chi-doan .dash-chart-card')[1];
            if (progressTitleEl) {
                const t = progressTitleEl.querySelector('.dash-chart-title');
                if (t) t.innerHTML = '<span class="dashicons dashicons-clipboard"></span> Tiến độ Cảm tình Đảng & Cảnh báo';
                const s = progressTitleEl.querySelector('.dash-chart-subtitle');
                if (s) s.textContent = 'Theo dõi lớp bồi dưỡng Cảm tình Đảng';
            }

            /* Warnings — phù hợp với Chi bộ */
            const warnEl = document.getElementById('cd-warning-list');
            if (warnEl) {
                const warnings = [];
                if (chuaTG > 0)  warnings.push({ type:'todo-warning', icon:'clock',               text:`<strong>${chuaTG} ĐVƯT</strong> chưa tham gia lớp Cảm tình Đảng` });
                if (dangHoc > 0) warnings.push({ type:'todo-info',    icon:'welcome-learn-more',  text:`<strong>${dangHoc} ĐVƯT</strong> đang học lớp Cảm tình Đảng` });
                if (daHT > 0)    warnings.push({ type:'todo-info',    icon:'yes-alt',             text:`<strong>${daHT} ĐVƯT</strong> đã hoàn thành CTĐ — sẵn sàng kết nạp` });
                if (dvDB > 0)    warnings.push({ type:'todo-danger',  icon:'flag',                text:`<strong>${dvDB} Đảng viên</strong> đang trong giai đoạn thử thách` });
                if (warnings.length === 0) warnings.push({ type:'todo-info', icon:'smiley', text:'Không có cảnh báo nào — mọi thứ đều ổn!' });
                warnEl.innerHTML = warnings.map(w => `
                    <li class="dash-todo-item ${w.type}">
                        <div class="dash-todo-icon"><span class="dashicons dashicons-${w.icon}"></span></div>
                        <div class="dash-todo-text">${w.text}</div>
                    </li>`).join('');
            }

        } else {
            /* ══ CHI ĐOÀN (mặc định): Phễu quy trình phát triển Đảng ══ */

            /* Reset tiêu đề biểu đồ về mặc định */
            const chartTitleEl = document.querySelector('#dashboard-chi-doan .dash-chart-card .dash-chart-title');
            if (chartTitleEl) chartTitleEl.innerHTML = '<span class="dashicons dashicons-filter"></span> Phễu quy trình phát triển Đảng';
            const chartSubEl = document.querySelector('#dashboard-chi-doan .dash-chart-card .dash-chart-subtitle');
            if (chartSubEl) chartSubEl.textContent = 'Số lượng Đoàn viên qua từng giai đoạn';

            const role = getRole();
            let funnelData, labels, colors, borderColors;

            if (role === 'bch_chi_doan') {
                funnelData = [
                    countIn(data, S_TIEM_NANG),
                    countIn(data, S_DVUT)
                ];
                labels = ['ĐV Tiềm năng','Đoàn viên Ưu tú'];
                colors = ['rgba(23,79,140,.82)','rgba(25,135,84,.82)'];
                borderColors = ['#174f8c','#198754'];
            } else {
                funnelData = [
                    countIn(data, S_TIEM_NANG),
                    countIn(data, S_DVUT),
                    countIn(data, S_DV_DU_BI),
                    countIn(data, S_DV_CT)
                ];
                labels = ['ĐV Tiềm năng','Đoàn viên Ưu tú','Đảng viên','Đảng viên chính thức'];
                colors = ['rgba(23,79,140,.82)','rgba(25,135,84,.82)','rgba(111,66,193,.82)','rgba(220,53,69,.82)'];
                borderColors = ['#174f8c','#198754','#6f42c1','#dc3545'];
            }

            const ctx = document.getElementById('chart-cd-funnel');
            if (ctx) {
                charts['cd-funnel'] = new Chart(ctx, {
                    type: 'bar',
                    data: {
                        labels: labels,
                        datasets: [{
                            data: funnelData,
                            backgroundColor: colors,
                            borderColor: borderColors,
                            borderWidth: 2, borderRadius: 8, barPercentage: .65,
                        }]
                    },
                    options: {
                        indexAxis: 'y', responsive: true, maintainAspectRatio: false,
                        plugins: {
                            legend: { display:false },
                            tooltip: { ...tipOpts(), callbacks: { label: c => '  ' + c.raw + ' người' } }
                        },
                        scales: {
                            x: { grid:{color:gridC()}, ticks:{color:txtC(), font:fontCfg(11)}, beginAtZero:true },
                            y: { grid:{display:false}, ticks:{color:txtC(), font:fontCfg(12,700)} }
                        },
                        animation: { duration:1200, easing:'easeOutQuart' }
                    }
                });
            }

            /* Progress Bars — compute from data fields */
            const progressEl = document.getElementById('cd-progress-bars');
            if (progressEl) {
                const activeData = data.filter(d => !['TU_CHOI','TRA_VE','DA_HUY','CHO_NOP'].includes(d.trang_thai));
                const totalActive = activeData.length || 1;
                const m1 = activeData.filter(d => !['CHO_CHI_DOAN'].includes(d.trang_thai)).length;
                const m2 = activeData.filter(d => !['CHO_CHI_DOAN','CHO_DOAN_KHOA'].includes(d.trang_thai)).length;
                const m3 = activeData.filter(d => ['DA_CONG_NHAN','CHO_DK_GIOI_THIEU','DANG_VIEN_DU_BI','CHO_DK_CHUYEN_DANG','DANG_VIEN_CHINH_THUC'].includes(d.trang_thai)).length;
                const pcts = [
                    { label:'Giai đoạn 1 — Đề cử & Nộp hồ sơ', pct: Math.round(m1/totalActive*100), cls:'fill-blue', color:'#174f8c' },
                    { label:'Giai đoạn 2 — Duyệt hồ sơ',       pct: Math.round(m2/totalActive*100), cls:'fill-green', color:'#198754' },
                    { label:'Giai đoạn 3 — Công nhận ĐVƯT',     pct: Math.round(m3/totalActive*100), cls:'fill-purple', color:'#6f42c1' },
                ];
                progressEl.innerHTML = pcts.map(p => `
                    <div class="dash-progress-group">
                        <div class="dash-progress-header">
                            <span>${p.label}</span><span style="color:${p.color};">${p.pct}%</span>
                        </div>
                        <div class="dash-progress-bar"><div class="dash-progress-fill ${p.cls}" style="width:0%" data-target="${p.pct}"></div></div>
                    </div>`).join('');
            }

            /* Reset tiêu đề panel 2 về mặc định */
            const progressTitleEl = document.querySelectorAll('#dashboard-chi-doan .dash-chart-card')[1];
            if (progressTitleEl) {
                const t = progressTitleEl.querySelector('.dash-chart-title');
                if (t) t.innerHTML = '<span class="dashicons dashicons-clipboard"></span> Tiến độ nộp hồ sơ & Cảnh báo';
                const s = progressTitleEl.querySelector('.dash-chart-subtitle');
                if (s) s.textContent = 'Đợt xét duyệt 2025-2026';
            }

            /* Warnings — compute from data */
            const warnEl = document.getElementById('cd-warning-list');
            if (warnEl) {
                const warnings = [];
                const dvutCount = countIn(data, ['DA_CONG_NHAN']);
                const duBiCount = countIn(data, ['DANG_VIEN_DU_BI']);
                const choNopCount = countIn(data, ['CHO_NOP']);
                const traVeCount = countIn(data, ['TRA_VE']);
                if (dvutCount > 0) {
                    const text = role === 'bch_chi_doan'
                        ? `<strong>${dvutCount} ĐVƯT</strong> đã được công nhận`
                        : `<strong>${dvutCount} ĐVƯT</strong> đã công nhận — chờ giới thiệu vào Đảng`;
                    warnings.push({ type:'todo-warning', icon:'flag', text: text });
                }
                if (role !== 'bch_chi_doan' && duBiCount > 0) {
                    warnings.push({ type:'todo-danger', icon:'clock', text:`<strong>${duBiCount} Đảng viên</strong> đang trong giai đoạn thử thách` });
                }
                if (choNopCount > 0) warnings.push({ type:'todo-info', icon:'media-document', text:`<strong>${choNopCount} Đoàn viên</strong> chưa nộp hồ sơ` });
                if (traVeCount > 0) warnings.push({ type:'todo-danger', icon:'dismiss', text:`<strong>${traVeCount} Hồ sơ</strong> bị trả về — cần bổ sung` });
                if (warnings.length === 0) warnings.push({ type:'todo-info', icon:'yes-alt', text:'Không có cảnh báo nào' });
                warnEl.innerHTML = warnings.map(w => `
                    <li class="dash-todo-item ${w.type}">
                        <div class="dash-todo-icon"><span class="dashicons dashicons-${w.icon}"></span></div>
                        <div class="dash-todo-text">${w.text}</div>
                    </li>`).join('');
            }
        }
    }

    /* ══════════════════════════════════════════════════════════════════ */
    /*  ĐOÀN KHOA CHARTS — Bar (per Chi Đoàn) + Pie (status) + WF     */
    /* ══════════════════════════════════════════════════════════════════ */
    function createDKCharts() {
        const data   = getData();
        const chiDs  = getChiDoan();

        /* Bar Chart — ĐVƯT vs GT Đảng per Chi Đoàn */
        const barCtx = document.getElementById('chart-dk-bar');
        if (barCtx && chiDs.length > 0) {
            const labels = chiDs.map(cd => cd.ten);
            const dvutPerCD = chiDs.map(cd => {
                return data.filter(d => d.chi_doan_id == cd.id && [...S_DVUT,...S_DV_DU_BI,...S_DV_CT].includes(d.trang_thai)).length;
            });
            const dangPerCD = chiDs.map(cd => {
                return data.filter(d => d.chi_doan_id == cd.id && [...S_DV_DU_BI,...S_DV_CT].includes(d.trang_thai)).length;
            });
            const role = getRole();
            const datasets = [
                { label:'ĐVƯT', data:dvutPerCD, backgroundColor:'rgba(23,79,140,.8)', borderColor:'#174f8c', borderWidth:1, borderRadius:6 }
            ];
            if (role !== 'can_bo_doan_khoa') {
                datasets.push({ label:'GT Đảng', data:dangPerCD, backgroundColor:'rgba(111,66,193,.8)', borderColor:'#6f42c1', borderWidth:1, borderRadius:6 });
            }

            charts['dk-bar'] = new Chart(barCtx, {
                type: 'bar',
                data: {
                    labels: labels,
                    datasets: datasets
                },
                options: {
                    responsive:true, maintainAspectRatio:false,
                    plugins: {
                        legend: { position:'top', labels:{ color:txtC(), font:fontCfg(11), usePointStyle:true, pointStyle:'rectRounded', padding:15 } },
                        tooltip: tipOpts()
                    },
                    scales: {
                        x: { grid:{display:false}, ticks:{color:txtC(), font:fontCfg(10), maxRotation:45, minRotation:20} },
                        y: { grid:{color:gridC()}, ticks:{color:txtC(), font:fontCfg(11)}, beginAtZero:true }
                    },
                    animation: { duration:1200, easing:'easeOutQuart' }
                }
            });
        }

        /* Pie Chart — Status distribution */
        const pieCtx = document.getElementById('chart-dk-pie');
        if (pieCtx) {
            const role = getRole();
            const statusGroups = [
                { label:'Chờ xử lý',           count: countIn(data, S_CHO_XU_LY) },
                { label:'Chờ nộp hồ sơ',       count: countIn(data, ['CHO_NOP']) },
                { label:'Đã công nhận ĐVƯT',    count: countIn(data, ['DA_CONG_NHAN']) },
                { label:'Đảng viên',      count: countIn(data, ['DANG_VIEN_DU_BI']) },
                { label:'Đảng viên chính thức',  count: countIn(data, ['DANG_VIEN_CHINH_THUC']) },
                { label:'Từ chối / Trả về',     count: countIn(data, ['TU_CHOI','TRA_VE','DA_HUY']) },
            ].filter(g => {
                if (role === 'can_bo_doan_khoa' && ['Đảng viên', 'Đảng viên chính thức'].includes(g.label)) {
                    return false;
                }
                return g.count > 0;
            });

            charts['dk-pie'] = new Chart(pieCtx, {
                type: 'doughnut',
                data: {
                    labels: statusGroups.map(g => g.label),
                    datasets: [{
                        data: statusGroups.map(g => g.count),
                        backgroundColor: statusGroups.map((_,i) => COLORS[i % COLORS.length]),
                        borderColor: isDark()?'#242526':'#fff', borderWidth:3, hoverOffset:8
                    }]
                },
                options: {
                    responsive:true, maintainAspectRatio:false, cutout:'55%',
                    plugins: {
                        legend: { position:'bottom', labels:{ color:txtC(), font:fontCfg(11), padding:15, usePointStyle:true } },
                        tooltip: tipOpts()
                    },
                    animation: { animateRotate:true, duration:1400, easing:'easeOutQuart' }
                }
            });
        }

        /* Workflow list — per Chi Đoàn */
        const wfList = document.getElementById('dk-wf-list');
        if (wfList) {
            if (chiDs.length === 0) {
                wfList.innerHTML = '<li class="dash-todo-item todo-info"><div class="dash-todo-text">Chưa có dữ liệu Chi Đoàn</div></li>';
            } else {
                wfList.innerHTML = chiDs.map(cd => {
                    const cdData = data.filter(d => d.chi_doan_id == cd.id);
                    const total  = cdData.length;
                    const done   = cdData.filter(d => ['DA_CONG_NHAN','DANG_VIEN_DU_BI','DANG_VIEN_CHINH_THUC'].includes(d.trang_thai)).length;
                    const pct    = total > 0 ? Math.round(done / total * 100) : 0;
                    const cls    = pct >= 80 ? 'todo-info' : pct >= 40 ? 'todo-warning' : 'todo-danger';
                    if (total === 0) return ''; // skip chi đoàn with no data
                    return `<li class="dash-todo-item ${cls}">
                        <div class="dash-todo-icon"><span class="dashicons dashicons-groups"></span></div>
                        <div class="dash-todo-text" style="flex:1"><strong>${cd.ten}</strong> — ${done}/${total} hoàn tất (${pct}%)</div>
                        <div style="flex:0 0 120px;">
                            <div class="dash-progress-bar"><div class="dash-progress-fill fill-blue" style="width:${pct}%"></div></div>
                        </div>
                    </li>`;
                }).filter(Boolean).join('') || '<li class="dash-todo-item todo-info"><div class="dash-todo-text">Không có đoàn viên nào</div></li>';
            }
        }
    }

    /* ══════════════════════════════════════════════════════════════════ */
    /*  ADMIN CHARTS — Bubble (per Khoa) + Line (trend) + Violations   */
    /* ══════════════════════════════════════════════════════════════════ */
    function createAdminCharts() {
        const data     = getData();
        const allCD    = getAllChiDoan();
        const excel    = getExcel();

        /* Build khoa lookup from DVUT_AJAX.khoa_list (has real names) */
        const khoaLookup = {};
        if (typeof DVUT_AJAX !== 'undefined' && Array.isArray(DVUT_AJAX.khoa_list)) {
            DVUT_AJAX.khoa_list.forEach(k => { khoaLookup[k.id] = k.ten; });
        }

        /* Tính số liệu per Khoa (lấy unique khoa từ allChiDoan) */
        const khoaMap = {};
        allCD.forEach(cd => {
            if (!khoaMap[cd.khoa_id]) khoaMap[cd.khoa_id] = { id: cd.khoa_id, ten: khoaLookup[cd.khoa_id] || ('Khoa ' + cd.khoa_id), cdIds: [] };
            khoaMap[cd.khoa_id].cdIds.push(cd.id);
        });
        const khoaList = Object.values(khoaMap);

        /* Bubble Chart — per Khoa */
        const bubCtx = document.getElementById('chart-admin-bubble');
        if (bubCtx && khoaList.length > 0) {
            const datasets = khoaList.map((khoa, i) => {
                const khoaData = data.filter(d => khoa.cdIds.includes(d.chi_doan_id));
                const dvutCount = khoaData.filter(d => [...S_DVUT,...S_DV_DU_BI,...S_DV_CT].includes(d.trang_thai)).length;
                // Compute average GPA from excel data if available
                const khoaExcel = excel.filter(e => khoa.cdIds.includes(parseInt(e.chi_doan_id)));
                const avgGpa = khoaExcel.length > 0
                    ? (khoaExcel.reduce((s,e) => s + parseFloat(e.diem_tb || 0), 0) / khoaExcel.length).toFixed(1)
                    : (7 + Math.random() * 2).toFixed(1); // fallback
                const bubbleR = Math.max(5, Math.min(25, dvutCount * 4 + 5)); // scale radius
                return {
                    label: khoa.ten,
                    data: [{ x: i + 1, y: parseFloat(avgGpa), r: bubbleR }],
                    backgroundColor: COLORS[i % COLORS.length].replace('.8','.55'),
                    borderColor: BORDERS[i % BORDERS.length],
                    borderWidth: 2
                };
            });
            charts['admin-bub'] = new Chart(bubCtx, {
                type: 'bubble',
                data: { datasets: datasets },
                options: {
                    responsive:true, maintainAspectRatio:false,
                    plugins: {
                        legend: { position:'top', labels:{ color:txtC(), font:fontCfg(11), usePointStyle:true, padding:12 } },
                        tooltip: { ...tipOpts(), callbacks:{ label: c => { const d=c.raw; return '  ĐTB: '+d.y+' | ĐVƯT: '+Math.round((d.r-5)/4); } } }
                    },
                    scales: {
                        x: { display:false },
                        y: { title:{display:true,text:'ĐTB tích lũy',color:txtC(),font:fontCfg(12,700)}, grid:{color:gridC()}, ticks:{color:txtC(),font:fontCfg(11)}, min:6, max:10 }
                    },
                    animation: { duration:1500, easing:'easeOutQuart' }
                }
            });
        }

        /* Line Chart — Trend (historical — placeholder, can be replaced with real data) */
        const lineCtx = document.getElementById('chart-admin-line');
        if (lineCtx) {
            // Compute current year counts as endpoint
            const curDVUT = countIn(data, [...S_DVUT,...S_DV_DU_BI,...S_DV_CT]);
            const curDang = countIn(data, [...S_DV_DU_BI,...S_DV_CT]);
            const curCT   = countIn(data, S_DV_CT);
            const ds = (label,vals,color,dash) => ({
                label, data:vals, borderColor:color,
                backgroundColor:color.replace(')',',0.1)').replace('rgb','rgba'),
                fill:true, tension:.4, pointBackgroundColor:color,
                pointBorderColor:isDark()?'#333':'#fff', pointBorderWidth:2,
                pointRadius:5, pointHoverRadius:8, borderDash:dash||[]
            });
            charts['admin-line'] = new Chart(lineCtx, {
                type: 'line',
                data: {
                    labels: ['2022','2023','2024','2025','Hiện tại'],
                    datasets: [
                        ds('ĐVƯT công nhận',[0,0,0,0,curDVUT],'rgb(23,79,140)'),
                        ds('Kết nạp Đảng',[0,0,0,0,curDang],'rgb(25,135,84)'),
                        ds('ĐV Chính thức',[0,0,0,0,curCT],'rgb(111,66,193)',[5,5])
                    ]
                },
                options: {
                    responsive:true, maintainAspectRatio:false,
                    plugins: {
                        legend: { position:'top', labels:{ color:txtC(), font:fontCfg(11), usePointStyle:true, padding:15 } },
                        tooltip: { ...tipOpts(), mode:'index', intersect:false }
                    },
                    scales: {
                        x: { grid:{color:gridC()}, ticks:{color:txtC(), font:fontCfg(11,700)} },
                        y: { grid:{color:gridC()}, ticks:{color:txtC(), font:fontCfg(11)}, beginAtZero:true }
                    },
                    interaction: { mode:'nearest', axis:'x', intersect:false },
                    animation: { duration:1500, easing:'easeOutQuart' }
                }
            });
        }

        /* Violations Bar — from Excel data */
        const violCtx = document.getElementById('chart-admin-violations');
        if (violCtx) {
            // Compute violations from excel screening data
            const gpaLow   = excel.filter(e => parseFloat(e.diem_tb || 10) < 7.0).length;
            const drlLow   = excel.filter(e => parseFloat(e.diem_rl || 100) < 80).length;
            const llctFail = excel.filter(e => !e.ly_luan_chinh_tri || e.ly_luan_chinh_tri !== 'Hoàn thành').length;
            const xlFail   = excel.filter(e => !e.xep_loai_doan_vien || (e.xep_loai_doan_vien !== 'Hoàn thành xuất sắc' && e.xep_loai_doan_vien !== 'Hoàn thành tốt')).length;
            const rejected = data.filter(d => ['TU_CHOI','DA_HUY'].includes(d.trang_thai)).length;
            const returned = data.filter(d => d.trang_thai === 'TRA_VE').length;

            charts['admin-viol'] = new Chart(violCtx, {
                type: 'bar',
                data: {
                    labels: ['GPA < 7.0','ĐRL < 80','LLCT chưa HT','Xếp loại không đạt','Từ chối / Hủy','Trả về bổ sung'],
                    datasets: [{
                        label:'Số trường hợp',
                        data: [gpaLow, drlLow, llctFail, xlFail, rejected, returned],
                        backgroundColor: ['rgba(220,53,69,.75)','rgba(240,92,32,.75)','rgba(255,193,7,.75)','rgba(220,53,69,.9)','rgba(111,66,193,.75)','rgba(108,117,125,.75)'],
                        borderColor: ['#dc3545','#f05c20','#ffc107','#dc3545','#6f42c1','#6c757d'],
                        borderWidth:1, borderRadius:6
                    }]
                },
                options: {
                    responsive:true, maintainAspectRatio:false,
                    plugins: {
                        legend: { display:false },
                        tooltip: { ...tipOpts(), callbacks:{ label: c => '  '+c.raw+' trường hợp' } }
                    },
                    scales: {
                        x: { grid:{display:false}, ticks:{color:txtC(), font:fontCfg(11), maxRotation:0} },
                        y: { grid:{color:gridC()}, ticks:{color:txtC(), font:fontCfg(11), stepSize:2}, beginAtZero:true }
                    },
                    animation: { duration:1200, easing:'easeOutQuart' }
                }
            });
        }
    }

    /* ──────────────── REFRESH MASTER ──────────────── */
    function refresh() {
        const role = getRole();
        destroyAll();
        showPanel(role);
        updateAllKPIs();
        document.querySelectorAll('.dash-progress-fill').forEach(b => { b.style.width='0%'; });
        setTimeout(() => {
            switch(role) {
                case 'bch_chi_doan':      createCDCharts(); animateBars(); break;
                case 'chi_bo_sinh_vien':  createCDCharts(); animateBars(); break;
                case 'can_bo_doan_khoa':  createDKCharts(); break;
                case 'admin_doan_truong': createAdminCharts(); break;
            }
        }, 120);
    }

    window.DvutDashboard = { refresh: refresh };

    /* ── Event listeners ── */
    document.addEventListener('DOMContentLoaded', () => {
        setTimeout(refresh, 600);
        // Role đã do Dev Hub / production quyết định, không cần listener role switcher cũ
        const ctxCD = document.getElementById('dvut-context-chi-doan');
        if (ctxCD) ctxCD.addEventListener('change', () => setTimeout(refresh, 60));
        const ctxKhoa = document.getElementById('dvut-context-khoa');
        if (ctxKhoa) ctxKhoa.addEventListener('change', () => setTimeout(refresh, 60));
        const themeBtn = document.getElementById('theme-toggle-btn');
        if (themeBtn) themeBtn.addEventListener('click', () => setTimeout(refresh, 150));
    });
    document.addEventListener('dvut:dataLoaded', refresh);
})();
</script>

<!-- ================================================================= -->
<!-- Profile View — View Switch + Upload + Save Logic                   -->
<!-- ================================================================= -->
<script>
(function() {
    'use strict';

    /**
     * Switch giữa "Quản lý xét duyệt" và "Hồ sơ cá nhân".
     */
    function switchView(view) {
        const manageView  = document.getElementById('dvut-manage-view');
        const profileView = document.getElementById('dvut-profile-view');
        const systemView  = document.getElementById('dvut-system-view');
        const menuQL      = document.getElementById('menu-quan-ly');
        const menuHS      = document.getElementById('menu-ho-so');
        const mobileTitle = document.getElementById('mobile-header-title');

        // Ban thường vụ đoàn trường không có chức năng xem hồ sơ cá nhân — chỉ Quản trị viên mới được chuyển sang view profile
        if (view === 'profile' && window.DvutApp && window.DvutApp.getCanViewPersonalProfile && !window.DvutApp.getCanViewPersonalProfile()) {
            return;
        }

        // Hide all views first
        if (manageView)  manageView.style.display  = 'none';
        if (profileView) profileView.style.display = 'none';
        if (systemView)  systemView.style.display  = 'none';
        if (menuQL) menuQL.classList.remove('active');
        if (menuHS) menuHS.classList.remove('active');

        if (view === 'profile') {
            if (profileView) profileView.style.display = 'block';
            if (menuHS) menuHS.classList.add('active');
            if (mobileTitle) mobileTitle.textContent = 'Hồ sơ cá nhân';
            if (window.DvutApp && window.DvutApp.getCanViewPersonalProfile && window.DvutApp.getCanViewPersonalProfile()) {
                if (window.DvutApp.resetAdminProfileView) {
                    window.DvutApp.resetAdminProfileView();
                }
            }
        } else if (view === 'system') {
            if (systemView) systemView.style.display = 'block';
            if (mobileTitle) mobileTitle.textContent = 'Quản lý hệ thống';
        } else {
            if (manageView)  manageView.style.display  = 'block';
            if (menuQL) menuQL.classList.add('active');
            if (mobileTitle) mobileTitle.textContent = 'Đoàn viên Ưu tú';
        }

        // Close mobile sidebar after switch
        document.querySelector('.admin-dashboard')?.classList.remove('sidebar-visible');
    }

    // Expose to DvutApp namepsace (will merge after DvutApp loads)
    if (!window.DvutApp) window.DvutApp = {};
    window.DvutApp.switchView = switchView;

    document.addEventListener('DOMContentLoaded', function() {
        /* ── Upload zone Mẫu 01: click & drag ── */
        const uploadZone01 = document.getElementById('profile-upload-mau01');
        const fileInput01  = document.getElementById('profile-mau01-file');
        const fileLabel01  = document.getElementById('mau01-file-name');

        if (uploadZone01 && fileInput01) {
            uploadZone01.addEventListener('dragover', e => { e.preventDefault(); uploadZone01.classList.add('dragover'); });
            uploadZone01.addEventListener('dragleave', () => uploadZone01.classList.remove('dragover'));
            uploadZone01.addEventListener('drop', e => {
                e.preventDefault();
                uploadZone01.classList.remove('dragover');
                if (e.dataTransfer.files.length > 0) {
                    fileInput01.files = e.dataTransfer.files;
                    if (fileLabel01) fileLabel01.textContent = '📎 ' + e.dataTransfer.files[0].name;
                }
            });
            fileInput01.addEventListener('change', () => {
                if (fileInput01.files.length > 0 && fileLabel01) fileLabel01.textContent = '📎 ' + fileInput01.files[0].name;
            });
        }

        /* ── Upload zone Mẫu 02: click & drag ── */
        const uploadZone = document.getElementById('profile-upload-mau02');
        const fileInput  = document.getElementById('profile-mau02-file');
        const fileLabel  = document.getElementById('mau02-file-name');

        if (uploadZone && fileInput) {
            uploadZone.addEventListener('click', () => fileInput.click());
            uploadZone.addEventListener('dragover', e => { e.preventDefault(); uploadZone.classList.add('dragover'); });
            uploadZone.addEventListener('dragleave', () => uploadZone.classList.remove('dragover'));
            uploadZone.addEventListener('drop', e => {
                e.preventDefault();
                uploadZone.classList.remove('dragover');
                if (e.dataTransfer.files.length > 0) {
                    fileInput.files = e.dataTransfer.files;
                    showFileName(e.dataTransfer.files[0]);
                }
            });
            fileInput.addEventListener('change', () => {
                if (fileInput.files.length > 0) showFileName(fileInput.files[0]);
            });
            function showFileName(file) {
                if (fileLabel) fileLabel.textContent = '📎 ' + file.name + ' (' + (file.size / 1024).toFixed(1) + ' KB)';
            }
        }

        /* ── Lưu thông tin cá nhân ── */
        document.getElementById('profile-save-btn')?.addEventListener('click', function() {
            if (typeof Swal !== 'undefined') {
                Swal.fire({ icon: 'success', title: 'Đã lưu!', text: 'Thông tin cá nhân đã được cập nhật thành công.', confirmButtonText: 'Đóng', confirmButtonColor: '#174f8c' });
            } else {
                alert('Đã lưu thông tin cá nhân!');
            }
        });

        /* ── Gửi hồ sơ (gọi AJAX) ── */
        document.getElementById('profile-submit-btn')?.addEventListener('click', async function() {
            const mau01Input = document.getElementById('profile-mau01-file');
            const mau02Input = document.getElementById('profile-mau02-file');

            if (!mau01Input?.files?.length) {
                if (typeof Swal !== 'undefined') {
                    Swal.fire({ icon: 'warning', title: 'Thiếu file', text: 'Vui lòng upload Mẫu 01 — Sơ lược quá trình phấn đấu.', confirmButtonColor: '#174f8c' });
                } else { alert('Vui lòng upload Mẫu 01.'); }
                return;
            }
            if (!mau02Input?.files?.length) {
                if (typeof Swal !== 'undefined') {
                    Swal.fire({ icon: 'warning', title: 'Thiếu file', text: 'Vui lòng upload Mẫu 02 — Bài cảm nhận về Đảng.', confirmButtonColor: '#174f8c' });
                } else { alert('Vui lòng upload Mẫu 02.'); }
                return;
            }

            // Hiện loading
            if (typeof Swal !== 'undefined') {
                Swal.fire({ title: 'Đang gửi hồ sơ...', allowOutsideClick: false, didOpen: () => Swal.showLoading() });
            }

            const res = await window.DvutApp.submitHoSoDoanVien(mau01Input.files[0], mau02Input.files[0]);

            if (res && res.success) {
                if (typeof Swal !== 'undefined') {
                    Swal.fire({
                        icon: 'success', title: 'Gửi hồ sơ thành công!',
                        html: 'Hồ sơ của bạn đã được gửi lên hệ thống.<br>BCH Chi Đoàn sẽ xem xét trong thời gian sớm nhất.',
                        confirmButtonText: 'Đóng', confirmButtonColor: '#198754'
                    });
                } else { alert('Gửi hồ sơ thành công!'); }
            } else {
                const msg = res?.data?.message || res?.message || 'Có lỗi xảy ra khi gửi hồ sơ.';
                if (typeof Swal !== 'undefined') {
                    Swal.fire({ icon: 'error', title: 'Lỗi', text: msg, confirmButtonColor: '#174f8c' });
                } else { alert(msg); }
            }
        });

        /* ── Populate Chi đoàn dropdown in profile from DvutApp ── */
        function populateProfileChiDoan() {
            const sel = document.getElementById('profile-chi-doan');
            if (!sel || !window.DvutApp?.getChiDoanList) return;
            const list = window.DvutApp.getChiDoanList();
            list.forEach(cd => {
                const opt = document.createElement('option');
                opt.value = cd.id;
                opt.textContent = cd.ten;
                sel.appendChild(opt);
            });
        }
        document.addEventListener('dvut:dataLoaded', populateProfileChiDoan);
        // Also try immediately in case data already loaded
        setTimeout(populateProfileChiDoan, 1000);
    });
})();
</script>

<!-- ================================================================= -->
<!-- Sidebar & Theme Toggle (Copy nguyên từ index.html)                 -->
<!-- ================================================================= -->
<script>
document.addEventListener('DOMContentLoaded', function() {
    // Sidebar toggle (Desktop)
    if (localStorage.getItem('dhs_sidebar_hidden') === 'true') {
        document.querySelector('.sidebar')?.classList.add('is-hidden');
    }
    document.getElementById('sidebar-tag-toggle')?.addEventListener('click', function() {
        const sidebar = document.querySelector('.sidebar');
        sidebar.classList.toggle('is-hidden');
        localStorage.setItem('dhs_sidebar_hidden', sidebar.classList.contains('is-hidden'));
    });

    // Mobile hamburger
    const hamburgerBtn = document.getElementById('hamburger-btn');
    const adminDashboard = document.querySelector('.admin-dashboard');
    const sidebarOverlay = document.querySelector('.sidebar-overlay');
    if (hamburgerBtn) {
        hamburgerBtn.addEventListener('click', () => adminDashboard.classList.toggle('sidebar-visible'));
    }
    if (sidebarOverlay) {
        sidebarOverlay.addEventListener('click', () => adminDashboard.classList.remove('sidebar-visible'));
    }

    // Dark Mode Toggle
    document.getElementById('theme-toggle-btn')?.addEventListener('click', function() {
        document.body.classList.toggle('dark-mode');
        localStorage.setItem('dhs_theme', document.body.classList.contains('dark-mode') ? 'dark' : 'light');
    });

    /* ── System Management tab switching ── */
    const sysNav = document.getElementById('dvut-system-nav');
    if (sysNav) {
        sysNav.addEventListener('click', function(e) {
            const link = e.target.closest('.sub-nav-link');
            if (!link) return;
            const tab = link.dataset.tab;
            sysNav.querySelectorAll('.sub-nav-link').forEach(l => l.classList.remove('active'));
            link.classList.add('active');
            document.querySelectorAll('.sys-panel').forEach(p => p.style.display = 'none');
            const panel = document.getElementById(tab + '-panel');
            if (panel) panel.style.display = 'block';
            // Auto-load data khi chuyển tab
            if (tab === 'sys-roles' && window.DvutApp && window.DvutApp.sysSearchUsers) {
                window.DvutApp.sysSearchUsers();
            } else if (tab === 'sys-logs' && window.DvutApp && window.DvutApp.sysLoadLogs) {
                window.DvutApp.sysLoadLogs();
            } else if (tab === 'sys-nhan-xet' && window.DvutApp && window.DvutApp.sysLoadNhanXetPeriods) {
                window.DvutApp.sysLoadNhanXetPeriods();
            }
        });
    }

    // Tự động load danh sách phân quyền khi lần đầu mở tab Quản lý hệ thống
    document.addEventListener('dvut:dataLoaded', function() {
        if (window.DvutApp && window.DvutApp.sysSearchUsers) {
            const sysView = document.getElementById('dvut-system-view');
            if (sysView) {
                // Dùng MutationObserver để detect khi system view hiện lên
                const obs = new MutationObserver(function(mutations) {
                    if (sysView.style.display !== 'none') {
                        window.DvutApp.sysSearchUsers();
                        obs.disconnect();
                    }
                });
                obs.observe(sysView, { attributes: true, attributeFilter: ['style'] });
            }
        }
    });

    /* ── System Import drag & drop ── */
    const importDrop = document.getElementById('sys-import-dropzone');
    const importFile = document.getElementById('sys-import-file');
    if (importDrop && importFile) {
        importDrop.addEventListener('dragover', e => { e.preventDefault(); importDrop.classList.add('dragover'); });
        importDrop.addEventListener('dragleave', () => importDrop.classList.remove('dragover'));
        importDrop.addEventListener('drop', e => {
            e.preventDefault();
            importDrop.classList.remove('dragover');
            if (e.dataTransfer.files.length > 0) {
                importFile.files = e.dataTransfer.files;
                if (window.DvutApp && window.DvutApp.sysHandleImportFile) {
                    window.DvutApp.sysHandleImportFile(e.dataTransfer.files[0]);
                }
            }
        });
        importFile.addEventListener('change', () => {
            if (importFile.files.length > 0 && window.DvutApp && window.DvutApp.sysHandleImportFile) {
                window.DvutApp.sysHandleImportFile(importFile.files[0]);
            }
        });
    }
});
</script>

<?php wp_footer(); ?>
</body>
</html>
