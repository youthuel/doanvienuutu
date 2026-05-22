<?php
// index.php — Fallback template bắt buộc cho WordPress.
// Mọi request fallback đều chuyển hướng an toàn về Trang chủ.
wp_safe_redirect( home_url( '/' ) );
exit;