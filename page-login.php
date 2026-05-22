<?php
/**
 * Template Name: Đăng nhập Unified — ĐVƯT UEL
 * Description: Giao diện màn hình đăng nhập độc lập Premium cho hệ thống Đoàn viên Ưu tú.
 *              Hỗ trợ tự động chuyển đổi Dark Mode, các hàm dynamic của WordPress.
 */

if ( is_user_logged_in() ) {
    wp_redirect( home_url('/') );
    exit;
}

// Lấy Google Client ID từ cấu hình hệ thống
$google_client_id = defined('DVUT_GOOGLE_CLIENT_ID') ? DVUT_GOOGLE_CLIENT_ID : get_option('dvut_google_client_id', '');
$is_dummy_client = empty($google_client_id) || strpos($google_client_id, 'dummy') !== false;
if (empty($google_client_id)) {
    $google_client_id = '1045330366657-dummyclientid.apps.googleusercontent.com';
}
?>
<!doctype html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Đăng nhập — Tuổi trẻ Kinh tế - Luật</title>
    
    <!-- Favicon / Site Icon -->
    <link rel="shortcut icon" type="image/png" href="<?php echo esc_url( get_template_directory_uri() . '/assets/uel_logo.png' ); ?>" />
    <link rel="apple-touch-icon" href="<?php echo esc_url( get_template_directory_uri() . '/assets/uel_logo.png' ); ?>" />
    
    <!-- Dependencies -->
    <link rel="stylesheet" href="<?php echo esc_url( includes_url('css/dashicons.min.css') ); ?>" />
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/sweetalert2@11.10.3/dist/sweetalert2.min.css" />
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@400;500;600;700&display=swap" rel="stylesheet">
    <script src="https://accounts.google.com/gsi/client" async defer onload="initGoogleSignIn()"></script>
    
    <script>
        // Thiết lập đồng bộ theme lập tức trước khi render body để tránh chớp nháy trắng
        (function() {
            try {
                var localTheme = localStorage.getItem('dhs_theme');
                var systemDark = window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches;
                if (localTheme === 'dark' || (localTheme === null && systemDark)) {
                    document.documentElement.classList.add('dark-mode');
                }
            } catch (e) {}
        })();
        
        // Cấu hình Ajax URL toàn cục cho Frontend
        var ajaxUrl = "<?php echo esc_url( admin_url('admin-ajax.php') ); ?>";
    </script>
    
    <style>
        /* CSS Variables - Core Palette */
        :root {
            --bg-primary: #f8fafc;
            --bg-card: #ffffff;
            --text-main: #1e293b;
            --text-muted: #64748b;
            --border-color: #e2e8f0;
            --primary: #174f8c;
            --primary-hover: #103d6d;
            --primary-glow: rgba(23, 79, 140, 0.15);
            --accent-orange: #f05c20;
            --accent-orange-hover: #c94a1d;
            --input-bg: #ffffff;
            --google-bg: #ffffff;
            --google-border: #cbd5e1;
            --google-text: #334155;
            --shadow-card: 0 20px 40px -5px rgba(15, 23, 42, 0.1), 0 0 0 1px rgba(15, 23, 42, 0.05);
        }

        html.dark-mode {
            --bg-primary: #0f172a;
            --bg-card: #1e293b;
            --text-main: #f1f5f9;
            --text-muted: #94a3b8;
            --border-color: #334155;
            --primary: #38bdf8;
            --primary-hover: #0ea5e9;
            --primary-glow: rgba(56, 189, 248, 0.15);
            --accent-orange: #f97316;
            --accent-orange-hover: #ea580c;
            --input-bg: #0f172a;
            --google-bg: #334155;
            --google-border: #475569;
            --google-text: #f1f5f9;
            --shadow-card: 0 25px 50px -12px rgba(0, 0, 0, 0.5), 0 0 0 1px rgba(255, 255, 255, 0.05);
        }

        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
            font-family: 'Montserrat', sans-serif;
            transition: background-color 0.3s ease, border-color 0.3s ease;
        }

        body {
            background-color: var(--bg-primary);
            color: var(--text-main);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
            overflow-x: hidden;
            position: relative;
        }

        /* Ambient glowing backgrounds in dark mode */
        .ambient-glow-1 {
            position: absolute;
            width: 500px;
            height: 500px;
            background: radial-gradient(circle, rgba(23, 79, 140, 0.15) 0%, rgba(23, 79, 140, 0) 70%);
            top: -100px;
            left: -100px;
            z-index: 0;
            pointer-events: none;
        }

        .ambient-glow-2 {
            position: absolute;
            width: 600px;
            height: 600px;
            background: radial-gradient(circle, rgba(240, 92, 32, 0.08) 0%, rgba(240, 92, 32, 0) 70%);
            bottom: -200px;
            right: -100px;
            z-index: 0;
            pointer-events: none;
        }

        html.dark-mode .ambient-glow-1 {
            background: radial-gradient(circle, rgba(56, 189, 248, 0.15) 0%, rgba(56, 189, 248, 0) 70%);
        }

        html.dark-mode .ambient-glow-2 {
            background: radial-gradient(circle, rgba(249, 115, 22, 0.1) 0%, rgba(249, 115, 22, 0) 70%);
        }

        .login-wrapper {
            position: relative;
            z-index: 10;
            width: 100%;
            max-width: 980px;
            display: grid;
            grid-template-columns: 1.1fr 0.9fr;
            background-color: var(--bg-card);
            border-radius: 24px;
            box-shadow: var(--shadow-card);
            overflow: hidden;
            min-height: 600px;
        }

        /* Left Side: Dynamic Showcase Panel */
        .showcase-panel {
            background: linear-gradient(135deg, #0f172a 0%, #1e3a8a 50%, #0f172a 100%);
            color: #ffffff;
            padding: 50px 40px;
            display: flex;
            flex-direction: column;
            justify-content: space-between;
            position: relative;
            overflow: hidden;
        }

        .showcase-panel::before {
            content: '';
            position: absolute;
            top: 0; left: 0; right: 0; bottom: 0;
            background: url('https://images.unsplash.com/photo-1541339907198-e08756dedf3f?auto=format&fit=crop&w=1200&q=80') center center / cover no-repeat;
            opacity: 0.12;
            z-index: 1;
        }

        .showcase-header {
            position: relative;
            z-index: 2;
            display: flex;
            align-items: center;
            gap: 15px;
        }

        .showcase-logo {
            height: 64px;
            width: auto;
            filter: drop-shadow(0 4px 6px rgba(0,0,0,0.2));
        }

        .showcase-brand h2 {
            font-size: 1.25em;
            font-weight: 700;
            letter-spacing: 0.5px;
            background: linear-gradient(to right, #38bdf8, #f43f5e);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
        }

        .showcase-brand p {
            font-size: 0.75em;
            opacity: 0.7;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 1px;
        }

        .showcase-content {
            position: relative;
            z-index: 2;
            margin: 40px 0;
        }

        .showcase-content h1 {
            font-size: 2.1em;
            font-weight: 700;
            line-height: 1.25;
            margin-bottom: 15px;
            text-shadow: 0 4px 10px rgba(0,0,0,0.3);
        }

        .showcase-content h1 span {
            color: #38bdf8;
        }

        .showcase-content p {
            font-size: 0.95em;
            line-height: 1.6;
            opacity: 0.85;
            font-weight: 500;
        }

        .showcase-footer {
            position: relative;
            z-index: 2;
            font-size: 0.78em;
            opacity: 0.7;
            font-weight: 500;
            display: flex;
            justify-content: space-between;
            align-items: center;
            border-top: 1px solid rgba(255, 255, 255, 0.15);
            padding-top: 20px;
        }

        /* Right Side: Form Panel */
        .form-panel {
            padding: 50px 45px;
            display: flex;
            flex-direction: column;
            justify-content: center;
        }

        .form-header {
            margin-bottom: 30px;
        }

        .form-header h2 {
            font-size: 1.7em;
            font-weight: 700;
            color: var(--text-main);
            margin-bottom: 8px;
        }

        .form-header p {
            font-size: 0.88em;
            color: var(--text-muted);
            font-weight: 500;
        }

        /* Custom Input Styling */
        .input-group {
            margin-bottom: 20px;
            position: relative;
        }

        .input-group label {
            display: block;
            font-size: 0.82em;
            font-weight: 600;
            color: var(--text-muted);
            margin-bottom: 8px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .input-wrapper {
            position: relative;
            display: flex;
            align-items: center;
        }

        .input-wrapper .input-icon {
            position: absolute;
            left: 14px;
            color: var(--text-muted);
            font-size: 20px;
            width: 20px;
            height: 20px;
            pointer-events: none;
        }

        #password {
            padding-right: 44px;
        }

        .input-wrapper input {
            width: 100%;
            padding: 14px 16px 14px 44px;
            border-radius: 12px;
            border: 1.5px solid var(--border-color);
            background-color: var(--input-bg);
            color: var(--text-main);
            font-size: 0.95em;
            font-weight: 500;
            outline: none;
            transition: all 0.25s ease;
        }

        .input-wrapper input:focus {
            border-color: var(--primary);
            box-shadow: 0 0 0 4px var(--primary-glow);
        }

        /* Password Toggle */
        .toggle-password {
            position: absolute;
            right: 14px;
            color: var(--text-muted);
            cursor: pointer;
            font-size: 20px;
            width: 20px;
            height: 20px;
            user-select: none;
        }

        .toggle-password:hover {
            color: var(--text-main);
        }

        /* Buttons */
        .btn-submit {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
            width: 100%;
            padding: 14px;
            background: linear-gradient(135deg, var(--primary), var(--primary-hover));
            color: #ffffff;
            border: none;
            border-radius: 12px;
            font-size: 0.95em;
            font-weight: 700;
            cursor: pointer;
            box-shadow: 0 4px 15px var(--primary-glow);
            transition: all 0.25s ease;
            margin-top: 10px;
        }

        .btn-submit:hover {
            transform: translateY(-1px);
            box-shadow: 0 6px 20px var(--primary-glow);
            filter: brightness(1.1);
        }

        .btn-submit:active {
            transform: translateY(1px);
        }

        .btn-submit:disabled {
            opacity: 0.7;
            cursor: not-allowed;
            transform: none;
            box-shadow: none;
        }

        .divider {
            display: flex;
            align-items: center;
            text-align: center;
            margin: 25px 0;
            color: var(--text-muted);
            font-size: 0.78em;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 1px;
        }

        .divider::before, .divider::after {
            content: '';
            flex: 1;
            border-bottom: 1.5px solid var(--border-color);
        }

        .divider:not(:empty)::before {
            margin-right: 15px;
        }

        .divider:not(:empty)::after {
            margin-left: 15px;
        }

        /* Google Login Button Custom */
        .btn-google-login-custom {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 12px;
            width: 100%;
            height: 100%;
            padding: 13px;
            background-color: var(--google-bg);
            border: 1.5px solid var(--google-border);
            border-radius: 12px;
            color: var(--google-text);
            font-size: 0.9em;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.25s cubic-bezier(0.4, 0, 0.2, 1);
            outline: none;
            box-shadow: 0 2px 4px rgba(0,0,0,0.02);
        }

        .google-signin-wrapper {
            position: relative;
            width: 100%;
            margin-top: 15px;
            height: 48px;
            max-width: 400px;
            margin-left: auto;
            margin-right: auto;
        }

        .google-signin-wrapper:hover .btn-google-login-custom {
            background-color: var(--border-color);
            transform: translateY(-1px);
            box-shadow: 0 4px 12px rgba(0,0,0,0.05);
        }

        .google-signin-wrapper:active .btn-google-login-custom {
            transform: translateY(0);
        }

        .btn-google-login-custom img {
            width: 20px;
            height: 20px;
        }

        /* Spinner Loading */
        .spinner-loading {
            display: inline-block;
            width: 18px;
            height: 18px;
            border: 2.5px solid rgba(255,255,255,0.3);
            border-radius: 50%;
            border-top-color: #ffffff;
            animation: spin-pulse 0.8s linear infinite;
        }

        @keyframes spin-pulse {
            to { transform: rotate(360deg); }
        }

        /* Theme Switcher Widget */
        .theme-switcher {
            position: absolute;
            top: 20px;
            right: 20px;
            z-index: 100;
        }

        .theme-btn {
            background-color: var(--bg-card);
            border: 1.5px solid var(--border-color);
            color: var(--text-main);
            width: 44px;
            height: 44px;
            border-radius: 12px;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            box-shadow: 0 4px 6px rgba(0,0,0,0.05);
            transition: all 0.2s ease;
        }

        .theme-btn:hover {
            transform: scale(1.05);
            border-color: var(--primary);
        }

        .theme-btn .dashicons {
            font-size: 20px;
            width: 20px;
            height: 20px;
        }

        /* Responsive Breakpoints */
        @media (max-width: 900px) {
            .login-wrapper {
                grid-template-columns: 1fr;
                max-width: 500px;
                min-height: auto;
            }

            .showcase-panel {
                display: none;
            }

            .form-panel {
                padding: 45px 35px;
            }
        }

        @media (max-width: 480px) {
            body {
                padding: 10px;
            }

            .form-panel {
                padding: 35px 20px;
            }

            .form-header h2 {
                font-size: 1.45em;
            }
        }

        /* SweetAlert2 Custom Styling overrides */
        .swal2-popup {
            font-family: 'Montserrat', sans-serif !important;
            border-radius: 16px !important;
        }
        html.dark-mode .swal2-popup {
            background-color: var(--bg-card) !important;
            color: var(--text-main) !important;
        }
        html.dark-mode .swal2-title, html.dark-mode .swal2-html-container {
            color: var(--text-main) !important;
        }
    </style>
</head>
<body class="login-body">

    <!-- Ambient glowing graphics -->
    <div class="ambient-glow-1"></div>
    <div class="ambient-glow-2"></div>

    <!-- Theme Switcher Widget -->
    <div class="theme-switcher">
        <button type="button" class="theme-btn" id="theme-toggle-btn" title="Chuyển đổi giao diện Sáng / Tối">
            <span class="dashicons dashicons-admin-appearance"></span>
        </button>
    </div>

    <!-- Login Container -->
    <div class="login-wrapper">
        
        <!-- Showcase Panel (Left Side) -->
        <div class="showcase-panel">
            <div class="showcase-header">
                <img class="showcase-logo" src="<?php echo esc_url( get_template_directory_uri() . '/assets/uel_logo.png' ); ?>" alt="UEL Logo">
                <div class="showcase-brand">
                    <h2>Tuổi trẻ UEL</h2>
                    <p>Đoàn viên ưu tú</p>
                </div>
            </div>
            
            <div class="showcase-content">
                <h1>Hệ thống Quản lý<br><span>Đoàn viên Ưu tú</span><br>&amp; Phát triển Đảng</h1>
                <p>Nền tảng số hóa quy trình xét duyệt đề cử, theo dõi rèn luyện và giới thiệu kết nạp Đảng dành cho Đoàn viên ưu tú trường Đại học Kinh tế - Luật.</p>
            </div>
            
            <div class="showcase-footer">
                <span>© 2026 Tuổi trẻ Kinh tế - Luật</span>
                <span>Phiên bản độc lập v3.0</span>
            </div>
        </div>

        <!-- Form Panel (Right Side) -->
        <div class="form-panel">
            <div class="form-header">
                <h2>Đăng nhập</h2>
                <p>Hệ thống Độc lập xét công nhận Đoàn viên Ưu tú</p>
            </div>

            <!-- Login Form -->
            <form id="login-form" method="POST" action="">
                <!-- Username / MSSV / Email -->
                <div class="input-group">
                    <label for="username">Mã số sinh viên / Email</label>
                    <div class="input-wrapper">
                        <span class="dashicons dashicons-admin-users input-icon"></span>
                        <input type="text" id="username" name="username" placeholder="Nhập MSSV hoặc email" required autocomplete="username">
                    </div>
                </div>

                <!-- Password -->
                <div class="input-group">
                    <label for="password">Mật khẩu</label>
                    <div class="input-wrapper">
                        <span class="dashicons dashicons-lock input-icon"></span>
                        <input type="password" id="password" name="password" placeholder="Nhập mật khẩu" required autocomplete="current-password">
                        <span class="dashicons dashicons-visibility toggle-password"></span>
                    </div>
                </div>

                <!-- Submit Button -->
                <button type="submit" class="btn-submit">
                    <span>Đăng nhập hệ thống</span>
                </button>
            </form>

            <div class="divider">Hoặc tiếp tục với</div>

            <!-- Google Authentication Button Container -->
            <div class="google-signin-wrapper">
                <button type="button" class="btn-google-login-custom" id="custom-google-btn">
                    <img src="https://upload.wikimedia.org/wikipedia/commons/c/c1/Google_%22G%22_logo.svg" alt="Google G Logo">
                    <span style="font-family: 'Montserrat', sans-serif !important;">Đăng nhập bằng Google</span>
                </button>
                <?php if ( ! $is_dummy_client ) : ?>
                    <!-- Lớp overlay Google thực tế (nằm đè lên nút custom với opacity cực thấp) -->
                    <div id="google-signin-btn" style="position: absolute; top: 0; left: 0; width: 100%; height: 100%; opacity: 0.01; z-index: 10; cursor: pointer; overflow: hidden; display: flex; justify-content: center; align-items: center;"></div>
                <?php endif; ?>
            </div>
        </div>

    </div>

    <!-- Scripts -->
    <script src="https://cdn.jsdelivr.net/npm/jquery@3.6.0/dist/jquery.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11.10.3/dist/sweetalert2.all.min.js"></script>
    
    <script>
        $(document).ready(function() {
            // Đồng bộ icon theme toggle dựa trên trạng thái hiện tại
            function updateThemeIcon() {
                var btn = $('#theme-toggle-btn .dashicons');
                if ($('html').hasClass('dark-mode')) {
                    btn.removeClass('dashicons-admin-appearance').addClass('dashicons-lightbulb');
                } else {
                    btn.removeClass('dashicons-lightbulb').addClass('dashicons-admin-appearance');
                }
            }
            updateThemeIcon();

            // Toggle Sáng / Tối
            $('#theme-toggle-btn').on('click', function() {
                if ($('html').hasClass('dark-mode')) {
                    $('html').removeClass('dark-mode');
                    localStorage.setItem('dhs_theme', 'light');
                } else {
                    $('html').addClass('dark-mode');
                    localStorage.setItem('dhs_theme', 'dark');
                }
                updateThemeIcon();
                if (typeof renderGoogleButton === 'function') {
                    renderGoogleButton();
                }
            });

            // Password visibility toggle
            $('.toggle-password').on('click', function() {
                var passwordField = $('#password');
                var fieldType = passwordField.attr('type');
                if (fieldType === 'password') {
                    passwordField.attr('type', 'text');
                    $(this).removeClass('dashicons-visibility').addClass('dashicons-hidden');
                } else {
                    passwordField.attr('type', 'password');
                    $(this).removeClass('dashicons-hidden').addClass('dashicons-visibility');
                }
            });

            // Submit Form qua AJAX
            $('#login-form').on('submit', function(e) {
                e.preventDefault();
                
                var username = $('#username').val().trim();
                var password = $('#password').val();
                
                if (!username || !password) {
                    Swal.fire({
                        icon: 'warning',
                        title: 'Thông tin thiếu',
                        text: 'Vui lòng nhập đầy đủ Mã số / Email và Mật khẩu.',
                        confirmButtonColor: '#174f8c'
                    });
                    return;
                }
                
                var submitBtn = $('.btn-submit');
                var originalBtnText = submitBtn.html();
                
                // Hiển thị trạng thái Loading
                submitBtn.prop('disabled', true).html('<span class="spinner-loading"></span> Đang xác thực...');
                
                $.ajax({
                    url: ajaxUrl,
                    type: 'POST',
                    dataType: 'json',
                    data: {
                        action: 'dhs_unified_password_login',
                        username: username,
                        password: password
                    },
                    success: function(response) {
                        if (response && response.success) {
                            Swal.fire({
                                icon: 'success',
                                title: 'Đăng nhập thành công',
                                text: 'Hệ thống đang chuyển hướng bạn về Trang chủ...',
                                showConfirmButton: false,
                                timer: 1500,
                                timerProgressBar: true
                            }).then(function() {
                                window.location.href = "<?php echo esc_url( home_url('/') ); ?>";
                            });
                        } else {
                            submitBtn.prop('disabled', false).html(originalBtnText);
                            Swal.fire({
                                icon: 'error',
                                title: 'Đăng nhập thất bại',
                                text: response.message || 'Mã số sinh viên/Email hoặc Mật khẩu không đúng.',
                                confirmButtonColor: '#ea580c'
                            });
                        }
                    },
                    error: function() {
                        submitBtn.prop('disabled', false).html(originalBtnText);
                        Swal.fire({
                            icon: 'error',
                            title: 'Lỗi máy chủ',
                            text: 'Không thể kết nối đến máy chủ. Vui lòng kiểm tra lại kết nối mạng.',
                            confirmButtonColor: '#ea580c'
                        });
                    }
                });
            });

            // Re-render Google button on window resize to ensure correct width
            $(window).on('resize', function() {
                if (typeof renderGoogleButton === 'function') {
                    renderGoogleButton();
                }
            });
        });

        // 1. Google OAuth Callback
        function handleCredentialResponse(response) {
            if (!response || !response.credential) {
                Swal.fire({
                    icon: 'error',
                    title: 'Lỗi xác thực',
                    text: 'Không nhận được thông tin xác thực từ Google.',
                    confirmButtonColor: '#ea580c'
                });
                return;
            }

            // Hiển thị trạng thái Loading cao cấp
            Swal.fire({
                title: 'Đăng nhập',
                text: 'Hệ thống đang tiến hành xác thực và gán Chi Đoàn tự động...',
                allowOutsideClick: false,
                didOpen: function() {
                    Swal.showLoading();
                }
            });

            $.ajax({
                url: ajaxUrl,
                type: 'POST',
                dataType: 'json',
                data: {
                    action: 'dhs_unified_google_login',
                    credential: response.credential
                },
                success: function(res) {
                    if (res && res.success) {
                        Swal.fire({
                            icon: 'success',
                            title: 'Đăng nhập thành công',
                            text: res.message || 'Chào mừng bạn quay trở lại hệ thống!',
                            showConfirmButton: false,
                            timer: 1500,
                            timerProgressBar: true
                        }).then(function() {
                            window.location.href = "<?php echo esc_url( home_url('/') ); ?>";
                        });
                    } else {
                        Swal.fire({
                            icon: 'error',
                            title: 'Đăng nhập thất bại',
                            text: res.message || 'Email của bạn không nằm trong danh sách xét duyệt.',
                            confirmButtonColor: '#ea580c'
                        });
                    }
                },
                error: function() {
                    Swal.fire({
                        icon: 'error',
                        title: 'Lỗi kết nối',
                        text: 'Không thể kết nối đến máy chủ. Vui lòng thử lại sau.',
                        confirmButtonColor: '#ea580c'
                    });
                }
            });
        }

        // 2. Render Google Sign-in Button with premium styles, adaptive to Dark Mode
        function renderGoogleButton() {
            if (typeof google !== 'undefined' && google.accounts && google.accounts.id && document.getElementById("google-signin-btn")) {
                var container = $('.google-signin-wrapper');
                var width = container.width() || 320;
                
                // Google Identity Services limits button width between 200px and 400px
                if (width > 400) width = 400;
                if (width < 200) width = 200;

                google.accounts.id.renderButton(
                    document.getElementById("google-signin-btn"),
                    {
                        theme: $('html').hasClass('dark-mode') ? 'filled_black' : 'outline',
                        size: 'large',
                        width: width,
                        shape: 'rectangular',
                        text: 'signin_with',
                        logo_alignment: 'left'
                    }
                );
            }
        }

        // 3. Initialize Google Identity Services
        function initGoogleSignIn() {
            <?php if ( ! $is_dummy_client ) : ?>
            if (typeof google !== 'undefined' && google.accounts && google.accounts.id) {
                google.accounts.id.initialize({
                    client_id: "<?php echo esc_attr( $google_client_id ); ?>",
                    callback: handleCredentialResponse,
                    context: "signin",
                    ux_mode: "popup",
                    auto_select: false
                });
                renderGoogleButton();
                google.accounts.id.prompt();
            }
            <?php endif; ?>
        }

        // Trigger initialization if Google script is loaded before DOM ready
        if (typeof google !== 'undefined' && google.accounts && google.accounts.id) {
            initGoogleSignIn();
        }

        $(document).ready(function() {
            // Khi Client ID chưa được cấu hình (dummy), hiển thị SweetAlert2 hướng dẫn chi tiết khi bấm vào nút
            <?php if ( $is_dummy_client ) : ?>
            $('#custom-google-btn').on('click', function(e) {
                e.preventDefault();
                Swal.fire({
                    icon: 'warning',
                    title: 'Chưa cấu hình Google Client ID',
                    html: `
                        <div style="text-align: left; font-size: 0.9em; line-height: 1.6;">
                            <p style="margin-bottom: 12px; font-weight: 500;">Tính năng <strong>Đăng nhập bằng Google</strong> chưa hoạt động do hệ thống đang dùng Google Client ID mặc định.</p>
                            <p style="margin-bottom: 8px; font-weight: 600; color: var(--text-main);">Hướng dẫn cấu hình dành cho Quản trị viên:</p>
                            <ol style="padding-left: 20px; margin-bottom: 15px; font-weight: 500;">
                                <li style="margin-bottom: 6px;">Mở file <code style="background: rgba(0,0,0,0.06); padding: 2px 6px; border-radius: 4px; font-family: monospace; font-size: 0.9em;">wp-config.php</code> ở thư mục gốc của dự án.</li>
                                <li style="margin-bottom: 6px;">Thêm dòng cấu hình sau (trước dòng <em>/* That's all, stop editing! Happy publishing. */</em>):</li>
                            </ol>
                            <div style="position: relative; margin-bottom: 12px;">
                                <pre style="background: rgba(0,0,0,0.05); padding: 12px; border-radius: 8px; font-family: monospace; font-size: 0.82em; overflow-x: auto; border: 1.5px solid var(--border-color); color: #ea580c; font-weight: 600; word-break: break-all; white-space: pre-wrap;">define('DVUT_GOOGLE_CLIENT_ID', 'YOUR_CLIENT_ID.apps.googleusercontent.com');</pre>
                            </div>
                            <p style="font-size: 0.82em; color: var(--text-muted); line-height: 1.4;">* Bạn có thể lấy Google Client ID này từ trang quản trị <strong>Google Cloud Console</strong> (chọn dự án > APIs & Services > Credentials > OAuth 2.0 Client IDs).</p>
                        </div>
                    `,
                    confirmButtonText: 'Đã hiểu',
                    confirmButtonColor: '#174f8c',
                    width: '520px'
                });
            });
            <?php endif; ?>
        });
    </script>
</body>
</html>
