<?php
/**
 * One-time script: thêm 500 đoàn viên từ hop-den.csv vào doan-vien.csv (test data).
 * Chạy: php append-500-doan-vien.php
 */
$dir = __DIR__;
$doanVienFile = $dir . '/doan-vien.csv';
$hopDenFile   = $dir . '/hop-den.csv';

$headerDoanVien = 'id,ho_ten,mssv,chi_doan_id,chi_doan,ngay_de_cu,trang_thai,so_luot_dong_y,tong_so_nguoi,ty_le,so_luoc_qua_trinh,bai_cam_nhan_file,ghi_chu,tien_do_cam_tinh_dang,file_chung_nhan_ctd,ngay_tu_choi';

$trangThaiList = [
    'CHO_NOP', 'CHO_NOP', 'CHO_NOP', 'CHO_CHI_DOAN', 'CHO_CHI_DOAN', 'CHO_DOAN_KHOA', 'CHO_DOAN_KHOA',
    'DA_CONG_NHAN', 'DA_CONG_NHAN', 'CHO_DT_XAC_NHAN_GT', 'CHO_DK_CHUYEN_DANG', 'CHO_DT_XAC_NHAN_CD',
    'DANG_VIEN_DU_BI', 'DANG_VIEN_CHINH_THUC', 'TU_CHOI'
];
$tienDoList = ['CHUA_THAM_GIA', 'CHUA_THAM_GIA', 'DANG_HOC', 'DA_HOAN_THANH', 'DA_HOAN_THANH'];

// Đọc MSSV đã có trong doan-vien.csv
$existingMssv = [];
if (($h = fopen($doanVienFile, 'r')) !== false) {
    $head = fgetcsv($h);
    while (($row = fgetcsv($h)) !== false) {
        if (isset($row[2]) && $row[2] !== '') $existingMssv[$row[2]] = true;
    }
    fclose($h);
}

// Đọc hop-den, lấy tối đa 500 dòng chưa có trong doan-vien
$candidates = [];
if (($h = fopen($hopDenFile, 'r')) !== false) {
    $head = fgetcsv($h);
    $idx = array_flip($head);
    $mssvIdx = $idx['mssv'] ?? 0;
    $hoTenIdx = $idx['ho_ten'] ?? 1;
    $chiDoanIdx = $idx['chi_doan'] ?? 3;
    $chiDoanIdIdx = $idx['chi_doan_id'] ?? 4;
    while (($row = fgetcsv($h)) !== false && count($candidates) < 500) {
        $mssv = isset($row[$mssvIdx]) ? trim($row[$mssvIdx]) : '';
        if ($mssv === '' || isset($existingMssv[$mssv])) continue;
        $existingMssv[$mssv] = true;
        $candidates[] = [
            'mssv' => $mssv,
            'ho_ten' => isset($row[$hoTenIdx]) ? trim($row[$hoTenIdx]) : '',
            'chi_doan' => isset($row[$chiDoanIdx]) ? trim($row[$chiDoanIdx]) : '',
            'chi_doan_id' => isset($row[$chiDoanIdIdx]) ? (int)$row[$chiDoanIdIdx] : 0,
        ];
    }
    fclose($h);
}

$n = count($candidates);
if ($n === 0) {
    echo "Không có dòng nào từ hop-den để thêm (có thể đã trùng hết MSSV).\n";
    exit(1);
}

// Xác định id bắt đầu: đọc lại doan-vien lấy max id
$nextId = 23;
if (($h = fopen($doanVienFile, 'r')) !== false) {
    fgetcsv($h);
    while (($row = fgetcsv($h)) !== false) {
        if (isset($row[0]) && is_numeric($row[0])) {
            $id = (int)$row[0];
            if ($id >= $nextId) $nextId = $id + 1;
        }
    }
    fclose($h);
}

$lines = [];
$months = ['01','02','03','04','05','06','07','08','09','10','11','12'];
$days = range(1, 28);

foreach ($candidates as $i => $c) {
    $id = $nextId + $i;
    $hoTen = str_replace('"', '""', $c['ho_ten']);
    $mssv = $c['mssv'];
    $chiDoanId = $c['chi_doan_id'];
    $chiDoan = $c['chi_doan'];
    $ngayDeCu = '2025-' . $months[$i % 12] . '-' . str_pad($days[$i % 28], 2, '0', STR_PAD_LEFT);
    $trangThai = $trangThaiList[$i % count($trangThaiList)];
    $tongSo = $trangThai === 'CHO_NOP' || $trangThai === 'TU_CHOI' ? 0 : rand(15, 25);
    $dongY = $trangThai === 'CHO_NOP' || $trangThai === 'TU_CHOI' ? 0 : (int)round($tongSo * (0.65 + (rand(0, 35) / 100)));
    $tyLe = $tongSo > 0 ? round($dongY / $tongSo * 100, 2) : 0;
    $soLuoc = ($trangThai !== 'CHO_NOP' && $trangThai !== 'TU_CHOI') ? 'Tham gia cong tac Doan tich cuc' : '';
    $baiFile = ($trangThai !== 'CHO_NOP' && $trangThai !== 'TU_CHOI') ? 'bai_cam_nhan_' . $id . '.pdf' : '';
    $tienDo = $tienDoList[$i % count($tienDoList)];
    $fileCtd = ($tienDo === 'DA_HOAN_THANH' && in_array($trangThai, ['DA_CONG_NHAN','DANG_VIEN_DU_BI','DANG_VIEN_CHINH_THUC'], true)) ? 'chung_nhan_ctd_' . $id . '.pdf' : '';
    $lines[] = implode(',', [
        $id,
        '"' . $hoTen . '"',
        $mssv,
        $chiDoanId,
        $chiDoan,
        $ngayDeCu,
        $trangThai,
        $dongY,
        $tongSo,
        $tyLe,
        '"' . str_replace('"', '""', $soLuoc) . '"',
        $baiFile,
        '',
        $tienDo,
        $fileCtd,
        ''
    ]);
}

$append = "\n" . implode("\n", $lines);
file_put_contents($doanVienFile, $append, FILE_APPEND | LOCK_EX);
echo "Da them " . count($lines) . " doan vien vao doan-vien.csv (id " . $nextId . " den " . ($nextId + count($lines) - 1) . ").\n";
