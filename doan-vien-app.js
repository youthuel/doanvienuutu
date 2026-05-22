/**
 * =================================================================
 * ĐOÀN VIÊN ƯU TÚ - APP.JS  (SRS v2 — Production MySQL-only)
 * ~6 200 dòng  |  12-table MySQL DB (không CSV)
 * =================================================================
 * File JS chính cho phân hệ "Quản lý Đoàn viên Ưu tú & Phát triển Đảng".
 *
 * DỮ LIỆU: Tất cả đều qua WordPress AJAX (admin-ajax.php),
 *           backend đọc/ghi trực tiếp 12 bảng MySQL (xem class-dvut-database.php).
 * DVUT_AJAX object (wp_localize_script) cung cấp:
 *   - ajax_url, nonce, theme_url
 *   - khoa_list, chi_doan_list
 *   - user (id, display_name, role, chi_doan_id, khoa_id, chi_bo_id, is_admin, mssv)
 *
 * PHÂN QUYỀN 3 CẤP (do hệ thống chính quyết định qua Onboarding):
 * - bch_chi_doan      : BCH Chi Đoàn — Đề cử, Nộp hồ sơ, BQ Chi Đoàn
 * - can_bo_doan_khoa  : Cán bộ Đoàn Khoa — Duyệt cấp Đoàn Khoa
 * - admin_doan_truong : Admin Đoàn Trường — Duyệt cấp cuối, Import Excel
 * =================================================================
 */

(function ($) {
    'use strict';

    // =================================================================
    // CẤU HÌNH — Dữ liệu từ WordPress AJAX
    // =================================================================
    if (typeof DVUT_AJAX === 'undefined') {
        console.error('[DVUT] DVUT_AJAX chưa được khởi tạo — wp_localize_script chưa chạy.');
        return;
    }

    const _currentUser = DVUT_AJAX.user || {};

    const ajaxUrl = DVUT_AJAX.ajax_url;
    const dvutNonce = DVUT_AJAX.nonce;

    // =================================================================
    // BIẾN TOÀN CỤC
    // =================================================================
    let allDoanVien = [];           // Danh sách đầy đủ từ server
    const KHOA_LIST = DVUT_AJAX.khoa_list || [];
    const CHI_DOAN_LIST = DVUT_AJAX.chi_doan_list || [];
    let currentTab = 'CHO_NOP';     // Tab đang active
    let currentPage = 1;            // Trang hiện tại
    let perPage = 10;             // Số dòng/trang
    let searchKeyword = '';         // Từ khóa tìm kiếm
    let filterKhoa = [];            // Mảng khoa đang lọc (dropdown multi-select — admin Đoàn Trường)
    let filterChiDoan = [];         // Mảng chi đoàn đang lọc (dropdown multi-select)

    // Phân quyền — luôn từ DVUT_AJAX.user
    let currentRole = _currentUser.role || 'bch_chi_doan';
    let currentChiDoanId = _currentUser.chi_doan_id || null;
    let currentKhoaId = _currentUser.khoa_id || null;
    let currentChiBoId = _currentUser.chi_bo_id || null;
    let currentUserId = _currentUser.id || 0;
    const isDevAdmin = !!_currentUser.is_admin;

    // Escape HTML helper — phòng chống XSS
    const esc = (str) => String(str ?? '').replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;').replace(/'/g, '&#39;');

    // =================================================================
    // BATCH SELECTION STATE
    // =================================================================
    let selectedIds = new Set();

    /**
     * Cập nhật toolbar hàng loạt: hiện/ẩn, đếm, và xác định nút nào hiện.
     */
    function updateBatchToolbar() {
        const toolbar = document.getElementById('dvut-batch-toolbar');
        if (!toolbar) return;

        const count = selectedIds.size;
        if (count === 0) {
            toolbar.style.display = 'none';
            // Reset select-all checkbox
            const cb = document.getElementById('dvut-select-all-cb');
            if (cb) { cb.checked = false; cb.indeterminate = false; }
            return;
        }

        toolbar.style.display = 'flex';
        toolbar.querySelector('.batch-count').textContent = `Đã chọn ${count} hồ sơ`;

        // Ẩn tất cả batch action buttons
        toolbar.querySelectorAll('[data-batch-action]').forEach(btn => btn.style.display = 'none');

        // Hiện nút tương ứng với tab + role hiện tại
        const actionMap = {
            'bch_chi_doan': {
                'CHO_CHI_DOAN': 'cd_duyet',
            },
            'can_bo_doan_khoa': {
                'CHO_DOAN_KHOA':     'dk_duyet',
                'CHO_DK_GIOI_THIEU': 'dk_duyet_gt',
                'CHO_DK_CHUYEN_DANG':'dk_duyet_cd',
            },
            'admin_doan_truong': {
                'CHO_DOAN_TRUONG':    'ban_hanh_qd',
            },
        };

        const roleMap = actionMap[currentRole] || {};
        const actionKey = roleMap[currentTab];
        if (actionKey) {
            const btn = toolbar.querySelector(`[data-batch-action="${actionKey}"]`);
            if (btn) btn.style.display = 'inline-flex';
        }

        // Cập nhật select-all checkbox state
        const filteredPageIds = getFilteredList()
            .slice((currentPage - 1) * perPage, currentPage * perPage)
            .map(dv => dv.id);
        const cb = document.getElementById('dvut-select-all-cb');
        if (cb) {
            const allSelected = filteredPageIds.length > 0 && filteredPageIds.every(id => selectedIds.has(id));
            const someSelected = filteredPageIds.some(id => selectedIds.has(id));
            cb.checked = allSelected;
            cb.indeterminate = someSelected && !allSelected;
        }
    }

    /**
     * Toggle chọn/bỏ chọn một dòng.
     */
    function toggleRowSelection(id, checked) {
        if (checked) {
            selectedIds.add(id);
        } else {
            selectedIds.delete(id);
        }
        const row = document.querySelector(`#dvut-table-body tr[data-id="${id}"]`);
        if (row) row.classList.toggle('batch-selected', checked);
        updateBatchToolbar();
    }

    /**
     * Chọn/bỏ tất cả (trên trang hiện tại).
     */
    function toggleSelectAll(checked) {
        const filtered = getFilteredList();
        const start = (currentPage - 1) * perPage;
        const pageData = filtered.slice(start, start + perPage);
        pageData.forEach(dv => {
            if (checked) selectedIds.add(dv.id);
            else selectedIds.delete(dv.id);
        });
        // update row class
        document.querySelectorAll('#dvut-table-body tr[data-id]').forEach(row => {
            const id = parseInt(row.dataset.id);
            const cb = row.querySelector('input[type=checkbox]');
            const sel = selectedIds.has(id);
            row.classList.toggle('batch-selected', sel);
            if (cb) cb.checked = sel;
        });
        updateBatchToolbar();
    }

    /**
     * Xóa toàn bộ selection.
     */
    function clearSelection() {
        selectedIds.clear();
        document.querySelectorAll('#dvut-table-body tr[data-id]').forEach(row => {
            row.classList.remove('batch-selected');
            const cb = row.querySelector('input[type=checkbox]');
            if (cb) cb.checked = false;
        });
        updateBatchToolbar();
    }

    // =================================================================
    // LỊCH SỬ CHỈNH SỬA — Audit Log (Production: wp_dvut_log)
    // =================================================================
    let editHistory = [];
    let historyIdCounter = 0;

    /**
     * Tên vai trò để hiển thị.
     */
    const ROLE_LABELS = {
        'bch_chi_doan': 'BCH Chi Đoàn',
        'can_bo_doan_khoa': 'Cán bộ Đoàn Khoa',
        'admin_doan_truong': 'Cán bộ đoàn trường',
    };

    /**
     * Tên hành động để hiển thị.
     */
    const ACTION_LABELS = {
        'de_cu': 'Đề cử',
        'nop_ho_so': 'Nộp hồ sơ',
        'chi_doan_duyet': 'Chi Đoàn duyệt',
        'doan_khoa_duyet': 'Đoàn Khoa duyệt',
        'admin_duyet': 'Admin duyệt/QĐ',
        'tu_choi': 'Từ chối/Trả về',
        'cam_tinh_dang': 'Cập nhật CTĐ',
        'gioi_thieu_dang': 'Giới thiệu Đảng',
        'chuyen_dang': 'Chuyển Đảng CT',
        'xoa': 'Xóa hồ sơ',
        'import': 'Import Excel',
    };

    /**
     * Ghi nhận hành động vào lịch sử chỉnh sửa (audit log).
     *
     * @param {string} hanh_dong - Loại hành động (key trong ACTION_LABELS)
     * @param {string} doi_tuong - Tên đoàn viên liên quan
     * @param {string} mssv      - MSSV đoàn viên (nếu có)
     * @param {string} mo_ta     - Mô tả ngắn gọn
     * @param {Object} extra     - Dữ liệu bổ sung: { chi_doan, chi_doan_id, khoa_id, chi_tiet }
     */
    function addHistoryEntry(hanh_dong, doi_tuong, mssv, mo_ta, extra = {}) {
        historyIdCounter++;
        const entry = {
            id: historyIdCounter,
            thoi_gian: new Date().toISOString(),
            nguoi_thuc_hien: currentRole === 'admin_doan_truong' ? 'Cán bộ đoàn trường'
                           : currentRole === 'can_bo_doan_khoa'  ? 'Cán bộ Đoàn Khoa'
                           : 'BCH Chi Đoàn',
            vai_tro: currentRole,
            hanh_dong: hanh_dong,
            doi_tuong: doi_tuong || '',
            mssv: mssv || '',
            chi_doan: extra.chi_doan || '',
            chi_doan_id: extra.chi_doan_id || currentChiDoanId || 0,
            khoa_id: extra.khoa_id || currentKhoaId || 0,
            mo_ta: mo_ta,
            chi_tiet: extra.chi_tiet || '',
        };
        editHistory.unshift(entry);
        // Lịch sử chỉnh sửa được backend tự ghi vào wp_dvut_log
        // (mỗi handler gọi write_admin_log). Client chỉ lưu local cho hiển thị.
    }

    /**
     * Đường dẫn gốc đến thư mục theme.
     */
    const themeUrl = DVUT_AJAX.theme_url || '';

    // =================================================================
    // HÀM AJAX — Gọi WordPress admin-ajax.php
    // =================================================================

    /**
     * Gọi WordPress AJAX.
     *
     * @param {string} action - Tên action (ví dụ: 'dvut_get_list')
     * @param {object} data   - Dữ liệu gửi đi
     * @returns {Promise<object>} - Kết quả JSON
     */
    async function ajaxRequest(action, data = {}) {
        data.action = action;
        data.nonce = DVUT_AJAX.nonce;
        const fd = new FormData();
        Object.entries(data).forEach(([k,v]) => {
            if (v instanceof File) fd.append(k, v);
            else fd.append(k, typeof v === 'object' ? JSON.stringify(v) : String(v));
        });
        const resp = await fetch(DVUT_AJAX.ajax_url, { method: 'POST', body: fd });
        return resp.json();
    }

    // =================================================================
    // HELPER FUNCTIONS
    // =================================================================

    /**
     * Render Skeleton Loader cho bảng (8 cột theo layout mới)
     */
    function renderSkeletonLoader(tbody, rowCount, colCount) {
        let html = '';
        for (let i = 0; i < rowCount; i++) {
            html += '<tr class="skeleton-loader">';
            for (let j = 0; j < colCount; j++) {
                html += '<td><span>&nbsp;</span></td>';
            }
            html += '</tr>';
        }
        tbody.innerHTML = html;
    }

    /**
     * Map trạng thái → Label & CSS class.
     * Mở rộng theo SRS v2 (12 trạng thái).
     */
    function getStatusBadge(status) {
        const map = {
            'CHO_NOP':              { label: 'Chờ nộp hồ sơ',     cls: 'status-CHO_NOP' },
            'CHO_CHI_DOAN':         { label: 'Chờ Chi Đoàn',      cls: 'status-CHO_CHI_DOAN' },
            'CHO_DOAN_KHOA':        { label: 'Chờ Đoàn Khoa',     cls: 'status-CHO_DOAN_KHOA' },
            'DA_CONG_NHAN':         { label: 'Đã công nhận',       cls: 'status-DA_CONG_NHAN' },
            'DANG_VIEN_DU_BI':      { label: 'Đảng viên',         cls: 'status-DA_CO_QD_KET_NAP' },   
            'CHO_DK_GIOI_THIEU':    { label: 'Chờ ĐK giới thiệu', cls: 'status-CHO_DOAN_KHOA' },  // GĐ3: Chờ Đoàn Khoa duyệt giới thiệu Đảng
            'CHO_DK_CHUYEN_DANG':   { label: 'Chờ ĐK chuyển Đảng',cls: 'status-CHO_DOAN_KHOA' },  // GĐ5: Chờ Đoàn Khoa duyệt chuyển Đảng chính thức
            'CHO_DOAN_TRUONG':      { label: 'Chờ Đoàn Trường',    cls: 'status-CHO_DOAN_KHOA' },  // Chờ Admin ĐT ban hành QĐ
            'DANG_VIEN_CHINH_THUC': { label: 'Đảng viên',         cls: 'status-DA_CO_QD_KET_NAP' }, // GĐ5 hoàn tất
            'TU_CHOI':              { label: 'Từ chối',            cls: 'status-TU_CHOI' },
            'TRA_VE':               { label: 'Trả về',             cls: 'status-TU_CHOI' },
            'CHUYEN_GIAO_CHI_BO':     { label: 'Chờ Chi bộ tiếp nhận', cls: 'status-CHUYEN_GIAO_CHI_BO' },
            'CHI_BO_DANG_THEO_DOI':   { label: 'CB đang theo dõi',     cls: 'status-CHI_BO_DANG_THEO_DOI' },
            'CHO_DANG_UY_TRUONG_XET': { label: 'Chờ Đảng uỷ duyệt',   cls: 'status-CHO_DANG_UY_TRUONG_XET' },
            'DA_CO_QD_KET_NAP':       { label: 'Đảng viên',           cls: 'status-DA_CO_QD_KET_NAP' },
        };
        return map[status] || { label: status, cls: '' };
    }

    /**
     * Map tiến độ Cảm tình Đảng → Label + style badge
     */
    function getCamTinhDangBadge(tienDo) {
        const map = {
            'CHUA_THAM_GIA': { label: 'Chưa tham gia', color: '#6c757d', bg: 'rgba(108,117,125,0.12)' },
            'DANG_HOC':      { label: 'Đang học',      color: '#0d6efd', bg: 'rgba(13,110,253,0.12)' },
            'DA_HOAN_THANH': { label: 'Đã hoàn thành', color: '#198754', bg: 'rgba(25,135,84,0.12)' },
        };
        return map[tienDo] || { label: '—', color: '#888', bg: 'transparent' };
    }

    /**
     * Lọc danh sách theo tab, tìm kiếm, chi đoàn — trong phạm vi dữ liệu được phép nhìn
     */
    function getFilteredList() {
        let filtered = getVisibleDoanVien();

        // Lọc theo tab (trạng thái)
        if (currentTab === 'CHO_NOP') {
            filtered = filtered.filter(d => d.trang_thai === 'CHO_NOP');
        } else if (currentTab === 'DANG_VIEN_DU_BI') {
            if (currentRole === 'chi_bo_sinh_vien') {
                filtered = filtered.filter(d => d.trang_thai === 'DANG_VIEN_DU_BI');
                // Tab Kết nạp Đảng: gom tất cả trạng thái từ giới thiệu Đảng đến đảng viên chính thức
                filtered = filtered.filter(d => ['CHO_DK_GIOI_THIEU', 'CHUYEN_GIAO_CHI_BO', 'CHI_BO_DANG_THEO_DOI', 'CHO_DANG_UY_TRUONG_XET', 'DA_CO_QD_KET_NAP', 'DANG_VIEN_DU_BI', 'CHO_DK_CHUYEN_DANG', 'DANG_VIEN_CHINH_THUC'].includes(d.trang_thai));
            }
        } else if (currentTab === 'CHO_DOAN_KHOA') {
            // Tab "Đoàn Khoa xét duyệt": chỉ gồm CHO_DOAN_KHOA
            filtered = filtered.filter(d => d.trang_thai === 'CHO_DOAN_KHOA');
        } else if (currentTab === 'DA_CONG_NHAN') {
            if (currentRole === 'chi_bo_sinh_vien') {
                // Chi bộ xem toàn bộ hồ sơ Đã công nhận và các trạng thái trung gian trong quy trình kết nạp
                filtered = filtered.filter(d => ['DA_CONG_NHAN', 'CHUYEN_GIAO_CHI_BO', 'CHI_BO_DANG_THEO_DOI', 'CHO_DANG_UY_TRUONG_XET', 'DA_CO_QD_KET_NAP'].includes(d.trang_thai));
            } else if (currentRole === 'admin_doan_truong') {
                // Admin/Cán bộ Đoàn Trường: chỉ xem Đã công nhận (Đoàn viên ưu tú)
                filtered = filtered.filter(d => d.trang_thai === 'DA_CONG_NHAN');
            } else {
                // Các vai trò khác: gom Chờ Đoàn Trường + Đã công nhận
                filtered = filtered.filter(d => ['CHO_DOAN_TRUONG', 'DA_CONG_NHAN'].includes(d.trang_thai));
            }
        } else if (currentTab === 'CHUYEN_GIAO_CHI_BO') {
            filtered = filtered.filter(d => d.trang_thai === 'CHUYEN_GIAO_CHI_BO');
        } else if (currentTab === 'CHI_BO_DANG_THEO_DOI') {
            filtered = filtered.filter(d => d.trang_thai === 'CHI_BO_DANG_THEO_DOI');
        } else if (currentTab === 'CHO_DANG_UY_TRUONG_XET') {
            filtered = filtered.filter(d => ['CHO_DANG_UY_TRUONG_XET', 'DA_CO_QD_KET_NAP'].includes(d.trang_thai));
        } else if (currentTab === 'DA_CO_QD_KET_NAP') {
            filtered = filtered.filter(d => d.trang_thai === 'DA_CO_QD_KET_NAP');
        } else if (currentTab === 'TU_CHOI') {
            // Tab "Bị từ chối": hiện tất cả hồ sơ bị từ chối (TU_CHOI) hoặc bị Đoàn Khoa trả về (TRA_VE)
            filtered = filtered.filter(d => ['TU_CHOI', 'TRA_VE'].includes(d.trang_thai));
        } else {
            filtered = filtered.filter(d => d.trang_thai === currentTab);
        }

        // Lọc theo khoa (admin Đoàn Trường) — multi-select
        if (filterKhoa.length) {
            const chiDoanIdsOfKhoa = CHI_DOAN_LIST.filter(cd => filterKhoa.includes(String(cd.khoa_id))).map(cd => String(cd.id));
            filtered = filtered.filter(d => chiDoanIdsOfKhoa.includes(String(d.chi_doan_id)));
        }

        // Lọc theo chi đoàn — multi-select
        if (filterChiDoan.length) {
            filtered = filtered.filter(d => filterChiDoan.includes(String(d.chi_doan_id)));
        }

        // Lọc theo từ khóa
        if (searchKeyword) {
            const kw = searchKeyword.toLowerCase();
            filtered = filtered.filter(d =>
                (d.ho_ten && d.ho_ten.toLowerCase().includes(kw)) ||
                (d.mssv && d.mssv.toLowerCase().includes(kw))
            );
        }

        return filtered;
    }

    /**
     * Lấy danh sách đoàn viên mà vai trò hiện tại được phép nhìn thấy.
     *
     * Lọc 2 tầng (dual-layer filtering):
     * - Tầng 1 (backend): apply_rbac_filter() đã giới hạn allDoanVien theo role khi load từ server.
     * - Tầng 2 (frontend – hàm này): lọc thêm dựa trên chi_doan để đồng bộ với backend.
     *   Dùng CHI_DOAN_LIST (từ wp_localize_script) để resolve quan hệ khoa → chi_doan.
     *
     * Dùng String() coercion cho mọi so sánh ID để đảm bảo type-safe (DB trả number, JS có thể là string).
     */
    function getVisibleDoanVien() {
        if (currentRole === 'bch_chi_doan' && currentChiDoanId) {
            return allDoanVien.filter(d => String(d.chi_doan_id) === String(currentChiDoanId));
        }
        if (currentRole === 'can_bo_doan_khoa' && currentKhoaId) {
            const chiDoanIdsOfKhoa = CHI_DOAN_LIST
                .filter(cd => String(cd.khoa_id) === String(currentKhoaId))
                .map(cd => String(cd.id));
            return allDoanVien.filter(d => chiDoanIdsOfKhoa.includes(String(d.chi_doan_id)));
        }
        if (currentRole === 'chi_bo_sinh_vien' && currentChiBoId) {
            // Tìm danh sách khoa_id thuộc chi bộ này
            const khoaIdsOfChiBo = KHOA_LIST
                .filter(k => String(k.chi_bo_id) === String(currentChiBoId))
                .map(k => String(k.id));
            // Lọc qua chi_doan → tìm chi_doan_id thuộc các khoa của chi bộ
            const chiDoanIdsOfKhoa = CHI_DOAN_LIST
                .filter(cd => khoaIdsOfChiBo.includes(String(cd.khoa_id)))
                .map(cd => String(cd.id));
            return allDoanVien.filter(d => chiDoanIdsOfKhoa.includes(String(d.chi_doan_id)));
        }
        return [...allDoanVien]; // admin_doan_truong
    }

    /**
     * Lấy danh sách chi đoàn mà vai trò hiện tại được phép nhìn thấy.
     * Lọc CHI_DOAN_LIST để chỉ hiển thị chi đoàn thuộc phạm vi role hiện tại
     * (theo chi_doan_id, khoa_id, hoặc chi_bo_id tùy vai trò).
     */
    function getVisibleChiDoan() {
        if (currentRole === 'bch_chi_doan' && currentChiDoanId) {
            return CHI_DOAN_LIST.filter(cd => String(cd.id) === String(currentChiDoanId));
        }
        if (currentRole === 'can_bo_doan_khoa' && currentKhoaId) {
            return CHI_DOAN_LIST.filter(cd => String(cd.khoa_id) === String(currentKhoaId));
        }
        if (currentRole === 'chi_bo_sinh_vien' && currentChiBoId) {
            const khoaIdsOfChiBo = KHOA_LIST
                .filter(k => String(k.chi_bo_id) === String(currentChiBoId))
                .map(k => String(k.id));
            return CHI_DOAN_LIST.filter(cd => khoaIdsOfChiBo.includes(String(cd.khoa_id)));
        }
        return [...CHI_DOAN_LIST];
    }

    /**
     * Lấy danh sách đoàn viên đã lọc theo role + bộ lọc Khoa/Chi đoàn (không lọc tab/search).
     * Áp dụng filter dropdown Khoa/Chi đoàn lên trên kết quả đã lọc theo role (getVisibleDoanVien).
     */
    function getFilteredByScope() {
        let list = getVisibleDoanVien();
        if (filterKhoa.length) {
            const cdIds = CHI_DOAN_LIST.filter(cd => filterKhoa.includes(String(cd.khoa_id))).map(cd => String(cd.id));
            list = list.filter(d => cdIds.includes(String(d.chi_doan_id)));
        }
        if (filterChiDoan.length) {
            list = list.filter(d => filterChiDoan.includes(String(d.chi_doan_id)));
        }
        return list;
    }

    /**
     * Đếm số lượng theo trạng thái (trong phạm vi role + bộ lọc Khoa/Chi đoàn)
     */
    function countByStatus(status) {
        return getFilteredByScope().filter(d => d.trang_thai === status).length;
    }

    /**
     * Format ngày (YYYY-MM-DD → DD/MM/YYYY)
     */
    function formatDate(dateStr) {
        if (!dateStr) return '—';
        const parts = dateStr.split('-');
        if (parts.length !== 3) return dateStr;
        return `${parts[2]}/${parts[1]}/${parts[0]}`;
    }

    // =================================================================
    // PHÂN QUYỀN — Ẩn/hiện nút trên Toolbar theo Role
    // =================================================================

    /**
     * Cập nhật giao diện toolbar khi thay đổi vai trò.
     * - Chi Đoàn      : Hiện nút "Đề cử", hiện bộ chọn chi đoàn context, ẩn filter chi đoàn
     * - Đoàn Khoa     : Ẩn nút "Đề cử", hiện bộ chọn khoa context, filter chi đoàn chỉ trong khoa
     * - Admin ĐT      : Hiện nút "Import Excel", ẩn "Đề cử", hiện tất cả chi đoàn
     */
    function applyRoleUI() {
        const $addBtn    = $('#dvut-add-btn');
        const $exportBtn = $('#dvut-export-btn');
        const $importBtn = $('#dvut-import-btn');
        const $ctxChiDoan = $('#dvut-context-chi-doan-wrapper');
        const $ctxKhoa    = $('#dvut-context-khoa-wrapper');
        const $filterCD   = $('#dvut-filter-chi-doan-wrapper');
        const $filterKhoa = $('#dvut-filter-khoa-wrapper');

        // Reset disabled state mỗi lần đổi role
        $('#dvut-context-chi-doan').prop('disabled', false);

        // Reset trạng thái menu/view mỗi lần đổi role
        $('#menu-quan-ly').closest('li').show();
        $('#menu-ho-so').closest('li').show();
        $('#sidebar-dropdown-tools').show(); // Mặc định hiện Công cụ (Lịch sử); đoàn viên sẽ ẩn ở case doan_vien
        $('#sidebar-system-mgmt-btn').hide(); // Ẩn mặc định, chỉ admin mới thấy
        $('#dvut-manage-view').show();
        $('#dvut-sub-nav').show();
        if (currentRole === 'admin_doan_truong') {
            $('#dvut-sub-nav button[data-status="CHO_DOAN_TRUONG"]').show();
            $('#dvut-sub-nav button[data-status="DA_CONG_NHAN"]').html(
                `<span class="dashicons dashicons-awards" style="font-size:16px; vertical-align:middle; margin-right:4px;"></span>` +
                `Đoàn viên ưu tú` +
                `<span class="badge-count" id="badge-da-cong-nhan" style="display:none;"></span>`
            );
            $('#dvut-sub-nav button[data-status="DANG_VIEN_DU_BI"]').show();
        } else {
            $('#dvut-sub-nav button[data-status="CHO_DOAN_TRUONG"]').hide();
            $('#dvut-sub-nav button[data-status="DA_CONG_NHAN"]').html(
                `<span class="dashicons dashicons-shield" style="font-size:16px; vertical-align:middle; margin-right:4px;"></span>` +
                `Đoàn Trường công nhận` +
                `<span class="badge-count" id="badge-da-cong-nhan" style="display:none;"></span>`
            );
            $('#dvut-sub-nav button[data-status="DANG_VIEN_DU_BI"]').hide();
        }
        $('#dvut-sub-nav-chi-bo').hide();
        $('#dvut-profile-view').hide();
        $('#dvut-system-view').hide();
        $('#menu-quan-ly').addClass('active');
        $('#menu-ho-so').removeClass('active');
        $('#admin-profile-search').hide();
        $('#admin-profile-search-results').empty();
        $('#admin-profile-search-input').val('');

        switch (currentRole) {
            case 'doan_vien':
                // Đoàn viên: chỉ xem hồ sơ cá nhân, ẩn hoàn toàn quản lý, không có quyền xem lịch sử chỉnh sửa
                $addBtn.hide();
                $exportBtn.hide();
                $importBtn.hide();
                $ctxChiDoan.hide();
                $ctxKhoa.hide();
                $('#dvut-context-cd-label').hide();
                $('#dvut-context-khoa-label').hide();
                $filterCD.hide();
                $filterKhoa.hide();
                // Đoàn viên: ẩn Lịch sử chỉnh sửa và Tin nhắn (đề nghị hỗ trợ), ẩn luôn sidebar-dropdown-tools
                $('#sidebar-history-btn').closest('li').hide();
                $('#sidebar-dropdown-tools').hide();
                // Ẩn manage view, buộc sang profile view
                $('#dvut-manage-view').hide();
                $('#dvut-profile-view').show();
                // Ẩn menu "Quản lý xét duyệt", chỉ giữ "Hồ sơ cá nhân"
                $('#menu-quan-ly').closest('li').hide();
                $('#menu-ho-so').closest('li').show();
                $('#menu-ho-so').addClass('active');
                // Ẩn admin search (không dùng cho đoàn viên)
                $('#admin-profile-search').hide();
                // NOTE: populateProfileForDoanVien() được gọi SAU khi loadData() hoàn tất (xem loadData)
                // Không gọi ở đây vì allDoanVien có thể chưa sẵn
                return; // Không cần render bảng/stats cho đoàn viên

            case 'bch_chi_doan':
                $addBtn.hide();
                $exportBtn.show();
                $importBtn.hide();
                $('#sidebar-dropdown-tools').show();
                $('#sidebar-history-btn').closest('li').show();
                // Ẩn menu "Hồ sơ cá nhân" cho BCH Chi Đoàn
                $('#menu-ho-so').closest('li').hide();
                // BCH chỉ quản lý chi đoàn được gán → ẩn dropdown, hiện tên chi đoàn dạng label
                if (currentChiDoanId) {
                    $ctxChiDoan.hide();
                    // Hiện label tên chi đoàn thay cho dropdown
                    const cdObj = CHI_DOAN_LIST.find(cd => String(cd.id) === String(currentChiDoanId));
                    const cdName = cdObj ? cdObj.ten : ('Chi đoàn #' + currentChiDoanId);
                    let $cdLabel = $('#dvut-context-cd-label');
                    if (!$cdLabel.length) {
                        $cdLabel = $(`<div id="dvut-context-cd-label" class="toolbar-item" style="min-width:180px;">
                            <label style="font-weight:600; color:#555; font-size:0.85em;">Chi đoàn của tôi</label>
                            <div style="padding:7px 12px; background:#e8f4fd; border-radius:8px; font-weight:600; color:#174f8c; font-size:0.95em; white-space:nowrap;"></div>
                        </div>`);
                        $ctxChiDoan.after($cdLabel);
                    }
                    $cdLabel.find('div').text(cdName);
                    $cdLabel.show();
                } else {
                    $ctxChiDoan.show();
                    $('#dvut-context-cd-label').hide();
                }
                $ctxKhoa.hide();
                $('#dvut-context-khoa-label').hide();
                $filterCD.hide();
                $filterKhoa.hide();
                $('#dvut-table .col-actions, #dvut-table th:last-child, #dvut-table td:last-child').show();
                break;

            case 'can_bo_doan_khoa':
                $addBtn.hide();
                $exportBtn.show();
                $importBtn.hide();
                $('#sidebar-dropdown-tools').show();
                $('#sidebar-history-btn').closest('li').show();
                $ctxChiDoan.hide();
                $('#dvut-context-cd-label').hide();
                // Đoàn Khoa chỉ quản lý khoa được gán → ẩn dropdown, hiện tên khoa dạng label
                if (currentKhoaId) {
                    $ctxKhoa.hide();
                    const khoaObj = KHOA_LIST.find(k => String(k.id) === String(currentKhoaId));
                    const khoaName = khoaObj ? khoaObj.ten : ('Khoa #' + currentKhoaId);
                    let $khoaLabel = $('#dvut-context-khoa-label');
                    if (!$khoaLabel.length) {
                        $khoaLabel = $(`<div id="dvut-context-khoa-label" class="toolbar-item" style="min-width:180px;">
                            <label style="font-weight:600; color:#555; font-size:0.85em;">Khoa của tôi</label>
                            <div style="padding:7px 12px; background:#e8f0e4; border-radius:8px; font-weight:600; color:#2e7d32; font-size:0.95em; white-space:nowrap;"></div>
                        </div>`);
                        $ctxKhoa.after($khoaLabel);
                    }
                    $khoaLabel.find('div').text(khoaName);
                    $khoaLabel.show();
                } else {
                    $ctxKhoa.show();
                    $('#dvut-context-khoa-label').hide();
                }
                $filterCD.show();
                $filterKhoa.hide();
                // Ẩn menu "Hồ sơ cá nhân" cho Đoàn Khoa
                $('#menu-ho-so').closest('li').hide();
                $('#dvut-table .col-actions, #dvut-table th:last-child, #dvut-table td:last-child').show();
                break;

            case 'admin_doan_truong': {
                $addBtn.hide();
                $exportBtn.show();
                $('#sidebar-dropdown-tools').show();
                $('#sidebar-history-btn').closest('li').show();
                // Chỉ Quản trị viên (is_admin) mới có "Hồ sơ cá nhân" + "Quản lý hệ thống"
                const canViewPersonalProfile = isDevAdmin;
                if (canViewPersonalProfile) {
                    $('#menu-ho-so').closest('li').show();
                    $('#admin-profile-search').show();
                    $('#sidebar-system-mgmt-btn').show();
                } else {
                    $('#menu-ho-so').closest('li').hide();
                    $('#admin-profile-search').hide();
                    $('#sidebar-system-mgmt-btn').hide();
                }
                // Import Excel
                $importBtn.hide();
                $ctxChiDoan.hide();
                $ctxKhoa.hide();
                $('#dvut-context-cd-label').hide();
                $('#dvut-context-khoa-label').hide();
                $filterKhoa.show(); // Admin: lọc theo Khoa
                $filterCD.show(); // Admin thấy tất cả chi đoàn
                $('#dvut-table .col-actions, #dvut-table th:last-child, #dvut-table td:last-child').show();
                break;
            }

            case 'chi_bo_sinh_vien':
                $addBtn.hide();
                $exportBtn.show();
                $importBtn.hide();
                $('#sidebar-dropdown-tools').show();
                $('#sidebar-history-btn').closest('li').show();
                $('#menu-ho-so').closest('li').hide();
                $ctxChiDoan.hide();
                $ctxKhoa.hide();
                $('#dvut-context-cd-label').hide();
                $('#dvut-context-khoa-label').hide();
                $filterCD.hide();
                $filterKhoa.hide();
                // Hiện sub-nav chi bộ, ẩn sub-nav chính
                $('#dvut-sub-nav').hide();
                $('#dvut-sub-nav-chi-bo').show();
                // Đặt tab mặc định là DA_CONG_NHAN
                currentTab = 'DA_CONG_NHAN';
                $('#dvut-sub-nav-chi-bo .sub-nav-link').removeClass('active');
                $('#dvut-sub-nav-chi-bo .sub-nav-link[data-status="DA_CONG_NHAN"]').addClass('active');
                $('#dvut-table .col-actions, #dvut-table th:last-child, #dvut-table td:last-child').show();
                break;
        }

        // Render dropdown Khoa filter (chỉ khi admin Đoàn Trường)
        if (currentRole === 'admin_doan_truong') {
            renderKhoaFilterDropdown();
        }

        // Không còn cột Lớp Cảm tình Đảng trên bảng chính (xóa hoàn toàn theo yêu cầu UI/UX)

        // Cập nhật dropdown filter chi đoàn theo phạm vi nhìn thấy (có cascade theo Khoa)
        const visibleCD = getVisibleChiDoan();
        const cascadedCD = filterKhoa.length
            ? visibleCD.filter(cd => filterKhoa.includes(String(cd.khoa_id)))
            : visibleCD;
        renderChiDoanDropdown(cascadedCD);

        // Cập nhật thống kê + render lại bảng
        updateStats();
        renderTable();
    }

    // =================================================================
    // ĐOÀN VIÊN — Populate hồ sơ cá nhân
    // =================================================================

    /**
     * Tìm dữ liệu sinh viên từ server (blackbox) và điền vào profile view.
     * Tra cứu bằng email hoặc MSSV.
     * Mapping trạng thái → timeline step.
     */
    async function populateProfileForDoanVien() {
        if (!_currentUser) return;

        // Tra cứu hồ sơ từ server (blackbox) bằng email hoặc mssv
        let hd = null;
        try {
            const res = await ajaxRequest('dvut_get_blackbox_profile', {
                email: _currentUser.email || '',
                mssv: _currentUser.mssv || ''
            });
            if (res.success && res.data) {
                hd = res.data;
            }
        } catch (e) {
            console.error('Error fetching profile:', e);
        }

        if (!hd) {
            const $title = $('#dvut-profile-view h1');
            if ($title.length) {
                $title.html(`<span style="color:#dc3545;">⚠️ Không tìm thấy dữ liệu cho email: ${esc(_currentUser.email || '(trống)')}</span>`);
            }
            return;
        }

        const mssv = hd.mssv;

        // Tìm trong danh sách đoàn viên đã tải (workflow progress)
        const dv = allDoanVien.find(d => d.mssv === mssv);

        // ── Cập nhật tiêu đề (chỉ hiện tên) ──
        const $title = $('#dvut-profile-view h1');
        if ($title.length) {
            $title.html(`Hồ sơ cá nhân — <span style="color:#174f8c;">${esc(hd.ho_ten)}</span>`);
        }

        // ── Cập nhật sidebar hiển thị tên thật ──
        const $logoH2 = $('.sidebar .logo-area h2');
        if ($logoH2.length) {
            $logoH2.text(hd.ho_ten);
        }

        // ── Điền thông tin cá nhân ──
        if (dv) {
            // Chi đoàn dropdown
            const $chiDoan = $('#profile-chi-doan');
            if ($chiDoan.length) {
                // Thêm option của sinh viên nếu chưa có
                if ($chiDoan.find(`option[value="${dv.chi_doan_id}"]`).length === 0) {
                    $chiDoan.append(`<option value="${dv.chi_doan_id}">${esc(dv.chi_doan)}</option>`);
                }
                $chiDoan.val(dv.chi_doan_id);
                $chiDoan.prop('disabled', true);
            }

            // Sơ lược quá trình / Mẫu 01 — hiển thị file đã nộp
            if (dv.mau01_file) {
                const m01Name = dv.mau01_file.split('/').pop();
                $('#mau01-file-name').text('📎 ' + m01Name).show();
            } else if (dv.so_luoc_qua_trinh) {
                $('#mau01-file-name').text('📝 ' + dv.so_luoc_qua_trinh.substring(0, 60)).show();
            }

            // File bài cảm nhận
            if (dv.bai_cam_nhan_file) {
                $('#mau02-file-name').text('📎 ' + dv.bai_cam_nhan_file).show();
            }
        } else if (hd) {
            // Sinh viên chưa được đề cử — chỉ có dữ liệu hộp đen
            const $chiDoan = $('#profile-chi-doan');
            if ($chiDoan.length && hd.chi_doan) {
                if ($chiDoan.find(`option[value="${hd.chi_doan_id}"]`).length === 0) {
                    $chiDoan.append(`<option value="${hd.chi_doan_id}">${esc(hd.chi_doan)}</option>`);
                }
                $chiDoan.val(hd.chi_doan_id);
                $chiDoan.prop('disabled', true);
            }
        }

        // ── Điền từ hộp đen (thông tin học tập) ──
        if (hd) {
            $('#profile-gpa').val(hd.diem_tb);
            $('#profile-diem-rl').val(hd.diem_rl);

            // Map xếp loại đoàn viên
            const xlMap = { 'Hoàn thành xuất sắc': 'xuat_sac', 'Hoàn thành tốt': 'kha', 'Hoàn thành': 'trung_binh', 'Không hoàn thành': 'yeu' };
            $('#profile-xep-loai').val(xlMap[hd.xep_loai_doan_vien] || 'khong_co');

            // Map lý luận chính trị
            const llctMap = { 'Hoàn thành': 'hoan_thanh', 'Đã đăng ký': 'da_dang_ky', 'LLCT': 'hoan_thanh' };
            $('#profile-llct').val(llctMap[hd.ly_luan_chinh_tri] || 'khong_co');

            // Chức vụ — mặc định Đoàn viên
            $('#profile-chuc-vu').val('doan_vien');
        }

        // ── Disable chỉ phần "Thông tin cá nhân" — giữ phần "Nộp tài liệu" để đoàn viên nhập ──
        $('#profile-chuc-vu, #profile-chi-doan, #profile-gpa, #profile-diem-rl, #profile-xep-loai, #profile-llct, #profile-noi-thuong-tru').prop('disabled', true);
        $('#profile-save-btn').hide();

        // ── Ẩn các trường đã hiển thị ở "Tổng quan hồ sơ" để tránh trùng lặp ──
        ['#profile-chi-doan', '#profile-gpa', '#profile-diem-rl', '#profile-xep-loai', '#profile-llct'].forEach(sel => {
            $(sel).closest('.form-group').hide();
        });

        // ── Cập nhật Timeline ──
        if (dv) {
            updateProfileTimeline(dv.trang_thai, dv);
        } else {
            updateProfileTimeline('CHUA_DE_CU', null);
        }

        // ── Quản lý visibility các khối UI ──
        const trangThai = dv ? dv.trang_thai : null;

        // Khối "Nhiệm vụ cần làm" — hiện khi CHO_NOP hoặc TU_CHOI
        if (trangThai === 'CHO_NOP') {
            $('#dv-task-card').show();
            $('#dv-task-content').html(`
                <div style="padding:12px 16px; background:linear-gradient(135deg,#fff7ed,#fff3e0); border-radius:10px; border:1px solid #ffe0b2;">
                    <p style="margin:0 0 12px; font-weight:600; color:#e65100; font-size:.92em;">
                        📋 Bạn đang ứng cử danh hiệu Đoàn viên Ưu tú. Vui lòng hoàn thiện hồ sơ bên dưới:
                    </p>
                    <ul style="margin:0; padding-left:20px; color:#555; font-size:.88em; line-height:1.8;">
                        <li><strong>Mẫu 01:</strong> Sơ lược quá trình phấn đấu</li>
                        <li><strong>Mẫu 02:</strong> Bài cảm nhận về Đảng (file PDF)</li>
                    </ul>
                </div>
            `);
        } else if (trangThai === 'TU_CHOI') {
            $('#dv-task-card').show();
            const ngayTC = dv.ngay_tu_choi ? new Date(dv.ngay_tu_choi).toLocaleDateString('vi-VN') : '—';
            $('#dv-task-content').html(`
                <div style="padding:12px 16px; background:linear-gradient(135deg,#fff5f5,#ffe6e6); border-radius:10px; border:1px solid #ffcdd2;">
                    <p style="margin:0 0 10px; font-weight:600; color:#dc3545; font-size:.92em;">❌ Hồ sơ đã bị từ chối</p>
                    <table style="width:100%; border-collapse:collapse; font-size:.88em;">
                        <tr><td style="padding:4px 8px; color:#777; font-weight:600; width:40%;">Cấp từ chối:</td><td style="padding:4px 8px; color:#dc3545;">${esc(dv.cap_tu_choi || '—')}</td></tr>
                        <tr><td style="padding:4px 8px; color:#777; font-weight:600;">Ngày từ chối:</td><td style="padding:4px 8px;">${ngayTC}</td></tr>
                        <tr><td style="padding:4px 8px; color:#777; font-weight:600;">Lý do:</td><td style="padding:4px 8px;">${esc(dv.ly_do_tu_choi || '—')}</td></tr>
                    </table>
                    <div style="margin-top:12px; text-align:right;">
                        <button onclick="DvutApp.doanVienUngCuLai()" style="background:linear-gradient(135deg,#0d6efd,#1a6fc4); color:white; border:none; padding:8px 18px; border-radius:8px; cursor:pointer; font-size:.88em; font-weight:600;">
                            🔄 Ứng cử lại
                        </button>
                    </div>
                </div>
            `);
        } else if (trangThai === 'TRA_VE') {
            $('#dv-task-card').show();
            const ngayTC = dv.ngay_tu_choi ? new Date(dv.ngay_tu_choi).toLocaleDateString('vi-VN') : '—';
            $('#dv-task-content').html(`
                <div style="padding:12px 16px; background:linear-gradient(135deg,#fff8f0,#ffe9d0); border-radius:10px; border:1px solid #ffcc99;">
                    <p style="margin:0 0 10px; font-weight:600; color:#e65100; font-size:.92em;">⚠️ Hồ sơ bị Đoàn Khoa trả về</p>
                    <table style="width:100%; border-collapse:collapse; font-size:.88em;">
                        <tr><td style="padding:4px 8px; color:#777; font-weight:600; width:40%;">Cấp trả về:</td><td style="padding:4px 8px; color:#e65100;">${esc(dv.cap_tu_choi || 'Đoàn Khoa')}</td></tr>
                        <tr><td style="padding:4px 8px; color:#777; font-weight:600;">Ngày trả về:</td><td style="padding:4px 8px;">${ngayTC}</td></tr>
                        <tr><td style="padding:4px 8px; color:#777; font-weight:600;">Lý do:</td><td style="padding:4px 8px;">${esc(dv.ly_do_tu_choi || dv.ghi_chu || '—')}</td></tr>
                    </table>
                    <div style="margin-top:12px; text-align:right;">
                        <button onclick="DvutApp.doanVienUngCuLai()" style="background:linear-gradient(135deg,#0d6efd,#1a6fc4); color:white; border:none; padding:8px 18px; border-radius:8px; cursor:pointer; font-size:.88em; font-weight:600;">
                            🔄 Ứng cử lại
                        </button>
                    </div>
                </div>
            `);
        } else {
            $('#dv-task-card').hide();
        }

        // Khối Upload — hiện khi CHO_NOP, ẩn/disable khi đã nộp
        if (!dv) {
            // Chưa đề cử → ẩn upload, hiện ứng cử
            $('#dv-upload-card').hide();
            $('#dvut-ung-cu-wrapper').show();
        } else if (trangThai === 'CHO_NOP') {
            $('#dv-upload-card').show();
            $('#dvut-ung-cu-wrapper').hide();
            // Enable form
            $('#profile-upload-mau01').css({'pointer-events': 'auto', 'opacity': '1'});
            $('#profile-mau01-file').prop('disabled', false);
            $('#profile-upload-mau02').css({'pointer-events': 'auto', 'opacity': '1'});
            $('#profile-mau02-file').prop('disabled', false);
            $('#profile-submit-btn').show();
        } else if (trangThai === 'TU_CHOI' || trangThai === 'TRA_VE') {
            // Hồ sơ bị từ chối/trả về — ẩn upload, đv cần ứng cử lại
            $('#dv-upload-card').hide();
            $('#dvut-ung-cu-wrapper').hide();
        } else {
            $('#dv-upload-card').show();
            $('#dvut-ung-cu-wrapper').hide();
            // Disable form sau khi đã nộp
            $('#profile-upload-mau01').css({'pointer-events': 'none', 'opacity': '0.5'});
            $('#profile-mau01-file').prop('disabled', true);
            $('#profile-upload-mau02').css({'pointer-events': 'none', 'opacity': '0.5'});
            $('#profile-mau02-file').prop('disabled', true);
            $('#profile-submit-btn').hide();
        }

        // Khối "Đánh giá định kỳ" — chỉ hiện từ DA_CONG_NHAN trở đi
        const POST_CONG_NHAN = ['DA_CONG_NHAN','CHO_DK_GIOI_THIEU',
            'CHUYEN_GIAO_CHI_BO','CHI_BO_DANG_THEO_DOI','CHO_DANG_UY_TRUONG_XET',
            'DA_CO_QD_KET_NAP','DANG_VIEN_DU_BI','CHO_DK_CHUYEN_DANG',
            'DANG_VIEN_CHINH_THUC'];
        if (trangThai && POST_CONG_NHAN.includes(trangThai)) {
            $('#dv-reviews-card').show();
            // Load nhận xét định kỳ cho đoàn viên này
            const dvutId = dv ? dv.id : 0;
            loadNhanXetForDoanVien(dvutId);
        } else {
            $('#dv-reviews-card').hide();
        }

        // ── Khối "Văn bản đã nộp" — hiện khi có bất kỳ file nào ──
        const FILE_STAGES = [
            { key: 'mau01_file',             label: 'Mẫu 01 — Sơ lược quá trình phấn đấu' },
            { key: 'bai_cam_nhan_file',       label: 'Mẫu 02 — Bài cảm nhận về Đảng' },
            { key: 'bien_ban_chi_doan_file',  label: 'Mẫu 03 — Biên bản Hội nghị Chi Đoàn' },
            { key: 'cong_van_dk_file',        label: 'Mẫu 04 — Công văn đề nghị Đoàn Khoa' },
            { key: 'bien_ban_dk_file',        label: 'Mẫu 05 — Biên bản họp BCH Đoàn Khoa' },
            { key: 'file_qd_cong_nhan',       label: 'Quyết định công nhận Đoàn viên Ưu tú' },
        ];
        if (dv) {
            const fileLinks = FILE_STAGES
                .filter(f => dv[f.key])
                .map(f => `
                    <div style="display:flex; align-items:center; gap:10px; padding:8px 12px; border-radius:8px; background:#f8f9fa; border:1px solid #e9ecef; margin-bottom:8px;">
                        <span class="dashicons dashicons-media-document" style="color:#174f8c; font-size:18px; flex-shrink:0;"></span>
                        <div style="flex:1; min-width:0;">
                            <div style="font-size:.82em; color:#888; margin-bottom:2px;">${esc(f.label)}</div>
                            <a href="${esc(dv[f.key])}" target="_blank" rel="noopener" style="color:#174f8c; font-weight:600; font-size:.9em; word-break:break-all;">${esc(dv[f.key].split('/').pop())}</a>
                        </div>
                    </div>`).join('');
            if (fileLinks) {
                $('#dv-documents-card').show();
                $('#dv-documents-list').html(fileLinks);
            } else {
                $('#dv-documents-card').hide();
            }
        } else {
            $('#dv-documents-card').hide();
        }

        // ── Thêm bảng tóm tắt thông tin ──
        renderDoanVienSummaryCard(dv, hd);
    }

    /**
     * Đoàn viên tự gửi hồ sơ: tạo record (nếu chưa) + chuyển CHO_NOP → CHO_CHI_DOAN.
     * Gọi từ nút "Gửi hồ sơ" trong profile view.
     */
    async function submitHoSoDoanVien(mau01File, mau02File) {
        if (!_currentUser) return { success: false, message: 'Chưa đăng nhập.' };

        // Tìm dữ liệu hộp đen từ server
        let hd = null;
        try {
            const res = await ajaxRequest('dvut_get_blackbox_profile', {
                email: _currentUser.email || '',
                mssv: _currentUser.mssv || ''
            });
            if (res.success && res.data) {
                hd = res.data;
            }
        } catch (e) {
            console.error('Error fetching blackbox profile:', e);
        }
        if (!hd) return { success: false, message: 'Không tìm thấy dữ liệu sinh viên.' };

        const mssv = hd.mssv;
        let dv = allDoanVien.find(d => d.mssv === mssv);

        // Nếu chưa có record → tự đề cử (tạo record mới)
        if (!dv) {
            const res = await ajaxRequest('dvut_add_de_cu', {
                ho_ten: hd.ho_ten,
                mssv: mssv,
                chi_doan_id: hd.chi_doan_id,
                khoa_id: hd.khoa_id || ''
            });
            if (!res.success) return { success: false, message: 'Không thể tạo hồ sơ.' };
            await loadData();
            dv = allDoanVien.find(d => d.mssv === mssv);
            if (!dv) return { success: false, message: 'Lỗi tạo hồ sơ.' };
        }

        // Nộp hồ sơ (chuyển CHO_NOP → CHO_CHI_DOAN)
        if (dv.trang_thai !== 'CHO_NOP') {
            return { success: false, message: 'Hồ sơ đã được nộp trước đó.' };
        }

        const res = await ajaxRequest('dvut_nop_ho_so', {
            id: dv.id,
            mau01: mau01File instanceof File ? mau01File : null,
            bai_cam_nhan: mau02File instanceof File ? mau02File : null,
        });

        if (res.success) {
            await loadData();
            // loadData() đã gọi populateProfileForDoanVien() cho role doan_vien
        }

        return res;
    }

    /**
     * Ứng cử danh hiệu ĐVƯT — Flow 3 bước cho Đoàn viên:
     * Bước 1: Kiểm tra điều kiện (tự động, không cần nhập MSSV)
     * Bước 2: Hiện kết quả + nút "Tiếp tục ứng cử"
     * Bước 3: Mở form điền Mẫu 01 + upload Mẫu 02 → Nộp
     */
    async function openUngCuModal() {
        if (!_currentUser) {
            Swal.fire({ icon: 'warning', title: 'Chưa đăng nhập', text: 'Vui lòng đăng nhập để ứng cử.' });
            return;
        }

        // ── Tìm dữ liệu hộp đen từ server ──
        let hd = null;
        try {
            const res = await ajaxRequest('dvut_get_blackbox_profile', {
                email: _currentUser.email || '',
                mssv: _currentUser.mssv || ''
            });
            if (res.success && res.data) {
                hd = res.data;
            }
        } catch (e) {
            console.error('Error fetching blackbox profile:', e);
        }
        if (!hd) {
            Swal.fire({ icon: 'error', title: 'Không tìm thấy dữ liệu', text: 'Hệ thống không tìm thấy dữ liệu của bạn trong Hộp đen.' });
            return;
        }

        // ════════════════════════════════════════════════════
        // BƯỚC 1: Kiểm tra điều kiện
        // ════════════════════════════════════════════════════
        Swal.fire({ title: 'Đang kiểm tra điều kiện...', allowOutsideClick: false, didOpen: () => Swal.showLoading() });

        // Tính toán tiêu chí
        const criteria = {
            ly_luan_chinh_tri:  { value: hd.ly_luan_chinh_tri || 'Không có',       pass: hd.ly_luan_chinh_tri === 'Hoàn thành' },
            xep_loai_doan_vien: { value: hd.xep_loai_doan_vien || 'Không có',      pass: hd.xep_loai_doan_vien === 'Hoàn thành xuất sắc' || hd.xep_loai_doan_vien === 'Hoàn thành tốt' },
            diem_tb:            { value: hd.diem_tb,                                pass: hd.diem_tb >= 7.0 },
            diem_ren_luyen:     { value: hd.diem_rl,                                pass: hd.diem_rl >= 80 },
        };
        const allPass = Object.values(criteria).every(c => c.pass);

        await new Promise(r => setTimeout(r, 500)); // Chờ animation loading

        // ════════════════════════════════════════════════════
        // BƯỚC 2: Hiện kết quả kiểm tra
        // ════════════════════════════════════════════════════
        const criteriaLabels = {
            ly_luan_chinh_tri:  'Lý luận chính trị',
            xep_loai_doan_vien: 'Xếp loại đoàn viên',
            diem_tb:            'Điểm TB tích lũy',
            diem_ren_luyen:     'Điểm rèn luyện',
        };
        const criteriaThresholds = {
            ly_luan_chinh_tri:  'Hoàn thành',
            xep_loai_doan_vien: 'HT xuất sắc / HT tốt',
            diem_tb:            '≥ 7.0',
            diem_ren_luyen:     '≥ 80',
        };

        let criteriaHtml = '';
        for (const [key, info] of Object.entries(criteria)) {
            const icon = info.pass ? '✅' : '❌';
            const color = info.pass ? '#198754' : '#dc3545';
            criteriaHtml += `
                <tr>
                    <td style="padding:8px 10px; border-bottom:1px solid #eee;">${icon}</td>
                    <td style="padding:8px 10px; border-bottom:1px solid #eee; font-weight:600;">${criteriaLabels[key]}</td>
                    <td style="padding:8px 10px; border-bottom:1px solid #eee; text-align:center; color:#888;">${criteriaThresholds[key]}</td>
                    <td style="padding:8px 10px; border-bottom:1px solid #eee; text-align:center; color:${color}; font-weight:700;">${info.value}</td>
                </tr>`;
        }

        if (!allPass) {
            // KHÔNG ĐẠT → Thông báo và dừng
            await Swal.fire({
                icon: 'error',
                title: 'Chưa đủ điều kiện ứng cử',
                html: `
                    <p style="text-align:left; margin-bottom:12px;">
                        <strong>${esc(hd.ho_ten)}</strong> — ${esc(hd.mssv)}<br>
                        <span style="color:#888;">Chi đoàn: ${esc(hd.chi_doan)} — ${esc(hd.khoa)}</span>
                    </p>
                    <div style="padding:10px; background:#fff3cd; border-radius:8px; color:#856404; font-size:0.9em; margin-bottom:12px;">
                        ⚠️ Bạn chưa đạt đủ các tiêu chí bắt buộc để ứng cử danh hiệu Đoàn viên Ưu tú.
                    </div>
                    <table style="width:100%; border-collapse:collapse; text-align:left; font-size:0.9em;">
                        <thead><tr>
                            <th style="padding:8px 10px; border-bottom:2px solid #ddd; width:30px;"></th>
                            <th style="padding:8px 10px; border-bottom:2px solid #ddd;">Tiêu chí</th>
                            <th style="padding:8px 10px; border-bottom:2px solid #ddd; text-align:center;">Yêu cầu</th>
                            <th style="padding:8px 10px; border-bottom:2px solid #ddd; text-align:center;">Của bạn</th>
                        </tr></thead>
                        <tbody>${criteriaHtml}</tbody>
                    </table>
                `,
                width: 580,
                confirmButtonText: 'Đã hiểu',
            });
            return;
        }

        // ĐẠT → Hiện kết quả + nút "Tiếp tục ứng cử"
        let criteriaPassTags = '';
        for (const [key, info] of Object.entries(criteria)) {
            criteriaPassTags += `<span style="display:inline-block; margin:3px 4px; padding:4px 12px; background:rgba(25,135,84,0.1); color:#198754; border-radius:12px; font-size:0.82em; font-weight:600;">✅ ${info.value}</span>`;
        }

        const { isConfirmed } = await Swal.fire({
            icon: 'success',
            title: 'Đạt điều kiện ứng cử!',
            html: `
                <div style="text-align:left;">
                    <table style="width:100%; border-collapse:collapse; margin-bottom:12px;">
                        <tr><td style="padding:6px 10px; font-weight:600; color:#555; width:30%;">Họ tên</td><td style="padding:6px 10px;"><strong style="color:#174f8c;">${esc(hd.ho_ten)}</strong></td></tr>
                        <tr><td style="padding:6px 10px; font-weight:600; color:#555;">MSSV</td><td style="padding:6px 10px;">${esc(hd.mssv)}</td></tr>
                        <tr><td style="padding:6px 10px; font-weight:600; color:#555;">Chi đoàn</td><td style="padding:6px 10px;">${esc(hd.chi_doan)}</td></tr>
                        <tr><td style="padding:6px 10px; font-weight:600; color:#555;">Khoa</td><td style="padding:6px 10px;">${esc(hd.khoa)}</td></tr>
                    </table>
                    <div style="margin-bottom:12px;">${criteriaPassTags}</div>
                    <div style="padding:10px; background:#d1e7dd; border-radius:8px; color:#0f5132; font-size:0.88em;">
                        🎉 Chúc mừng! Bạn đủ điều kiện ứng cử danh hiệu <strong>Đoàn viên Ưu tú</strong>. Nhấn nút bên dưới để điền hồ sơ.
                    </div>
                </div>
            `,
            showCancelButton: true,
            confirmButtonText: 'Tiếp tục điền hồ sơ',
            cancelButtonText: 'Để sau',
            confirmButtonColor: '#174f8c',
            width: 540,
        });

        if (!isConfirmed) return;

        // ════════════════════════════════════════════════════
        // BƯỚC 3: Form điền hồ sơ (Mẫu 01 + Mẫu 02)
        // ════════════════════════════════════════════════════
        const { value: formResult } = await Swal.fire({
            title: 'Nộp hồ sơ ứng cử',
            html: `
                <div style="text-align:left;">
                    <p style="color:#555; margin-bottom:16px;">
                        Đoàn viên: <strong style="color:#174f8c;">${esc(hd.ho_ten)}</strong> — ${esc(hd.mssv)}
                    </p>

                    <div class="form-group" style="margin-bottom:16px;">
                        <label style="font-weight:700; color:#174f8c; font-size:0.92em; display:block; margin-bottom:6px;">
                            📄 Mẫu 01 — Sơ lược quá trình phấn đấu <span style="color:red;">*</span>
                        </label>
                        <small style="color:#888; display:block; margin-bottom:8px;">Upload file PDF hoặc DOCX (tối đa 5MB).</small>
                        <div id="swal-m01-uc-area" style="border:2px dashed #ddd; border-radius:10px; padding:20px; text-align:center; cursor:pointer; transition:all 0.2s; background:#fafafa;" onclick="document.getElementById('swal-m01-uc-input').click();">
                            <span class="dashicons dashicons-upload" style="font-size:28px; color:#aaa; display:block; margin-bottom:6px;"></span>
                            <p style="margin:0; color:#666;">Kéo thả file hoặc <strong style="color:#174f8c;">click để chọn</strong></p>
                            <p style="font-size:0.8em; color:#bbb; margin-top:4px;">PDF, DOC, DOCX — Tối đa 5MB</p>
                        </div>
                        <input type="file" id="swal-m01-uc-input" accept=".pdf,.doc,.docx" style="display:none;">
                        <div id="swal-m01-uc-name" style="margin-top:8px; color:#174f8c; font-weight:600; display:none;"></div>
                    </div>

                    <div class="form-group">
                        <label style="font-weight:700; color:#174f8c; font-size:0.92em; display:block; margin-bottom:6px;">
                            📝 Mẫu 02 — Bài cảm nhận về Đảng
                        </label>
                        <small style="color:#888; display:block; margin-bottom:8px;">File PDF hoặc DOCX (tối đa 5MB).</small>
                        <div id="swal-ung-cu-file-area" style="border:2px dashed #ddd; border-radius:10px; padding:24px; text-align:center; cursor:pointer; transition:all 0.2s; background:#fafafa;" onclick="document.getElementById('swal-ung-cu-file-input').click();">
                            <span class="dashicons dashicons-upload" style="font-size:32px; color:#aaa; display:block; margin-bottom:8px;"></span>
                            <p style="margin:0; color:#666;">Kéo thả file vào đây hoặc <strong style="color:#174f8c;">click để chọn file</strong></p>
                            <p style="font-size:0.8em; color:#bbb; margin-top:4px;">PDF, DOC, DOCX — Tối đa 5MB</p>
                        </div>
                        <input type="file" id="swal-ung-cu-file-input" accept=".pdf,.doc,.docx" style="display:none;">
                        <div id="swal-ung-cu-file-name" style="margin-top:8px; color:#174f8c; font-weight:600; display:none;"></div>
                    </div>
                </div>
            `,
            width: 620,
            showCancelButton: true,
            confirmButtonText: 'Nộp hồ sơ ứng cử',
            cancelButtonText: 'Hủy',
            confirmButtonColor: '#198754',
            focusConfirm: false,
            didOpen: () => {
                // Mẫu 01
                const m01Input  = document.getElementById('swal-m01-uc-input');
                const m01Area   = document.getElementById('swal-m01-uc-area');
                const m01NameEl = document.getElementById('swal-m01-uc-name');
                m01Input.addEventListener('change', function() {
                    if (this.files && this.files[0]) {
                        m01NameEl.innerHTML = '📎 ' + this.files[0].name;
                        m01NameEl.style.display = 'block';
                        m01Area.style.borderColor = '#198754';
                        m01Area.style.background = '#f0fdf4';
                    }
                });
                m01Area.addEventListener('dragover', (e) => { e.preventDefault(); m01Area.style.borderColor = '#174f8c'; });
                m01Area.addEventListener('dragleave', () => { m01Area.style.borderColor = '#ddd'; });
                m01Area.addEventListener('drop', (e) => {
                    e.preventDefault();
                    if (e.dataTransfer.files && e.dataTransfer.files[0]) {
                        m01Input.files = e.dataTransfer.files;
                        m01NameEl.innerHTML = '📎 ' + e.dataTransfer.files[0].name;
                        m01NameEl.style.display = 'block';
                        m01Area.style.borderColor = '#198754';
                        m01Area.style.background = '#f0fdf4';
                    }
                });

                // Mẫu 02
                const fileInput = document.getElementById('swal-ung-cu-file-input');
                const fileArea = document.getElementById('swal-ung-cu-file-area');
                const fileNameEl = document.getElementById('swal-ung-cu-file-name');
                fileInput.addEventListener('change', function() {
                    if (this.files && this.files[0]) {
                        fileNameEl.innerHTML = '📎 ' + this.files[0].name;
                        fileNameEl.style.display = 'block';
                        fileArea.style.borderColor = '#198754';
                        fileArea.style.background = '#f0fdf4';
                    }
                });
                fileArea.addEventListener('dragover', (e) => { e.preventDefault(); fileArea.style.borderColor = '#174f8c'; fileArea.style.background = '#f0f7ff'; });
                fileArea.addEventListener('dragleave', () => { fileArea.style.borderColor = '#ddd'; fileArea.style.background = '#fafafa'; });
                fileArea.addEventListener('drop', (e) => {
                    e.preventDefault();
                    fileArea.style.borderColor = '#198754';
                    fileArea.style.background = '#f0fdf4';
                    if (e.dataTransfer.files && e.dataTransfer.files[0]) {
                        fileNameEl.innerHTML = '📎 ' + e.dataTransfer.files[0].name;
                        fileNameEl.style.display = 'block';
                    }
                });
            },
            preConfirm: () => {
                const m01Input = document.getElementById('swal-m01-uc-input');
                if (!m01Input.files.length) {
                    Swal.showValidationMessage('Vui lòng upload file Mẫu 01 (Sơ lược quá trình phấn đấu).');
                    return false;
                }
                const mau02Input = document.getElementById('swal-ung-cu-file-input');
                return {
                    mau01: m01Input.files[0],
                    bai_cam_nhan: mau02Input.files.length ? mau02Input.files[0] : null,
                };
            }
        });

        if (!formResult) return;

        // ════════════════════════════════════════════════════
        // XỬ LÝ: Tạo đề cử + nộp hồ sơ
        // ════════════════════════════════════════════════════
        Swal.fire({ title: 'Đang nộp hồ sơ...', allowOutsideClick: false, didOpen: () => Swal.showLoading() });

        // Bước A: Tạo record đề cử
        const addRes = await ajaxRequest('dvut_add_de_cu', {
            ho_ten: hd.ho_ten,
            mssv: hd.mssv,
            chi_doan_id: hd.chi_doan_id,
            khoa_id: hd.khoa_id || ''
        });

        if (!addRes.success) {
            Swal.fire({ icon: 'error', title: 'Lỗi', text: addRes.data?.message || 'Không thể tạo hồ sơ ứng cử.' });
            return;
        }

        await loadData();
        const dv = allDoanVien.find(d => d.mssv === hd.mssv);
        if (!dv) {
            Swal.fire({ icon: 'error', title: 'Lỗi', text: 'Không tìm thấy hồ sơ sau khi tạo.' });
            return;
        }

        // Bước B: Nộp hồ sơ (Mẫu 01 + Mẫu 02) — truyền File objects thực
        const nopRes = await ajaxRequest('dvut_nop_ho_so', {
            id: dv.id,
            mau01: formResult.mau01 || null,
            bai_cam_nhan: formResult.bai_cam_nhan || null,
        });

        if (nopRes.success) {
            await loadData(); // loadData() tự gọi populateProfileForDoanVien()
            Swal.fire({
                icon: 'success',
                title: 'Nộp hồ sơ thành công! 🎉',
                html: '<p>Hồ sơ ứng cử của bạn đã được gửi.<br>Vui lòng chờ BCH Chi Đoàn xét duyệt.</p>',
                confirmButtonText: 'Tuyệt vời!',
                confirmButtonColor: '#198754',
            });
        } else {
            Swal.fire({ icon: 'error', title: 'Lỗi', text: nopRes.data?.message || 'Có lỗi khi nộp hồ sơ.' });
        }
    }

    /**
     * Cập nhật timeline theo trạng thái hiện tại của hồ sơ.
     */
    /**
     * updateProfileTimeline() — Cập nhật cả 2 timeline dựa trên trạng thái hồ sơ.
     * Timeline 1 (ĐVƯT): 5 bước: Đề cử → Nộp hồ sơ → CĐ biểu quyết → ĐK xét duyệt → Công nhận
     * Timeline 2 (Đảng): 4 bước: Cảm tình Đảng → Giới thiệu → Kết nạp → Chính thức
     * Timeline 2 chỉ hiện khi trạng thái >= DA_CONG_NHAN
     */
    function updateProfileTimeline(trangThai, dv) {
        // ── Timeline 1: Công nhận ĐVƯT (5 bước) ──
        const TL1_MAP = {
            'CHO_NOP':              1,
            'CHO_CHI_DOAN':         2,
            'CHO_CHI_DOAN_DUYET':   2,
            'CHO_DOAN_KHOA':        3,
            'CHO_DOAN_TRUONG':      4,
            'DA_CONG_NHAN':         5,
            // Các trạng thái sau DA_CONG_NHAN → timeline 1 hoàn tất
            'CHO_DK_GIOI_THIEU':    5,
            'CHUYEN_GIAO_CHI_BO':   5,
            'CHI_BO_DANG_THEO_DOI': 5,
            'CHO_DANG_UY_TRUONG_XET': 5,
            'DA_CO_QD_KET_NAP':     5,
            'DANG_VIEN_DU_BI':      5,
            'CHO_DK_CHUYEN_DANG':   5,
            'DANG_VIEN_CHINH_THUC': 5,
        };

        // ── Timeline 2: Phát triển Đảng (4 bước) ──
        const TL2_MAP = {
            'DA_CONG_NHAN':         0, // Mới công nhận, chưa bắt đầu Đảng
            'CHO_DK_GIOI_THIEU':    2,
            'CHUYEN_GIAO_CHI_BO':   2,
            'CHI_BO_DANG_THEO_DOI': 2,
            'CHO_DANG_UY_TRUONG_XET': 2,
            'DA_CO_QD_KET_NAP':     3,
            'DANG_VIEN_DU_BI':      3,
            'CHO_DK_CHUYEN_DANG':   4,
            'DANG_VIEN_CHINH_THUC': 4,
        };

        // Kiểm tra cảm tình Đảng từ field riêng
        const ctdProgress = dv ? (dv.tien_do_cam_tinh_dang || 'CHUA_THAM_GIA') : 'CHUA_THAM_GIA';

        const activeStep1 = TL1_MAP[trangThai] || 0;
        const $steps1 = $('#profile-timeline .tl-step');
        const totalSteps1 = $steps1.length;

        // Xác định bước bị từ chối
        let rejectedStep1 = 0;
        if (trangThai === 'TU_CHOI' || trangThai === 'TRA_VE') {
            if (dv && dv.trang_thai_truoc_tu_choi) {
                rejectedStep1 = TL1_MAP[dv.trang_thai_truoc_tu_choi] || 0;
            } else if (dv && dv.cap_tu_choi) {
                const capStepMap = { 'Chi Đoàn': 2, 'Đoàn Khoa': 3, 'Đoàn Trường': 4 };
                rejectedStep1 = capStepMap[dv.cap_tu_choi] || 0;
            }
        }

        // Cập nhật Timeline 1
        let doneCount1 = 0;
        $steps1.each(function (idx) {
            const stepNum = idx + 1;
            $(this).removeClass('done active rejected overdue');
            if (trangThai === 'TU_CHOI' || trangThai === 'TRA_VE') {
                if (rejectedStep1 > 0) {
                    if (stepNum < rejectedStep1) { $(this).addClass('done'); doneCount1 = stepNum; }
                    else if (stepNum === rejectedStep1) $(this).addClass('rejected');
                }
                // Không còn fallback hardcode step 2 — nếu rejectedStep1 === 0 (chưa có dữ liệu)
                // thì không tô màu bước nào, tránh hiển thị sai cấp từ chối
            } else if (activeStep1 >= totalSteps1 && stepNum <= totalSteps1) {
                $(this).addClass('done'); doneCount1 = stepNum;
            } else if (stepNum < activeStep1) {
                $(this).addClass('done'); doneCount1 = stepNum;
            } else if (stepNum === activeStep1) {
                $(this).addClass('active');
            }
        });

        // Progress bar cho Timeline 1
        let progress1El = $('#profile-timeline .tl-progress');
        if (progress1El.length === 0) {
            $('#profile-timeline').prepend('<div class="tl-progress"></div>');
            progress1El = $('#profile-timeline .tl-progress');
        }
        const pct1 = totalSteps1 > 1 ? Math.min(100, ((doneCount1) / (totalSteps1 - 1)) * 100) : 0;
        progress1El.css('width', pct1 + '%');

        // Cảnh báo 15 ngày (Node 4 → 5)
        if (dv && trangThai === 'CHO_DOAN_TRUONG' && dv.ngay_gui_doan_truong) {
            const sent = new Date(dv.ngay_gui_doan_truong);
            const now = new Date();
            const bizDays = countBusinessDays(sent, now);
            if (bizDays > 15) {
                $('#tl-warning-15day').show();
                $steps1.eq(3).addClass('overdue').removeClass('active');
            } else {
                $('#tl-warning-15day').hide();
            }
        } else {
            $('#tl-warning-15day').hide();
        }

        // Hiện ghi chú từ chối
        const $rejNote = $('#tl-rejection-note');
        if ((trangThai === 'TU_CHOI' || trangThai === 'TRA_VE') && dv && dv.ly_do_tu_choi) {
            $rejNote.html(`
                <div style="margin-top:8px; padding:10px 14px; background:#fff3cd; border:1px solid #ffc107; border-radius:8px; font-size:.85em; color:#856404;">
                    <strong>⚠️ Lý do từ chối:</strong> ${esc(dv.ly_do_tu_choi)}
                    ${dv.cap_tu_choi ? ` (Cấp: ${esc(dv.cap_tu_choi)})` : ''}
                </div>
            `).show();
        } else {
            $rejNote.hide();
        }

        // ── Timeline 2: Phát triển Đảng ──
        const showTL2 = activeStep1 >= 5 && trangThai !== 'TU_CHOI' && trangThai !== 'TRA_VE';
        if (showTL2) {
            $('#timeline-dang-card').show();

            let activeStep2 = TL2_MAP[trangThai] || 0;
            // Bước 1 Cảm tình Đảng: xem field riêng
            const ctdDone = (ctdProgress === 'DA_HOAN_THANH');
            if (ctdDone && activeStep2 < 1) activeStep2 = 1;

            const $steps2 = $('#profile-timeline-dang .tl-step');
            const totalSteps2 = $steps2.length;
            let doneCount2 = 0;

            $steps2.each(function (idx) {
                const stepNum = idx + 1;
                $(this).removeClass('done active rejected overdue');
                if (trangThai === 'DANG_VIEN_CHINH_THUC') {
                    $(this).addClass('done'); doneCount2 = stepNum;
                } else if (stepNum === 1) {
                    // Cảm tình Đảng
                    if (ctdDone) { $(this).addClass('done'); doneCount2 = 1; }
                    else if (ctdProgress === 'DANG_HOC') $(this).addClass('active');
                    // CHUA_THAM_GIA → giữ mặc định
                } else if (stepNum < activeStep2) {
                    $(this).addClass('done'); doneCount2 = stepNum;
                } else if (stepNum === activeStep2) {
                    $(this).addClass('active');
                }
            });

            // Progress bar cho Timeline 2
            let progress2El = $('#profile-timeline-dang .tl-progress');
            if (progress2El.length === 0) {
                $('#profile-timeline-dang').prepend('<div class="tl-progress"></div>');
                progress2El = $('#profile-timeline-dang .tl-progress');
            }
            const pct2 = totalSteps2 > 1 ? Math.min(100, ((doneCount2) / (totalSteps2 - 1)) * 100) : 0;
            progress2El.css('width', pct2 + '%');

            // Cảnh báo 12 tháng (giới thiệu chưa kết nạp)
            if (dv && dv.ngay_gioi_thieu_dang) {
                const gtDate = new Date(dv.ngay_gioi_thieu_dang);
                const now = new Date();
                const monthsDiff = (now - gtDate) / (1000 * 60 * 60 * 24 * 30);
                if (monthsDiff > 12 && trangThai !== 'DANG_VIEN_DU_BI' && trangThai !== 'DANG_VIEN_CHINH_THUC') {
                    $('#tl-warning-12month').show();
                } else {
                    $('#tl-warning-12month').hide();
                }
            } else {
                $('#tl-warning-12month').hide();
            }

            // Đồng hồ đếm ngược dự bị (12 tháng)
            if (trangThai === 'DANG_VIEN_DU_BI' && dv && dv.ngay_ket_nap_dang) {
                const knDate = new Date(dv.ngay_ket_nap_dang);
                const duBiEnd = new Date(knDate);
                duBiEnd.setFullYear(duBiEnd.getFullYear() + 1);
                const now = new Date();
                const daysLeft = Math.ceil((duBiEnd - now) / (1000 * 60 * 60 * 24));
                if (daysLeft > 0) {
                    $('#tl-countdown-dubi').html(`⏱️ Thời gian dự bị còn lại: <strong>${daysLeft} ngày</strong> (hết hạn: ${formatDate(duBiEnd.toISOString().split('T')[0])})`).show();
                } else {
                    $('#tl-countdown-dubi').html(`✅ Đã hết thời gian dự bị. Sẵn sàng chuyển Đảng chính thức.`).show();
                }
            } else {
                $('#tl-countdown-dubi').hide();
            }
        } else {
            $('#timeline-dang-card').hide();
        }
    }

    /**
     * Đếm ngày làm việc giữa 2 ngày (bỏ T7, CN).
     * Dùng cho cảnh báo trễ hạn 15 ngày.
     */
    function countBusinessDays(start, end) {
        let count = 0;
        const d = new Date(start);
        while (d <= end) {
            const dow = d.getDay();
            if (dow !== 0 && dow !== 6) count++;
            d.setDate(d.getDate() + 1);
        }
        return count;
    }

    // =================================================================
    // ADMIN — Tra cứu hồ sơ đoàn viên
    // =================================================================

    /**
     * Tìm kiếm đoàn viên theo họ tên, MSSV hoặc email.
     * Hiển thị danh sách kết quả clickable. Khi chọn → populate profile view.
     */
    async function searchProfileForAdmin() {
        const query = $('#admin-profile-search-input').val().trim().toLowerCase();
        const $results = $('#admin-profile-search-results');
        $results.empty();

        if (!query || query.length < 2) {
            $results.html('<p style="color:#888; font-size:0.85em; margin:0;">Vui lòng nhập ít nhất 2 ký tự.</p>');
            return;
        }

        // Tìm kiếm hồ sơ (blackbox) từ server
        let results = [];
        try {
            const res = await ajaxRequest('dvut_search_blackbox', { query: query, limit: 20 });
            if (res.success && Array.isArray(res.data)) {
                results = res.data;
            }
        } catch (e) {
            console.error('Error searching blackbox:', e);
        }

        if (results.length === 0) {
            $results.html('<p style="color:#dc3545; font-size:0.85em; margin:0;">Không tìm thấy kết quả nào.</p>');
            return;
        }

        // Render danh sách kết quả
        let html = `<div style="border:1px solid #e5e7eb; border-radius:8px; overflow:hidden; max-height:300px; overflow-y:auto;">`;
        results.forEach((r, i) => {
            const dv = allDoanVien.find(d => d.mssv === r.mssv);
            const statusBadge = dv ? `<span class="${getStatusBadge(dv.trang_thai).cls}" style="font-size:0.78em; padding:2px 10px; border-radius:10px;">${esc(getStatusBadge(dv.trang_thai).label)}</span>` : '<span style="font-size:0.78em; color:#888;">Chưa đề cử</span>';
            html += `<div class="admin-search-result-item" data-mssv="${esc(r.mssv)}" style="padding:10px 14px; cursor:pointer; border-bottom:1px solid #f0f0f0; display:flex; justify-content:space-between; align-items:center; transition:background .15s;${i === 0 ? '' : ''}">
                <div>
                    <strong style="color:#174f8c;">${esc(r.ho_ten)}</strong>
                    <span style="color:#888; font-size:0.85em; margin-left:8px;">${esc(r.mssv)}</span>
                    <span style="color:#aaa; font-size:0.8em; margin-left:6px;">${esc(r.email || '')}</span>
                </div>
                <div>${statusBadge}</div>
            </div>`;
        });
        html += `</div>`;
        if (results.length === 20) {
            html += `<p style="color:#888; font-size:0.8em; margin-top:6px;">Hiển thị tối đa 20 kết quả. Hãy nhập chính xác hơn để thu hẹp.</p>`;
        }
        $results.html(html);

        // Event: click vào kết quả → populate profile
        $results.find('.admin-search-result-item').on('click', function () {
            const mssv = $(this).data('mssv');
            populateProfileForAdmin(String(mssv));
        });
    }

    /**
     * Populate hồ sơ cá nhân cho Admin khi chọn 1 đoàn viên từ kết quả tìm kiếm.
     * Tương tự populateProfileForDoanVien nhưng dựa trên mssv thay vì current user,
     * và ẩn các nút ứng cử / nộp hồ sơ (chỉ xem).
     */
    async function populateProfileForAdmin(mssv) {
        // Tìm hồ sơ từ server (blackbox)
        let hd = null;
        try {
            const res = await ajaxRequest('dvut_get_blackbox_profile', { mssv: mssv });
            if (res.success && res.data) {
                hd = res.data;
            }
        } catch (e) {
            console.error('Error fetching admin profile:', e);
        }
        if (!hd) return;

        // Tìm trong danh sách đoàn viên (nếu đã đề cử)
        const dv = allDoanVien.find(d => d.mssv === mssv);

        // Đóng kết quả tìm kiếm
        $('#admin-profile-search-results').empty();
        $('#admin-profile-search-input').val(hd.ho_ten + ' — ' + hd.mssv);

        // ── Cập nhật tiêu đề ──
        const $title = $('#dvut-profile-view h1');
        if ($title.length) {
            $title.html(`Hồ sơ — <span style="color:#174f8c;">${esc(hd.ho_ten)}</span> <span style="font-size:0.7em; color:#888;">(${esc(hd.mssv)})</span>`);
        }

        // ── Điền thông tin cá nhân ──
        // Reset trước
        $('#profile-chuc-vu, #profile-chi-doan, #profile-gpa, #profile-diem-rl, #profile-xep-loai, #profile-llct, #profile-noi-thuong-tru').val('').prop('disabled', true);
        $('#mau01-file-name').text('').hide();
        $('#profile-mau01-file').prop('disabled', true);
        $('#mau02-file-name').hide();

        if (dv) {
            const $chiDoan = $('#profile-chi-doan');
            if ($chiDoan.length) {
                if ($chiDoan.find(`option[value="${dv.chi_doan_id}"]`).length === 0) {
                    $chiDoan.append(`<option value="${dv.chi_doan_id}">${esc(dv.chi_doan)}</option>`);
                }
                $chiDoan.val(dv.chi_doan_id);
            }
            if (dv.mau01_file) {
                $('#mau01-file-name').text('📎 ' + dv.mau01_file.split('/').pop()).show();
            } else if (dv.so_luoc_qua_trinh) {
                $('#mau01-file-name').text('📝 ' + dv.so_luoc_qua_trinh.substring(0, 60)).show();
            }
            if (dv.bai_cam_nhan_file) {
                $('#mau02-file-name').text('📎 ' + dv.bai_cam_nhan_file).show();
            }
        } else if (hd) {
            const $chiDoan = $('#profile-chi-doan');
            if ($chiDoan.length && hd.chi_doan_id) {
                if ($chiDoan.find(`option[value="${hd.chi_doan_id}"]`).length === 0) {
                    $chiDoan.append(`<option value="${hd.chi_doan_id}">${esc(hd.chi_doan)}</option>`);
                }
                $chiDoan.val(hd.chi_doan_id);
            }
        }

        // ── Điền từ hộp đen (thông tin học tập) ──
        if (hd) {
            $('#profile-gpa').val(hd.diem_tb);
            $('#profile-diem-rl').val(hd.diem_rl);
            const xlMap = { 'Hoàn thành xuất sắc': 'xuat_sac', 'Hoàn thành tốt': 'kha', 'Hoàn thành': 'trung_binh', 'Không hoàn thành': 'yeu' };
            $('#profile-xep-loai').val(xlMap[hd.xep_loai_doan_vien] || 'khong_co');
            const llctMap = { 'Hoàn thành': 'hoan_thanh', 'Đã đăng ký': 'da_dang_ky', 'LLCT': 'hoan_thanh' };
            $('#profile-llct').val(llctMap[hd.ly_luan_chinh_tri] || 'khong_co');
            $('#profile-chuc-vu').val('doan_vien');
        }

        // ── Tất cả trường đều disabled (admin chỉ xem) ──
        $('#profile-chuc-vu, #profile-chi-doan, #profile-gpa, #profile-diem-rl, #profile-xep-loai, #profile-llct, #profile-noi-thuong-tru').prop('disabled', true);
        $('#profile-save-btn').hide();

        // ── Ẩn các trường trùng lặp (đã hiển thị ở "Tổng quan hồ sơ") ──
        ['#profile-chi-doan', '#profile-gpa', '#profile-diem-rl', '#profile-xep-loai', '#profile-llct'].forEach(sel => {
            $(sel).closest('.form-group').hide();
        });

        // ── Ẩn nút ứng cử + nộp tài liệu (admin chỉ xem) ──
        $('#dvut-ung-cu-wrapper').hide();
        $('#profile-submit-btn').hide();
        $('#profile-upload-mau02').css('pointer-events', 'none').css('opacity', '0.5');
        $('#profile-mau02-file').prop('disabled', true);

        // ── Cập nhật Timeline ──
        if (dv) {
            updateProfileTimeline(dv.trang_thai, dv);
        } else {
            updateProfileTimeline('CHUA_DE_CU', null);
        }

        // ── Thêm bảng tóm tắt ──
        renderDoanVienSummaryCard(dv, hd);
    }

    /**
     * Reset profile view về trạng thái trống (dùng khi admin chuyển sang profile view).
     */
    function resetAdminProfileView() {
        $('#dvut-profile-view h1').html('Hồ sơ cá nhân');
        $('#admin-profile-search-input').val('');
        $('#admin-profile-search-results').empty();
        $('#dv-summary-card').remove();
        $('#dvut-ung-cu-wrapper').hide();
        // Reset timelines
        $('#profile-timeline .tl-step, #profile-timeline-dang .tl-step').removeClass('done active rejected overdue');
        $('#profile-timeline .tl-progress, #profile-timeline-dang .tl-progress').css('width', '0');
        $('#timeline-dang-card, #dv-task-card, #dv-reviews-card').hide();
        $('#tl-warning-15day, #tl-warning-12month, #tl-countdown-dubi, #tl-rejection-note').hide();
        // Reset form fields
        $('#profile-chuc-vu, #profile-chi-doan, #profile-gpa, #profile-diem-rl, #profile-xep-loai, #profile-llct, #profile-noi-thuong-tru').val('').prop('disabled', true);
        $('#mau01-file-name').text('').hide();
        $('#mau02-file-name').hide();
        $('#profile-save-btn').hide();
        $('#profile-submit-btn').hide();
    }

    /**
     * Render card tóm tắt thông tin cho Đoàn viên (hiện ở đầu profile view).
     */
    function renderDoanVienSummaryCard(dv, hd) {
        // Xóa card cũ nếu có
        $('#dv-summary-card').remove();

        if (!dv && !hd) return;

        const badge = dv ? getStatusBadge(dv.trang_thai) : null;
        const ctdBadge = dv ? getCamTinhDangBadge(dv.tien_do_cam_tinh_dang) : null;

        // ── Resolve email & chi_doan bằng fallback chain ──
        const email = (dv && dv.email) || (hd && hd.email) || (_currentUser && _currentUser.email) || '';
        let chiDoanName = '';
        if (dv && dv.chi_doan) {
            chiDoanName = dv.chi_doan;
        } else {
            // Fallback: lookup từ CHI_DOAN_LIST bằng chi_doan_id
            const cdId = (dv && dv.chi_doan_id) || (hd && hd.chi_doan_id) || 0;
            if (cdId) {
                const cdObj = CHI_DOAN_LIST.find(c => String(c.id) === String(cdId));
                chiDoanName = cdObj ? cdObj.ten : '';
            }
            if (!chiDoanName && hd && hd.chi_doan) {
                chiDoanName = hd.chi_doan;
            }
        }
        // Resolve khoa name: ưu tiên từ DVUT record, rồi blackbox, rồi KHOA_LIST lookup
        let khoaName = '';
        if (dv && dv.khoa) {
            khoaName = dv.khoa;
        } else if (hd && hd.khoa) {
            khoaName = hd.khoa;
        } else {
            const kId = (dv && dv.khoa_id) || (hd && hd.khoa_id) || 0;
            if (kId) {
                const kObj = KHOA_LIST.find(k => String(k.id) === String(kId));
                khoaName = kObj ? kObj.ten : '';
            }
        }

        let html = `
        <div id="dv-summary-card" class="profile-card" style="border-left: 4px solid #174f8c;">
            <div class="profile-card-title">
                <span class="dashicons dashicons-info-outline"></span> Tổng quan hồ sơ
            </div>
            <div style="display:grid; grid-template-columns: repeat(4, 1fr); gap:16px 24px; padding:12px 0;">`;

        // ── Hàng 1: Thông tin cơ bản (luôn hiện) ──
        const hoTen = (dv && dv.ho_ten) || (hd && hd.ho_ten) || '';
        const mssv = (dv && dv.mssv) || (hd && hd.mssv) || '';
        html += `
                <div><strong style="color:#888; font-size:.82em; display:block; margin-bottom:4px;">Họ tên</strong>${esc(hoTen)}</div>
                <div><strong style="color:#888; font-size:.82em; display:block; margin-bottom:4px;">MSSV</strong>${esc(mssv)}</div>
                <div><strong style="color:#888; font-size:.82em; display:block; margin-bottom:4px;">Email</strong>${esc(email)}</div>
                <div><strong style="color:#888; font-size:.82em; display:block; margin-bottom:4px;">Chi đoàn</strong>${esc(chiDoanName)}</div>`;

        // ── Hàng 2: Khoa + Trạng thái ──
        html += `
                <div><strong style="color:#888; font-size:.82em; display:block; margin-bottom:4px;">Khoa</strong>${esc(khoaName)}</div>`;

        if (dv && badge) {
            html += `<div><strong style="color:#888; font-size:.82em; display:block; margin-bottom:4px;">Trạng thái</strong><span class="status-badge ${badge.cls}">${badge.label}</span></div>`;
            if (ctdBadge) {
                html += `<div><strong style="color:#888; font-size:.82em; display:block; margin-bottom:4px;">Cảm tình Đảng</strong><span style="color:${ctdBadge.color};background:${ctdBadge.bg};padding:2px 10px;border-radius:12px;font-weight:600;font-size:.85em;">${ctdBadge.label}</span></div>`;
            }
            if (dv.ty_le > 0 && currentRole !== 'doan_vien') {
                html += `<div><strong style="color:#888; font-size:.82em; display:block; margin-bottom:4px;">Biểu quyết CĐ</strong>${dv.so_luot_dong_y}/${dv.tong_so_nguoi} (${dv.ty_le}%)</div>`;
            }
        } else {
            html += `<div><strong style="color:#888; font-size:.82em; display:block; margin-bottom:4px;">Trạng thái</strong><span class="status-badge" style="background:#e9ecef;color:#495057;">Chưa ứng cử</span></div>`;
        }

        // ── Hàng 3: Điểm số (từ hộp đen) ──
        if (hd) {
            html += `
                <div><strong style="color:#888; font-size:.82em; display:block; margin-bottom:4px;">Điểm TB</strong><span style="font-weight:700; color:${hd.diem_tb >= 7 ? '#198754' : '#dc3545'};">${hd.diem_tb}</span></div>
                <div><strong style="color:#888; font-size:.82em; display:block; margin-bottom:4px;">Điểm RL</strong><span style="font-weight:700; color:${hd.diem_rl >= 80 ? '#198754' : '#dc3545'};">${hd.diem_rl}</span></div>
                <div><strong style="color:#888; font-size:.82em; display:block; margin-bottom:4px;">Xếp loại đoàn viên</strong>${esc(hd.xep_loai_doan_vien || 'Không có')}</div>
                <div><strong style="color:#888; font-size:.82em; display:block; margin-bottom:4px;">Lý luận chính trị</strong>${esc(hd.ly_luan_chinh_tri || 'Không có')}</div>`;
        }

        html += `</div>`;

        // ── Khối từ chối (nếu có) ──
        if (dv && ['TU_CHOI', 'TRA_VE'].includes(dv.trang_thai)) {
            const tuChoiTitle = dv.trang_thai === 'TRA_VE' ? 'Hồ sơ bị Đoàn Khoa trả về' : 'Hồ sơ bị từ chối';
            const capTuChoi   = dv.cap_tu_choi || (dv.trang_thai === 'TRA_VE' ? 'Đoàn Khoa' : '');
            const lyDoTuChoi  = dv.ly_do_tu_choi || (dv.trang_thai === 'TRA_VE' ? dv.ghi_chu : '');
            html += `
            <div style="margin-top:12px; padding:14px 18px; background:#fff3cd; border:1px solid #ffc107; border-radius:10px;">
                <div style="font-weight:700; color:#856404; margin-bottom:8px; font-size:.92em;">⚠️ ${tuChoiTitle}</div>
                <div style="display:flex; flex-wrap:wrap; gap:12px 24px; font-size:.88em;">
                    ${capTuChoi ? `<div><strong style="color:#856404;">Cấp từ chối:</strong> <span style="color:#58151c;">${esc(capTuChoi)}</span></div>` : ''}
                    ${dv.ngay_tu_choi ? `<div><strong style="color:#856404;">Ngày từ chối:</strong> <span style="color:#58151c;">${formatDate(dv.ngay_tu_choi)}</span></div>` : ''}
                </div>
                ${lyDoTuChoi ? `<div style="margin-top:8px; padding:10px 14px; background:#fff; border:1px solid #ffe69c; border-radius:8px; color:#58151c; line-height:1.6; font-size:.88em;"><strong style="color:#856404;">Lý do:</strong> ${esc(lyDoTuChoi)}</div>` : ''}
            </div>`;
        }

        html += `</div>`;

        // Chèn sau tiêu đề h1
        $('#dvut-profile-view h1').after(html);
    }

    // =================================================================
    // RENDER FUNCTIONS
    // =================================================================

    /**
     * Cập nhật thống kê (stat cards + badges)
     */
    function updateStats() {
        const counts = {
            CHO_NOP:         countByStatus('CHO_NOP'),
            CHO_CHI_DOAN:    countByStatus('CHO_CHI_DOAN'),
            CHO_DOAN_KHOA:   countByStatus('CHO_DOAN_KHOA'),
            CHO_DOAN_TRUONG: countByStatus('CHO_DOAN_TRUONG'),
            DA_CONG_NHAN:    (currentRole === 'admin_doan_truong') ? countByStatus('DA_CONG_NHAN') : (countByStatus('DA_CONG_NHAN') + countByStatus('CHO_DOAN_TRUONG')),
            DANG_VIEN_DU_BI: countByStatus('DANG_VIEN_DU_BI') + countByStatus('DANG_VIEN_CHINH_THUC') + countByStatus('CHO_DK_GIOI_THIEU') + countByStatus('CHO_DK_CHUYEN_DANG') + countByStatus('CHUYEN_GIAO_CHI_BO') + countByStatus('CHI_BO_DANG_THEO_DOI') + countByStatus('CHO_DANG_UY_TRUONG_XET') + countByStatus('DA_CO_QD_KET_NAP'),
            TU_CHOI:         countByStatus('TU_CHOI') + countByStatus('TRA_VE'),
        };

        const visible = getVisibleDoanVien();

        // Stat cards — nội dung khác nhau tùy role
        if (currentRole === 'chi_bo_sinh_vien') {
            const cbCounts = {
                da_cong_nhan:      visible.filter(d => ['DA_CONG_NHAN', 'CHUYEN_GIAO_CHI_BO', 'CHI_BO_DANG_THEO_DOI', 'CHO_DANG_UY_TRUONG_XET', 'DA_CO_QD_KET_NAP'].includes(d.trang_thai)).length,
                da_hoan_thanh_ctd: visible.filter(d => ['DA_CONG_NHAN', 'CHUYEN_GIAO_CHI_BO', 'CHI_BO_DANG_THEO_DOI', 'CHO_DANG_UY_TRUONG_XET', 'DA_CO_QD_KET_NAP'].includes(d.trang_thai) && d.tien_do_cam_tinh_dang === 'DA_HOAN_THANH').length,
                dang_hoc_ctd:      visible.filter(d => ['DA_CONG_NHAN', 'CHUYEN_GIAO_CHI_BO', 'CHI_BO_DANG_THEO_DOI', 'CHO_DANG_UY_TRUONG_XET', 'DA_CO_QD_KET_NAP'].includes(d.trang_thai) && d.tien_do_cam_tinh_dang === 'DANG_HOC').length,
                dang_vien_du_bi:   visible.filter(d => d.trang_thai === 'DANG_VIEN_DU_BI').length,
            };
            $('#stat-cho-nop').text(cbCounts.da_cong_nhan);
            $('#stat-cho-nop').closest('.dvut-stat-card').find('.stat-label').text('Đoàn viên ưu tú');
            $('#stat-chi-doan').text(cbCounts.da_hoan_thanh_ctd);
            $('#stat-chi-doan').closest('.dvut-stat-card').find('.stat-label').text('Đã hoàn thành CTĐ');
            $('#stat-doan-khoa').text(cbCounts.dang_hoc_ctd);
            $('#stat-doan-khoa').closest('.dvut-stat-card').find('.stat-label').text('Đang học CTĐ');
            $('#stat-da-cong-nhan').text(cbCounts.dang_vien_du_bi);
            $('#stat-da-cong-nhan').closest('.dvut-stat-card').find('.stat-label').text('Đảng viên');
        } else {
            // Đặt lại label mặc định cho các role khác
            $('#stat-cho-nop').text(counts.CHO_NOP);
            $('#stat-cho-nop').closest('.dvut-stat-card').find('.stat-label').text('Chờ nộp hồ sơ');
            $('#stat-chi-doan').text(counts.CHO_CHI_DOAN);
            $('#stat-chi-doan').closest('.dvut-stat-card').find('.stat-label').text('Chi Đoàn xét duyệt');
            $('#stat-doan-khoa').text(counts.CHO_DOAN_KHOA);
            $('#stat-doan-khoa').closest('.dvut-stat-card').find('.stat-label').text('Đoàn Khoa xét duyệt');
            $('#stat-da-cong-nhan').text(counts.DA_CONG_NHAN);
            $('#stat-da-cong-nhan').closest('.dvut-stat-card').find('.stat-label').text(currentRole === 'admin_doan_truong' ? 'Đoàn viên ưu tú' : 'Đoàn Trường công nhận');
        }

        // Badges trên tabs — hiện số lượng hồ sơ ở mỗi tab
        updateBadge('badge-cho-nop', counts.CHO_NOP);
        updateBadge('badge-chi-doan', counts.CHO_CHI_DOAN);
        updateBadge('badge-doan-khoa', counts.CHO_DOAN_KHOA);
        updateBadge('badge-cho-doan-truong', counts.CHO_DOAN_TRUONG);
        updateBadge('badge-da-cong-nhan', counts.DA_CONG_NHAN);
        updateBadge('badge-dang-vien-du-bi', counts.DANG_VIEN_DU_BI);
        updateBadge('badge-tu-choi', counts.TU_CHOI);

        // Chi bộ badges
        const cbDaCongNhan = visible.filter(d => ['DA_CONG_NHAN', 'CHUYEN_GIAO_CHI_BO', 'CHI_BO_DANG_THEO_DOI', 'CHO_DANG_UY_TRUONG_XET', 'DA_CO_QD_KET_NAP'].includes(d.trang_thai)).length;
        const cbDangVienDuBi = visible.filter(d => d.trang_thai === 'DANG_VIEN_DU_BI').length;
        updateBadge('badge-da-cong-nhan-cb', cbDaCongNhan);
        updateBadge('badge-dang-vien-du-bi-cb', cbDangVienDuBi);
        updateBadge('badge-chuyen-giao-cb', 0);
        updateBadge('badge-dang-theo-doi', 0);
        updateBadge('badge-cho-dang-uy', 0);
        updateBadge('badge-da-co-qd', 0);
    }

    function updateBadge(elementId, count) {
        const el = document.getElementById(elementId);
        if (!el) return;
        if (count > 0) {
            el.textContent = count;
            el.style.display = 'inline-flex';
        } else {
            el.style.display = 'none';
        }
    }

    /**
     * Render bảng dữ liệu — Phiên bản SRS v2.
     * Bảng gồm 7 cột: STT | Họ Tên | MSSV | Chi đoàn | Trạng thái | Cảm tình Đảng | Hành động
     */
    function renderTable() {
        const tbody = document.getElementById('dvut-table-body');
        if (!tbody) return;

        const filtered = getFilteredList();
        const totalPages = Math.ceil(filtered.length / perPage);
        if (currentPage > totalPages) currentPage = Math.max(1, totalPages);

        const start = (currentPage - 1) * perPage;
        const pageData = filtered.slice(start, start + perPage);

        if (pageData.length === 0) {
            const colspanVal = 7;
            tbody.innerHTML = `<tr><td colspan="${colspanVal}" style="text-align:center; padding:30px; color:#888;">
                <span class="dashicons dashicons-info-outline" style="font-size:24px; display:block; margin-bottom:8px;"></span>
                Không có dữ liệu phù hợp.
            </td></tr>`;
            renderPagination(0);
            return;
        }

        // Dynamically adjust header column for BCH Chi Đoàn - Always display actions column
        const actionHeader = document.querySelector('#dvut-table th.action-header');
        if (actionHeader) {
            actionHeader.style.display = '';
        }

        let html = '';
        pageData.forEach((dv, index) => {
            const stt = start + index + 1;
            const statusInfo = getStatusBadge(dv.trang_thai);

            // Lấy tên Khoa từ chi_doan_id
            const chiDoanObj = CHI_DOAN_LIST.find(c => String(c.id) === String(dv.chi_doan_id));
            const khoaLabel = (currentRole === 'admin_doan_truong' && chiDoanObj)
                ? `<br><small style="color:#888; font-size:0.8em;">${esc(chiDoanObj.khoa)}</small>`
                : '';

            const actionsTd = `<td data-label="Hành động" style="white-space: nowrap; text-align: center;">
                <div class="actions-dropdown expanded" style="justify-content: center; display: inline-flex;">
                    <span class="actions-dropdown-label">Thao tác</span>
                    <div class="actions-dropdown-menu">
                        ${getActionButtons(dv)}
                    </div>
                </div>
            </td>`;

            html += `<tr data-id="${dv.id}" class="${selectedIds.has(dv.id) ? 'batch-selected' : ''}">
                <td data-label="" style="text-align:center; padding:8px 6px;">
                    <input type="checkbox" class="dvut-row-cb" data-id="${dv.id}"
                        ${selectedIds.has(dv.id) ? 'checked' : ''}
                        onclick="DvutApp.toggleRowCb(${dv.id}, this.checked)">
                </td>
                <td data-label="STT" style="text-align:center;">${stt}</td>
                <td data-label="Họ và Tên"><strong>${esc(dv.ho_ten)}</strong></td>
                <td data-label="MSSV">${esc(dv.mssv)}</td>
                <td data-label="Chi đoàn">${esc(dv.chi_doan)}${khoaLabel}</td>
                <td data-label="Trạng thái">
                    <span class="status-badge ${statusInfo.cls}">${statusInfo.label}</span>
                    ${dv.trang_thai === 'TU_CHOI' && dv.cap_tu_choi ? `<div style="margin-top:4px; font-size:0.78em; color:#856404; font-weight:600;">${esc(dv.cap_tu_choi)}</div>` : ''}
                    ${dv.trang_thai === 'TU_CHOI' && dv.ngay_tu_choi ? `<div style="margin-top:2px; font-size:0.75em; color:#888; font-style:italic;">${formatDate(dv.ngay_tu_choi)}</div>` : ''}
                </td>
                ${actionsTd}
            </tr>`;
        });

        tbody.innerHTML = html;

        // Hiệu ứng fade in
        tbody.querySelectorAll('tr').forEach((row, i) => {
            row.style.opacity = '0';
            row.style.transform = 'translateY(10px)';
            setTimeout(() => {
                row.style.transition = 'opacity 0.3s ease, transform 0.3s ease';
                row.style.opacity = '1';
                row.style.transform = 'translateY(0)';
            }, i * 50);
        });

        renderPagination(filtered.length);
        updateBatchToolbar();
    }

    /**
     * Xác định nút hành động theo trạng thái + phân quyền (currentRole).
     * Trạng thái không xác định (default) luôn hiển thị nút Xem.
     *
     * Logic RBAC:
     * ┌─────────────────┬──────────────┬──────────────┬────────────────────┐
     * │ Trạng thái      │ Chi Đoàn     │ Đoàn Khoa    │ Admin Đoàn Trường  │
     * ├─────────────────┼──────────────┼──────────────┼────────────────────┤
     * │ CHO_NOP         │ Nộp HS, Xóa  │ Xem          │ Xem, Xóa           │
     * │ CHO_CHI_DOAN    │ BQ, TC, Xem  │ Xem          │ Xem                │
     * │ CHO_DOAN_KHOA   │ Xem          │ Duyệt,TC,Xem│ Xem                │
     * │ CHO_DOAN_TRUONG │ Xem          │ Xem          │ Duyệt, TC, Xem    │
     * │ DA_CONG_NHAN    │ Xem          │ Xem          │ Xem                │
     * │ DANG_VIEN_DU_BI │ Xem          │ Xem          │ Xem                │
     * │ TU_CHOI         │ Xem, Xóa    │ Xem          │ Xem, Xóa           │
     * │ (default)       │ Xem          │ Xem          │ Xem                │
     * └─────────────────┴──────────────┴──────────────┴────────────────────┘
     */
    function getActionButtons(dv) {
        let btns = '';
        const role = currentRole;

        if (role === 'chi_bo_sinh_vien') {
            btns += `<button class="action-btn view-btn" onclick="DvutApp.xemChiTiet(${dv.id})" title="Xem hồ sơ">
                <span class="dashicons dashicons-visibility"></span>
            </button>`;
            btns += `<button class="action-btn edit-btn" onclick="DvutApp.updateCamTinhDang(${dv.id})" title="Cập nhật Cảm tình Đảng" style="background:rgba(0,123,255,0.12); color:#007bff; border-color: rgba(0,123,255,0.2);">
                <span class="dashicons dashicons-welcome-learn-more"></span>
            </button>`;
            btns += `<button class="action-btn approve-btn" onclick="DvutApp.cbCapNhatKetNap(${dv.id})" title="Cập nhật Kết nạp Đảng" style="background:rgba(40,167,69,0.12); color:#28a745; border-color: rgba(40,167,69,0.2);">
                <span class="dashicons dashicons-flag"></span>
            </button>`;
            return btns;
        }

        switch (dv.trang_thai) {
            case 'CHO_NOP':
                // Chi Đoàn: Bỏ quyền nộp hồ sơ & đề cử (chỉ có quyền xem ở trạng thái này)
                // Admin: Xem + Xóa
                if (role === 'admin_doan_truong') {
                    btns += `<button class="action-btn view-btn" onclick="DvutApp.xemChiTiet(${dv.id})" title="Xem hồ sơ">
                        <span class="dashicons dashicons-visibility"></span>
                    </button>`;
                    btns += `<button class="action-btn reject-btn" onclick="DvutApp.xoaHoSo(${dv.id})" title="Xóa">
                        <span class="dashicons dashicons-trash"></span>
                    </button>`;
                }
                // Đoàn Khoa: chỉ Xem
                if (role === 'can_bo_doan_khoa') {
                    btns += `<button class="action-btn view-btn" onclick="DvutApp.xemChiTiet(${dv.id})" title="Xem hồ sơ">
                        <span class="dashicons dashicons-visibility"></span>
                    </button>`;
                }
                break;

            case 'CHO_CHI_DOAN':
                // Chi Đoàn: Biểu quyết + Từ chối + Xem
                if (role === 'bch_chi_doan') {
                    btns += `<button class="action-btn approve-btn" onclick="DvutApp.chiDoanDuyet(${dv.id})" title="Chi Đoàn duyệt">
                        <span class="dashicons dashicons-yes-alt"></span>
                    </button>`;
                    btns += `<button class="action-btn reject-btn" onclick="DvutApp.tuChoiHoSo(${dv.id},'chi_doan')" title="Từ chối">
                        <span class="dashicons dashicons-no-alt"></span>
                    </button>`;
                }
                // Tất cả role: Xem
                btns += `<button class="action-btn view-btn" onclick="DvutApp.xemChiTiet(${dv.id})" title="Xem hồ sơ">
                    <span class="dashicons dashicons-visibility"></span>
                </button>`;
                break;

            case 'CHO_DOAN_KHOA':
                // Đoàn Khoa: Duyệt + Từ chối + Xem
                if (role === 'can_bo_doan_khoa') {
                    btns += `<button class="action-btn approve-btn" onclick="DvutApp.doanKhoaDuyet(${dv.id})" title="Đoàn Khoa duyệt">
                        <span class="dashicons dashicons-yes-alt"></span>
                    </button>`;
                    btns += `<button class="action-btn reject-btn" onclick="DvutApp.doanKhoaTuChoi(${dv.id})" title="Từ chối">
                        <span class="dashicons dashicons-no-alt"></span>
                    </button>`;
                }
                // Tất cả role: Xem
                btns += `<button class="action-btn view-btn" onclick="DvutApp.xemChiTiet(${dv.id})" title="Xem hồ sơ">
                    <span class="dashicons dashicons-visibility"></span>
                </button>`;
                break;

            case 'DA_CONG_NHAN':
                // Chi Đoàn: Nhận xét định kỳ (bỏ BQ Giới thiệu vào Đảng theo yêu cầu)
                if (role === 'bch_chi_doan') {
                    btns += `<button class="action-btn edit-btn" onclick="DvutApp.openNhanXetModal(${dv.id},'${esc(dv.ho_ten)}')" title="Nhận xét định kỳ" style="background:rgba(23,79,140,0.12); color:#174f8c;">
                        <span class="dashicons dashicons-clipboard"></span>
                    </button>`;
                }
                // Chi bộ / Admin: Cập nhật Cảm tình Đảng + Cập nhật Kết nạp Đảng
                if (role === 'chi_bo_sinh_vien' || role === 'admin_doan_truong') {
                    btns += `<button class="action-btn approve-btn" onclick="DvutApp.updateCamTinhDang(${dv.id})" title="Cập nhật Cảm tình Đảng" style="background:rgba(0,123,255,0.12); color:#007bff; border-color: rgba(0,123,255,0.2);">
                        <span class="dashicons dashicons-welcome-learn-more"></span>
                    </button>`;
                    btns += `<button class="action-btn approve-btn" onclick="DvutApp.cbCapNhatKetNap(${dv.id})" title="Cập nhật Kết nạp Đảng" style="background:rgba(40,167,69,0.12); color:#28a745; border-color: rgba(40,167,69,0.2);">
                        <span class="dashicons dashicons-flag"></span>
                    </button>`;
                }
                // Tất cả role: Xem
                btns += `<button class="action-btn view-btn" onclick="DvutApp.xemChiTiet(${dv.id})" title="Xem hồ sơ">
                    <span class="dashicons dashicons-visibility"></span>
                </button>`;
                break;

            case 'DANG_VIEN_DU_BI':
                // Chi Đoàn: Nút "BQ Chuyển Đảng chính thức" (GĐ5) + Nhận xét
                if (role === 'bch_chi_doan') {
                    btns += `<button class="action-btn approve-btn" onclick="DvutApp.chuyenDang(${dv.id})" title="BQ Chuyển Đảng chính thức" style="background:rgba(220,53,69,0.12); color:#dc3545;">
                        <span class="dashicons dashicons-star-filled"></span>
                    </button>`;
                    btns += `<button class="action-btn edit-btn" onclick="DvutApp.openNhanXetModal(${dv.id},'${esc(dv.ho_ten)}')" title="Nhận xét định kỳ" style="background:rgba(23,79,140,0.12); color:#174f8c;">
                        <span class="dashicons dashicons-clipboard"></span>
                    </button>`;
                }
                // Tất cả role: Xem
                btns += `<button class="action-btn view-btn" onclick="DvutApp.xemChiTiet(${dv.id})" title="Xem hồ sơ">
                    <span class="dashicons dashicons-visibility"></span>
                </button>`;
                break;

            case 'CHO_DK_GIOI_THIEU':
                // Đoàn Khoa: Duyệt giới thiệu Đảng + Từ chối
                if (role === 'can_bo_doan_khoa') {
                    btns += `<button class="action-btn approve-btn" onclick="DvutApp.dkDuyetGioiThieu(${dv.id})" title="Duyệt Giới thiệu Đảng" style="background:rgba(111,66,193,0.15); color:#6f42c1;">
                        <span class="dashicons dashicons-flag"></span>
                    </button>`;
                    btns += `<button class="action-btn reject-btn" onclick="DvutApp.tuChoiHoSo(${dv.id},'doan_khoa')" title="Từ chối">
                        <span class="dashicons dashicons-no-alt"></span>
                    </button>`;
                }
                btns += `<button class="action-btn view-btn" onclick="DvutApp.xemChiTiet(${dv.id})" title="Xem hồ sơ">
                    <span class="dashicons dashicons-visibility"></span>
                </button>`;
                break;

            case 'CHO_DK_CHUYEN_DANG':
                // Đoàn Khoa: Duyệt chuyển Đảng chính thức + Từ chối
                if (role === 'can_bo_doan_khoa') {
                    btns += `<button class="action-btn approve-btn" onclick="DvutApp.dkDuyetChuyenDang(${dv.id})" title="Duyệt Chuyển Đảng chính thức" style="background:rgba(220,53,69,0.12); color:#dc3545;">
                        <span class="dashicons dashicons-star-filled"></span>
                    </button>`;
                    btns += `<button class="action-btn reject-btn" onclick="DvutApp.tuChoiHoSo(${dv.id},'doan_khoa')" title="Từ chối">
                        <span class="dashicons dashicons-no-alt"></span>
                    </button>`;
                }
                btns += `<button class="action-btn view-btn" onclick="DvutApp.xemChiTiet(${dv.id})" title="Xem hồ sơ">
                    <span class="dashicons dashicons-visibility"></span>
                </button>`;
                break;

            case 'CHO_DOAN_TRUONG':
                // Admin Đoàn Trường: Ban hành QĐ Công nhận ĐVƯT + Từ chối
                if (role === 'admin_doan_truong') {
                    btns += `<button class="action-btn approve-btn" onclick="DvutApp.adminBanHanh(${dv.id})" title="Ban hành QĐ Công nhận ĐVƯT" style="background:rgba(23,79,140,0.15); color:#174f8c;">
                        <span class="dashicons dashicons-awards"></span>
                    </button>`;
                    btns += `<button class="action-btn reject-btn" onclick="DvutApp.tuChoiHoSo(${dv.id},'doan_truong')" title="Từ chối">
                        <span class="dashicons dashicons-no-alt"></span>
                    </button>`;
                }
                btns += `<button class="action-btn view-btn" onclick="DvutApp.xemChiTiet(${dv.id})" title="Xem hồ sơ">
                    <span class="dashicons dashicons-visibility"></span>
                </button>`;
                break;

            case 'CHUYEN_GIAO_CHI_BO':
            case 'CHI_BO_DANG_THEO_DOI':
            case 'CHO_DANG_UY_TRUONG_XET':
            case 'DA_CO_QD_KET_NAP':
                if (role === 'chi_bo_sinh_vien' || role === 'admin_doan_truong') {
                    btns += `<button class="action-btn approve-btn" onclick="DvutApp.updateCamTinhDang(${dv.id})" title="Cập nhật Cảm tình Đảng" style="background:rgba(0,123,255,0.12); color:#007bff; border-color: rgba(0,123,255,0.2);">
                        <span class="dashicons dashicons-welcome-learn-more"></span>
                    </button>`;
                    btns += `<button class="action-btn approve-btn" onclick="DvutApp.cbCapNhatKetNap(${dv.id})" title="Cập nhật Kết nạp Đảng" style="background:rgba(40,167,69,0.12); color:#28a745; border-color: rgba(40,167,69,0.2);">
                        <span class="dashicons dashicons-flag"></span>
                    </button>`;
                }
                btns += `<button class="action-btn view-btn" onclick="DvutApp.xemChiTiet(${dv.id})" title="Xem hồ sơ">
                    <span class="dashicons dashicons-visibility"></span>
                </button>`;
                break;

            case 'DANG_VIEN_CHINH_THUC':
                // Đã chuyển Đảng chính thức — Tất cả role chỉ Xem
                btns += `<button class="action-btn view-btn" onclick="DvutApp.xemChiTiet(${dv.id})" title="Xem hồ sơ">
                    <span class="dashicons dashicons-visibility"></span>
                </button>`;
                break;

            case 'TU_CHOI':
            case 'TRA_VE':
                btns += `<button class="action-btn view-btn" onclick="DvutApp.xemChiTiet(${dv.id})" title="Xem chi tiết">
                    <span class="dashicons dashicons-visibility"></span>
                </button>`;
                // Chỉ Admin (Quản trị viên) mới có quyền gỡ trạng thái từ chối
                if (role === 'admin_doan_truong' && isDevAdmin) {
                    btns += `<button class="action-btn approve-btn" onclick="DvutApp.goTuChoi(${dv.id})" title="Gỡ từ chối (khôi phục hồ sơ)" style="background:rgba(23,79,140,0.15); color:#174f8c;">
                        <span class="dashicons dashicons-undo"></span>
                    </button>`;
                }
                // BCH Chi Đoàn: Đề cử lại
                if (role === 'bch_chi_doan') {
                    btns += `<button class="action-btn approve-btn" onclick="DvutApp.deCuLai(${dv.id})" title="Đề cử lại" style="background:rgba(13,110,253,0.15); color:#0d6efd;">
                        <span class="dashicons dashicons-controls-repeat"></span>
                    </button>`;
                }
                // Chi Đoàn & Admin: Xóa
                if (role === 'bch_chi_doan' || role === 'admin_doan_truong') {
                    btns += `<button class="action-btn reject-btn" onclick="DvutApp.xoaHoSo(${dv.id})" title="Xóa">
                        <span class="dashicons dashicons-trash"></span>
                    </button>`;
                }
                break;

            default:
                // Trạng thái không xác định — luôn hiện nút Xem
                btns += `<button class="action-btn view-btn" onclick="DvutApp.xemChiTiet(${dv.id})" title="Xem hồ sơ">
                    <span class="dashicons dashicons-visibility"></span>
                </button>`;
                break;
        }

        // Chi bộ sinh viên handled above explicitly

        return btns;
    }

    /**
     * Render pagination
     */
    function renderPagination(totalItems) {
        const container = document.getElementById('dvut-pagination');
        if (!container) return;

        const totalPages = Math.ceil(totalItems / perPage);
        if (totalPages <= 1) {
            container.innerHTML = '';
            return;
        }

        let html = '';
        html += `<button ${currentPage === 1 ? 'disabled' : ''} onclick="DvutApp.goToPage(${currentPage - 1})">
            <span class="dashicons dashicons-arrow-left-alt2" style="font-size:14px;"></span>
        </button>`;

        for (let i = 1; i <= totalPages; i++) {
            if (totalPages > 7) {
                if (i === 1 || i === totalPages || (i >= currentPage - 1 && i <= currentPage + 1)) {
                    html += `<button ${i === currentPage ? 'style="background:#123d6d; font-weight:700;"' : ''} onclick="DvutApp.goToPage(${i})">${i}</button>`;
                } else if (i === currentPage - 2 || i === currentPage + 2) {
                    html += `<span style="padding:0 4px;">...</span>`;
                }
            } else {
                html += `<button ${i === currentPage ? 'style="background:#123d6d; font-weight:700;"' : ''} onclick="DvutApp.goToPage(${i})">${i}</button>`;
            }
        }

        html += `<button ${currentPage === totalPages ? 'disabled' : ''} onclick="DvutApp.goToPage(${currentPage + 1})">
            <span class="dashicons dashicons-arrow-right-alt2" style="font-size:14px;"></span>
        </button>`;

        container.innerHTML = html;
    }

    /**
     * Render dropdown Khoa filter (Select2) — cho admin Đoàn Trường
     */
    function renderKhoaFilterDropdown() {
        const select = $('#dvut-filter-khoa');
        if (!select.length) return;
        // Destroy Select2 cũ nếu đã init
        if (select.hasClass('select2-hidden-accessible')) {
            select.select2('destroy');
        }
        select.empty();
        // Build danh sách khoa duy nhất từ CHI_DOAN_LIST
        const khoaMap = new Map();
        CHI_DOAN_LIST.forEach(cd => {
            if (!khoaMap.has(cd.khoa_id)) {
                khoaMap.set(cd.khoa_id, cd.khoa);
            }
        });
        khoaMap.forEach((ten, id) => {
            // Đếm số chi đoàn + số đoàn viên trong khoa
            const cdCount = CHI_DOAN_LIST.filter(cd => String(cd.khoa_id) === String(id)).length;
            const dvCount = allDoanVien.filter(d => {
                const cd = CHI_DOAN_LIST.find(c => String(c.id) === String(d.chi_doan_id));
                return cd && String(cd.khoa_id) === String(id);
            }).length;
            select.append(`<option value="${id}">${esc(ten)} (${cdCount} CĐ, ${dvCount} ĐV)</option>`);
        });
        // Restore current value
        if (filterKhoa.length) select.val(filterKhoa);
        select.select2({
            placeholder: 'Chọn khoa...',
            allowClear: true,
            width: '100%',
            minimumResultsForSearch: 0,
            multiple: true,
            closeOnSelect: false
        });
    }

    /**
     * Render dropdown Chi đoàn filter (Select2) — chỉ hiện chi đoàn trong phạm vi nhìn thấy
     * Khi là admin Đoàn Trường: group bằng <optgroup> theo Khoa
     */
    function renderChiDoanDropdown(chiDoanList) {
        const select = $('#dvut-filter-chi-doan');
        // Destroy Select2 cũ nếu đã init
        if (select.hasClass('select2-hidden-accessible')) {
            select.select2('destroy');
        }
        select.empty();

        // Khi admin/Đoàn Trường: group theo Khoa bằng <optgroup>
        if (currentRole === 'admin_doan_truong' && !filterKhoa.length) {
            // Nhóm chi đoàn theo khoa
            const grouped = new Map();
            chiDoanList.forEach(cd => {
                const khoaName = cd.khoa || 'Khác';
                if (!grouped.has(khoaName)) grouped.set(khoaName, []);
                grouped.get(khoaName).push(cd);
            });
            grouped.forEach((cds, khoaName) => {
                const $group = $(`<optgroup label="${esc(khoaName)} (${cds.length})">`);
                cds.forEach(cd => {
                    $group.append(`<option value="${cd.id}">${esc(cd.ten)}</option>`);
                });
                select.append($group);
            });
        } else {
            // Flat list cho các role khác hoặc khi đã chọn khoa
            chiDoanList.forEach(cd => {
                select.append(`<option value="${cd.id}">${esc(cd.ten)}</option>`);
            });
        }

        // Restore current value
        if (filterChiDoan.length) select.val(filterChiDoan);
        select.select2({
            placeholder: 'Chọn chi đoàn',
            allowClear: true,
            width: '100%',
            minimumResultsForSearch: 0,
            multiple: true,
            closeOnSelect: false
        });
    }

    /**
     * Populate context dropdowns (Chi Đoàn picker cho BCH, Khoa picker cho Đoàn Khoa).
     * Khoa dropdown dùng KHOA_LIST trực tiếp (không trích từ CHI_DOAN_LIST).
     * Gọi 1 lần sau khi AJAX đã tải xong.
     */
    function populateContextDropdowns() {
        // ── Chi Đoàn context dropdown ──
        const $ctxCD = $('#dvut-context-chi-doan');
        if ($ctxCD.length && $ctxCD.find('option').length <= 1) {
            CHI_DOAN_LIST.forEach(cd => {
                $ctxCD.append(`<option value="${cd.id}">${esc(cd.ten)}</option>`);
            });
        }

        // ── Khoa context dropdown (dùng KHOA_LIST thay vì trích từ CHI_DOAN_LIST) ──
        const $ctxKhoa = $('#dvut-context-khoa');
        if ($ctxKhoa.length && $ctxKhoa.find('option').length <= 1) {
            KHOA_LIST.forEach(k => {
                $ctxKhoa.append(`<option value="${k.id}">${esc(k.ten)}</option>`);
            });
        }
    }

    // =================================================================
    // ACTION HANDLERS (SweetAlert2 Modals)
    // =================================================================

    /**
     * Mở modal Đề cử Đoàn viên mới — Luồng 2 bước.
     *
     * Bước 1: Nhập MSSV → Nút "Kiểm tra điều kiện"
     *         → Gọi dvut_check_mssv để đối chiếu Hộp đen.
     * Bước 2: Nếu ĐẠT → Tự động điền Họ Tên, Chi đoàn → Nút "Đề cử".
     *         Nếu KHÔNG ĐẠT → Báo lỗi chi tiết.
     */
    async function openAddModal() {

        // ──────────── BƯỚC 1: Nhập MSSV & Kiểm tra ────────────
        const { value: mssv } = await Swal.fire({
            title: 'Kiểm tra điều kiện đầu vào',
            html: `
                <div class="form-group">
                    <label>Mã số Sinh viên (MSSV) <span style="color:red;">*</span></label>
                    <small>Nhập MSSV để hệ thống đối chiếu với Hộp đen (dữ liệu Excel Master).</small>
                    <input type="text" id="swal-mssv-check" placeholder="VD: K474010001" class="swal2-input" style="margin:0; margin-top:8px;">
                </div>
                <div style="margin-top:12px; padding:10px; background:#f8f9fa; border-radius:8px; font-size:0.85em; color:#666; text-align:left;">
                    <strong>Tiêu chí bắt buộc:</strong>
                    <ul style="margin:6px 0 0 0; padding-left:18px;">
                        <li>Sinh hoạt Đoàn ≥ 6 tháng</li>
                        <li>Xếp loại: Xuất sắc</li>
                        <li>Điểm trung bình tích lũy ≥ 7.0</li>
                        <li>Điểm rèn luyện ≥ 80</li>
                    </ul>
                </div>
            `,
            showCancelButton: true,
            confirmButtonText: 'Kiểm tra điều kiện',
            cancelButtonText: 'Hủy',
            focusConfirm: false,
            preConfirm: () => {
                const val = document.getElementById('swal-mssv-check').value.trim();
                if (!val) {
                    Swal.showValidationMessage('Vui lòng nhập MSSV');
                    return false;
                }
                return val;
            }
        });

        if (!mssv) return;

        // ──────────── Gọi API kiểm tra MSSV ────────────
        Swal.fire({ title: 'Đang kiểm tra...', allowOutsideClick: false, didOpen: () => Swal.showLoading() });

        const checkRes = await ajaxRequest('dvut_check_mssv', { mssv });

        if (!checkRes.success) {
            Swal.fire({
                icon: 'error',
                title: 'Không tìm thấy MSSV',
                text: checkRes.data?.message || 'MSSV không tồn tại trong hệ thống Hộp đen.',
            });
            return;
        }

        const { eligible, ho_ten, chi_doan, chi_doan_id, khoa, khoa_id, criteria, message } = checkRes.data;

        // ──────────── Hiển thị kết quả kiểm tra ────────────
        if (!eligible) {
            // KHÔNG ĐẠT → Hiển thị chi tiết lý do
            let criteriaHtml = '';
            const criteriaLabels = {
                ly_luan_chinh_tri:   'Lý luận chính trị',
                xep_loai_doan_vien:  'Xếp loại đoàn viên',
                diem_tb:            'Điểm TB tích lũy',
                diem_ren_luyen:     'Điểm rèn luyện',
            };
            const criteriaThresholds = {
                ly_luan_chinh_tri:   'Hoàn thành',
                xep_loai_doan_vien:  'HT xuất sắc / HT tốt',
                diem_tb:            '≥ 7.0',
                diem_ren_luyen:     '≥ 80',
            };

            for (const [key, info] of Object.entries(criteria)) {
                const icon = info.pass ? '✅' : '❌';
                criteriaHtml += `
                    <tr>
                        <td style="padding:6px 10px; border-bottom:1px solid #eee;">${icon}</td>
                        <td style="padding:6px 10px; border-bottom:1px solid #eee; font-weight:600;">${criteriaLabels[key] || key}</td>
                        <td style="padding:6px 10px; border-bottom:1px solid #eee; text-align:center;">${criteriaThresholds[key]}</td>
                    </tr>
                `;
            }

            await Swal.fire({
                icon: 'error',
                title: 'Không đạt điều kiện',
                html: `
                    <p style="text-align:left;"><strong>${esc(ho_ten)}</strong> (${esc(mssv)})</p>
                    <p style="text-align:left; color:#dc3545; font-weight:600;">${esc(message)}</p>
                    <table style="width:100%; border-collapse:collapse; margin-top:10px; text-align:left;">
                        <thead><tr>
                            <th style="padding:6px 10px; border-bottom:2px solid #ddd; width:30px;"></th>
                            <th style="padding:6px 10px; border-bottom:2px solid #ddd;">Tiêu chí</th>
                            <th style="padding:6px 10px; border-bottom:2px solid #ddd; text-align:center;">Yêu cầu</th>
                        </tr></thead>
                        <tbody>${criteriaHtml}</tbody>
                    </table>
                `,
                width: 550,
                confirmButtonText: 'Đóng',
            });
            return;
        }

        // ──────────── BƯỚC 2: ĐẠT → Tự động điền & Đề cử ────────────
        // Hiển thị kết quả ĐẠT + cho phép xác nhận đề cử
        const criteriaPassLabels = {
            ly_luan_chinh_tri:   'Lý luận chính trị',
            xep_loai_doan_vien:  'Xếp loại đoàn viên',
            diem_tb:             'Điểm TB tích lũy',
            diem_ren_luyen:      'Điểm rèn luyện',
        };
        let criteriaPassHtml = '';
        for (const [key] of Object.entries(criteria)) {
            criteriaPassHtml += `<span style="display:inline-block; margin:3px 4px; padding:3px 10px; background:rgba(25,135,84,0.1); color:#198754; border-radius:12px; font-size:0.82em; font-weight:600;">✅ ${criteriaPassLabels[key] || key}</span>`;
        }

        const { isConfirmed } = await Swal.fire({
            icon: 'success',
            title: 'Đạt tất cả điều kiện!',
            html: `
                <div style="text-align:left;">
                    <table style="width:100%; border-collapse:collapse;">
                        <tr><td style="padding:8px; font-weight:600; color:#555; width:35%;">Họ và Tên</td><td style="padding:8px;"><strong style="color:#174f8c; font-size:1.05em;">${esc(ho_ten)}</strong></td></tr>
                        <tr><td style="padding:8px; font-weight:600; color:#555;">MSSV</td><td style="padding:8px;">${esc(mssv)}</td></tr>
                        <tr><td style="padding:8px; font-weight:600; color:#555;">Chi đoàn</td><td style="padding:8px;">${esc(chi_doan)}</td></tr>
                        <tr><td style="padding:8px; font-weight:600; color:#555;">Khoa</td><td style="padding:8px;">${esc(khoa)}</td></tr>
                    </table>
                    <div style="margin-top:10px;">${criteriaPassHtml}</div>
                </div>
            `,
            showCancelButton: true,
            confirmButtonText: 'Đề cử ngay',
            cancelButtonText: 'Hủy',
            width: 500,
        });

        if (!isConfirmed) return;

        // ──────────── Gọi API Đề cử ────────────
        Swal.fire({ title: 'Đang xử lý...', allowOutsideClick: false, didOpen: () => Swal.showLoading() });

        const res = await ajaxRequest('dvut_add_de_cu', { ho_ten, mssv, chi_doan_id, khoa_id });
        if (res.success) {
            await loadData();
            Swal.fire({ icon: 'success', title: 'Thành công!', text: res.data.message, timer: 2000, showConfirmButton: false });
        } else {
            Swal.fire({ icon: 'error', title: 'Lỗi', text: res.data?.message || 'Có lỗi xảy ra.' });
        }
    }

    /**
     * Mở modal Nộp hồ sơ (Mẫu 01 + Mẫu 02)
     */
    async function openNopHoSoModal(id) {
        // ── Kiểm tra quyền: Chỉ BCH Chi Đoàn mới được nộp hồ sơ ──
        if (currentRole !== 'bch_chi_doan') {
            Swal.fire({ icon: 'warning', title: 'Không có quyền', text: 'Chỉ BCH Chi Đoàn mới được thực hiện thao tác Nộp hồ sơ.' });
            return;
        }

        const dv = allDoanVien.find(d => String(d.id) === String(id));
        if (!dv) return;

        const { value: formData } = await Swal.fire({
            title: 'Nộp Hồ sơ',
            html: `
                <p style="text-align:left; margin-bottom:15px; color:#555;">
                    Đoàn viên: <strong style="color:#174f8c;">${esc(dv.ho_ten)}</strong> — ${esc(dv.mssv)}
                </p>

                <div class="form-group">
                    <label>Mẫu 01 — Sơ lược quá trình phấn đấu <span style="color:red;">*</span></label>
                    <small>Upload file PDF hoặc DOCX (tối đa 5MB).</small>
                    <div class="swal-file-upload-area" id="swal-m01-area" onclick="document.getElementById('swal-m01-input').click();">
                        <span class="dashicons dashicons-media-document"></span>
                        <p>Nhấp hoặc kéo thả file Mẫu 01 vào đây</p>
                        <div class="file-name" id="swal-m01-name">${dv.mau01_file ? '📎 ' + esc(dv.mau01_file.split('/').pop()) : ''}</div>
                    </div>
                    <input type="file" id="swal-m01-input" accept=".pdf,.doc,.docx" style="display:none;">
                </div>

                <div class="form-group">
                    <label>Mẫu 02 — Bài cảm nhận (Upload file)</label>
                    <small>File PDF hoặc DOCX (tối đa 5MB).</small>
                    <div class="swal-file-upload-area" id="swal-file-area" onclick="document.getElementById('swal-file-input').click();">
                        <span class="dashicons dashicons-media-document"></span>
                        <p>Nhấp hoặc kéo thả file vào đây</p>
                        <div class="file-name" id="swal-file-name">${dv.bai_cam_nhan_file ? '📎 ' + esc(dv.bai_cam_nhan_file.split('/').pop()) : ''}</div>
                    </div>
                    <input type="file" id="swal-file-input" accept=".pdf,.doc,.docx" style="display:none;">
                </div>
            `,
            showCancelButton: true,
            confirmButtonText: 'Nộp hồ sơ',
            cancelButtonText: 'Hủy',
            width: 580,
            didOpen: () => {
                // Mẫu 01 drag & drop
                const m01Area  = document.getElementById('swal-m01-area');
                const m01Input = document.getElementById('swal-m01-input');
                const m01Name  = document.getElementById('swal-m01-name');
                ['dragenter', 'dragover'].forEach(evt => m01Area.addEventListener(evt, (e) => { e.preventDefault(); m01Area.classList.add('dragover'); }));
                ['dragleave', 'drop'].forEach(evt => m01Area.addEventListener(evt, (e) => { e.preventDefault(); m01Area.classList.remove('dragover'); }));
                m01Area.addEventListener('drop', (e) => { if (e.dataTransfer.files.length) { m01Input.files = e.dataTransfer.files; m01Name.textContent = '📎 ' + e.dataTransfer.files[0].name; } });
                m01Input.addEventListener('change', () => { if (m01Input.files.length) m01Name.textContent = '📎 ' + m01Input.files[0].name; });

                // Mẫu 02 drag & drop
                const fileArea  = document.getElementById('swal-file-area');
                const fileInput = document.getElementById('swal-file-input');
                const fileName  = document.getElementById('swal-file-name');
                ['dragenter', 'dragover'].forEach(evt => fileArea.addEventListener(evt, (e) => { e.preventDefault(); fileArea.classList.add('dragover'); }));
                ['dragleave', 'drop'].forEach(evt => fileArea.addEventListener(evt, (e) => { e.preventDefault(); fileArea.classList.remove('dragover'); }));
                fileArea.addEventListener('drop', (e) => { if (e.dataTransfer.files.length) { fileInput.files = e.dataTransfer.files; fileName.textContent = '📎 ' + e.dataTransfer.files[0].name; } });
                fileInput.addEventListener('change', () => { if (fileInput.files.length) fileName.textContent = '📎 ' + fileInput.files[0].name; });
            },
            preConfirm: () => {
                const m01Input  = document.getElementById('swal-m01-input');
                const mau02Input = document.getElementById('swal-file-input');

                // Mẫu 01 bắt buộc: phải có file mới hoặc đã có file cũ
                if (!m01Input.files.length && !dv.mau01_file) {
                    Swal.showValidationMessage('Vui lòng upload file Mẫu 01 (Sơ lược quá trình phấn đấu)');
                    return false;
                }
                return {
                    mau01: m01Input.files.length ? m01Input.files[0] : null,
                    bai_cam_nhan: mau02Input.files.length ? mau02Input.files[0] : null,
                };
            }
        });

        if (formData) {
            Swal.fire({ title: 'Đang nộp hồ sơ...', allowOutsideClick: false, didOpen: () => Swal.showLoading() });

            const res = await ajaxRequest('dvut_nop_ho_so', { id, ...formData });
            if (res.success) {
                await loadData();
                Swal.fire({ icon: 'success', title: 'Thành công!', text: res.data.message, timer: 2500, showConfirmButton: false });
            } else {
                Swal.fire({ icon: 'error', title: 'Lỗi', text: res.data?.message || 'Có lỗi xảy ra.' });
            }
        }
    }

    /**
     * Mở modal Chi Đoàn duyệt (nhập kết quả biểu quyết)
     */
    async function openChiDoanDuyetModal(id) {
        const dv = allDoanVien.find(d => String(d.id) === String(id));
        if (!dv) return;

        let chiDoanMemberCount = 0; // sẽ được gán sau khi AJAX trả về trong didOpen

        const { value: formData } = await Swal.fire({
            title: 'Chi Đoàn Biểu quyết',
            html: `
                <p style="text-align:left; margin-bottom:15px; color:#555;">
                    Đoàn viên: <strong style="color:#174f8c;">${esc(dv.ho_ten)}</strong> — ${esc(dv.mssv)}<br>
                    <small>Chi đoàn: ${esc(dv.chi_doan)}</small>
                </p>

                <div class="form-group">
                    <label>Tổng số Đoàn viên tham dự <span style="color:red;">*</span></label>
                    <input type="number" id="swal-tong-so" min="1" placeholder="Nhập tổng số người" class="swal2-input" style="margin:0;" value="${dv.tong_so_nguoi || ''}">
                    <div id="swal-hop-den-info" style="font-size:12px; color:#888; margin-top:6px; min-height:18px;">⏳ Đang tải dữ liệu Hộp đen...</div>
                </div>
                <div class="form-group">
                    <label>Số lượt đồng ý <span style="color:red;">*</span></label>
                    <input type="number" id="swal-dong-y" min="0" placeholder="Nhập số lượt biểu quyết đồng ý" class="swal2-input" style="margin:0;" value="${dv.so_luot_dong_y || ''}">
                </div>
                <div class="vote-result-display" id="swal-vote-result" style="display:none;">
                    <div class="vote-ratio" id="swal-vote-ratio">0%</div>
                    <div class="vote-label" id="swal-vote-label">Tỷ lệ biểu quyết</div>
                </div>

                <div class="form-group" style="margin-top:16px;">
                    <label>Biên bản Hội nghị Chi Đoàn — Mẫu 03 <span style="color:red;">*</span></label>
                    <small>Upload file PDF hoặc DOCX (tối đa 5MB).</small>
                    <div class="swal-file-upload-area" id="swal-m03-area" onclick="document.getElementById('swal-m03-input').click();">
                        <span class="dashicons dashicons-media-document"></span>
                        <p>Nhấp hoặc kéo thả file Biên bản vào đây</p>
                        <div class="file-name" id="swal-m03-name"></div>
                    </div>
                    <input type="file" id="swal-m03-input" accept=".pdf,.doc,.docx" style="display:none;">
                </div>
            `,
            showCancelButton: true,
            confirmButtonText: 'Xác nhận kết quả',
            cancelButtonText: 'Hủy',
            width: 500,
            didOpen: () => {
                const tongSoEl = document.getElementById('swal-tong-so');
                const dongYEl = document.getElementById('swal-dong-y');
                const resultEl = document.getElementById('swal-vote-result');
                const ratioEl = document.getElementById('swal-vote-ratio');
                const labelEl = document.getElementById('swal-vote-label');

                function calcVote() {
                    const tong = parseInt(tongSoEl.value) || 0;
                    const dong = parseInt(dongYEl.value) || 0;
                    if (tong > 0 && dong >= 0) {
                        const ratio = ((dong / tong) * 100).toFixed(2);
                        ratioEl.textContent = `${ratio}%`;
                        resultEl.style.display = 'block';
                        resultEl.classList.remove('vote-pass', 'vote-fail');
                        if (parseFloat(ratio) > 50) {
                            resultEl.classList.add('vote-pass');
                            labelEl.textContent = '✓ Đạt tỷ lệ > 50% — Đủ điều kiện';
                        } else {
                            resultEl.classList.add('vote-fail');
                            labelEl.textContent = '✗ Không đạt tỷ lệ > 50%';
                        }
                    } else {
                        resultEl.style.display = 'none';
                    }
                }

                tongSoEl.addEventListener('input', calcVote);
                dongYEl.addEventListener('input', calcVote);
                calcVote();

                // Tự động lấy số lượng đoàn viên từ Hộp đen
                if (dv.chi_doan_id) {
                    ajaxRequest('dvut_get_chi_doan_member_count', { chi_doan_id: dv.chi_doan_id })
                        .then(cntRes => {
                            const infoEl = document.getElementById('swal-hop-den-info');
                            if (!infoEl) return;
                            if (cntRes?.success && cntRes.data?.total_members != null) {
                                const cnt = cntRes.data.total_members;
                                chiDoanMemberCount = cnt;
                                const minRequiredDisplay = Math.floor(cnt * 2 / 3);
                                infoEl.textContent = `Số lượng đoàn viên: ${cnt} Đoàn viên (tối thiểu tham dự: ${minRequiredDisplay})`;
                                if (!tongSoEl.value) {
                                    tongSoEl.value = cnt;
                                    calcVote();
                                }
                            } else {
                                infoEl.textContent = '(Không có dữ liệu từ Hộp đen)';
                            }
                        })
                        .catch(() => {
                            const infoEl = document.getElementById('swal-hop-den-info');
                            if (infoEl) infoEl.textContent = '(Không thể tải dữ liệu Hộp đen)';
                        });
                }

                // ── Drag & Drop + click cho Mẫu 03 ──
                const m03Area  = document.getElementById('swal-m03-area');
                const m03Input = document.getElementById('swal-m03-input');
                const m03Name  = document.getElementById('swal-m03-name');
                ['dragenter', 'dragover'].forEach(evt => {
                    m03Area.addEventListener(evt, (e) => { e.preventDefault(); m03Area.classList.add('dragover'); });
                });
                ['dragleave', 'drop'].forEach(evt => {
                    m03Area.addEventListener(evt, (e) => { e.preventDefault(); m03Area.classList.remove('dragover'); });
                });
                m03Area.addEventListener('drop', (e) => {
                    if (e.dataTransfer.files.length) { m03Input.files = e.dataTransfer.files; m03Name.textContent = e.dataTransfer.files[0].name; }
                });
                m03Input.addEventListener('change', () => {
                    if (m03Input.files.length) m03Name.textContent = m03Input.files[0].name;
                });
            },
            preConfirm: () => {
                const tong_so_nguoi = parseInt(document.getElementById('swal-tong-so').value);
                const so_luot_dong_y = parseInt(document.getElementById('swal-dong-y').value);

                if (!tong_so_nguoi || tong_so_nguoi < 1) {
                    Swal.showValidationMessage('Vui lòng nhập tổng số đoàn viên tham dự');
                    return false;
                }
                if (chiDoanMemberCount > 0) {
                    const minRequired = Math.floor(chiDoanMemberCount * 2 / 3);
                    if (tong_so_nguoi < minRequired) {
                        Swal.showValidationMessage(`Số đoàn viên tham dự phải đạt tối thiểu 2/3 tổng số đoàn viên chi đoàn (tối thiểu ${minRequired}/${chiDoanMemberCount})`);
                        return false;
                    }
                }
                if (isNaN(so_luot_dong_y) || so_luot_dong_y < 0) {
                    Swal.showValidationMessage('Vui lòng nhập số lượt đồng ý hợp lệ');
                    return false;
                }
                if (so_luot_dong_y > tong_so_nguoi) {
                    Swal.showValidationMessage('Số lượt đồng ý không thể lớn hơn tổng số người');
                    return false;
                }

                // Validate file Mẫu 03
                const m03Input = document.getElementById('swal-m03-input');
                if (!m03Input.files.length) {
                    Swal.showValidationMessage('Vui lòng upload Biên bản Hội nghị Chi Đoàn (Mẫu 03)');
                    return false;
                }
                return { tong_so_nguoi, so_luot_dong_y, bien_ban_chi_doan: m03Input.files[0] };
            }
        });

        if (formData) {
            const ratio = ((formData.so_luot_dong_y / formData.tong_so_nguoi) * 100).toFixed(2);
            const isPass = parseFloat(ratio) > 50;

            // Xác nhận lần nữa
            const confirm = await Swal.fire({
                icon: isPass ? 'question' : 'warning',
                title: isPass ? 'Xác nhận chuyển lên Đoàn Khoa?' : 'Không đạt tỷ lệ!',
                html: `Kết quả biểu quyết: <strong>${ratio}%</strong> (${formData.so_luot_dong_y}/${formData.tong_so_nguoi})<br><br>
                    ${isPass ? 'Hồ sơ sẽ được chuyển lên <strong>Đoàn Khoa</strong> để xét duyệt.' : 'Hồ sơ sẽ bị <strong style="color:red;">từ chối</strong>.'}`,
                showCancelButton: true,
                confirmButtonText: isPass ? 'Chuyển lên Đoàn Khoa' : 'Xác nhận từ chối',
                cancelButtonText: 'Quay lại',
                confirmButtonColor: isPass ? '#174f8c' : '#dc3545',
            });

            if (confirm.isConfirmed) {
                Swal.fire({ title: 'Đang xử lý...', allowOutsideClick: false, didOpen: () => Swal.showLoading() });

                const res = await ajaxRequest('dvut_chi_doan_duyet', { id, ...formData });
                if (res.success) {
                    await loadData();
                    Swal.fire({
                        icon: isPass ? 'success' : 'info',
                        title: isPass ? 'Đã chuyển lên Đoàn Khoa!' : 'Hồ sơ bị từ chối',
                        text: res.data.message,
                        timer: 2500,
                        showConfirmButton: false
                    });
                } else {
                    Swal.fire({ icon: 'error', title: 'Lỗi', text: res.data?.message || 'Có lỗi xảy ra.' });
                }
            }
        }
    }

    /**
     * ================================================================
     * Đoàn Khoa duyệt — Công nhận ĐVƯT (GĐ2 — Nâng cấp)
     * ================================================================
     * Form SweetAlert2 yêu cầu nhập:
     *   - Tổng số Ủy viên BCH có mặt
     *   - Số phiếu đồng ý
     *   - Upload: Công văn đề nghị (Mẫu 04) + Biên bản họp BCH (Mẫu 05)
     * Logic: Tỷ lệ > 50% → DA_CONG_NHAN, ngược lại → TU_CHOI.
     */
    async function doanKhoaPheduyet(id) {
        // ── Kiểm tra quyền ──
        if (currentRole !== 'can_bo_doan_khoa') {
            Swal.fire({ icon: 'warning', title: 'Không có quyền', text: 'Chỉ Cán bộ Đoàn Khoa mới được thực hiện thao tác này.' });
            return;
        }

        const dv = allDoanVien.find(d => String(d.id) === String(id));
        if (!dv) return;

        const { value: formData } = await Swal.fire({
            title: 'Duyệt Công nhận ĐVƯT',
            html: `
                <p style="text-align:left; margin-bottom:15px; color:#555;">
                    Đoàn viên: <strong style="color:#174f8c;">${esc(dv.ho_ten)}</strong> — ${esc(dv.mssv)}<br>
                    <small>Chi đoàn: ${esc(dv.chi_doan)} | BQ Chi Đoàn: <strong>${dv.ty_le}%</strong></small>
                </p>

                <div class="form-group">
                    <label>Tổng số Ủy viên BCH có mặt <span style="color:red;">*</span></label>
                    <input type="number" id="swal-dk-tong-uv" min="1" placeholder="Nhập tổng số UV BCH có mặt" class="swal2-input" style="margin:0;">
                </div>
                <div class="form-group">
                    <label>Số phiếu đồng ý <span style="color:red;">*</span></label>
                    <input type="number" id="swal-dk-phieu-dy" min="0" placeholder="Nhập số phiếu đồng ý" class="swal2-input" style="margin:0;">
                </div>
                <div class="vote-result-display" id="swal-dk-vote-result" style="display:none;">
                    <div class="vote-ratio" id="swal-dk-vote-ratio">0%</div>
                    <div class="vote-label" id="swal-dk-vote-label">Tỷ lệ biểu quyết BCH</div>
                </div>

                <div class="form-group" style="margin-top:16px;">
                    <label>Công văn đề nghị — Mẫu 04 <span style="color:red;">*</span></label>
                    <small>Upload file PDF hoặc DOCX (tối đa 5MB).</small>
                    <div class="swal-file-upload-area" id="swal-m04-area" onclick="document.getElementById('swal-m04-input').click();">
                        <span class="dashicons dashicons-media-document"></span>
                        <p>Nhấp hoặc kéo thả file vào đây</p>
                        <div class="file-name" id="swal-m04-name"></div>
                    </div>
                    <input type="file" id="swal-m04-input" accept=".pdf,.doc,.docx" style="display:none;">
                </div>

                <div class="form-group" style="margin-top:12px;">
                    <label>Biên bản họp BCH Đoàn Khoa — Mẫu 05 <span style="color:red;">*</span></label>
                    <small>Upload file PDF hoặc DOCX (tối đa 5MB).</small>
                    <div class="swal-file-upload-area" id="swal-m05-area" onclick="document.getElementById('swal-m05-input').click();">
                        <span class="dashicons dashicons-media-document"></span>
                        <p>Nhấp hoặc kéo thả file vào đây</p>
                        <div class="file-name" id="swal-m05-name"></div>
                    </div>
                    <input type="file" id="swal-m05-input" accept=".pdf,.doc,.docx" style="display:none;">
                </div>
            `,
            showCancelButton: true,
            confirmButtonText: 'Xác nhận kết quả',
            cancelButtonText: 'Hủy',
            width: 600,
            didOpen: () => {
                const tongUvEl  = document.getElementById('swal-dk-tong-uv');
                const phieuDyEl = document.getElementById('swal-dk-phieu-dy');
                const resultEl  = document.getElementById('swal-dk-vote-result');
                const ratioEl   = document.getElementById('swal-dk-vote-ratio');
                const labelEl   = document.getElementById('swal-dk-vote-label');

                function calcVote() {
                    const tong = parseInt(tongUvEl.value) || 0;
                    const dong = parseInt(phieuDyEl.value) || 0;
                    if (tong > 0 && dong >= 0) {
                        const ratio = ((dong / tong) * 100).toFixed(2);
                        ratioEl.textContent = `${ratio}%`;
                        resultEl.style.display = 'block';
                        resultEl.classList.remove('vote-pass', 'vote-fail');
                        if (parseFloat(ratio) > 50) {
                            resultEl.classList.add('vote-pass');
                            labelEl.textContent = '✓ Đạt tỷ lệ > 50% — Đủ điều kiện công nhận';
                        } else {
                            resultEl.classList.add('vote-fail');
                            labelEl.textContent = '✗ Không đạt tỷ lệ > 50% — Hồ sơ sẽ bị từ chối';
                        }
                    } else {
                        resultEl.style.display = 'none';
                    }
                }
                tongUvEl.addEventListener('input', calcVote);
                phieuDyEl.addEventListener('input', calcVote);

                // ── Drag & Drop cho Mẫu 04 ──
                const m04Area  = document.getElementById('swal-m04-area');
                const m04Input = document.getElementById('swal-m04-input');
                const m04Name  = document.getElementById('swal-m04-name');
                ['dragenter', 'dragover'].forEach(evt => {
                    m04Area.addEventListener(evt, (e) => { e.preventDefault(); m04Area.classList.add('dragover'); });
                });
                ['dragleave', 'drop'].forEach(evt => {
                    m04Area.addEventListener(evt, (e) => { e.preventDefault(); m04Area.classList.remove('dragover'); });
                });
                m04Area.addEventListener('drop', (e) => {
                    if (e.dataTransfer.files.length) { m04Input.files = e.dataTransfer.files; m04Name.textContent = e.dataTransfer.files[0].name; }
                });
                m04Input.addEventListener('change', () => {
                    if (m04Input.files.length) m04Name.textContent = m04Input.files[0].name;
                });

                // ── Drag & Drop cho Mẫu 05 ──
                const m05Area  = document.getElementById('swal-m05-area');
                const m05Input = document.getElementById('swal-m05-input');
                const m05Name  = document.getElementById('swal-m05-name');
                ['dragenter', 'dragover'].forEach(evt => {
                    m05Area.addEventListener(evt, (e) => { e.preventDefault(); m05Area.classList.add('dragover'); });
                });
                ['dragleave', 'drop'].forEach(evt => {
                    m05Area.addEventListener(evt, (e) => { e.preventDefault(); m05Area.classList.remove('dragover'); });
                });
                m05Area.addEventListener('drop', (e) => {
                    if (e.dataTransfer.files.length) { m05Input.files = e.dataTransfer.files; m05Name.textContent = e.dataTransfer.files[0].name; }
                });
                m05Input.addEventListener('change', () => {
                    if (m05Input.files.length) m05Name.textContent = m05Input.files[0].name;
                });
            },
            preConfirm: () => {
                const tong_so_uy_vien = parseInt(document.getElementById('swal-dk-tong-uv').value);
                const so_phieu_dong_y = parseInt(document.getElementById('swal-dk-phieu-dy').value);

                if (!tong_so_uy_vien || tong_so_uy_vien < 1) {
                    Swal.showValidationMessage('Vui lòng nhập tổng số Ủy viên BCH có mặt');
                    return false;
                }
                if (isNaN(so_phieu_dong_y) || so_phieu_dong_y < 0) {
                    Swal.showValidationMessage('Vui lòng nhập số phiếu đồng ý hợp lệ');
                    return false;
                }
                if (so_phieu_dong_y > tong_so_uy_vien) {
                    Swal.showValidationMessage('Số phiếu đồng ý không thể lớn hơn tổng số Ủy viên');
                    return false;
                }
                const m04File = document.getElementById('swal-m04-input');
                const cong_van_dk = m04File.files.length ? m04File.files[0] : null;
                if (!cong_van_dk) {
                    Swal.showValidationMessage('Vui lòng upload Công văn đề nghị (Mẫu 04)');
                    return false;
                }
                const m05File = document.getElementById('swal-m05-input');
                const bien_ban_dk = m05File.files.length ? m05File.files[0] : null;
                if (!bien_ban_dk) {
                    Swal.showValidationMessage('Vui lòng upload Biên bản họp BCH Đoàn Khoa (Mẫu 05)');
                    return false;
                }
                return { tong_so_uy_vien, so_phieu_dong_y, cong_van_dk, bien_ban_dk };
            }
        });

        if (formData) {
            const ratio = ((formData.so_phieu_dong_y / formData.tong_so_uy_vien) * 100).toFixed(2);
            const isPass = parseFloat(ratio) > 50;

            // Xác nhận lần nữa
            const confirm = await Swal.fire({
                icon: isPass ? 'question' : 'warning',
                title: isPass ? 'Chuyển hồ sơ lên Đoàn Trường?' : 'Không đạt tỷ lệ!',
                html: `Kết quả biểu quyết BCH: <strong>${ratio}%</strong> (${formData.so_phieu_dong_y}/${formData.tong_so_uy_vien})<br><br>
                    ${isPass ? 'Hồ sơ sẽ được <strong style="color:#198754;">chuyển lên Đoàn Trường</strong> để ban hành QĐ.' : 'Hồ sơ sẽ bị <strong style="color:red;">từ chối</strong>.'}`,
                showCancelButton: true,
                confirmButtonText: isPass ? 'Chuyển lên Đoàn Trường' : 'Xác nhận từ chối',
                cancelButtonText: 'Quay lại',
                confirmButtonColor: isPass ? '#174f8c' : '#dc3545',
            });

            if (confirm.isConfirmed) {
                Swal.fire({ title: 'Đang xử lý...', allowOutsideClick: false, didOpen: () => Swal.showLoading() });

                const res = await ajaxRequest('dvut_doan_khoa_duyet', { id, approved: true, ...formData });
                if (res.success) {
                    await loadData();
                    Swal.fire({
                        icon: isPass ? 'success' : 'info',
                        title: isPass ? 'Đã chuyển lên Đoàn Trường!' : 'Hồ sơ bị từ chối',
                        text: res.data.message,
                        timer: 2500,
                        showConfirmButton: false
                    });
                } else {
                    Swal.fire({ icon: 'error', title: 'Lỗi', text: res.data?.message || 'Có lỗi xảy ra.' });
                }
            }
        }
    }

    /**
     * Đoàn Khoa từ chối
     */
    async function doanKhoaTuChoi(id) {
        const dv = allDoanVien.find(d => String(d.id) === String(id));
        if (!dv) return;

        const { value: lyDo } = await Swal.fire({
            icon: 'warning',
            title: 'Từ chối hồ sơ?',
            html: `Từ chối hồ sơ của <strong>${esc(dv.ho_ten)}</strong> (${esc(dv.mssv)})?`,
            input: 'textarea',
            inputLabel: 'Lý do từ chối',
            inputPlaceholder: 'Nhập lý do từ chối...',
            inputAttributes: { style: 'font-family: Montserrat, sans-serif;' },
            showCancelButton: true,
            confirmButtonText: 'Từ chối',
            confirmButtonColor: '#dc3545',
            cancelButtonText: 'Hủy',
            inputValidator: (value) => {
                if (!value || !value.trim()) return 'Vui lòng nhập lý do từ chối';
            }
        });

        if (lyDo) {
            Swal.fire({ title: 'Đang xử lý...', allowOutsideClick: false, didOpen: () => Swal.showLoading() });

            const res = await ajaxRequest('dvut_doan_khoa_duyet', { id, approved: false, ly_do: lyDo });
            if (res.success) {
                await loadData();
                Swal.fire({ icon: 'info', title: 'Đã từ chối', text: res.data.message, timer: 2500, showConfirmButton: false });
            } else {
                Swal.fire({ icon: 'error', title: 'Lỗi', text: res.data?.message || 'Có lỗi xảy ra.' });
            }
        }
    }

    /**
     * ================================================================
     * Từ chối hồ sơ — Dùng chung cho tất cả các cấp
     * ================================================================
     * @param {number} id   - ID đoàn viên
     * @param {string} cap  - 'chi_doan' | 'doan_khoa' | 'doan_truong'
     *
     * Hiện modal nhập lý do → cập nhật trạng thái TU_CHOI + lưu ly_do_tu_choi.
     */
    async function tuChoiHoSo(id, cap) {
        const dv = allDoanVien.find(d => String(d.id) === String(id));
        if (!dv) return;

        const capLabel = {
            chi_doan: 'Chi Đoàn',
            doan_khoa: 'Đoàn Khoa',
            doan_truong: 'Đoàn Trường'
        }[cap] || cap;

        const { value: lyDo } = await Swal.fire({
            title: 'Từ chối hồ sơ',
            html: `
                <div style="text-align:left; font-family:'Montserrat',sans-serif; font-size:14px;">
                    <div style="background:#f8f9fa; border-radius:8px; padding:12px 14px; margin-bottom:16px;">
                        <div style="display:flex; gap:8px; margin-bottom:8px;">
                            <span style="color:#666; min-width:90px; flex-shrink:0;">Đoàn viên</span>
                            <span><strong style="color:#174f8c;">${esc(dv.ho_ten)}</strong> <span style="color:#888;">— ${esc(dv.mssv)}</span></span>
                        </div>
                        <div style="display:flex; gap:8px; margin-bottom:8px;">
                            <span style="color:#666; min-width:90px; flex-shrink:0;">Chi đoàn</span>
                            <span>${esc(dv.chi_doan)}</span>
                        </div>
                        <div style="display:flex; gap:8px;">
                            <span style="color:#666; min-width:90px; flex-shrink:0;">Cấp từ chối</span>
                            <span style="color:#dc3545; font-weight:600;">${capLabel}</span>
                        </div>
                    </div>

                    <label for="swal-ly-do-tu-choi" style="font-weight:600; color:#333; margin-bottom:8px; display:block; font-size:14px;">Lý do từ chối <span style="color:#dc3545;">*</span></label>
                    <textarea id="swal-ly-do-tu-choi" placeholder="Nhập lý do từ chối hồ sơ..." maxlength="500"
                        style="font-family:'Montserrat',sans-serif; font-size:14px; width:100%; min-height:100px; padding:10px 12px; border:1px solid #d1d5db; border-radius:8px; resize:vertical; box-sizing:border-box; outline:none; transition:border-color .2s;"
                        onfocus="this.style.borderColor='#174f8c'" onblur="this.style.borderColor='#d1d5db'"></textarea>
                </div>
            `,
            showCancelButton: true,
            confirmButtonText: 'Xác nhận từ chối',
            confirmButtonColor: '#dc3545',
            cancelButtonText: 'Hủy',
            width: 480,
            focusConfirm: false,
            preConfirm: () => {
                const val = document.getElementById('swal-ly-do-tu-choi').value;
                if (!val || !val.trim()) { Swal.showValidationMessage('Vui lòng nhập lý do từ chối'); return false; }
                if (val.trim().length < 5) { Swal.showValidationMessage('Lý do từ chối phải có ít nhất 5 ký tự'); return false; }
                return val.trim();
            }
        });

        if (lyDo) {
            // Xác nhận lần nữa
            const confirm = await Swal.fire({
                icon: 'warning',
                title: 'Xác nhận từ chối?',
                html: `<p>Bạn chắc chắn muốn từ chối hồ sơ của <strong>${esc(dv.ho_ten)}</strong>?</p>
                    <div style="margin-top:10px; padding:10px 12px; background:#fff3cd; border-radius:8px; border:1px solid #ffecb5; text-align:left;">
                        <strong style="color:#856404; font-size:0.9em;">Lý do:</strong>
                        <p style="margin:4px 0 0; color:#664d03; font-size:0.9em;">${esc(lyDo)}</p>
                    </div>`,
                showCancelButton: true,
                confirmButtonText: 'Từ chối',
                confirmButtonColor: '#dc3545',
                cancelButtonText: 'Quay lại',
            });

            if (confirm.isConfirmed) {
                Swal.fire({ title: 'Đang xử lý...', allowOutsideClick: false, didOpen: () => Swal.showLoading() });

                const res = await ajaxRequest('dvut_tu_choi', { id, cap, ly_do: lyDo });
                if (res.success) {
                    await loadData();
                    Swal.fire({ icon: 'info', title: 'Đã từ chối hồ sơ', text: res.data.message, timer: 2500, showConfirmButton: false });
                } else {
                    Swal.fire({ icon: 'error', title: 'Lỗi', text: res.data?.message || 'Có lỗi xảy ra.' });
                }
            }
        }
    }

    /**
     * ================================================================
     * Cập nhật tiến độ Cảm tình Đảng — Chi Đoàn
     * ================================================================
     * Cho phép Chi Đoàn cập nhật trạng thái:
     *   CHUA_THAM_GIA → DANG_HOC → DA_HOAN_THANH
     * Nếu chọn DA_HOAN_THANH → bắt buộc upload Giấy chứng nhận.
     */
    async function updateCamTinhDang(id) {
        // ── Kiểm tra quyền: Chi bộ hoặc Admin ──
        if (currentRole !== 'chi_bo_sinh_vien' && currentRole !== 'admin_doan_truong') {
            Swal.fire({ icon: 'warning', title: 'Không có quyền', text: 'Chỉ Chi bộ hoặc Admin mới được cập nhật tiến độ Cảm tình Đảng.' });
            return;
        }

        const dv = allDoanVien.find(d => String(d.id) === String(id));
        if (!dv) return;

        const currentCTD = dv.tien_do_cam_tinh_dang || 'CHUA_THAM_GIA';

        const { value: formData } = await Swal.fire({
            title: 'Cập nhật Cảm tình Đảng',
            html: `
                <p style="text-align:left; margin-bottom:15px; color:#555;">
                    Đoàn viên: <strong style="color:#174f8c;">${esc(dv.ho_ten)}</strong> — ${esc(dv.mssv)}<br>
                    <small>Chi đoàn: ${esc(dv.chi_doan)}</small>
                </p>

                <div class="form-group">
                    <label style="font-weight:600; font-size:14px; text-align:left; display:block;">Tiến độ lớp Cảm tình Đảng <span style="color:red;">*</span></label>
                    <select id="swal-ctd-status" class="swal2-select" style="width:100%; padding:10px; border:1px solid #ddd; border-radius:8px; font-family:'Montserrat',sans-serif; margin-top:8px;">
                        <option value="CHUA_THAM_GIA" ${currentCTD === 'CHUA_THAM_GIA' ? 'selected' : ''}>🔘 Chưa tham gia</option>
                        <option value="DANG_HOC" ${currentCTD === 'DANG_HOC' ? 'selected' : ''}>📖 Đang học</option>
                        <option value="DA_HOAN_THANH" ${currentCTD === 'DA_HOAN_THANH' ? 'selected' : ''}>✅ Đã hoàn thành</option>
                    </select>
                </div>

                <div id="swal-ctd-details-group" style="display:${currentCTD === 'DA_HOAN_THANH' ? 'block' : 'none'}; margin-top:12px;">
                    <div class="form-group" style="margin-bottom:12px;">
                        <label style="font-weight:600; font-size:14px; text-align:left; display:block;">Số chứng nhận cảm tình Đảng <span style="color:red;">*</span></label>
                        <input type="text" id="swal-ctd-number" class="swal2-input" style="width:100%; margin:8px 0 0 0; padding:10px; box-sizing:border-box; border:1px solid #ddd; border-radius:8px;" value="${dv.so_chung_nhan_ctd || ''}" placeholder="Ví dụ: 123/CN-ĐU">
                    </div>

                    <div class="form-group" style="margin-bottom:12px;">
                        <label style="font-weight:600; font-size:14px; text-align:left; display:block;">Ngày cấp chứng nhận <span style="color:red;">*</span></label>
                        <input type="date" id="swal-ctd-date" class="swal2-input" style="width:100%; margin:8px 0 0 0; padding:10px; box-sizing:border-box; border:1px solid #ddd; border-radius:8px;" value="${dv.ngay_chung_nhan_ctd || ''}">
                    </div>

                    <div class="form-group" id="swal-ctd-file-group" style="margin-top:12px;">
                        <label style="font-weight:600; font-size:14px; text-align:left; display:block;">File đính kèm (Ảnh/PDF) <span style="color:red;">*</span></label>
                        <small style="color:#666; display:block; margin-bottom:6px;">Upload file ảnh hoặc PDF Giấy chứng nhận hoàn thành lớp bồi dưỡng (tối đa 5MB).</small>
                        <div class="swal-file-upload-area" id="swal-ctd-file-area" onclick="document.getElementById('swal-ctd-file-input').click();">
                            <span class="dashicons dashicons-awards"></span>
                            <p>Nhấp hoặc kéo thả file Giấy chứng nhận</p>
                            <div class="file-name" id="swal-ctd-file-name">${dv.file_chung_nhan_ctd ? esc(dv.file_chung_nhan_ctd.split('/').pop()) : ''}</div>
                        </div>
                        <input type="file" id="swal-ctd-file-input" accept=".jpg,.jpeg,.png,.pdf" style="display:none;">
                    </div>
                </div>
            `,
            showCancelButton: true,
            confirmButtonText: 'Cập nhật',
            cancelButtonText: 'Hủy',
            width: 500,
            didOpen: () => {
                const selectEl   = document.getElementById('swal-ctd-status');
                const detailsGroup = document.getElementById('swal-ctd-details-group');
                const fileArea   = document.getElementById('swal-ctd-file-area');
                const fileInput  = document.getElementById('swal-ctd-file-input');
                const fileName   = document.getElementById('swal-ctd-file-name');

                // Hiển thị/ẩn các trường chi tiết khi thay đổi trạng thái
                selectEl.addEventListener('change', () => {
                    detailsGroup.style.display = selectEl.value === 'DA_HOAN_THANH' ? 'block' : 'none';
                });

                // Drag & Drop
                ['dragenter', 'dragover'].forEach(evt => {
                    fileArea.addEventListener(evt, (e) => { e.preventDefault(); fileArea.classList.add('dragover'); });
                });
                ['dragleave', 'drop'].forEach(evt => {
                    fileArea.addEventListener(evt, (e) => { e.preventDefault(); fileArea.classList.remove('dragover'); });
                });
                fileArea.addEventListener('drop', (e) => {
                    if (e.dataTransfer.files.length) { fileInput.files = e.dataTransfer.files; fileName.textContent = e.dataTransfer.files[0].name; }
                });
                fileInput.addEventListener('change', () => {
                    if (fileInput.files.length) fileName.textContent = fileInput.files[0].name;
                });
            },
            preConfirm: () => {
                const tien_do = document.getElementById('swal-ctd-status').value;
                if (tien_do === 'DA_HOAN_THANH') {
                    const so_chung_nhan_ctd = document.getElementById('swal-ctd-number').value.trim();
                    const ngay_chung_nhan_ctd = document.getElementById('swal-ctd-date').value;
                    const fileInput = document.getElementById('swal-ctd-file-input');

                    if (!so_chung_nhan_ctd) {
                        Swal.showValidationMessage('Vui lòng nhập Số chứng nhận Cảm tình Đảng');
                        return false;
                    }
                    if (!ngay_chung_nhan_ctd) {
                        Swal.showValidationMessage('Vui lòng chọn Ngày cấp chứng nhận');
                        return false;
                    }
                    if (!fileInput.files.length && !dv.file_chung_nhan_ctd) {
                        Swal.showValidationMessage('Vui lòng upload Giấy chứng nhận hoàn thành Cảm tình Đảng');
                        return false;
                    }

                    const data = {
                        tien_do,
                        so_chung_nhan_ctd,
                        ngay_chung_nhan_ctd
                    };
                    if (fileInput.files.length) {
                        data.file_chung_nhan_ctd = fileInput.files[0];
                    }
                    return data;
                }
                return { tien_do };
            }
        });

        if (formData) {
            Swal.fire({ title: 'Đang cập nhật...', allowOutsideClick: false, didOpen: () => Swal.showLoading() });

            const res = await ajaxRequest('dvut_update_cam_tinh_dang', { id, ...formData });
            if (res.success) {
                await loadData();
                Swal.fire({ icon: 'success', title: 'Thành công!', text: res.data.message, timer: 2000, showConfirmButton: false });
            } else {
                Swal.fire({ icon: 'error', title: 'Lỗi', text: res.data?.message || 'Có lỗi xảy ra.' });
            }
        }
    }

    /**
     * ================================================================
     * Chi bộ cập nhật Kết nạp Đảng → Đảng viên dự bị
     * ================================================================
     */
    async function cbCapNhatKetNap(id) {
        if (currentRole !== 'chi_bo_sinh_vien' && currentRole !== 'admin_doan_truong') {
            Swal.fire({ icon: 'warning', title: 'Không có quyền', text: 'Chỉ Chi bộ hoặc Admin mới được cập nhật thông tin Kết nạp Đảng.' });
            return;
        }

        const dv = allDoanVien.find(d => String(d.id) === String(id));
        if (!dv) return;

        const { value: formData } = await Swal.fire({
            title: 'Cập nhật Kết nạp Đảng',
            html: `
                <p style="text-align:left; margin-bottom:15px; color:#555;">
                    Đoàn viên: <strong style="color:#174f8c;">${esc(dv.ho_ten)}</strong> — ${esc(dv.mssv)}<br>
                    <small>Chi đoàn: ${esc(dv.chi_doan)}</small>
                </p>

                <div class="form-group" style="margin-bottom:12px;">
                    <label style="font-weight:600; font-size:14px; text-align:left; display:block;">Số Quyết định kết nạp <span style="color:red;">*</span></label>
                    <input type="text" id="swal-qd-number" class="swal2-input" style="width:100%; margin:8px 0 0 0; padding:10px; box-sizing:border-box; border:1px solid #ddd; border-radius:8px;" value="${dv.so_qd_ket_nap || ''}" placeholder="Ví dụ: 456-QĐ/ĐU">
                </div>

                <div class="form-group" style="margin-bottom:12px;">
                    <label style="font-weight:600; font-size:14px; text-align:left; display:block;">Ngày Quyết định <span style="color:red;">*</span></label>
                    <input type="date" id="swal-qd-date" class="swal2-input" style="width:100%; margin:8px 0 0 0; padding:10px; box-sizing:border-box; border:1px solid #ddd; border-radius:8px;" value="${dv.ngay_qd_ket_nap || ''}">
                </div>

                <div class="form-group" style="margin-bottom:12px;">
                    <label style="font-weight:600; font-size:14px; text-align:left; display:block;">Ngày tổ chức Lễ kết nạp <span style="color:red;">*</span></label>
                    <input type="date" id="swal-le-date" class="swal2-input" style="width:100%; margin:8px 0 0 0; padding:10px; box-sizing:border-box; border:1px solid #ddd; border-radius:8px;" value="${dv.ngay_le_ket_nap || ''}">
                </div>

                <div class="form-group" style="margin-top:12px;">
                    <label style="font-weight:600; font-size:14px; text-align:left; display:block;">File scan Quyết định kết nạp <span style="color:red;">*</span></label>
                    <small style="color:#666; display:block; margin-bottom:6px;">Upload file ảnh hoặc PDF Quyết định (tối đa 5MB).</small>
                    <div class="swal-file-upload-area" id="swal-qd-file-area" onclick="document.getElementById('swal-qd-file-input').click();">
                        <span class="dashicons dashicons-flag"></span>
                        <p>Nhấp hoặc kéo thả file scan Quyết định</p>
                        <div class="file-name" id="swal-qd-file-name">${dv.file_qd_ket_nap ? esc(dv.file_qd_ket_nap.split('/').pop()) : ''}</div>
                    </div>
                    <input type="file" id="swal-qd-file-input" accept=".jpg,.jpeg,.png,.pdf" style="display:none;">
                </div>
            `,
            showCancelButton: true,
            confirmButtonText: 'Xác nhận kết nạp',
            cancelButtonText: 'Hủy',
            width: 500,
            didOpen: () => {
                const fileArea   = document.getElementById('swal-qd-file-area');
                const fileInput  = document.getElementById('swal-qd-file-input');
                const fileName   = document.getElementById('swal-qd-file-name');

                // Drag & Drop
                ['dragenter', 'dragover'].forEach(evt => {
                    fileArea.addEventListener(evt, (e) => { e.preventDefault(); fileArea.classList.add('dragover'); });
                });
                ['dragleave', 'drop'].forEach(evt => {
                    fileArea.addEventListener(evt, (e) => { e.preventDefault(); fileArea.classList.remove('dragover'); });
                });
                fileArea.addEventListener('drop', (e) => {
                    if (e.dataTransfer.files.length) { fileInput.files = e.dataTransfer.files; fileName.textContent = e.dataTransfer.files[0].name; }
                });
                fileInput.addEventListener('change', () => {
                    if (fileInput.files.length) fileName.textContent = fileInput.files[0].name;
                });
            },
            preConfirm: () => {
                const so_qd_ket_nap = document.getElementById('swal-qd-number').value.trim();
                const ngay_qd_ket_nap = document.getElementById('swal-qd-date').value;
                const ngay_le_ket_nap = document.getElementById('swal-le-date').value;
                const fileInput = document.getElementById('swal-qd-file-input');

                if (!so_qd_ket_nap) {
                    Swal.showValidationMessage('Vui lòng nhập Số Quyết định kết nạp');
                    return false;
                }
                if (!ngay_qd_ket_nap) {
                    Swal.showValidationMessage('Vui lòng chọn Ngày Quyết định');
                    return false;
                }
                if (!ngay_le_ket_nap) {
                    Swal.showValidationMessage('Vui lòng chọn Ngày tổ chức Lễ kết nạp');
                    return false;
                }
                if (!fileInput.files.length && !dv.file_qd_ket_nap) {
                    Swal.showValidationMessage('Vui lòng upload file scan Quyết định kết nạp');
                    return false;
                }

                const data = {
                    so_qd_ket_nap,
                    ngay_qd_ket_nap,
                    ngay_le_ket_nap
                };
                if (fileInput.files.length) {
                    data.file_qd_ket_nap = fileInput.files[0];
                }
                return data;
            }
        });

        if (formData) {
            Swal.fire({ title: 'Đang cập nhật kết nạp...', allowOutsideClick: false, didOpen: () => Swal.showLoading() });

            const res = await ajaxRequest('dvut_cb_cap_nhat_ket_nap', { id, ...formData });
            if (res.success) {
                await loadData();
                Swal.fire({ icon: 'success', title: 'Thành công!', text: res.data.message, timer: 2000, showConfirmButton: false });
            } else {
                Swal.fire({ icon: 'error', title: 'Lỗi', text: res.data?.message || 'Có lỗi xảy ra.' });
            }
        }
    }

    /**
     * ================================================================
     * Đoàn Khoa duyệt Giới thiệu vào Đảng (GĐ3)
     * ================================================================
     * Xử lý hồ sơ ở trạng thái CHO_DK_GIOI_THIEU.
     * Form: Nhập tỷ lệ BQ BCH ĐK, tóm tắt ưu/khuyết điểm,
     *       upload Nghị quyết giới thiệu (Mẫu 07).
     * Logic: > 50% → DANG_VIEN_DU_BI.
     */
    async function doanKhoaDuyetGioiThieu(id) {
        // ── Kiểm tra quyền ──
        if (currentRole !== 'can_bo_doan_khoa') {
            Swal.fire({ icon: 'warning', title: 'Không có quyền', text: 'Chỉ Cán bộ Đoàn Khoa mới được thực hiện thao tác này.' });
            return;
        }

        const dv = allDoanVien.find(d => String(d.id) === String(id));
        if (!dv) return;

        // ── Kiểm tra trạng thái ──
        if (dv.trang_thai !== 'CHO_DK_GIOI_THIEU') {
            Swal.fire({ icon: 'warning', title: 'Không hợp lệ', text: 'Hồ sơ không ở trạng thái chờ Đoàn Khoa duyệt giới thiệu Đảng.' });
            return;
        }

        const { value: formData } = await Swal.fire({
            title: 'Duyệt Giới thiệu vào Đảng',
            html: `
                <p style="text-align:left; margin-bottom:15px; color:#555;">
                    Đoàn viên: <strong style="color:#174f8c;">${esc(dv.ho_ten)}</strong> — ${esc(dv.mssv)}<br>
                    <small>Chi đoàn: ${esc(dv.chi_doan)} | BQ Chi Đoàn GT Đảng: <strong>${dv.ty_le}%</strong></small>
                </p>

                <div class="form-group">
                    <label>Tổng số Ủy viên BCH Đoàn Khoa có mặt <span style="color:red;">*</span></label>
                    <input type="number" id="swal-dkgt-tong-uv" min="1" placeholder="Nhập tổng số UV BCH có mặt" class="swal2-input" style="margin:0;">
                </div>
                <div class="form-group">
                    <label>Số phiếu đồng ý <span style="color:red;">*</span></label>
                    <input type="number" id="swal-dkgt-phieu-dy" min="0" placeholder="Nhập số phiếu đồng ý" class="swal2-input" style="margin:0;">
                </div>
                <div class="vote-result-display" id="swal-dkgt-vote-result" style="display:none;">
                    <div class="vote-ratio" id="swal-dkgt-vote-ratio">0%</div>
                    <div class="vote-label" id="swal-dkgt-vote-label">Tỷ lệ biểu quyết BCH</div>
                </div>

                <div class="form-group" style="margin-top:16px;">
                    <label>Tóm tắt ưu điểm / khuyết điểm</label>
                    <textarea id="swal-dkgt-nhan-xet" rows="4" placeholder="Nhập tóm tắt ưu điểm, khuyết điểm của Đoàn viên..." style="width:100%; padding:10px; border:1px solid #ddd; border-radius:8px; font-family:'Montserrat',sans-serif; margin-top:8px; resize:vertical;"></textarea>
                </div>

                <div class="form-group" style="margin-top:12px;">
                    <label>Nghị quyết giới thiệu ĐVƯT vào Đảng — Mẫu 07 <span style="color:red;">*</span></label>
                    <small>Upload file PDF hoặc DOCX (tối đa 5MB).</small>
                    <div class="swal-file-upload-area" id="swal-m07-area" onclick="document.getElementById('swal-m07-input').click();">
                        <span class="dashicons dashicons-media-document"></span>
                        <p>Nhấp hoặc kéo thả file Nghị quyết vào đây</p>
                        <div class="file-name" id="swal-m07-name"></div>
                    </div>
                    <input type="file" id="swal-m07-input" accept=".pdf,.doc,.docx" style="display:none;">
                </div>
            `,
            showCancelButton: true,
            confirmButtonText: 'Xác nhận kết quả',
            cancelButtonText: 'Hủy',
            width: 600,
            didOpen: () => {
                const tongUvEl  = document.getElementById('swal-dkgt-tong-uv');
                const phieuDyEl = document.getElementById('swal-dkgt-phieu-dy');
                const resultEl  = document.getElementById('swal-dkgt-vote-result');
                const ratioEl   = document.getElementById('swal-dkgt-vote-ratio');
                const labelEl   = document.getElementById('swal-dkgt-vote-label');

                function calcVote() {
                    const tong = parseInt(tongUvEl.value) || 0;
                    const dong = parseInt(phieuDyEl.value) || 0;
                    if (tong > 0 && dong >= 0) {
                        const ratio = ((dong / tong) * 100).toFixed(2);
                        ratioEl.textContent = `${ratio}%`;
                        resultEl.style.display = 'block';
                        resultEl.classList.remove('vote-pass', 'vote-fail');
                        if (parseFloat(ratio) > 50) {
                            resultEl.classList.add('vote-pass');
                            labelEl.textContent = '✓ Đạt tỷ lệ > 50% — Đủ điều kiện giới thiệu vào Đảng';
                        } else {
                            resultEl.classList.add('vote-fail');
                            labelEl.textContent = '✗ Không đạt tỷ lệ > 50%';
                        }
                    } else {
                        resultEl.style.display = 'none';
                    }
                }
                tongUvEl.addEventListener('input', calcVote);
                phieuDyEl.addEventListener('input', calcVote);

                // ── Drag & Drop cho Mẫu 07 ──
                const m07Area  = document.getElementById('swal-m07-area');
                const m07Input = document.getElementById('swal-m07-input');
                const m07Name  = document.getElementById('swal-m07-name');
                ['dragenter', 'dragover'].forEach(evt => {
                    m07Area.addEventListener(evt, (e) => { e.preventDefault(); m07Area.classList.add('dragover'); });
                });
                ['dragleave', 'drop'].forEach(evt => {
                    m07Area.addEventListener(evt, (e) => { e.preventDefault(); m07Area.classList.remove('dragover'); });
                });
                m07Area.addEventListener('drop', (e) => {
                    if (e.dataTransfer.files.length) { m07Input.files = e.dataTransfer.files; m07Name.textContent = e.dataTransfer.files[0].name; }
                });
                m07Input.addEventListener('change', () => {
                    if (m07Input.files.length) m07Name.textContent = m07Input.files[0].name;
                });
            },
            preConfirm: () => {
                const tong_so_uy_vien = parseInt(document.getElementById('swal-dkgt-tong-uv').value);
                const so_phieu_dong_y = parseInt(document.getElementById('swal-dkgt-phieu-dy').value);
                const nhan_xet       = document.getElementById('swal-dkgt-nhan-xet').value.trim();

                if (!tong_so_uy_vien || tong_so_uy_vien < 1) {
                    Swal.showValidationMessage('Vui lòng nhập tổng số Ủy viên BCH có mặt');
                    return false;
                }
                if (isNaN(so_phieu_dong_y) || so_phieu_dong_y < 0) {
                    Swal.showValidationMessage('Vui lòng nhập số phiếu đồng ý hợp lệ');
                    return false;
                }
                if (so_phieu_dong_y > tong_so_uy_vien) {
                    Swal.showValidationMessage('Số phiếu đồng ý không thể lớn hơn tổng số Ủy viên');
                    return false;
                }
                const m07File = document.getElementById('swal-m07-input');
                const nghi_quyet_m07 = m07File.files.length ? m07File.files[0].name : '';
                if (!nghi_quyet_m07) {
                    Swal.showValidationMessage('Vui lòng upload Nghị quyết giới thiệu ĐVƯT vào Đảng (Mẫu 07)');
                    return false;
                }
                return { tong_so_uy_vien, so_phieu_dong_y, nhan_xet, nghi_quyet_m07 };
            }
        });

        if (formData) {
            const ratio = ((formData.so_phieu_dong_y / formData.tong_so_uy_vien) * 100).toFixed(2);
            const isPass = parseFloat(ratio) > 50;

            const confirm = await Swal.fire({
                icon: isPass ? 'question' : 'warning',
                title: isPass ? 'Chuyển hồ sơ lên Đoàn Trường xác nhận GT?' : 'Không đạt tỷ lệ!',
                html: `Kết quả biểu quyết BCH Đoàn Khoa: <strong>${ratio}%</strong> (${formData.so_phieu_dong_y}/${formData.tong_so_uy_vien})<br><br>
                    ${isPass ? 'Hồ sơ sẽ được <strong style="color:#198754;">chuyển lên Đoàn Trường</strong> xác nhận giới thiệu Đảng.' : 'Hồ sơ sẽ bị <strong style="color:red;">từ chối</strong>.'}`,
                showCancelButton: true,
                confirmButtonText: isPass ? 'Chuyển lên Đoàn Trường' : 'Xác nhận từ chối',
                cancelButtonText: 'Quay lại',
                confirmButtonColor: isPass ? '#6f42c1' : '#dc3545',
            });

            if (confirm.isConfirmed) {
                Swal.fire({ title: 'Đang xử lý...', allowOutsideClick: false, didOpen: () => Swal.showLoading() });

                const res = await ajaxRequest('dvut_dk_duyet_gioi_thieu', { id, approved: true, ...formData });
                if (res.success) {
                    await loadData();
                    Swal.fire({
                        icon: isPass ? 'success' : 'info',
                        title: isPass ? 'Đã chuyển lên Đoàn Trường xác nhận GT Đảng!' : 'Hồ sơ bị từ chối',
                        text: res.data.message,
                        timer: 2500,
                        showConfirmButton: false
                    });
                } else {
                    Swal.fire({ icon: 'error', title: 'Lỗi', text: res.data?.message || 'Có lỗi xảy ra.' });
                }
            }
        }
    }

    /**
     * ================================================================
     * Đoàn Khoa duyệt Chuyển Đảng chính thức (GĐ5)
     * ================================================================
     * Xử lý hồ sơ ở trạng thái CHO_DK_CHUYEN_DANG.
     * Form: Nhập tỷ lệ BQ BCH ĐK, upload Ý kiến nhận xét (Mẫu 09).
     * Logic: > 50% → DANG_VIEN_CHINH_THUC.
     */
    async function doanKhoaDuyetChuyenDang(id) {
        // ── Kiểm tra quyền ──
        if (currentRole !== 'can_bo_doan_khoa') {
            Swal.fire({ icon: 'warning', title: 'Không có quyền', text: 'Chỉ Cán bộ Đoàn Khoa mới được thực hiện thao tác này.' });
            return;
        }

        const dv = allDoanVien.find(d => String(d.id) === String(id));
        if (!dv) return;

        // ── Kiểm tra trạng thái ──
        if (dv.trang_thai !== 'CHO_DK_CHUYEN_DANG') {
            Swal.fire({ icon: 'warning', title: 'Không hợp lệ', text: 'Hồ sơ không ở trạng thái chờ Đoàn Khoa duyệt chuyển Đảng.' });
            return;
        }

        const { value: formData } = await Swal.fire({
            title: 'Duyệt Chuyển Đảng chính thức',
            html: `
                <p style="text-align:left; margin-bottom:15px; color:#555;">
                    Đảng viên: <strong style="color:#174f8c;">${esc(dv.ho_ten)}</strong> — ${esc(dv.mssv)}<br>
                    <small>Chi đoàn: ${esc(dv.chi_doan)} | BQ Chi Đoàn chuyển Đảng: <strong>${dv.ty_le}%</strong></small>
                </p>

                <div class="form-group">
                    <label>Tổng số Ủy viên BCH Đoàn Khoa có mặt <span style="color:red;">*</span></label>
                    <input type="number" id="swal-dkcd-tong-uv" min="1" placeholder="Nhập tổng số UV BCH có mặt" class="swal2-input" style="margin:0;">
                </div>
                <div class="form-group">
                    <label>Số phiếu đồng ý <span style="color:red;">*</span></label>
                    <input type="number" id="swal-dkcd-phieu-dy" min="0" placeholder="Nhập số phiếu đồng ý" class="swal2-input" style="margin:0;">
                </div>
                <div class="vote-result-display" id="swal-dkcd-vote-result" style="display:none;">
                    <div class="vote-ratio" id="swal-dkcd-vote-ratio">0%</div>
                    <div class="vote-label" id="swal-dkcd-vote-label">Tỷ lệ biểu quyết BCH</div>
                </div>

                <div class="form-group" style="margin-top:16px;">
                    <label>Ý kiến nhận xét — Mẫu 09 <span style="color:red;">*</span></label>
                    <small>Upload file PDF hoặc DOCX (tối đa 5MB).</small>
                    <div class="swal-file-upload-area" id="swal-m09-area" onclick="document.getElementById('swal-m09-input').click();">
                        <span class="dashicons dashicons-media-document"></span>
                        <p>Nhấp hoặc kéo thả file Ý kiến nhận xét vào đây</p>
                        <div class="file-name" id="swal-m09-name"></div>
                    </div>
                    <input type="file" id="swal-m09-input" accept=".pdf,.doc,.docx" style="display:none;">
                </div>
            `,
            showCancelButton: true,
            confirmButtonText: 'Xác nhận kết quả',
            cancelButtonText: 'Hủy',
            width: 600,
            didOpen: () => {
                const tongUvEl  = document.getElementById('swal-dkcd-tong-uv');
                const phieuDyEl = document.getElementById('swal-dkcd-phieu-dy');
                const resultEl  = document.getElementById('swal-dkcd-vote-result');
                const ratioEl   = document.getElementById('swal-dkcd-vote-ratio');
                const labelEl   = document.getElementById('swal-dkcd-vote-label');

                function calcVote() {
                    const tong = parseInt(tongUvEl.value) || 0;
                    const dong = parseInt(phieuDyEl.value) || 0;
                    if (tong > 0 && dong >= 0) {
                        const ratio = ((dong / tong) * 100).toFixed(2);
                        ratioEl.textContent = `${ratio}%`;
                        resultEl.style.display = 'block';
                        resultEl.classList.remove('vote-pass', 'vote-fail');
                        if (parseFloat(ratio) > 50) {
                            resultEl.classList.add('vote-pass');
                            labelEl.textContent = '✓ Đạt tỷ lệ > 50% — Đủ điều kiện chuyển Đảng chính thức';
                        } else {
                            resultEl.classList.add('vote-fail');
                            labelEl.textContent = '✗ Không đạt tỷ lệ > 50%';
                        }
                    } else {
                        resultEl.style.display = 'none';
                    }
                }
                tongUvEl.addEventListener('input', calcVote);
                phieuDyEl.addEventListener('input', calcVote);

                // ── Drag & Drop cho Mẫu 09 ──
                const m09Area  = document.getElementById('swal-m09-area');
                const m09Input = document.getElementById('swal-m09-input');
                const m09Name  = document.getElementById('swal-m09-name');
                ['dragenter', 'dragover'].forEach(evt => {
                    m09Area.addEventListener(evt, (e) => { e.preventDefault(); m09Area.classList.add('dragover'); });
                });
                ['dragleave', 'drop'].forEach(evt => {
                    m09Area.addEventListener(evt, (e) => { e.preventDefault(); m09Area.classList.remove('dragover'); });
                });
                m09Area.addEventListener('drop', (e) => {
                    if (e.dataTransfer.files.length) { m09Input.files = e.dataTransfer.files; m09Name.textContent = e.dataTransfer.files[0].name; }
                });
                m09Input.addEventListener('change', () => {
                    if (m09Input.files.length) m09Name.textContent = m09Input.files[0].name;
                });
            },
            preConfirm: () => {
                const tong_so_uy_vien = parseInt(document.getElementById('swal-dkcd-tong-uv').value);
                const so_phieu_dong_y = parseInt(document.getElementById('swal-dkcd-phieu-dy').value);

                if (!tong_so_uy_vien || tong_so_uy_vien < 1) {
                    Swal.showValidationMessage('Vui lòng nhập tổng số Ủy viên BCH có mặt');
                    return false;
                }
                if (isNaN(so_phieu_dong_y) || so_phieu_dong_y < 0) {
                    Swal.showValidationMessage('Vui lòng nhập số phiếu đồng ý hợp lệ');
                    return false;
                }
                if (so_phieu_dong_y > tong_so_uy_vien) {
                    Swal.showValidationMessage('Số phiếu đồng ý không thể lớn hơn tổng số Ủy viên');
                    return false;
                }
                const m09File = document.getElementById('swal-m09-input');
                const y_kien_m09 = m09File.files.length ? m09File.files[0].name : '';
                if (!y_kien_m09) {
                    Swal.showValidationMessage('Vui lòng upload Ý kiến nhận xét (Mẫu 09)');
                    return false;
                }
                return { tong_so_uy_vien, so_phieu_dong_y, y_kien_m09 };
            }
        });

        if (formData) {
            const ratio = ((formData.so_phieu_dong_y / formData.tong_so_uy_vien) * 100).toFixed(2);
            const isPass = parseFloat(ratio) > 50;

            const confirm = await Swal.fire({
                icon: isPass ? 'question' : 'warning',
                title: isPass ? 'Chuyển hồ sơ lên Đoàn Trường xác nhận CĐ?' : 'Không đạt tỷ lệ!',
                html: `Kết quả biểu quyết BCH Đoàn Khoa: <strong>${ratio}%</strong> (${formData.so_phieu_dong_y}/${formData.tong_so_uy_vien})<br><br>
                    ${isPass ? 'Hồ sơ sẽ được <strong style="color:#198754;">chuyển lên Đoàn Trường</strong> xác nhận chuyển Đảng.' : 'Hồ sơ sẽ bị <strong style="color:red;">từ chối</strong>.'}`,
                showCancelButton: true,
                confirmButtonText: isPass ? 'Chuyển lên Đoàn Trường' : 'Xác nhận từ chối',
                cancelButtonText: 'Quay lại',
                confirmButtonColor: isPass ? '#dc3545' : '#6c757d',
            });

            if (confirm.isConfirmed) {
                Swal.fire({ title: 'Đang xử lý...', allowOutsideClick: false, didOpen: () => Swal.showLoading() });

                const res = await ajaxRequest('dvut_dk_duyet_chuyen_dang', { id, approved: true, ...formData });
                if (res.success) {
                    await loadData();
                    Swal.fire({
                        icon: isPass ? 'success' : 'info',
                        title: isPass ? 'Đã chuyển lên Đoàn Trường xác nhận CĐ!' : 'Hồ sơ bị từ chối',
                        text: res.data.message,
                        timer: 2500,
                        showConfirmButton: false
                    });
                } else {
                    Swal.fire({ icon: 'error', title: 'Lỗi', text: res.data?.message || 'Có lỗi xảy ra.' });
                }
            }
        }
    }

    /**
     * [CHỈ ADMIN] Gỡ trạng thái từ chối — khôi phục hồ sơ về trạng thái trước khi bị từ chối.
     */
    async function goTuChoi(id) {
        const isAdmin = isDevAdmin;
        if (currentRole !== 'admin_doan_truong' || !isAdmin) {
            Swal.fire({ icon: 'warning', title: 'Không có quyền', text: 'Chỉ Quản trị viên (Admin) mới được gỡ trạng thái từ chối.' });
            return;
        }

        const dv = allDoanVien.find(d => String(d.id) === String(id));
        if (!dv || dv.trang_thai !== 'TU_CHOI') {
            Swal.fire({ icon: 'warning', title: 'Không hợp lệ', text: 'Chỉ áp dụng cho hồ sơ đang ở trạng thái Từ chối.' });
            return;
        }

        const trangThaiPhucHoi = dv.trang_thai_truoc_tu_choi || 'CHO_CHI_DOAN';
        const confirm = await Swal.fire({
            icon: 'question',
            title: 'Gỡ trạng thái từ chối',
            html: `Xác nhận khôi phục hồ sơ <strong>${esc(dv.ho_ten)}</strong> (${esc(dv.mssv)}) về trạng thái <strong>${trangThaiPhucHoi}</strong>?`,
            showCancelButton: true,
            confirmButtonText: 'Khôi phục',
            cancelButtonText: 'Hủy',
            confirmButtonColor: '#174f8c',
        });

        if (confirm.isConfirmed) {
            Swal.fire({ title: 'Đang xử lý...', allowOutsideClick: false, didOpen: () => Swal.showLoading() });
            const res = await ajaxRequest('dvut_admin_go_tu_choi', { id });
            if (res && res.success) {
                await loadData();
                Swal.fire({ icon: 'success', title: 'Đã gỡ từ chối', text: res.data?.message || 'Hồ sơ đã được khôi phục.', timer: 2500, showConfirmButton: false });
            } else {
                Swal.fire({ icon: 'error', title: 'Lỗi', text: (res && res.data && res.data.message) || 'Có lỗi xảy ra.' });
            }
        }
    }

    /**
     * BCH Chi Đoàn — Đề cử lại sau khi hồ sơ bị từ chối
     */
    async function deCuLai(id) {
        if (currentRole !== 'bch_chi_doan') return;
        const dv = allDoanVien.find(d => String(d.id) === String(id));
        if (!dv || !['TU_CHOI', 'TRA_VE'].includes(dv.trang_thai)) return;

        const tuChoiLabel = dv.trang_thai === 'TRA_VE' ? 'bị Đoàn Khoa trả về' : 'bị từ chối';
        const capTuChoi   = dv.cap_tu_choi || (dv.trang_thai === 'TRA_VE' ? 'Đoàn Khoa' : '—');
        const ngayTuChoi = dv.ngay_tu_choi ? new Date(dv.ngay_tu_choi).toLocaleDateString('vi-VN') : '—';
        const confirm = await Swal.fire({
            icon: 'question',
            title: 'Đề cử lại hồ sơ?',
            html: `
                <div style="text-align:left; padding:8px;">
                    <p>Hồ sơ của <strong style="color:#174f8c;">${esc(dv.ho_ten)}</strong> (${esc(dv.mssv)}) đã ${tuChoiLabel}:</p>
                    <table style="width:100%; margin-top:10px; border-collapse:collapse; font-size:.9em;">
                        <tr><td style="padding:6px 10px; font-weight:600; color:#555; width:40%; border-bottom:1px solid #eee;">Cấp từ chối</td><td style="padding:6px 10px; border-bottom:1px solid #eee; color:#dc3545;">${esc(capTuChoi)}</td></tr>
                        <tr><td style="padding:6px 10px; font-weight:600; color:#555; border-bottom:1px solid #eee;">Ngày từ chối</td><td style="padding:6px 10px; border-bottom:1px solid #eee;">${ngayTuChoi}</td></tr>
                        <tr><td style="padding:6px 10px; font-weight:600; color:#555;">Lý do</td><td style="padding:6px 10px;">${esc(dv.ly_do_tu_choi || dv.ghi_chu || '—')}</td></tr>
                    </table>
                    <p style="margin-top:12px; color:#555; font-size:.9em;">Tạo hồ sơ đề cử mới cho đoàn viên này?</p>
                </div>
            `,
            showCancelButton: true,
            confirmButtonText: 'Đề cử lại',
            cancelButtonText: 'Hủy',
            confirmButtonColor: '#0d6efd',
            width: 500,
        });

        if (!confirm.isConfirmed) return;

        Swal.fire({ title: 'Đang tạo hồ sơ mới...', allowOutsideClick: false, didOpen: () => Swal.showLoading() });

        const res = await ajaxRequest('dvut_add_de_cu', {
            ho_ten: dv.ho_ten,
            mssv: dv.mssv,
            chi_doan_id: dv.chi_doan_id,
            khoa_id: dv.khoa_id || '',
        });

        if (!res.success) {
            Swal.fire({ icon: 'error', title: 'Lỗi', text: res.data?.message || 'Không thể tạo hồ sơ mới.' });
            return;
        }

        await loadData();
        Swal.fire({ icon: 'success', title: 'Đề cử lại thành công!', text: 'Hồ sơ mới đã được tạo. Vui lòng nhấn nút Nộp hồ sơ để hoàn thiện.', timer: 3000, showConfirmButton: false });
    }

    /**
     * Đoàn viên — Ứng cử lại sau khi hồ sơ bị từ chối
     */
    async function doanVienUngCuLai() {
        if (!_currentUser) return;

        let hd = null;
        try {
            const res = await ajaxRequest('dvut_get_blackbox_profile', { email: _currentUser.email || '', mssv: _currentUser.mssv || '' });
            if (res.success && res.data) hd = res.data;
        } catch (e) {}

        if (!hd) { Swal.fire({ icon: 'error', title: 'Lỗi', text: 'Không tìm thấy dữ liệu sinh viên.' }); return; }

        const mssv = hd.mssv;
        const dv = allDoanVien.find(d => d.mssv === mssv && ['TU_CHOI', 'TRA_VE'].includes(d.trang_thai));
        if (!dv) { Swal.fire({ icon: 'warning', title: 'Không tìm thấy hồ sơ bị từ chối.' }); return; }

        const capTuChoi  = dv.cap_tu_choi || (dv.trang_thai === 'TRA_VE' ? 'Đoàn Khoa' : '—');
        const ngayTuChoi = dv.ngay_tu_choi ? new Date(dv.ngay_tu_choi).toLocaleDateString('vi-VN') : '—';
        const confirm = await Swal.fire({
            icon: 'info',
            title: 'Ứng cử lại?',
            html: `
                <div style="text-align:left; padding:8px;">
                    <p>Hồ sơ của bạn đã bị ${dv.trang_thai === 'TRA_VE' ? 'Đoàn Khoa trả về' : 'từ chối bởi <strong style="color:#dc3545;">' + esc(capTuChoi) + '</strong>'} vào ngày ${ngayTuChoi}.</p>
                    <p style="margin-top:8px;"><strong>Lý do:</strong> ${esc(dv.ly_do_tu_choi || dv.ghi_chu || '—')}</p>
                    <p style="margin-top:12px; color:#555;">Bạn có muốn tạo hồ sơ ứng cử mới không?</p>
                </div>
            `,
            showCancelButton: true,
            confirmButtonText: 'Ứng cử lại',
            cancelButtonText: 'Hủy',
            confirmButtonColor: '#0d6efd',
            width: 480,
        });

        if (!confirm.isConfirmed) return;

        Swal.fire({ title: 'Đang tạo hồ sơ mới...', allowOutsideClick: false, didOpen: () => Swal.showLoading() });

        const res = await ajaxRequest('dvut_add_de_cu', {
            ho_ten: hd.ho_ten,
            mssv: mssv,
            chi_doan_id: hd.chi_doan_id,
            khoa_id: hd.khoa_id || '',
        });

        if (!res.success) {
            Swal.fire({ icon: 'error', title: 'Lỗi', text: res.data?.message || 'Không thể tạo hồ sơ mới.' });
            return;
        }

        await loadData();
        Swal.fire({ icon: 'success', title: 'Ứng cử lại thành công!', text: 'Hồ sơ mới đã được tạo. Vui lòng hoàn thiện hồ sơ trong trang Hồ sơ cá nhân.', timer: 3000, showConfirmButton: false });
        // loadData() tự gọi populateProfileForDoanVien() — profile sẽ hiển thị trạng thái CHO_NOP
    }

    /**
     * ================================================================
     * Admin Đoàn Trường — Ban hành Quyết định Công nhận ĐVƯT
     * ================================================================
     * Xử lý hồ sơ ở trạng thái CHO_DOAN_TRUONG.
     * Bắt buộc upload file QĐ → chuyển sang DA_CONG_NHAN.
     */
    async function adminBanHanhQuyetDinh(id) {
        if (currentRole !== 'admin_doan_truong') {
            Swal.fire({ icon: 'warning', title: 'Không có quyền', text: 'Chỉ Admin Đoàn Trường mới được thực hiện thao tác này.' });
            return;
        }

        const dv = allDoanVien.find(d => String(d.id) === String(id));
        if (!dv) return;

        if (dv.trang_thai !== 'CHO_DOAN_TRUONG') {
            Swal.fire({ icon: 'warning', title: 'Không hợp lệ', text: 'Hồ sơ không ở trạng thái chờ Đoàn Trường xử lý.' });
            return;
        }

        const { value: formData } = await Swal.fire({
            title: 'Ban hành Quyết định Công nhận ĐVƯT',
            html: `
                <div style="text-align:left; font-size:.9em;">
                    <p>Xác nhận công nhận <strong style="color:#174f8c;">${esc(dv.ho_ten)}</strong> (${esc(dv.mssv)}) là <strong style="color:#198754;">Đoàn viên Ưu tú</strong>.</p>
                    <table style="width:100%; margin:10px 0 14px; border-collapse:collapse;">
                        <tr><td style="padding:5px 8px; font-weight:600; color:#555; border-bottom:1px solid #eee; width:40%;">Chi đoàn</td><td style="padding:5px 8px; border-bottom:1px solid #eee;">${esc(dv.chi_doan)}</td></tr>
                        <tr><td style="padding:5px 8px; font-weight:600; color:#555;">BQ Đoàn Khoa</td><td style="padding:5px 8px;"><strong>${dv.ty_le_dk || dv.ty_le || 0}%</strong></td></tr>
                    </table>

                    <div style="margin-bottom:12px;">
                        <label style="font-weight:600; color:#555; display:block; margin-bottom:4px;">Số Quyết định <span style="color:#888; font-weight:400;">(tuỳ chọn)</span></label>
                        <input id="swal-qd-so" type="text" placeholder="VD: 01/QĐ-ĐTr" style="width:100%; padding:8px 10px; border:1px solid #dee2e6; border-radius:6px; font-size:.9em; box-sizing:border-box;">
                    </div>

                    <div>
                        <label style="font-weight:600; color:#174f8c; display:block; margin-bottom:6px;">📎 File Quyết định công nhận <span style="color:#dc3545;">*</span></label>
                        <div id="swal-qd-area" style="border:2px dashed #174f8c; border-radius:8px; padding:18px; text-align:center; cursor:pointer; background:#f0f6ff; transition:background .2s;">
                            <div style="font-size:1.6em; margin-bottom:4px;">📄</div>
                            <div style="color:#174f8c; font-weight:600; font-size:.88em;">Kéo thả hoặc nhấn để chọn file QĐ</div>
                            <div id="swal-qd-name" style="margin-top:6px; color:#198754; font-size:.85em; font-weight:600;"></div>
                            <input id="swal-qd-input" type="file" accept=".pdf,.doc,.docx" style="display:none;">
                        </div>
                    </div>
                </div>
            `,
            width: 520,
            showCancelButton: true,
            confirmButtonText: 'Ban hành QĐ',
            cancelButtonText: 'Hủy',
            confirmButtonColor: '#174f8c',
            didOpen: () => {
                const area  = document.getElementById('swal-qd-area');
                const input = document.getElementById('swal-qd-input');
                const name  = document.getElementById('swal-qd-name');

                area.addEventListener('click', () => input.click());
                ['dragover','dragenter'].forEach(evt => {
                    area.addEventListener(evt, (e) => { e.preventDefault(); area.classList.add('dragover'); area.style.background = '#dbeafe'; });
                });
                ['dragleave','dragend'].forEach(evt => {
                    area.addEventListener(evt, (e) => { e.preventDefault(); area.classList.remove('dragover'); area.style.background = '#f0f6ff'; });
                });
                area.addEventListener('drop', (e) => {
                    e.preventDefault();
                    area.style.background = '#f0f6ff';
                    if (e.dataTransfer.files.length) { input.files = e.dataTransfer.files; name.textContent = e.dataTransfer.files[0].name; }
                });
                input.addEventListener('change', () => {
                    if (input.files.length) name.textContent = input.files[0].name;
                });
            },
            preConfirm: () => {
                const qdInput = document.getElementById('swal-qd-input');
                const file_qd_cong_nhan = qdInput.files.length ? qdInput.files[0] : null;
                if (!file_qd_cong_nhan) {
                    Swal.showValidationMessage('Vui lòng đính kèm file Quyết định công nhận');
                    return false;
                }
                const so_quyet_dinh = document.getElementById('swal-qd-so').value.trim();
                return { file_qd_cong_nhan, so_quyet_dinh };
            }
        });

        if (formData) {
            Swal.fire({ title: 'Đang xử lý...', allowOutsideClick: false, didOpen: () => Swal.showLoading() });

            const res = await ajaxRequest('dvut_admin_ban_hanh', {
                id,
                so_quyet_dinh: formData.so_quyet_dinh,
                file_qd_cong_nhan: formData.file_qd_cong_nhan,
            });
            if (res.success) {
                await loadData();
                Swal.fire({ icon: 'success', title: 'Đã ban hành Quyết định!', text: res.data.message, timer: 2500, showConfirmButton: false });
            } else {
                Swal.fire({ icon: 'error', title: 'Lỗi', text: res.data?.message || 'Có lỗi xảy ra.' });
            }
        }
    }




    /**
     * Xem chi tiết hồ sơ — Mở rộng với Cảm tình Đảng
     */
    function xemChiTiet(id) {
        const dv = allDoanVien.find(d => String(d.id) === String(id));
        if (!dv) return;

        const statusInfo = getStatusBadge(dv.trang_thai);
        const ctdInfo    = getCamTinhDangBadge(dv.tien_do_cam_tinh_dang);
        const showPartyFields = (currentRole === 'chi_bo_sinh_vien' || currentRole === 'admin_doan_truong' || isDevAdmin);

        Swal.fire({
            title: 'Hồ sơ Đoàn viên',
            html: `
                <div style="text-align:left;">
                    <table style="width:100%; border-collapse:collapse;">
                        <tr><td style="padding:8px 12px; font-weight:600; color:#555; width:40%; border-bottom:1px solid #eee;">Họ và Tên</td><td style="padding:8px 12px; border-bottom:1px solid #eee;"><strong>${esc(dv.ho_ten)}</strong></td></tr>
                        <tr><td style="padding:8px 12px; font-weight:600; color:#555; border-bottom:1px solid #eee;">MSSV</td><td style="padding:8px 12px; border-bottom:1px solid #eee;">${esc(dv.mssv)}</td></tr>
                        <tr><td style="padding:8px 12px; font-weight:600; color:#555; border-bottom:1px solid #eee;">Chi đoàn</td><td style="padding:8px 12px; border-bottom:1px solid #eee;">${esc(dv.chi_doan)}</td></tr>
                        <tr><td style="padding:8px 12px; font-weight:600; color:#555; border-bottom:1px solid #eee;">Ngày đề cử</td><td style="padding:8px 12px; border-bottom:1px solid #eee;">${formatDate(dv.ngay_de_cu)}</td></tr>
                        <tr>
                            <td style="padding:8px 12px; font-weight:600; color:#555; border-bottom:1px solid #eee;">Trạng thái</td>
                            <td style="padding:8px 12px; border-bottom:1px solid #eee; display:flex; align-items:center; gap:8px; flex-wrap:wrap;">
                                <span class="status-badge ${statusInfo.cls}">${statusInfo.label}</span>
                                ${(currentRole === 'chi_bo_sinh_vien' || currentRole === 'admin_doan_truong') ? `
                                    <button onclick="Swal.close(); setTimeout(() => DvutApp.cbCapNhatKetNap(${dv.id}), 200);" class="btn-sm" style="background:#28a745; color:#fff; border:none; border-radius:4px; padding:3px 8px; cursor:pointer; font-size:0.8em; font-weight:600; display:inline-flex; align-items:center; gap:4px; transition: background 0.2s;" onmouseover="this.style.background='#1e7e34'" onmouseout="this.style.background='#28a745'">
                                        <span class="dashicons dashicons-flag" style="font-size:14px; width:14px; height:14px; vertical-align:middle; margin-top:-2px;"></span> Kết nạp Đảng
                                    </button>
                                ` : ''}
                            </td>
                        </tr>
                        <tr><td style="padding:8px 12px; font-weight:600; color:#555; border-bottom:1px solid #eee;">Kết quả BQ</td><td style="padding:8px 12px; border-bottom:1px solid #eee;">${dv.ty_le > 0 ? `<strong style="color:${dv.ty_le > 50 ? '#198754' : '#dc3545'};">${dv.ty_le}%</strong> (${dv.so_luot_dong_y}/${dv.tong_so_nguoi})` : '<em style="color:#888;">Chưa biểu quyết</em>'}</td></tr>
                        ${showPartyFields ? `
                        <tr>
                            <td style="padding:8px 12px; font-weight:600; color:#555; border-bottom:1px solid #eee;">Lớp Cảm tình Đảng</td>
                            <td style="padding:8px 12px; border-bottom:1px solid #eee; display:flex; align-items:center; gap:8px; flex-wrap:wrap;">
                                <span style="display:inline-block; padding:4px 10px; border-radius:1rem; font-size:0.85em; font-weight:600; color:${ctdInfo.color}; background:${ctdInfo.bg};">${ctdInfo.label}</span>
                                ${(currentRole === 'chi_bo_sinh_vien' || currentRole === 'admin_doan_truong') ? `
                                    <button onclick="Swal.close(); setTimeout(() => DvutApp.updateCamTinhDang(${dv.id}), 200);" class="btn-sm" style="background:#007bff; color:#fff; border:none; border-radius:4px; padding:3px 8px; cursor:pointer; font-size:0.8em; font-weight:600; display:inline-flex; align-items:center; gap:4px; transition: background 0.2s;" onmouseover="this.style.background='#0056b3'" onmouseout="this.style.background='#007bff'">
                                        <span class="dashicons dashicons-edit" style="font-size:14px; width:14px; height:14px; vertical-align:middle; margin-top:-2px;"></span> Cập nhật CTĐ
                                    </button>
                                ` : ''}
                            </td>
                        </tr>
                        ${dv.so_chung_nhan_ctd ? `<tr><td style="padding:8px 12px; font-weight:600; color:#555; border-bottom:1px solid #eee;">Số CN Cảm tình Đảng</td><td style="padding:8px 12px; border-bottom:1px solid #eee;">${esc(dv.so_chung_nhan_ctd)}</td></tr>` : ''}
                        ${dv.ngay_chung_nhan_ctd && dv.ngay_chung_nhan_ctd !== '0000-00-00' ? `<tr><td style="padding:8px 12px; font-weight:600; color:#555; border-bottom:1px solid #eee;">Ngày cấp CN</td><td style="padding:8px 12px; border-bottom:1px solid #eee;">${formatDate(dv.ngay_chung_nhan_ctd)}</td></tr>` : ''}
                        ${dv.so_qd_ket_nap ? `<tr><td style="padding:8px 12px; font-weight:600; color:#555; border-bottom:1px solid #eee;">Số QĐ Kết nạp</td><td style="padding:8px 12px; border-bottom:1px solid #eee;">${esc(dv.so_qd_ket_nap)}</td></tr>` : ''}
                        ${dv.ngay_qd_ket_nap && dv.ngay_qd_ket_nap !== '0000-00-00' ? `<tr><td style="padding:8px 12px; font-weight:600; color:#555; border-bottom:1px solid #eee;">Ngày ký QĐ</td><td style="padding:8px 12px; border-bottom:1px solid #eee;">${formatDate(dv.ngay_qd_ket_nap)}</td></tr>` : ''}
                        ${dv.ngay_le_ket_nap && dv.ngay_le_ket_nap !== '0000-00-00' ? `<tr><td style="padding:8px 12px; font-weight:600; color:#555; border-bottom:1px solid #eee;">Ngày Lễ kết nạp</td><td style="padding:8px 12px; border-bottom:1px solid #eee;">${formatDate(dv.ngay_le_ket_nap)}</td></tr>` : ''}
                        ` : ''}
                    </table>

                    ${dv.so_luoc_qua_trinh || dv.mau01_file ? `
                    <div style="margin-top:15px; padding:12px; background:#f8f9fa; border-radius:8px; border:1px solid #e9ecef;">
                        <strong style="color:#174f8c; font-size:0.95em;">
                            <span class="dashicons dashicons-text-page" style="font-size:16px; vertical-align:middle;"></span>
                            Mẫu 01 — Sơ lược quá trình:
                        </strong>
                        ${dv.mau01_file ? `<div style="margin-top:6px;"><a href="${esc(dv.mau01_file)}" target="_blank" rel="noopener" style="color:#174f8c; font-weight:600;">📎 ${esc(dv.mau01_file.split('/').pop())}</a></div>` : ''}
                        ${dv.so_luoc_qua_trinh ? `<p style="margin:8px 0 0; line-height:1.6; color:#555;">${esc(dv.so_luoc_qua_trinh)}</p>` : ''}
                    </div>` : ''}

                    ${dv.bai_cam_nhan_file ? `
                    <div style="margin-top:10px; padding:10px 12px; background:#f0f6ff; border-radius:8px; border:1px solid #cfe2ff;">
                        <span class="dashicons dashicons-media-document" style="color:#174f8c; font-size:16px; vertical-align:middle;"></span>
                        <strong style="font-size:0.9em;">Mẫu 02:</strong>
                        <a href="${esc(dv.bai_cam_nhan_file)}" target="_blank" rel="noopener" style="color:#174f8c; font-weight:600;">${esc(dv.bai_cam_nhan_file.split('/').pop())}</a>
                    </div>` : ''}

                    ${dv.bien_ban_chi_doan_file ? `
                    <div style="margin-top:10px; padding:10px 12px; background:#f0f6ff; border-radius:8px; border:1px solid #cfe2ff;">
                        <span class="dashicons dashicons-media-document" style="color:#174f8c; font-size:16px; vertical-align:middle;"></span>
                        <strong style="font-size:0.9em;">Biên bản Chi Đoàn (M03):</strong>
                        <a href="${esc(dv.bien_ban_chi_doan_file)}" target="_blank" rel="noopener" style="color:#174f8c; font-weight:600;">${esc(dv.bien_ban_chi_doan_file.split('/').pop())}</a>
                    </div>` : ''}

                    ${dv.cong_van_dk_file ? `
                    <div style="margin-top:10px; padding:10px 12px; background:#f0f6ff; border-radius:8px; border:1px solid #cfe2ff;">
                        <span class="dashicons dashicons-media-document" style="color:#174f8c; font-size:16px; vertical-align:middle;"></span>
                        <strong style="font-size:0.9em;">Công văn Đoàn Khoa:</strong>
                        <a href="${esc(dv.cong_van_dk_file)}" target="_blank" rel="noopener" style="color:#174f8c; font-weight:600;">${esc(dv.cong_van_dk_file.split('/').pop())}</a>
                    </div>` : ''}

                    ${dv.bien_ban_dk_file ? `
                    <div style="margin-top:10px; padding:10px 12px; background:#f0f6ff; border-radius:8px; border:1px solid #cfe2ff;">
                        <span class="dashicons dashicons-media-document" style="color:#174f8c; font-size:16px; vertical-align:middle;"></span>
                        <strong style="font-size:0.9em;">Biên bản Đoàn Khoa:</strong>
                        <a href="${esc(dv.bien_ban_dk_file)}" target="_blank" rel="noopener" style="color:#174f8c; font-weight:600;">${esc(dv.bien_ban_dk_file.split('/').pop())}</a>
                    </div>` : ''}

                    ${showPartyFields && dv.bien_ban_gt_file ? `
                    <div style="margin-top:10px; padding:10px 12px; background:#f0f6ff; border-radius:8px; border:1px solid #cfe2ff;">
                        <span class="dashicons dashicons-media-document" style="color:#174f8c; font-size:16px; vertical-align:middle;"></span>
                        <strong style="font-size:0.9em;">Biên bản giới thiệu Đảng:</strong>
                        <a href="${esc(dv.bien_ban_gt_file)}" target="_blank" rel="noopener" style="color:#174f8c; font-weight:600;">${esc(dv.bien_ban_gt_file.split('/').pop())}</a>
                    </div>` : ''}

                    ${showPartyFields && dv.nghi_quyet_dk_file ? `
                    <div style="margin-top:10px; padding:10px 12px; background:#f0f6ff; border-radius:8px; border:1px solid #cfe2ff;">
                        <span class="dashicons dashicons-media-document" style="color:#174f8c; font-size:16px; vertical-align:middle;"></span>
                        <strong style="font-size:0.9em;">Nghị quyết Đoàn Khoa:</strong>
                        <a href="${esc(dv.nghi_quyet_dk_file)}" target="_blank" rel="noopener" style="color:#174f8c; font-weight:600;">${esc(dv.nghi_quyet_dk_file.split('/').pop())}</a>
                    </div>` : ''}

                    ${showPartyFields && dv.bien_ban_cd_ct_file ? `
                    <div style="margin-top:10px; padding:10px 12px; background:#f0f6ff; border-radius:8px; border:1px solid #cfe2ff;">
                        <span class="dashicons dashicons-media-document" style="color:#174f8c; font-size:16px; vertical-align:middle;"></span>
                        <strong style="font-size:0.9em;">Biên bản Chi Đoàn (chuyển Đảng):</strong>
                        <a href="${esc(dv.bien_ban_cd_ct_file)}" target="_blank" rel="noopener" style="color:#174f8c; font-weight:600;">${esc(dv.bien_ban_cd_ct_file.split('/').pop())}</a>
                    </div>` : ''}

                    ${showPartyFields && dv.y_kien_dk_file ? `
                    <div style="margin-top:10px; padding:10px 12px; background:#f0f6ff; border-radius:8px; border:1px solid #cfe2ff;">
                        <span class="dashicons dashicons-media-document" style="color:#174f8c; font-size:16px; vertical-align:middle;"></span>
                        <strong style="font-size:0.9em;">Ý kiến Đoàn Khoa (chuyển Đảng):</strong>
                        <a href="${esc(dv.y_kien_dk_file)}" target="_blank" rel="noopener" style="color:#174f8c; font-weight:600;">${esc(dv.y_kien_dk_file.split('/').pop())}</a>
                    </div>` : ''}

                    ${showPartyFields && dv.nghi_quyet_chi_bo_file ? `
                    <div style="margin-top:10px; padding:10px 12px; background:#f0f6ff; border-radius:8px; border:1px solid #cfe2ff;">
                        <span class="dashicons dashicons-media-document" style="color:#174f8c; font-size:16px; vertical-align:middle;"></span>
                        <strong style="font-size:0.9em;">Nghị quyết Chi bộ:</strong>
                        <a href="${esc(dv.nghi_quyet_chi_bo_file)}" target="_blank" rel="noopener" style="color:#174f8c; font-weight:600;">${esc(dv.nghi_quyet_chi_bo_file.split('/').pop())}</a>
                    </div>` : ''}

                    ${showPartyFields && dv.file_qd_ket_nap ? `
                    <div style="margin-top:10px; padding:10px 12px; background:#f0f6ff; border-radius:8px; border:1px solid #cfe2ff;">
                        <span class="dashicons dashicons-media-document" style="color:#174f8c; font-size:16px; vertical-align:middle;"></span>
                        <strong style="font-size:0.9em;">QĐ kết nạp:</strong>
                        <a href="${esc(dv.file_qd_ket_nap)}" target="_blank" rel="noopener" style="color:#174f8c; font-weight:600;">${esc(dv.file_qd_ket_nap.split('/').pop())}</a>
                    </div>` : ''}

                    ${showPartyFields && dv.file_chung_nhan_ctd ? `
                    <div style="margin-top:10px; padding:10px 12px; background:#d1e7dd; border-radius:8px; border:1px solid #a3cfbb;">
                        <span class="dashicons dashicons-awards" style="color:#198754; font-size:16px; vertical-align:middle;"></span>
                        <strong style="font-size:0.9em; color:#198754;">Chứng nhận CTĐ:</strong>
                        <a href="${esc(dv.file_chung_nhan_ctd)}" target="_blank" rel="noopener" style="color:#0f5132; font-weight:600;">${esc(dv.file_chung_nhan_ctd.split('/').pop())}</a>
                    </div>` : ''}

                    ${dv.file_qd_cong_nhan ? `
                    <div style="margin-top:10px; padding:10px 12px; background:#f0f6ff; border-radius:8px; border:1px solid #cfe2ff;">
                        <span class="dashicons dashicons-media-document" style="color:#174f8c; font-size:16px; vertical-align:middle;"></span>
                        <strong style="font-size:0.9em;">Quyết định công nhận ĐVƯT:</strong>
                        <a href="${esc(dv.file_qd_cong_nhan)}" target="_blank" rel="noopener" style="color:#174f8c; font-weight:600;">${esc(dv.file_qd_cong_nhan.split('/').pop())}</a>
                    </div>` : ''}

                    ${dv.ghi_chu && !['TU_CHOI', 'TRA_VE'].includes(dv.trang_thai) ? `
                    <div style="margin-top:10px; padding:10px 12px; background:#fff3cd; border-radius:8px; border:1px solid #ffecb5;">
                        <span class="dashicons dashicons-info-outline" style="color:#856404; font-size:16px; vertical-align:middle;"></span>
                        <strong style="font-size:0.9em; color:#856404;">Ghi chú:</strong>
                        <span style="color:#664d03;">${esc(dv.ghi_chu)}</span>
                    </div>` : ''}

                    ${['TU_CHOI', 'TRA_VE'].includes(dv.trang_thai) && (dv.ly_do_tu_choi || dv.ghi_chu) ? `
                    <div style="margin-top:10px; padding:12px; background:#f8d7da; border-radius:8px; border:1px solid #f1aeb5;">
                        <div style="display:flex; align-items:center; gap:6px; margin-bottom:6px;">
                            <span class="dashicons dashicons-no-alt" style="color:#dc3545; font-size:18px;"></span>
                            <strong style="font-size:0.95em; color:#842029;">${dv.trang_thai === 'TRA_VE' ? 'Hồ sơ bị Đoàn Khoa trả về' : 'Hồ sơ bị từ chối'}</strong>
                        </div>
                        <table style="width:100%; border-collapse:collapse; font-size:0.9em;">
                            <tr><td style="padding:4px 8px; font-weight:600; color:#842029; width:30%;">Cấp từ chối</td><td style="padding:4px 8px; color:#58151c;">${esc(dv.cap_tu_choi || (dv.trang_thai === 'TRA_VE' ? 'Đoàn Khoa' : ''))}</td></tr>
                            <tr><td style="padding:4px 8px; font-weight:600; color:#842029;">Ngày</td><td style="padding:4px 8px; color:#58151c;">${formatDate(dv.ngay_tu_choi || '')}</td></tr>
                            <tr><td style="padding:4px 8px; font-weight:600; color:#842029; vertical-align:top;">Lý do</td><td style="padding:4px 8px; color:#58151c; line-height:1.5;">${esc(dv.ly_do_tu_choi || dv.ghi_chu)}</td></tr>
                        </table>
                    </div>` : ''}
                </div>
            `,
            width: 600,
            confirmButtonText: 'Đóng',
        });
    }

    /**
     * Xóa hồ sơ
     */
    async function xoaHoSo(id) {
        const dv = allDoanVien.find(d => String(d.id) === String(id));
        if (!dv) return;

        const confirm = await Swal.fire({
            icon: 'warning',
            title: 'Xác nhận xóa?',
            html: `Xóa hồ sơ đề cử của <strong>${esc(dv.ho_ten)}</strong> (${esc(dv.mssv)})?<br><small style="color:#dc3545;">Hành động này không thể hoàn tác.</small>`,
            showCancelButton: true,
            confirmButtonText: 'Xóa',
            confirmButtonColor: '#dc3545',
            cancelButtonText: 'Hủy',
        });

        if (confirm.isConfirmed) {
            Swal.fire({ title: 'Đang xóa...', allowOutsideClick: false, didOpen: () => Swal.showLoading() });

            const res = await ajaxRequest('dvut_delete', { id });
            if (res.success) {
                await loadData();
                Swal.fire({ icon: 'success', title: 'Đã xóa!', text: res.data.message, timer: 2000, showConfirmButton: false });
            } else {
                Swal.fire({ icon: 'error', title: 'Lỗi', text: res.data?.message || 'Có lỗi xảy ra.' });
            }
        }
    }

    /**
     * ================================================================
     * BQ Giới thiệu vào Đảng — GĐ3 (Chi Đoàn)
     * ================================================================
     * Ràng buộc: tien_do_cam_tinh_dang phải là 'DA_HOAN_THANH'.
     * Form: Nhập kết quả BQ + Upload file Biên bản Hội nghị (Mẫu 06).
     * Logic: Nếu > 50% → chuyển trạng thái CHO_DK_GIOI_THIEU.
     */
    async function openGioiThieuDangModal(id) {
        // ── Kiểm tra quyền ──
        if (currentRole !== 'bch_chi_doan') {
            Swal.fire({ icon: 'warning', title: 'Không có quyền', text: 'Chỉ BCH Chi Đoàn mới được thực hiện thao tác này.' });
            return;
        }

        const dv = allDoanVien.find(d => String(d.id) === String(id));
        if (!dv) return;

        // ── Ràng buộc: Phải hoàn thành Lớp Cảm tình Đảng ──
        if (dv.tien_do_cam_tinh_dang !== 'DA_HOAN_THANH') {
            Swal.fire({
                icon: 'error',
                title: 'Chưa đủ điều kiện',
                html: `<p>Đoàn viên <strong style="color:#174f8c;">${esc(dv.ho_ten)}</strong> (${esc(dv.mssv)}) chưa hoàn thành lớp Cảm tình Đảng, không thể giới thiệu vào Đảng.</p>
                    <div style="margin-top:12px; padding:10px; background:#fff3cd; border-radius:8px; font-size:0.9em; color:#856404;">
                        <span class="dashicons dashicons-warning" style="font-size:14px; vertical-align:middle;"></span>
                        Trạng thái CTĐ hiện tại: <strong>${getCamTinhDangBadge(dv.tien_do_cam_tinh_dang).label}</strong>
                    </div>`,
                confirmButtonText: 'Đã hiểu',
            });
            return;
        }

        // ── Form nhập kết quả biểu quyết + upload Mẫu 06 ──
        const { value: formData } = await Swal.fire({
            title: 'BQ Giới thiệu vào Đảng',
            html: `
                <p style="text-align:left; margin-bottom:15px; color:#555;">
                    Đoàn viên: <strong style="color:#174f8c;">${esc(dv.ho_ten)}</strong> — ${esc(dv.mssv)}<br>
                    <small>Chi đoàn: ${esc(dv.chi_doan)}</small><br>
                    <small>CTĐ: <span style="color:#198754; font-weight:600;">Đã hoàn thành ✓</span></small>
                </p>

                <div class="form-group">
                    <label>Tổng số Đoàn viên tham dự <span style="color:red;">*</span></label>
                    <input type="number" id="swal-gt-tong-so" min="1" placeholder="Nhập tổng số người" class="swal2-input" style="margin:0;">
                </div>
                <div class="form-group">
                    <label>Số lượt đồng ý <span style="color:red;">*</span></label>
                    <input type="number" id="swal-gt-dong-y" min="0" placeholder="Nhập số lượt biểu quyết đồng ý" class="swal2-input" style="margin:0;">
                </div>
                <div class="vote-result-display" id="swal-gt-vote-result" style="display:none;">
                    <div class="vote-ratio" id="swal-gt-vote-ratio">0%</div>
                    <div class="vote-label" id="swal-gt-vote-label">Tỷ lệ biểu quyết</div>
                </div>

                <div class="form-group" style="margin-top:16px;">
                    <label>Biên bản Hội nghị Chi Đoàn — Mẫu 06 <span style="color:red;">*</span></label>
                    <small>Upload file PDF hoặc DOCX (tối đa 5MB).</small>
                    <div class="swal-file-upload-area" id="swal-m06-area" onclick="document.getElementById('swal-m06-input').click();">
                        <span class="dashicons dashicons-media-document"></span>
                        <p>Nhấp hoặc kéo thả file Biên bản vào đây</p>
                        <div class="file-name" id="swal-m06-name"></div>
                    </div>
                    <input type="file" id="swal-m06-input" accept=".pdf,.doc,.docx" style="display:none;">
                </div>
            `,
            showCancelButton: true,
            confirmButtonText: 'Xác nhận kết quả',
            cancelButtonText: 'Hủy',
            width: 550,
            didOpen: () => {
                const tongSoEl = document.getElementById('swal-gt-tong-so');
                const dongYEl  = document.getElementById('swal-gt-dong-y');
                const resultEl = document.getElementById('swal-gt-vote-result');
                const ratioEl  = document.getElementById('swal-gt-vote-ratio');
                const labelEl  = document.getElementById('swal-gt-vote-label');

                function calcVote() {
                    const tong = parseInt(tongSoEl.value) || 0;
                    const dong = parseInt(dongYEl.value) || 0;
                    if (tong > 0 && dong >= 0) {
                        const ratio = ((dong / tong) * 100).toFixed(2);
                        ratioEl.textContent = `${ratio}%`;
                        resultEl.style.display = 'block';
                        resultEl.classList.remove('vote-pass', 'vote-fail');
                        if (parseFloat(ratio) > 50) {
                            resultEl.classList.add('vote-pass');
                            labelEl.textContent = '✓ Đạt tỷ lệ > 50% — Đủ điều kiện giới thiệu Đảng';
                        } else {
                            resultEl.classList.add('vote-fail');
                            labelEl.textContent = '✗ Không đạt tỷ lệ > 50%';
                        }
                    } else {
                        resultEl.style.display = 'none';
                    }
                }
                tongSoEl.addEventListener('input', calcVote);
                dongYEl.addEventListener('input', calcVote);

                // Drag & Drop cho Mẫu 06
                const m06Area  = document.getElementById('swal-m06-area');
                const m06Input = document.getElementById('swal-m06-input');
                const m06Name  = document.getElementById('swal-m06-name');
                ['dragenter', 'dragover'].forEach(evt => {
                    m06Area.addEventListener(evt, (e) => { e.preventDefault(); m06Area.classList.add('dragover'); });
                });
                ['dragleave', 'drop'].forEach(evt => {
                    m06Area.addEventListener(evt, (e) => { e.preventDefault(); m06Area.classList.remove('dragover'); });
                });
                m06Area.addEventListener('drop', (e) => {
                    if (e.dataTransfer.files.length) { m06Input.files = e.dataTransfer.files; m06Name.textContent = e.dataTransfer.files[0].name; }
                });
                m06Input.addEventListener('change', () => {
                    if (m06Input.files.length) m06Name.textContent = m06Input.files[0].name;
                });
            },
            preConfirm: () => {
                const tong_so_nguoi  = parseInt(document.getElementById('swal-gt-tong-so').value);
                const so_luot_dong_y = parseInt(document.getElementById('swal-gt-dong-y').value);

                if (!tong_so_nguoi || tong_so_nguoi < 1) {
                    Swal.showValidationMessage('Vui lòng nhập tổng số đoàn viên tham dự');
                    return false;
                }
                if (isNaN(so_luot_dong_y) || so_luot_dong_y < 0) {
                    Swal.showValidationMessage('Vui lòng nhập số lượt đồng ý hợp lệ');
                    return false;
                }
                if (so_luot_dong_y > tong_so_nguoi) {
                    Swal.showValidationMessage('Số lượt đồng ý không thể lớn hơn tổng số người');
                    return false;
                }
                const m06File = document.getElementById('swal-m06-input');
                const bien_ban_m06 = m06File.files.length ? m06File.files[0].name : '';
                if (!bien_ban_m06) {
                    Swal.showValidationMessage('Vui lòng upload Biên bản Hội nghị Chi Đoàn (Mẫu 06)');
                    return false;
                }
                return { tong_so_nguoi, so_luot_dong_y, bien_ban_m06 };
            }
        });

        if (formData) {
            const ratio = ((formData.so_luot_dong_y / formData.tong_so_nguoi) * 100).toFixed(2);
            const isPass = parseFloat(ratio) > 50;

            // Xác nhận lần nữa
            const confirm = await Swal.fire({
                icon: isPass ? 'question' : 'warning',
                title: isPass ? 'Xác nhận giới thiệu vào Đảng?' : 'Không đạt tỷ lệ!',
                html: `Kết quả biểu quyết: <strong>${ratio}%</strong> (${formData.so_luot_dong_y}/${formData.tong_so_nguoi})<br><br>
                    ${isPass ? 'Hồ sơ sẽ được chuyển lên <strong>Đoàn Khoa</strong> để xét duyệt giới thiệu vào Đảng.' : 'Hồ sơ sẽ bị <strong style="color:red;">từ chối</strong>.'}`,
                showCancelButton: true,
                confirmButtonText: isPass ? 'Chuyển lên Đoàn Khoa' : 'Xác nhận từ chối',
                cancelButtonText: 'Quay lại',
                confirmButtonColor: isPass ? '#6f42c1' : '#dc3545',
            });

            if (confirm.isConfirmed) {
                Swal.fire({ title: 'Đang xử lý...', allowOutsideClick: false, didOpen: () => Swal.showLoading() });

                const res = await ajaxRequest('dvut_gioi_thieu_dang', { id, ...formData });
                if (res.success) {
                    await loadData();
                    Swal.fire({
                        icon: isPass ? 'success' : 'info',
                        title: isPass ? 'Đã chuyển hồ sơ giới thiệu Đảng!' : 'Hồ sơ bị từ chối',
                        text: res.data.message,
                        timer: 2500,
                        showConfirmButton: false
                    });
                } else {
                    Swal.fire({ icon: 'error', title: 'Lỗi', text: res.data?.message || 'Có lỗi xảy ra.' });
                }
            }
        }
    }

    /**
     * ================================================================
     * BQ Chuyển Đảng chính thức — GĐ5 (Chi Đoàn)
     * ================================================================
     * Áp dụng: Đoàn viên đang ở trạng thái DANG_VIEN_DU_BI.
     * Form: Nhập kết quả BQ + Upload file Biên bản họp (Mẫu 08).
     * Logic: Nếu > 50% → chuyển trạng thái CHO_DK_CHUYEN_DANG.
     */
    async function openChuyenDangModal(id) {
        // ── Kiểm tra quyền ──
        if (currentRole !== 'bch_chi_doan') {
            Swal.fire({ icon: 'warning', title: 'Không có quyền', text: 'Chỉ BCH Chi Đoàn mới được thực hiện thao tác này.' });
            return;
        }

        const dv = allDoanVien.find(d => String(d.id) === String(id));
        if (!dv) return;

        // ── Kiểm tra trạng thái: phải là DANG_VIEN_DU_BI ──
        if (dv.trang_thai !== 'DANG_VIEN_DU_BI') {
            Swal.fire({ icon: 'warning', title: 'Không hợp lệ', text: 'Chỉ áp dụng cho Đảng viên.' });
            return;
        }

        // ── Form nhập kết quả biểu quyết + upload Mẫu 08 ──
        const { value: formData } = await Swal.fire({
            title: 'BQ Chuyển Đảng chính thức',
            html: `
                <p style="text-align:left; margin-bottom:15px; color:#555;">
                    Đảng viên: <strong style="color:#174f8c;">${esc(dv.ho_ten)}</strong> — ${esc(dv.mssv)}<br>
                    <small>Chi đoàn: ${esc(dv.chi_doan)}</small>
                </p>

                <div class="form-group">
                    <label>Tổng số Đoàn viên tham dự <span style="color:red;">*</span></label>
                    <input type="number" id="swal-cd-tong-so" min="1" placeholder="Nhập tổng số người" class="swal2-input" style="margin:0;">
                </div>
                <div class="form-group">
                    <label>Số lượt đồng ý <span style="color:red;">*</span></label>
                    <input type="number" id="swal-cd-dong-y" min="0" placeholder="Nhập số lượt biểu quyết đồng ý" class="swal2-input" style="margin:0;">
                </div>
                <div class="vote-result-display" id="swal-cd-vote-result" style="display:none;">
                    <div class="vote-ratio" id="swal-cd-vote-ratio">0%</div>
                    <div class="vote-label" id="swal-cd-vote-label">Tỷ lệ biểu quyết</div>
                </div>

                <div class="form-group" style="margin-top:16px;">
                    <label>Biên bản họp — Mẫu 08 <span style="color:red;">*</span></label>
                    <small>Upload file PDF hoặc DOCX (tối đa 5MB).</small>
                    <div class="swal-file-upload-area" id="swal-m08-area" onclick="document.getElementById('swal-m08-input').click();">
                        <span class="dashicons dashicons-media-document"></span>
                        <p>Nhấp hoặc kéo thả file Biên bản vào đây</p>
                        <div class="file-name" id="swal-m08-name"></div>
                    </div>
                    <input type="file" id="swal-m08-input" accept=".pdf,.doc,.docx" style="display:none;">
                </div>
            `,
            showCancelButton: true,
            confirmButtonText: 'Xác nhận kết quả',
            cancelButtonText: 'Hủy',
            width: 550,
            didOpen: () => {
                const tongSoEl = document.getElementById('swal-cd-tong-so');
                const dongYEl  = document.getElementById('swal-cd-dong-y');
                const resultEl = document.getElementById('swal-cd-vote-result');
                const ratioEl  = document.getElementById('swal-cd-vote-ratio');
                const labelEl  = document.getElementById('swal-cd-vote-label');

                function calcVote() {
                    const tong = parseInt(tongSoEl.value) || 0;
                    const dong = parseInt(dongYEl.value) || 0;
                    if (tong > 0 && dong >= 0) {
                        const ratio = ((dong / tong) * 100).toFixed(2);
                        ratioEl.textContent = `${ratio}%`;
                        resultEl.style.display = 'block';
                        resultEl.classList.remove('vote-pass', 'vote-fail');
                        if (parseFloat(ratio) > 50) {
                            resultEl.classList.add('vote-pass');
                            labelEl.textContent = '✓ Đạt tỷ lệ > 50% — Đủ điều kiện chuyển Đảng chính thức';
                        } else {
                            resultEl.classList.add('vote-fail');
                            labelEl.textContent = '✗ Không đạt tỷ lệ > 50%';
                        }
                    } else {
                        resultEl.style.display = 'none';
                    }
                }
                tongSoEl.addEventListener('input', calcVote);
                dongYEl.addEventListener('input', calcVote);

                // Drag & Drop cho Mẫu 08
                const m08Area  = document.getElementById('swal-m08-area');
                const m08Input = document.getElementById('swal-m08-input');
                const m08Name  = document.getElementById('swal-m08-name');
                ['dragenter', 'dragover'].forEach(evt => {
                    m08Area.addEventListener(evt, (e) => { e.preventDefault(); m08Area.classList.add('dragover'); });
                });
                ['dragleave', 'drop'].forEach(evt => {
                    m08Area.addEventListener(evt, (e) => { e.preventDefault(); m08Area.classList.remove('dragover'); });
                });
                m08Area.addEventListener('drop', (e) => {
                    if (e.dataTransfer.files.length) { m08Input.files = e.dataTransfer.files; m08Name.textContent = e.dataTransfer.files[0].name; }
                });
                m08Input.addEventListener('change', () => {
                    if (m08Input.files.length) m08Name.textContent = m08Input.files[0].name;
                });
            },
            preConfirm: () => {
                const tong_so_nguoi  = parseInt(document.getElementById('swal-cd-tong-so').value);
                const so_luot_dong_y = parseInt(document.getElementById('swal-cd-dong-y').value);

                if (!tong_so_nguoi || tong_so_nguoi < 1) {
                    Swal.showValidationMessage('Vui lòng nhập tổng số đoàn viên tham dự');
                    return false;
                }
                if (isNaN(so_luot_dong_y) || so_luot_dong_y < 0) {
                    Swal.showValidationMessage('Vui lòng nhập số lượt đồng ý hợp lệ');
                    return false;
                }
                if (so_luot_dong_y > tong_so_nguoi) {
                    Swal.showValidationMessage('Số lượt đồng ý không thể lớn hơn tổng số người');
                    return false;
                }
                const m08File = document.getElementById('swal-m08-input');
                const bien_ban_m08 = m08File.files.length ? m08File.files[0].name : '';
                if (!bien_ban_m08) {
                    Swal.showValidationMessage('Vui lòng upload Biên bản họp (Mẫu 08)');
                    return false;
                }
                return { tong_so_nguoi, so_luot_dong_y, bien_ban_m08 };
            }
        });

        if (formData) {
            const ratio = ((formData.so_luot_dong_y / formData.tong_so_nguoi) * 100).toFixed(2);
            const isPass = parseFloat(ratio) > 50;

            // Xác nhận lần nữa
            const confirm = await Swal.fire({
                icon: isPass ? 'question' : 'warning',
                title: isPass ? 'Xác nhận chuyển Đảng chính thức?' : 'Không đạt tỷ lệ!',
                html: `Kết quả biểu quyết: <strong>${ratio}%</strong> (${formData.so_luot_dong_y}/${formData.tong_so_nguoi})<br><br>
                    ${isPass ? 'Hồ sơ sẽ được chuyển lên <strong>Đoàn Khoa</strong> để xét duyệt chuyển Đảng chính thức.' : 'Hồ sơ sẽ bị <strong style="color:red;">từ chối</strong>.'}`,
                showCancelButton: true,
                confirmButtonText: isPass ? 'Chuyển lên Đoàn Khoa' : 'Xác nhận từ chối',
                cancelButtonText: 'Quay lại',
                confirmButtonColor: isPass ? '#dc3545' : '#6c757d',
            });

            if (confirm.isConfirmed) {
                Swal.fire({ title: 'Đang xử lý...', allowOutsideClick: false, didOpen: () => Swal.showLoading() });

                const res = await ajaxRequest('dvut_chuyen_dang', { id, ...formData });
                if (res.success) {
                    await loadData();
                    Swal.fire({
                        icon: isPass ? 'success' : 'info',
                        title: isPass ? 'Đã chuyển hồ sơ chuyển Đảng chính thức!' : 'Hồ sơ bị từ chối',
                        text: res.data.message,
                        timer: 2500,
                        showConfirmButton: false
                    });
                } else {
                    Swal.fire({ icon: 'error', title: 'Lỗi', text: res.data?.message || 'Có lỗi xảy ra.' });
                }
            }
        }
    }

    /**
     * ================================================================
     * Import Excel Master — Dành riêng Admin Đoàn Trường
     * ================================================================
     * Upload file Excel (.xlsx) chứa dữ liệu sinh viên để đồng bộ
     * vào hệ thống tra cứu (Hộp đen).
     * Mock: Hiển thị "Đã đồng bộ 1520 sinh viên vào hệ thống tra cứu".
     */
    async function openImportExcel() {
        // ── Kiểm tra quyền ──
        if (currentRole !== 'admin_doan_truong') {
            Swal.fire({ icon: 'warning', title: 'Không có quyền', text: 'Chỉ Admin Đoàn Trường mới được Import Excel Master.' });
            return;
        }

        const { value: confirmed } = await Swal.fire({
            title: 'Import Excel Master',
            html: `
                <p style="text-align:left; color:#555;">
                    Tải lên file Excel chứa dữ liệu sinh viên để cập nhật <strong>Hộp đen</strong> (bảng đối chiếu MSSV).
                </p>
                <div class="swal-file-upload-area" id="swal-import-area" onclick="document.getElementById('swal-import-input').click();">
                    <span class="dashicons dashicons-media-spreadsheet" style="font-size:28px; color:#198754;"></span>
                    <p>Nhấp hoặc kéo thả file Excel (.xlsx) vào đây</p>
                    <div class="file-name" id="swal-import-name"></div>
                </div>
                <input type="file" id="swal-import-input" accept=".xlsx,.xls,.csv" style="display:none;">
                <div style="margin-top:12px; padding:10px; background:#fff3cd; border-radius:8px; font-size:0.85em; color:#856404; text-align:left;">
                    <span class="dashicons dashicons-warning" style="font-size:14px; vertical-align:middle;"></span>
                    <strong>Lưu ý:</strong> Dữ liệu cũ sẽ bị ghi đè (TRUNCATE) khi import mới.
                </div>
            `,
            showCancelButton: true,
            confirmButtonText: 'Import',
            confirmButtonColor: '#198754',
            cancelButtonText: 'Hủy',
            width: 550,
            didOpen: () => {
                const importArea = document.getElementById('swal-import-area');
                const fileInput  = document.getElementById('swal-import-input');
                const fileName   = document.getElementById('swal-import-name');

                // Drag & Drop
                ['dragenter', 'dragover'].forEach(evt => {
                    importArea.addEventListener(evt, (e) => { e.preventDefault(); importArea.classList.add('dragover'); });
                });
                ['dragleave', 'drop'].forEach(evt => {
                    importArea.addEventListener(evt, (e) => { e.preventDefault(); importArea.classList.remove('dragover'); });
                });
                importArea.addEventListener('drop', (e) => {
                    if (e.dataTransfer.files.length) { fileInput.files = e.dataTransfer.files; fileName.textContent = e.dataTransfer.files[0].name; }
                });
                fileInput.addEventListener('change', () => {
                    if (fileInput.files.length) fileName.textContent = fileInput.files[0].name;
                });
            },
            preConfirm: () => {
                const fileInput = document.getElementById('swal-import-input');
                if (!fileInput.files.length) {
                    Swal.showValidationMessage('Vui lòng chọn file Excel');
                    return false;
                }
                return fileInput.files[0].name;
            }
        });

        if (confirmed) {
            // Hiệu ứng đang import
            Swal.fire({ title: 'Đang import dữ liệu...', html: '<p style="color:#555;">Vui lòng đợi, hệ thống đang đồng bộ dữ liệu sinh viên...</p>', allowOutsideClick: false, didOpen: () => Swal.showLoading() });

            const res = await ajaxRequest('dvut_import_excel', { file_name: confirmed });
            if (res.success) {
                Swal.fire({
                    icon: 'success',
                    title: 'Import thành công!',
                    html: `<p style="color:#555;">Đã đồng bộ <strong style="color:#198754; font-size:1.2em;">${res.data.count || 1520}</strong> sinh viên vào hệ thống tra cứu (Hộp đen).</p>
                        <div style="margin-top:12px; padding:10px; background:#d1e7dd; border-radius:8px; font-size:0.9em; color:#0f5132;">
                            <span class="dashicons dashicons-yes-alt" style="font-size:16px; vertical-align:middle;"></span>
                            File: <strong>${esc(confirmed)}</strong>
                        </div>`,
                    confirmButtonText: 'Đóng',
                    confirmButtonColor: '#198754',
                });
            } else {
                Swal.fire({ icon: 'error', title: 'Lỗi', text: res.data?.message || 'Có lỗi xảy ra.' });
            }
        }
    }

    // =================================================================
    // DATA LOADING
    // =================================================================

    /**
     * Tải dữ liệu từ server.
     * WordPress mode: gọi admin-ajax.php trực tiếp.
     */
    async function loadData() {
        const tbody = document.getElementById('dvut-table-body');
        if (tbody) renderSkeletonLoader(tbody, 5, 7);

        try {
            // Load lịch sử chỉnh sửa từ server — để hiển thị lại sau khi thoát phiên
            if (typeof ajaxUrl !== 'undefined' && dvutNonce) {
                try {
                    const histRes = await $.post(ajaxUrl, { action: 'dvut_get_edit_history', nonce: dvutNonce });
                    if (histRes && histRes.success && Array.isArray(histRes.data.items) && histRes.data.items.length > 0) {
                        editHistory = histRes.data.items;
                        historyIdCounter = Math.max(0, ...histRes.data.items.map(h => parseInt(h.id, 10) || 0));
                    }
                } catch (e) {
                    // Giữ editHistory = []
                }
            }

            const res = await ajaxRequest('dvut_get_list');
            if (res.success) {
                allDoanVien = res.data.items || [];

                // Populate context dropdowns (chỉ cần thiết lần đầu)
                populateContextDropdowns();

                // Set default context nếu chưa có — chỉ đặt cho role thực sự dùng dropdown tương ứng:
                // BCH Chi Đoàn → chi_doan dropdown, Đoàn Khoa → khoa dropdown
                // Nếu đã được gán ID từ hệ thống (onboarding) thì KHÔNG đặt default
                if (!currentChiDoanId && currentRole === 'bch_chi_doan' && CHI_DOAN_LIST.length > 0) {
                    currentChiDoanId = CHI_DOAN_LIST[0].id;
                    $('#dvut-context-chi-doan').val(currentChiDoanId);
                }
                // Đoàn Khoa: tương tự — fallback khi chưa có khoa_id
                if (!currentKhoaId && currentRole === 'can_bo_doan_khoa' && KHOA_LIST.length > 0) {
                    currentKhoaId = KHOA_LIST[0].id;
                    $('#dvut-context-khoa').val(currentKhoaId);
                }

                // Render dropdown filters + bảng theo phạm vi role
                applyRoleUI();

                // Nếu là Đoàn viên → populate hồ sơ cá nhân (dữ liệu đã sẵn lúc này)
                if (currentRole === 'doan_vien') {
                    populateProfileForDoanVien();
                }

                document.dispatchEvent(new CustomEvent('dvut:dataLoaded'));
            }
        } catch (err) {
            console.error('Lỗi tải dữ liệu:', err);
                tbody.innerHTML = `<tr><td colspan="7" style="text-align:center; padding:30px; color:#dc3545;">
                    <span class="dashicons dashicons-warning" style="font-size:24px; display:block; margin-bottom:8px;"></span>
                    Có lỗi xảy ra khi tải dữ liệu. Vui lòng thử lại.
                </td></tr>`;
        }
    }

    // =================================================================
    // EVENT BINDINGS
    // =================================================================

    $(document).ready(function () {
        // Load dữ liệu ban đầu
        loadData();

        // ─── Thay đổi số bản ghi hiển thị trên trang ───
        $('#dvut-per-page-select').on('change', function () {
            perPage = parseInt($(this).val()) || 10;
            currentPage = 1;
            renderTable();
        });

        // ─── Admin: Tra cứu hồ sơ đoàn viên ───
        $('#admin-profile-search-btn').on('click', function () {
            searchProfileForAdmin();
        });
        $('#admin-profile-search-input').on('keydown', function (e) {
            if (e.key === 'Enter') {
                e.preventDefault();
                searchProfileForAdmin();
            }
        });
        // Hover effect cho search results
        $(document).on('mouseenter', '.admin-search-result-item', function () {
            $(this).css('background', '#f0f6ff');
        }).on('mouseleave', '.admin-search-result-item', function () {
            $(this).css('background', '');
        });

        // ─── Role: do Dev Hub (local) hoặc hệ thống (production) quyết định ───
        // Không còn Role Switcher dropdown trên toolbar.

        // ─── Context: chọn Chi Đoàn (cho vai trò BCH Chi Đoàn) ───
        if (currentChiDoanId) {
            // Production: tự set giá trị Chi Đoàn từ hệ thống, ẩn dropdown
            $('#dvut-context-chi-doan').val(currentChiDoanId);
            $('#dvut-context-chi-doan-wrapper').hide();
        }
        $('#dvut-context-chi-doan').on('change', function () {
            currentChiDoanId = $(this).val() ? parseInt($(this).val()) : null;
            filterKhoa = [];
            filterChiDoan = [];
            currentPage = 1;
            applyRoleUI();
        });

        // ─── Context: chọn Khoa (cho vai trò Đoàn Khoa) ───
        if (currentKhoaId) {
            // Production: tự set giá trị Khoa từ hệ thống, ẩn dropdown
            $('#dvut-context-khoa').val(currentKhoaId);
            $('#dvut-context-khoa-wrapper').hide();
        }
        $('#dvut-context-khoa').on('change', function () {
            currentKhoaId = $(this).val() ? parseInt($(this).val()) : null;
            filterKhoa = [];
            filterChiDoan = [];
            currentPage = 1;
            applyRoleUI();
        });

        // Áp dụng UI theo role ban đầu
        applyRoleUI();

        // ─── Tab navigation ───
        $('#dvut-sub-nav').on('click', '.sub-nav-link', function () {
            $('#dvut-sub-nav .sub-nav-link').removeClass('active');
            $(this).addClass('active');
            currentTab = $(this).data('status');
            currentPage = 1;
            clearSelection();
            renderTable();
        });

        // ─── Tab navigation Chi bộ Sinh viên ───
        $('#dvut-sub-nav-chi-bo').on('click', '.sub-nav-link', function () {
            $('#dvut-sub-nav-chi-bo .sub-nav-link').removeClass('active');
            $(this).addClass('active');
            currentTab = $(this).data('status');
            currentPage = 1;
            clearSelection();
            renderTable();
        });

        // ─── Search (debounce 300ms) ───
        let searchTimer;
        $('#dvut-search-input').on('input', function () {
            clearTimeout(searchTimer);
            searchTimer = setTimeout(() => {
                searchKeyword = $(this).val().trim();
                currentPage = 1;
                renderTable();
            }, 300);
        });

        // ─── Filter Chi đoàn (multi-select) ───
        $('#dvut-filter-chi-doan').on('change', function () {
            filterChiDoan = $(this).val() || [];
            currentPage = 1;
            updateStats();
            renderTable();
        });

        // ─── Filter Khoa (multi-select, cascade → Chi đoàn) ───
        $('#dvut-filter-khoa').on('change', function () {
            filterKhoa = $(this).val() || [];
            filterChiDoan = []; // Reset chi đoàn khi đổi khoa
            currentPage = 1;
            // Cascade: re-render chi đoàn dropdown theo khoa đã chọn
            const visibleCD = getVisibleChiDoan();
            const cascadedCD = filterKhoa.length
                ? visibleCD.filter(cd => filterKhoa.includes(String(cd.khoa_id)))
                : visibleCD;
            renderChiDoanDropdown(cascadedCD);
            updateStats();
            renderTable();
        });

        // ─── Nút "Đề cử" ───
        $('#dvut-add-btn').on('click', openAddModal);

        // ─── Nút "Ứng cử danh hiệu ĐVƯT" (Đoàn viên tự ứng cử) ───
        $('#dvut-ung-cu-btn').on('click', openUngCuModal);

        // ─── Nút "Xuất Excel" (placeholder) ───
        $('#dvut-export-btn').on('click', function () {
            Swal.fire({
                icon: 'info',
                title: 'Xuất Excel',
                text: 'Chức năng xuất Excel sẽ được Admin tích hợp trên hệ thống WordPress.',
                confirmButtonText: 'Đã hiểu'
            });
        });

        // ─── Sortable headers ───
        $('#dvut-table').on('click', 'th.sortable', function () {
            const sortField = $(this).data('sort');
            const isAsc = $(this).hasClass('asc');

            $('#dvut-table th.sortable').removeClass('asc desc');

            if (isAsc) {
                $(this).addClass('desc');
                allDoanVien.sort((a, b) => (b[sortField] || '').localeCompare(a[sortField] || '', 'vi'));
            } else {
                $(this).addClass('asc');
                allDoanVien.sort((a, b) => (a[sortField] || '').localeCompare(b[sortField] || '', 'vi'));
            }

            renderTable();
        });
    });

    // =================================================================
    // LỊCH SỬ CHỈNH SỬA — Panel Slide Logic
    // =================================================================

    /** State cho history panel */
    let historyPage = 1;
    let historyPerPage = 20;
    let historyPanelOpen = false;

    /**
     * Lấy danh sách lịch sử chỉnh sửa có phân quyền (local mode).
     *
     * Quy tắc:
     *   - admin_doan_truong : Xem tất cả
     *   - can_bo_doan_khoa  : Xem lịch sử của chính mình + bch_chi_doan cùng khoa
     *   - bch_chi_doan      : Chỉ xem lịch sử của bch_chi_doan cùng chi đoàn
     *
     * @param {Object} filters - Bộ lọc { role, action, keyword, from, to }
     * @returns {Array} Danh sách history items đã lọc
     */
    function getFilteredHistory(filters = {}) {
        // Role đoàn viên không có quyền xem lịch sử chỉnh sửa
        if (currentRole === 'doan_vien') return [];

        let items = [...editHistory];

        // ── Phân quyền theo vai trò hiện tại (role cao xem được lịch sử của role dưới) ──
        if (currentRole === 'can_bo_doan_khoa') {
            // Đoàn Khoa: thấy lịch sử của mình + chi đoàn cùng khoa
            items = items.filter(h =>
                (h.vai_tro === 'can_bo_doan_khoa' || h.vai_tro === 'bch_chi_doan') &&
                (currentKhoaId ? h.khoa_id == currentKhoaId || h.khoa_id == 0 : true)
            );
        } else if (currentRole === 'bch_chi_doan') {
            // Chi Đoàn: chỉ thấy lịch sử bch_chi_doan cùng chi đoàn
            items = items.filter(h =>
                h.vai_tro === 'bch_chi_doan' &&
                h.vai_tro === 'bch_chi_doan' &&
                (currentChiDoanId ? h.chi_doan_id == currentChiDoanId || h.chi_doan_id == 0 : true)
            );
        }
        // admin_doan_truong: không lọc → thấy tất cả

        // ── Áp dụng bộ lọc ──
        if (filters.role) {
            items = items.filter(h => h.vai_tro === filters.role);
        }
        if (filters.action) {
            items = items.filter(h => h.hanh_dong === filters.action);
        }
        if (filters.keyword) {
            const kw = filters.keyword.toLowerCase();
            items = items.filter(h =>
                (h.doi_tuong || '').toLowerCase().includes(kw) ||
                (h.mssv || '').toLowerCase().includes(kw) ||
                (h.mo_ta || '').toLowerCase().includes(kw)
            );
        }
        if (filters.from) {
            const fromDate = new Date(filters.from + 'T00:00:00');
            items = items.filter(h => new Date(h.thoi_gian) >= fromDate);
        }
        if (filters.to) {
            const toDate = new Date(filters.to + 'T23:59:59');
            items = items.filter(h => new Date(h.thoi_gian) <= toDate);
        }

        return items;
    }

    function renderHistoryPanel() {
        const bodyEl = document.getElementById('history-body');
        if (!bodyEl) return;
        if (currentRole === 'doan_vien') {
            bodyEl.innerHTML = '<div class="dvut-history-empty"><p>Bạn không có quyền xem lịch sử chỉnh sửa.</p></div>';
            return;
        }

        const filters = {
            role: $('#history-filter-role').val() || '',
            action: $('#history-filter-action').val() || '',
            keyword: $('#history-filter-keyword').val() || '',
            from: $('#history-filter-from').val() || '',
            to: $('#history-filter-to').val() || '',
        };

        function renderHistoryList(pageItems, total, totalPagesVal) {
            const startIdx = (historyPage - 1) * historyPerPage;
            if (pageItems.length === 0) {
                bodyEl.innerHTML = `
                    <div class="dvut-history-empty">
                        <span class="dashicons dashicons-backup"></span>
                        <p style="font-weight:600; font-size:1.05em; margin:0 0 6px;">Chưa có lịch sử chỉnh sửa</p>
                        <p style="font-size:0.88em; margin:0;">Các thao tác sẽ được ghi nhận tại đây.</p>
                    </div>`;
            } else {
                bodyEl.innerHTML = `<ul class="dvut-history-list">${pageItems.map(h => {
                    const timeStr = formatHistoryTime(h.thoi_gian);
                    const avatarIcon = h.vai_tro === 'admin_doan_truong' ? 'dashicons-shield'
                                    : h.vai_tro === 'can_bo_doan_khoa'  ? 'dashicons-building'
                                    : 'dashicons-groups';
                    return `
                        <li class="dvut-history-item">
                            <div class="history-avatar role-${esc(h.vai_tro)}">
                                <span class="dashicons ${avatarIcon}"></span>
                            </div>
                            <div class="history-content">
                                <div class="history-meta">
                                    <span class="history-user">${esc(h.nguoi_thuc_hien)}</span>
                                    <span class="history-role-badge badge-${esc(h.vai_tro)}">${esc(ROLE_LABELS[h.vai_tro] || h.vai_tro)}</span>
                                    <span class="history-action-badge action-badge-${esc(h.hanh_dong)}">${esc(ACTION_LABELS[h.hanh_dong] || h.hanh_dong)}</span>
                                </div>
                                <div class="history-desc">${esc(h.mo_ta || '')}</div>
                                ${h.chi_doan ? `<div class="history-time"><span class="dashicons dashicons-location" style="font-size:13px;"></span> ${esc(h.chi_doan)}</div>` : ''}
                                <div class="history-time"><span class="dashicons dashicons-clock" style="font-size:13px;"></span> ${timeStr}</div>
                            </div>
                        </li>`;
                }).join('')}</ul>`;
            }
            const summaryEl = document.getElementById('history-summary');
            if (summaryEl) summaryEl.textContent = `Hiển thị ${startIdx + 1}–${Math.min(startIdx + historyPerPage, total)} / ${total} bản ghi`;
            const pagEl = document.getElementById('history-pagination');
            if (pagEl) {
                let pagHtml = '';
                pagHtml += `<button ${historyPage <= 1 ? 'disabled' : ''} onclick="DvutApp.historyGoPage(${historyPage - 1})">‹</button>`;
                let startP = Math.max(1, historyPage - 2);
                let endP = Math.min(totalPagesVal, startP + 4);
                if (endP - startP < 4) startP = Math.max(1, endP - 4);
                for (let p = startP; p <= endP; p++) {
                    pagHtml += `<button ${p === historyPage ? 'style="background:#123d6d;font-weight:700;"' : ''} onclick="DvutApp.historyGoPage(${p})">${p}</button>`;
                }
                pagHtml += `<button ${historyPage >= totalPagesVal ? 'disabled' : ''} onclick="DvutApp.historyGoPage(${historyPage + 1})">›</button>`;
                pagEl.innerHTML = pagHtml;
            }
            updateHistoryRoleFilter();
        }

        bodyEl.innerHTML = '<div class="dvut-history-empty"><p>Đang tải...</p></div>';
        ajaxRequest('dvut_get_edit_history', {
            filter_role: filters.role,
            filter_action: filters.action,
            filter_keyword: filters.keyword,
            filter_from: filters.from,
            filter_to: filters.to,
            page: historyPage,
            per_page: historyPerPage,
        }).then(function (res) {
            if (!res || !res.success) {
                bodyEl.innerHTML = '<div class="dvut-history-empty"><p>' + (res && res.data && res.data.message ? esc(res.data.message) : 'Không tải được lịch sử.') + '</p></div>';
                document.getElementById('history-summary') && (document.getElementById('history-summary').textContent = '0 bản ghi');
                document.getElementById('history-pagination') && (document.getElementById('history-pagination').innerHTML = '');
                updateHistoryRoleFilter();
                return;
            }
            const data = res.data;
            const pageItems = data.items || [];
            const total = data.total || 0;
            const totalPages = data.total_pages || 1;
            if (historyPage > totalPages) historyPage = totalPages;
            renderHistoryList(pageItems, total, totalPages);
        }).catch(function () {
            bodyEl.innerHTML = '<div class="dvut-history-empty"><p>Không tải được lịch sử. Vui lòng thử lại.</p></div>';
            const summaryEl = document.getElementById('history-summary');
            if (summaryEl) summaryEl.textContent = '0 bản ghi';
            const pagEl = document.getElementById('history-pagination');
            if (pagEl) pagEl.innerHTML = '';
            updateHistoryRoleFilter();
        });
    }

    function formatHistoryTime(isoString) {
        const d = new Date(isoString);
        const now = new Date();
        const diffMs = now - d;
        const diffMin = Math.floor(diffMs / 60000);
        const diffHour = Math.floor(diffMs / 3600000);
        const diffDay = Math.floor(diffMs / 86400000);

        if (diffMin < 1) return 'Vừa xong';
        if (diffMin < 60) return `${diffMin} phút trước`;
        if (diffHour < 24) return `${diffHour} giờ trước`;
        if (diffDay < 7) return `${diffDay} ngày trước`;
        return d.toLocaleDateString('vi-VN', { day: '2-digit', month: '2-digit', year: 'numeric', hour: '2-digit', minute: '2-digit' });
    }

    function updateHistoryRoleFilter() {
        const filterEl = document.getElementById('history-filter-role');
        const groupEl = document.getElementById('history-filter-role-group');
        if (!filterEl || !groupEl) return;

        if (currentRole === 'bch_chi_doan') {
            groupEl.style.display = 'none';
        } else {
            groupEl.style.display = '';
            const options = filterEl.querySelectorAll('option');
            options.forEach(opt => {
                if (opt.value === '') {
                    opt.style.display = '';
                } else if (currentRole === 'can_bo_doan_khoa') {
                    opt.style.display = (opt.value === 'admin_doan_truong') ? 'none' : '';
                } else {
                    opt.style.display = '';
                }
            });
        }
    }

    function openHistoryPanel() {
        if (currentRole === 'doan_vien') return;
        historyPage = 1;
        historyPanelOpen = true;

        const overlay = document.getElementById('history-overlay');
        const panel = document.getElementById('history-panel');
        if (overlay) overlay.classList.add('active');
        setTimeout(() => {
            if (panel) panel.classList.add('active');
        }, 10);

        renderHistoryPanel();
    }

    function closeHistoryPanel() {
        historyPanelOpen = false;
        const overlay = document.getElementById('history-overlay');
        const panel = document.getElementById('history-panel');
        if (panel) panel.classList.remove('active');
        setTimeout(() => {
            if (overlay) overlay.classList.remove('active');
        }, 350);
    }

    function historyGoPage(page) {
        historyPage = page;
        renderHistoryPanel();
        document.getElementById('history-body')?.scrollTo({ top: 0, behavior: 'smooth' });
    }

    $(document).ready(function () {
        $('#close-history-btn').on('click', closeHistoryPanel);
        $('#history-overlay').on('click', closeHistoryPanel);

        // ─── Thay đổi số bản ghi lịch sử hiển thị trên trang ───
        $('#history-per-page-select').on('change', function () {
            historyPerPage = parseInt($(this).val()) || 20;
            historyPage = 1;
            renderHistoryPanel();
        });

        function applyHistoryFilter() {
            historyPage = 1;
            renderHistoryPanel();
        }

        $('#history-btn-apply').on('click', applyHistoryFilter);

        $('#history-filter-role').on('change', applyHistoryFilter);
        $('#history-filter-action').on('change', applyHistoryFilter);
        $('#history-filter-from').on('change', applyHistoryFilter);
        $('#history-filter-to').on('change', applyHistoryFilter);

        let historySearchTimer;
        $('#history-filter-keyword').on('input', function () {
            clearTimeout(historySearchTimer);
            historySearchTimer = setTimeout(applyHistoryFilter, 300);
        });

        $('#history-btn-reset').on('click', function () {
            $('#history-filter-role').val('');
            $('#history-filter-action').val('');
            $('#history-filter-keyword').val('');
            $('#history-filter-from').val('');
            $('#history-filter-to').val('');
            applyHistoryFilter();
        });

        $('#history-filter-keyword').on('keydown', function (e) {
            if (e.key === 'Enter') {
                e.preventDefault();
                clearTimeout(historySearchTimer);
                applyHistoryFilter();
            }
        });

        $(document).on('keydown', function (e) {
            if (e.key === 'Escape' && historyPanelOpen) {
                closeHistoryPanel();
            }
        });
    });

    // =================================================================
    // QUẢN LÝ HỆ THỐNG — System Management Module (Admin only)
    // =================================================================

    // ── State ──
    let _sysRolesPage = 1;
    let _sysDbPage = 1;
    let _sysLogsPage = 1;
    let _sysImportData = null; // parsed CSV rows
    let _sysImportHeaders = null;

    const SYS_ROLE_LABELS = {
        'doan_vien':         'Đoàn viên',
        'bch_chi_doan':      'BCH Chi Đoàn',
        'can_bo_doan_khoa':  'Cán bộ Đoàn Khoa',
        'admin_doan_truong': 'Admin Đoàn Trường',
        'chi_bo_sinh_vien':  'Chi bộ Sinh viên',
    };

    /**
     * Helper: Make AJAX call for system management.
     */
    async function sysAjax(action, data = {}) {
        return ajaxRequest(action, data);
    }

    // ── 1. Quản lý phân quyền ──

    async function sysSearchUsers() {
        const search = $('#sys-role-search').val().trim();
        _sysRolesPage = 1;
        await sysLoadRoles(search);
    }

    async function sysLoadRoles(search = '', page = 1) {
        try {
            const res = await sysAjax('dvut_sys_list_roles', { search, page, per_page: 15 });
            if (!res.success) { Swal.fire('Lỗi', res.data?.message || 'Không tải được danh sách.', 'error'); return; }
            const { items, total, total_pages } = res.data;
            const tbody = document.getElementById('sys-roles-tbody');
            if (!items || items.length === 0) {
                tbody.innerHTML = '<tr><td colspan="8" class="text-center text-muted">Không có kết quả</td></tr>';
                $('#sys-roles-pagination').html('');
                return;
            }
            tbody.innerHTML = items.map(r => `
                <tr data-id="${r.id}">
                    <td>${r.id}</td>
                    <td><strong>${esc(r.user_login || r.display_name || 'N/A')}</strong></td>
                    <td>${esc(r.user_email || '')}</td>
                    <td><span class="status-badge">${esc(SYS_ROLE_LABELS[r.role] || r.role)}</span></td>
                    <td>${esc(r.khoa_name || r.khoa_id || '—')}</td>
                    <td>${esc(r.chi_doan_name || r.chi_doan_id || '—')}</td>
                    <td>${r.chi_bo_id ? 'CBSV ' + r.chi_bo_id : '—'}</td>
                    <td class="sys-cell-actions">
                        <button class="btn-primary btn-sm" onclick="DvutApp.sysEditRole(${r.id})"><span class="dashicons dashicons-edit"></span></button>
                        <button class="btn-danger btn-sm" onclick="DvutApp.sysDeleteRole(${r.id})"><span class="dashicons dashicons-trash"></span></button>
                    </td>
                </tr>
            `).join('');
            renderSysPagination('sys-roles-pagination', page, total_pages, (p) => { _sysRolesPage = p; sysLoadRoles(search, p); });
        } catch (e) {
            console.error('[SYS] Error loading roles:', e);
        }
    }

    function sysAddUserRole() {
        const khoaOptions = KHOA_LIST
            .map(k => `<option value="${k.id}">${esc(k.ten)}</option>`).join('');

        Swal.fire({
            title: 'Thêm phân quyền mới',
            html: `
                <div style="text-align:left;font-size:14px;">
                    <div style="margin-bottom:12px;">
                        <label style="font-weight:600;">Tạo tài khoản mới hoặc gán cho User ID có sẵn:</label>
                        <div style="display:flex;gap:8px;margin-top:6px;">
                            <input id="swal-sys-username" class="swal2-input" placeholder="Username / MSSV" style="margin:0;flex:1;" />
                            <input id="swal-sys-email" class="swal2-input" placeholder="Email" style="margin:0;flex:1;" />
                        </div>
                        <input id="swal-sys-password" class="swal2-input" type="password" placeholder="Mật khẩu (để trống nếu gán user có sẵn)" style="margin-top:6px;" />
                        <input id="swal-sys-user-id" class="swal2-input" type="number" placeholder="Hoặc nhập User ID có sẵn" style="margin-top:6px;" />
                    </div>
                    <div style="margin-bottom:12px;">
                        <label style="font-weight:600;">Vai trò:</label>
                        <select id="swal-sys-role" class="swal2-select" style="width:100%;padding:8px;">
                            <option value="doan_vien">Đoàn viên</option>
                            <option value="bch_chi_doan">BCH Chi Đoàn</option>
                            <option value="can_bo_doan_khoa">Cán bộ Đoàn Khoa</option>
                            <option value="admin_doan_truong">Admin Đoàn Trường</option>
                            <option value="chi_bo_sinh_vien">Chi bộ Sinh viên</option>
                        </select>
                    </div>
                    <div style="display:flex;gap:8px;margin-bottom:12px;">
                        <div style="flex:1;">
                            <label style="font-weight:600;">Khoa:</label>
                            <select id="swal-sys-khoa" class="swal2-select" style="width:100%;padding:8px;">
                                <option value="">— Không chọn —</option>
                                ${khoaOptions}
                            </select>
                        </div>
                        <div style="flex:1;">
                            <label style="font-weight:600;">Chi bộ:</label>
                            <select id="swal-sys-chibo" class="swal2-select" style="width:100%;padding:8px;">
                                <option value="">— Không chọn —</option>
                                ${[1,2,3,4,5,6,7].map(i => `<option value="${i}">CBSV ${i}</option>`).join('')}
                            </select>
                        </div>
                    </div>
                </div>
            `,
            showCancelButton: true,
            confirmButtonText: 'Thêm',
            cancelButtonText: 'Hủy',
            confirmButtonColor: '#16a34a',
            preConfirm: async () => {
                const username = document.getElementById('swal-sys-username').value.trim();
                const email = document.getElementById('swal-sys-email').value.trim();
                const password = document.getElementById('swal-sys-password').value;
                const userId = document.getElementById('swal-sys-user-id').value.trim();
                const role = document.getElementById('swal-sys-role').value;
                const khoaId = document.getElementById('swal-sys-khoa').value;
                const chiBoId = document.getElementById('swal-sys-chibo').value;

                if (!userId && !username) {
                    Swal.showValidationMessage('Vui lòng nhập User ID hoặc Username để tạo tài khoản mới.');
                    return false;
                }

                let finalUserId = userId;

                // Tạo tài khoản mới nếu có username + password
                if (!userId && username && password) {
                    try {
                        const createRes = await sysAjax('dvut_sys_create_account', { username, email, password, display_name: username });
                        if (!createRes.success) {
                            Swal.showValidationMessage(createRes.data?.message || 'Không tạo được tài khoản.');
                            return false;
                        }
                        finalUserId = createRes.data.user_id;
                    } catch (e) {
                        Swal.showValidationMessage('Lỗi khi tạo tài khoản: ' + e.message);
                        return false;
                    }
                } else if (!userId && username && !password) {
                    // Dev mode: dùng username làm mock ID
                    finalUserId = null;
                    if (!finalUserId) {
                        Swal.showValidationMessage('Vui lòng nhập mật khẩu để tạo tài khoản.');
                        return false;
                    }
                }

                try {
                    const res = await sysAjax('dvut_sys_add_role', {
                        user_id: finalUserId,
                        role,
                        khoa_id: khoaId || '',
                        chi_doan_id: '',
                        chi_bo_id: chiBoId || '',
                        username: username,
                        email: email,
                    });
                    if (!res.success) {
                        Swal.showValidationMessage(res.data?.message || 'Không thêm được.');
                        return false;
                    }
                    return res.data;
                } catch (e) {
                    Swal.showValidationMessage('Lỗi: ' + e.message);
                    return false;
                }
            }
        }).then(result => {
            if (result.isConfirmed) {
                Swal.fire({ icon: 'success', title: 'Thành công!', text: result.value?.message || 'Đã thêm phân quyền.', timer: 1500, showConfirmButton: false });
                sysLoadRoles($('#sys-role-search').val().trim(), _sysRolesPage);
            }
        });
    }

    async function sysEditRole(id) {
        // Fetch current role data from server
        let record = {};
        try {
            const res = await sysAjax('dvut_sys_list_roles', { search: '', page: 1, per_page: 999 });
            if (res.success && res.data.items) {
                record = res.data.items.find(r => r.id === id) || {};
            }
        } catch (e) {
            console.error('Error fetching role:', e);
        }
        const khoaOptions = KHOA_LIST
            .map(k => `<option value="${k.id}" ${k.id == record.khoa_id ? 'selected' : ''}>${esc(k.ten)}</option>`).join('');

        Swal.fire({
            title: 'Chỉnh sửa phân quyền #' + id,
            html: `
                <div style="text-align:left;font-size:14px;">
                    <p><strong>User:</strong> ${esc(record.user_login || 'ID: ' + record.user_id)}</p>
                    <div style="margin-bottom:12px;">
                        <label style="font-weight:600;">Vai trò:</label>
                        <select id="swal-edit-role" class="swal2-select" style="width:100%;padding:8px;">
                            ${Object.entries(SYS_ROLE_LABELS).map(([k, v]) => `<option value="${k}" ${k === record.role ? 'selected' : ''}>${v}</option>`).join('')}
                        </select>
                    </div>
                    <div style="display:flex;gap:8px;margin-bottom:12px;">
                        <div style="flex:1;">
                            <label style="font-weight:600;">Khoa:</label>
                            <select id="swal-edit-khoa" class="swal2-select" style="width:100%;padding:8px;">
                                <option value="">— Không —</option>
                                ${khoaOptions}
                            </select>
                        </div>
                        <div style="flex:1;">
                            <label style="font-weight:600;">Chi bộ:</label>
                            <select id="swal-edit-chibo" class="swal2-select" style="width:100%;padding:8px;">
                                <option value="">— Không —</option>
                                ${[1,2,3,4,5,6,7].map(i => `<option value="${i}" ${i == record.chi_bo_id ? 'selected' : ''}>CBSV ${i}</option>`).join('')}
                            </select>
                        </div>
                    </div>
                    <div>
                        <label style="font-weight:600;">Trạng thái:</label>
                        <select id="swal-edit-active" class="swal2-select" style="width:100%;padding:8px;">
                            <option value="1" ${record.is_active == 1 ? 'selected' : ''}>Đang hoạt động</option>
                            <option value="0" ${record.is_active == 0 ? 'selected' : ''}>Vô hiệu hóa</option>
                        </select>
                    </div>
                </div>
            `,
            showCancelButton: true,
            confirmButtonText: 'Lưu',
            cancelButtonText: 'Hủy',
            confirmButtonColor: '#1a56db',
            preConfirm: async () => {
                const role = document.getElementById('swal-edit-role').value;
                const khoaId = document.getElementById('swal-edit-khoa').value;
                const chiBoId = document.getElementById('swal-edit-chibo').value;
                const isActive = document.getElementById('swal-edit-active').value;
                try {
                    const res = await sysAjax('dvut_sys_update_role', { id, role, khoa_id: khoaId, chi_bo_id: chiBoId, is_active: isActive });
                    if (!res.success) { Swal.showValidationMessage(res.data?.message); return false; }
                    return res.data;
                } catch (e) { Swal.showValidationMessage('Lỗi: ' + e.message); return false; }
            }
        }).then(result => {
            if (result.isConfirmed) {
                Swal.fire({ icon: 'success', title: 'Đã cập nhật!', timer: 1200, showConfirmButton: false });
                sysLoadRoles($('#sys-role-search').val().trim(), _sysRolesPage);
            }
        });
    }

    async function sysDeleteRole(id) {
        const confirm = await Swal.fire({
            title: 'Xác nhận xóa',
            text: `Bạn có chắc muốn xóa phân quyền #${id}?`,
            icon: 'warning',
            showCancelButton: true,
            confirmButtonText: 'Xóa',
            cancelButtonText: 'Hủy',
            confirmButtonColor: '#dc2626',
        });
        if (!confirm.isConfirmed) return;
        try {
            const res = await sysAjax('dvut_sys_delete_role', { id });
            if (res.success) {
                Swal.fire({ icon: 'success', title: 'Đã xóa!', timer: 1200, showConfirmButton: false });
                sysLoadRoles($('#sys-role-search').val().trim(), _sysRolesPage);
            } else {
                Swal.fire('Lỗi', res.data?.message || 'Không xóa được.', 'error');
            }
        } catch (e) { Swal.fire('Lỗi', e.message, 'error'); }
    }

    // ── 2. Import Hộp đen ──

    function sysHandleImportFile(file) {
        if (!file) return;
        const nameEl = document.getElementById('sys-import-filename');
        if (nameEl) nameEl.textContent = '📎 ' + file.name + ' (' + (file.size / 1024).toFixed(1) + ' KB)';

        const reader = new FileReader();
        reader.onload = function(e) {
            const text = e.target.result;
            const lines = text.split(/\r?\n/).filter(l => l.trim());
            if (lines.length < 2) {
                Swal.fire('Lỗi', 'File CSV phải có ít nhất 2 dòng (header + dữ liệu).', 'error');
                return;
            }
            // Remove BOM if present
            let headerLine = lines[0];
            if (headerLine.charCodeAt(0) === 0xFEFF) headerLine = headerLine.substring(1);
            
            _sysImportHeaders = parseCSVLine(headerLine);
            _sysImportData = [];
            for (let i = 1; i < lines.length; i++) {
                const vals = parseCSVLine(lines[i]);
                if (vals.length === 0) continue;
                const row = {};
                _sysImportHeaders.forEach((h, idx) => {
                    row[h.toLowerCase().trim()] = (vals[idx] || '').trim();
                });
                if (row.mssv) _sysImportData.push(row);
            }

            // Show preview
            const previewEl = document.getElementById('sys-import-preview');
            const countEl = document.getElementById('sys-import-count');
            const theadEl = document.getElementById('sys-import-preview-thead');
            const tbodyEl = document.getElementById('sys-import-preview-tbody');
            if (previewEl) previewEl.style.display = 'block';
            if (countEl) countEl.textContent = _sysImportData.length;

            const cols = _sysImportHeaders.map(h => h.toLowerCase().trim());
            if (theadEl) {
                theadEl.innerHTML = '<tr>' + cols.map(c => `<th>${esc(c)}</th>`).join('') + '</tr>';
            }
            if (tbodyEl) {
                const preview = _sysImportData.slice(0, 10);
                tbodyEl.innerHTML = preview.map(row =>
                    '<tr>' + cols.map(c => `<td>${esc(row[c] || '')}</td>`).join('') + '</tr>'
                ).join('');
                if (_sysImportData.length > 10) {
                    tbodyEl.innerHTML += `<tr><td colspan="${cols.length}" class="text-center text-muted">... và ${_sysImportData.length - 10} dòng nữa</td></tr>`;
                }
            }
        };
        reader.readAsText(file, 'UTF-8');
    }

    function parseCSVLine(line) {
        const result = [];
        let current = '';
        let inQuotes = false;
        for (let i = 0; i < line.length; i++) {
            const ch = line[i];
            if (inQuotes) {
                if (ch === '"' && line[i + 1] === '"') { current += '"'; i++; }
                else if (ch === '"') { inQuotes = false; }
                else { current += ch; }
            } else {
                if (ch === '"') { inQuotes = true; }
                else if (ch === ',') { result.push(current); current = ''; }
                else { current += ch; }
            }
        }
        result.push(current);
        return result;
    }

    async function sysConfirmImport() {
        if (!_sysImportData || _sysImportData.length === 0) {
            Swal.fire('Lỗi', 'Không có dữ liệu để import.', 'error');
            return;
        }
        const dotXet = document.getElementById('sys-import-dot-xet')?.value?.trim() || '';
        const confirm = await Swal.fire({
            title: 'Xác nhận Import',
            html: `<p>Sẽ import <strong>${_sysImportData.length}</strong> dòng vào bảng Hộp đen.</p>
                   ${dotXet ? '<p>Đợt xét: <strong>' + esc(dotXet) + '</strong></p>' : ''}
                   <p>Dữ liệu trùng MSSV sẽ được cập nhật (UPSERT).</p>`,
            icon: 'question',
            showCancelButton: true,
            confirmButtonText: 'Import',
            cancelButtonText: 'Hủy',
            confirmButtonColor: '#16a34a',
        });
        if (!confirm.isConfirmed) return;

        Swal.fire({ title: 'Đang import...', allowOutsideClick: false, didOpen: () => Swal.showLoading() });

        try {
            // Upload actual file
            const fileInput = document.getElementById('sys-import-file');
            if (!fileInput || !fileInput.files[0]) {
                Swal.fire('Lỗi', 'Không tìm thấy file.', 'error');
                return;
            }
            const fd = new FormData();
            fd.append('action', 'dvut_sys_import_blackbox');
            fd.append('nonce', dvutNonce);
            fd.append('csv_file', fileInput.files[0]);
            fd.append('dot_xet', dotXet);
            fd.append('upsert', document.getElementById('sys-import-update')?.checked ? '1' : '0');
            const response = await fetch(ajaxUrl, { method: 'POST', body: fd });
            const res = await response.json();

            if (res.success) {
                Swal.fire({ icon: 'success', title: 'Import thành công!', text: res.data.message, confirmButtonColor: '#1a56db' });
                sysCancelImport();
            } else {
                Swal.fire('Lỗi', res.data?.message || 'Import thất bại.', 'error');
            }
        } catch (e) {
            Swal.fire('Lỗi', 'Lỗi kết nối: ' + e.message, 'error');
        }
    }

    function sysCancelImport() {
        _sysImportData = null;
        _sysImportHeaders = null;
        const previewEl = document.getElementById('sys-import-preview');
        const resultEl = document.getElementById('sys-import-result');
        const filenameEl = document.getElementById('sys-import-filename');
        const fileInput = document.getElementById('sys-import-file');
        if (previewEl) previewEl.style.display = 'none';
        if (resultEl) resultEl.style.display = 'none';
        if (filenameEl) filenameEl.textContent = '';
        if (fileInput) fileInput.value = '';
    }

    // ── 3. Database Browser ──

    async function sysLoadTable() {
        const tableName = document.getElementById('sys-db-table-select')?.value;
        if (!tableName) { Swal.fire('Thông báo', 'Vui lòng chọn bảng.', 'info'); return; }
        _sysDbPage = 1;
        await sysLoadTableData(tableName, 1);
    }

    async function sysLoadTableData(tableName, page) {
        try {
            const res = await sysAjax('dvut_sys_browse_table', { table: tableName, page, per_page: 20 });
            if (!res.success) { Swal.fire('Lỗi', res.data?.message || 'Không tải được.', 'error'); return; }
            const { items, columns, total, total_pages } = res.data;
            const wrapper = document.getElementById('sys-db-table-wrapper');
            const theadEl = document.getElementById('sys-db-thead');
            const tbodyEl = document.getElementById('sys-db-tbody');
            const countEl = document.getElementById('sys-db-row-count');

            if (wrapper) wrapper.style.display = 'block';
            if (countEl) countEl.textContent = `${total} bản ghi`;

            if (!items || items.length === 0) {
                if (theadEl) theadEl.innerHTML = '';
                if (tbodyEl) tbodyEl.innerHTML = '<tr><td class="text-center text-muted">Bảng trống</td></tr>';
                $('#sys-db-pagination').html('');
                return;
            }

            const cols = columns && columns.length ? columns : Object.keys(items[0]);
            if (theadEl) {
                theadEl.innerHTML = '<tr>' + cols.map(c => `<th>${esc(c)}</th>`).join('') + '<th>Thao tác</th></tr>';
            }
            if (tbodyEl) {
                tbodyEl.innerHTML = items.map(row => {
                    const rowId = row.id || row.ID || '';
                    return '<tr data-id="' + rowId + '">' +
                        cols.map(c => {
                            const val = row[c] !== null && row[c] !== undefined ? String(row[c]) : '';
                            const truncated = val.length > 60 ? val.substring(0, 60) + '...' : val;
                            return `<td title="${esc(val)}">${esc(truncated)}</td>`;
                        }).join('') +
                        `<td><button class="btn-primary btn-sm" onclick="DvutApp.sysEditRecord('${esc(tableName)}', ${rowId})"><span class="dashicons dashicons-edit"></span></button></td>` +
                        '</tr>';
                }).join('');
            }
            renderSysPagination('sys-db-pagination', page, total_pages, (p) => { _sysDbPage = p; sysLoadTableData(tableName, p); });
        } catch (e) { console.error('[SYS] Browse error:', e); }
    }

    function sysEditRecord(tableName, rowId) {
        // Find the row from current table data to show current values
        const tbody = document.getElementById('sys-db-tbody');
        const row = tbody?.querySelector(`tr[data-id="${rowId}"]`);
        const thead = document.getElementById('sys-db-thead');
        if (!row || !thead) return;

        const headers = Array.from(thead.querySelectorAll('th')).map(th => th.textContent).filter(t => t !== 'Thao tác');
        const cells = Array.from(row.querySelectorAll('td'));
        
        const fieldsHtml = headers.map((h, i) => {
            const val = cells[i]?.getAttribute('title') || cells[i]?.textContent || '';
            const isId = h.toLowerCase() === 'id';
            return `<div style="margin-bottom:8px;">
                <label style="font-weight:600;font-size:13px;">${esc(h)}:</label>
                <input class="swal2-input sys-edit-field" data-field="${esc(h)}" value="${esc(val)}" ${isId ? 'disabled' : ''} style="margin:4px 0;font-size:13px;" />
            </div>`;
        }).join('');

        Swal.fire({
            title: 'Chỉnh sửa bản ghi #' + rowId,
            html: `<div style="text-align:left;max-height:400px;overflow-y:auto;">${fieldsHtml}</div>`,
            showCancelButton: true,
            confirmButtonText: 'Lưu thay đổi',
            cancelButtonText: 'Hủy',
            confirmButtonColor: '#1a56db',
            width: '600px',
            preConfirm: async () => {
                const inputs = document.querySelectorAll('.sys-edit-field:not(:disabled)');
                let changeCount = 0;
                for (const inp of inputs) {
                    const field = inp.dataset.field;
                    const newVal = inp.value;
                    const origVal = cells[headers.indexOf(field)]?.getAttribute('title') || '';
                    if (newVal !== origVal) {
                        try {
                            const res = await sysAjax('dvut_sys_edit_record', { table: tableName, id: rowId, field, value: newVal });
                            if (!res.success) { Swal.showValidationMessage(`Lỗi cập nhật ${field}: ${res.data?.message}`); return false; }
                            changeCount++;
                        } catch (e) { Swal.showValidationMessage('Lỗi: ' + e.message); return false; }
                    }
                }
                return { changeCount };
            }
        }).then(result => {
            if (result.isConfirmed) {
                if (result.value.changeCount > 0) {
                    Swal.fire({ icon: 'success', title: `Đã cập nhật ${result.value.changeCount} trường!`, timer: 1500, showConfirmButton: false });
                    sysLoadTableData(tableName, _sysDbPage);
                } else {
                    Swal.fire({ icon: 'info', title: 'Không có thay đổi.', timer: 1200, showConfirmButton: false });
                }
            }
        });
    }

    // ── 4. Nhật ký hệ thống ──

    async function sysLoadLogs() {
        const search = document.getElementById('sys-log-search')?.value?.trim() || '';
        _sysLogsPage = 1;
        await sysLoadLogsData(search, 1);
    }

    async function sysLoadLogsData(search, page) {
        try {
            const res = await sysAjax('dvut_sys_get_logs', { search, page, per_page: 25 });
            if (!res.success) { Swal.fire('Lỗi', res.data?.message || 'Không tải được nhật ký.', 'error'); return; }
            const { items, total, total_pages } = res.data;
            const tbody = document.getElementById('sys-logs-tbody');
            if (!items || items.length === 0) {
                tbody.innerHTML = '<tr><td colspan="4" class="text-center text-muted">Chưa có nhật ký nào</td></tr>';
                $('#sys-logs-pagination').html('');
                return;
            }
            tbody.innerHTML = items.map(l => `
                <tr>
                    <td style="white-space:nowrap;font-size:12px;">${esc(l.created_at || l.time || '')}</td>
                    <td>${esc(l.user_login || l.user || '')}</td>
                    <td><strong>${esc(l.action)}</strong></td>
                    <td style="font-size:12px;">${esc(l.details || l.detail || '')}</td>
                </tr>
            `).join('');
            renderSysPagination('sys-logs-pagination', page, total_pages, (p) => { _sysLogsPage = p; sysLoadLogsData(search, p); });
        } catch (e) { console.error('[SYS] Logs error:', e); }
    }

    // ── Pagination helper ──

    function renderSysPagination(containerId, currentPage, totalPages, callback) {
        const container = document.getElementById(containerId);
        if (!container || totalPages <= 1) { if (container) container.innerHTML = ''; return; }
        let html = '<div style="display:flex;gap:4px;justify-content:center;padding:12px 0;">';
        if (currentPage > 1) html += `<button class="btn-primary btn-sm" onclick="void(0)" data-page="${currentPage - 1}">« Trước</button>`;
        for (let p = Math.max(1, currentPage - 2); p <= Math.min(totalPages, currentPage + 2); p++) {
            html += `<button class="${p === currentPage ? 'btn-primary' : 'btn-secondary'} btn-sm" onclick="void(0)" data-page="${p}">${p}</button>`;
        }
        if (currentPage < totalPages) html += `<button class="btn-primary btn-sm" onclick="void(0)" data-page="${currentPage + 1}">Tiếp »</button>`;
        html += '</div>';
        container.innerHTML = html;
        container.querySelectorAll('button[data-page]').forEach(btn => {
            btn.addEventListener('click', () => callback(parseInt(btn.dataset.page)));
        });
    }

    // =================================================================
    // NHẬN XÉT ĐỊNH KỲ — Đánh giá quý ĐVƯT
    // =================================================================

    /**
     * Load danh sách kỳ đánh giá (admin).
     * Hiển thị trong tab "Nhận xét định kỳ" của Quản lý hệ thống.
     */
    async function sysLoadNhanXetPeriods() {
        const tbody = document.getElementById('sys-nhan-xet-tbody');
        if (!tbody) return;
        tbody.innerHTML = '<tr><td colspan="8" class="text-center text-muted">Đang tải...</td></tr>';

        try {
            const res = await ajaxRequest('dvut_nhan_xet_periods', {});
            if (!res.success) throw new Error(res.data?.message || 'Lỗi');

            const periods = res.data.periods || [];
            if (periods.length === 0) {
                tbody.innerHTML = '<tr><td colspan="8" class="text-center text-muted">Chưa có kỳ đánh giá nào.</td></tr>';
                return;
            }

            tbody.innerHTML = periods.map(p => {
                const statusBadge = p.is_open == 1
                    ? '<span style="background:#d4edda;color:#155724;padding:3px 10px;border-radius:12px;font-weight:600;font-size:.82em;">🔓 Đang mở</span>'
                    : '<span style="background:#f8d7da;color:#721c24;padding:3px 10px;border-radius:12px;font-weight:600;font-size:.82em;">🔒 Đã khóa</span>';
                const toggleBtn = p.is_open == 1
                    ? `<button class="btn-sm" style="background:#dc3545;color:#fff;border:none;border-radius:6px;padding:4px 10px;cursor:pointer;font-size:.8em;" onclick="DvutApp.sysToggleNhanXetPeriod(${p.id},'lock')">🔒 Khóa</button>`
                    : `<button class="btn-sm" style="background:#198754;color:#fff;border:none;border-radius:6px;padding:4px 10px;cursor:pointer;font-size:.8em;" onclick="DvutApp.sysToggleNhanXetPeriod(${p.id},'open')">🔓 Mở</button>`;
                return `<tr>
                    <td>${p.id}</td>
                    <td>${esc(p.ten_ky)}</td>
                    <td>Q${p.quy}</td>
                    <td>${esc(p.nam_hoc)}</td>
                    <td>${statusBadge}</td>
                    <td>${p.ngay_mo ? formatDate(p.ngay_mo) : '—'}</td>
                    <td>${p.ngay_khoa ? formatDate(p.ngay_khoa) : '—'}</td>
                    <td>${toggleBtn}</td>
                </tr>`;
            }).join('');
        } catch (e) {
            tbody.innerHTML = `<tr><td colspan="8" class="text-center" style="color:#dc3545;">${esc(e.message)}</td></tr>`;
        }
    }

    /**
     * Tạo kỳ đánh giá mới (admin).
     */
    async function sysAddNhanXetPeriod() {
        const { value: formValues } = await Swal.fire({
            title: 'Tạo kỳ đánh giá mới',
            html: `
                <div class="form-group" style="text-align:left; margin-bottom:12px;">
                    <label style="font-weight:600; margin-bottom:4px; display:block;">Tên kỳ</label>
                    <input id="swal-nx-ten" class="swal2-input" placeholder="Vd: Quý 1 năm học 2025-2026" style="margin:0;width:100%;">
                </div>
                <div style="display:grid; grid-template-columns:1fr 1fr; gap:12px; text-align:left;">
                    <div class="form-group">
                        <label style="font-weight:600; margin-bottom:4px; display:block;">Quý</label>
                        <select id="swal-nx-quy" class="swal2-select" style="width:100%;">
                            <option value="1">Quý 1</option>
                            <option value="2">Quý 2</option>
                            <option value="3">Quý 3</option>
                            <option value="4">Quý 4</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label style="font-weight:600; margin-bottom:4px; display:block;">Năm học</label>
                        <input id="swal-nx-namhoc" class="swal2-input" placeholder="2025-2026" style="margin:0;width:100%;">
                    </div>
                </div>
            `,
            showCancelButton: true,
            confirmButtonText: 'Tạo',
            cancelButtonText: 'Hủy',
            confirmButtonColor: '#198754',
            preConfirm: () => ({
                ten_ky: document.getElementById('swal-nx-ten').value.trim(),
                quy: document.getElementById('swal-nx-quy').value,
                nam_hoc: document.getElementById('swal-nx-namhoc').value.trim(),
            }),
        });

        if (!formValues) return;
        if (!formValues.ten_ky || !formValues.nam_hoc) {
            Swal.fire('Lỗi', 'Vui lòng nhập đầy đủ thông tin.', 'error');
            return;
        }

        try {
            const res = await ajaxRequest('dvut_nhan_xet_period_save', formValues);
            if (res.success) {
                Swal.fire({ icon: 'success', title: 'Đã tạo!', timer: 1500, showConfirmButton: false });
                sysLoadNhanXetPeriods();
            } else {
                Swal.fire('Lỗi', res.data?.message || 'Không thể tạo.', 'error');
            }
        } catch (e) {
            Swal.fire('Lỗi', e.message, 'error');
        }
    }

    /**
     * Mở/khóa kỳ đánh giá (admin).
     */
    async function sysToggleNhanXetPeriod(id, action) {
        const label = action === 'open' ? 'MỞ' : 'KHÓA';
        const result = await Swal.fire({
            title: `${label} kỳ đánh giá?`,
            text: action === 'open'
                ? 'BCH Chi Đoàn sẽ có thể nhận xét ĐVƯT trong kỳ này.'
                : 'Nhận xét sẽ bị khóa, chỉ Admin mới sửa được.',
            icon: 'question',
            showCancelButton: true,
            confirmButtonText: label,
            cancelButtonText: 'Hủy',
            confirmButtonColor: action === 'open' ? '#198754' : '#dc3545',
        });

        if (!result.isConfirmed) return;

        try {
            const res = await ajaxRequest('dvut_nhan_xet_period_toggle', { id, toggle_action: action });
            if (res.success) {
                Swal.fire({ icon: 'success', title: 'Thành công!', timer: 1500, showConfirmButton: false });
                sysLoadNhanXetPeriods();
            } else {
                Swal.fire('Lỗi', res.data?.message || 'Không thể thực hiện.', 'error');
            }
        } catch (e) {
            Swal.fire('Lỗi', e.message, 'error');
        }
    }

    /**
     * Load nhận xét cho đoàn viên (hiển thị trong khối "Đánh giá định kỳ").
     * Gọi từ populateProfileForDoanVien khi trạng thái >= DA_CONG_NHAN.
     */
    async function loadNhanXetForDoanVien(dvutId) {
        const container = document.getElementById('dv-reviews-list');
        if (!container) return;
        container.innerHTML = '<p class="text-muted text-center">Đang tải nhận xét...</p>';

        try {
            const res = await ajaxRequest('dvut_nhan_xet_list', { dvut_id: dvutId || 0 });
            if (!res.success) throw new Error(res.data?.message || 'Lỗi');

            const items = res.data.items || [];
            if (items.length === 0) {
                container.innerHTML = '<p class="text-muted text-center" style="padding:16px;">Chưa có nhận xét nào cho bạn.</p>';
                return;
            }

            container.innerHTML = items.map(nx => `
                <div style="border:1px solid #e9ecef; border-radius:12px; padding:16px; margin-bottom:12px; background:#fafbfc;">
                    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:10px;">
                        <strong style="color:#174f8c; font-size:.92em;">
                            📋 ${esc(nx.ten_ky || 'Kỳ đánh giá')}
                        </strong>
                        <span style="font-size:.78em; color:#888;">Q${nx.quy} / ${esc(nx.nam_hoc || '')}</span>
                    </div>
                    ${nx.pham_chat ? `<div style="margin-bottom:8px;"><span style="font-weight:600;color:#555;font-size:.84em;">Phẩm chất đạo đức:</span><p style="margin:4px 0 0;color:#333;font-size:.88em;line-height:1.5;">${esc(nx.pham_chat)}</p></div>` : ''}
                    ${nx.nang_luc ? `<div style="margin-bottom:8px;"><span style="font-weight:600;color:#555;font-size:.84em;">Năng lực:</span><p style="margin:4px 0 0;color:#333;font-size:.88em;line-height:1.5;">${esc(nx.nang_luc)}</p></div>` : ''}
                    ${nx.quan_he ? `<div style="margin-bottom:8px;"><span style="font-weight:600;color:#555;font-size:.84em;">Quan hệ quần chúng:</span><p style="margin:4px 0 0;color:#333;font-size:.88em;line-height:1.5;">${esc(nx.quan_he)}</p></div>` : ''}
                    ${nx.tong_hop ? `<div style="border-top:1px solid #e9ecef;padding-top:8px;margin-top:8px;"><span style="font-weight:600;color:#174f8c;font-size:.84em;">Kết luận:</span><p style="margin:4px 0 0;color:#333;font-size:.88em;line-height:1.5;">${esc(nx.tong_hop)}</p></div>` : ''}
                </div>
            `).join('');
        } catch (e) {
            container.innerHTML = `<p class="text-center" style="color:#dc3545;">${esc(e.message)}</p>`;
        }
    }

    /**
     * BCH Chi Đoàn: Mở form nhận xét cho 1 đoàn viên.
     * Gọi từ nút "Nhận xét" trong bảng danh sách ĐVƯT.
     */
    async function openNhanXetModal(dvutId, hoTen) {
        // Load periods
        let periods = [];
        try {
            const pRes = await ajaxRequest('dvut_nhan_xet_periods', {});
            if (pRes.success) periods = (pRes.data.periods || []).filter(p => p.is_open == 1);
        } catch (e) { /* ignore */ }

        if (periods.length === 0) {
            Swal.fire('Thông báo', 'Hiện tại không có kỳ đánh giá nào đang mở. Vui lòng liên hệ Admin Đoàn Trường.', 'info');
            return;
        }

        // Load existing review for this dvut + latest open period
        let existing = null;
        try {
            const nxRes = await ajaxRequest('dvut_nhan_xet_list', { dvut_id: dvutId, period_id: periods[0].id });
            if (nxRes.success && nxRes.data.items && nxRes.data.items.length > 0) {
                existing = nxRes.data.items[0];
            }
        } catch (e) { /* ignore */ }

        const periodOptions = periods.map(p =>
            `<option value="${p.id}" ${p.id == periods[0].id ? 'selected' : ''}>${esc(p.ten_ky)}</option>`
        ).join('');

        const { value: formResult } = await Swal.fire({
            title: `Nhận xét — ${esc(hoTen)}`,
            width: 650,
            html: `
                <div style="text-align:left;">
                    <div class="form-group" style="margin-bottom:12px;">
                        <label style="font-weight:600;margin-bottom:4px;display:block;">Kỳ đánh giá</label>
                        <select id="swal-nx-period" class="swal2-select" style="width:100%;">${periodOptions}</select>
                    </div>
                    <div class="form-group" style="margin-bottom:12px;">
                        <label style="font-weight:600;margin-bottom:4px;display:block;">Phẩm chất đạo đức</label>
                        <textarea id="swal-nx-phamchat" class="swal2-textarea" rows="2" style="width:100%;margin:0;" placeholder="Nhận xét về phẩm chất, đạo đức...">${esc(existing?.pham_chat || '')}</textarea>
                    </div>
                    <div class="form-group" style="margin-bottom:12px;">
                        <label style="font-weight:600;margin-bottom:4px;display:block;">Năng lực</label>
                        <textarea id="swal-nx-nangluc" class="swal2-textarea" rows="2" style="width:100%;margin:0;" placeholder="Nhận xét về năng lực hoạt động...">${esc(existing?.nang_luc || '')}</textarea>
                    </div>
                    <div class="form-group" style="margin-bottom:12px;">
                        <label style="font-weight:600;margin-bottom:4px;display:block;">Quan hệ quần chúng</label>
                        <textarea id="swal-nx-quanhe" class="swal2-textarea" rows="2" style="width:100%;margin:0;" placeholder="Nhận xét về quan hệ quần chúng...">${esc(existing?.quan_he || '')}</textarea>
                    </div>
                    <div class="form-group" style="margin-bottom:12px;">
                        <label style="font-weight:600;margin-bottom:4px;display:block;">Kết luận / Tổng hợp</label>
                        <textarea id="swal-nx-tonghop" class="swal2-textarea" rows="2" style="width:100%;margin:0;" placeholder="Đánh giá tổng hợp...">${esc(existing?.tong_hop || '')}</textarea>
                    </div>
                </div>
            `,
            showCancelButton: true,
            confirmButtonText: 'Lưu nhận xét',
            cancelButtonText: 'Hủy',
            confirmButtonColor: '#174f8c',
            preConfirm: () => ({
                period_id: document.getElementById('swal-nx-period').value,
                pham_chat: document.getElementById('swal-nx-phamchat').value.trim(),
                nang_luc: document.getElementById('swal-nx-nangluc').value.trim(),
                quan_he: document.getElementById('swal-nx-quanhe').value.trim(),
                tong_hop: document.getElementById('swal-nx-tonghop').value.trim(),
            }),
        });

        if (!formResult) return;

        try {
            const res = await ajaxRequest('dvut_nhan_xet_save', {
                dvut_id: dvutId,
                ...formResult,
            });
            if (res.success) {
                Swal.fire({ icon: 'success', title: 'Đã lưu nhận xét!', timer: 1500, showConfirmButton: false });
            } else {
                Swal.fire('Lỗi', res.data?.message || 'Không thể lưu.', 'error');
            }
        } catch (e) {
            Swal.fire('Lỗi', e.message, 'error');
        }
    }

    // =================================================================
    // BATCH ACTION FUNCTIONS
    // =================================================================

    /**
     * Helper: gửi AJAX batch với danh sách ids + FormData.
     */
    async function sendBatchRequest(action, extraData, files) {
        const fd = new FormData();
        fd.append('action', action);
        fd.append('_ajax_nonce', dvutNonce);
        selectedIds.forEach(id => fd.append('ids[]', id));
        if (extraData) Object.keys(extraData).forEach(k => fd.append(k, extraData[k]));
        if (files) Object.keys(files).forEach(k => { if (files[k]) fd.append(k, files[k]); });
        return fetch(ajaxUrl, { method: 'POST', body: fd }).then(r => r.json());
    }

    /**
     * Helper: gắn drag-and-drop vào một upload area giống cá nhân.
     */
    function attachDragDrop(areaId, inputId, nameId) {
        const area  = document.getElementById(areaId);
        const input = document.getElementById(inputId);
        const name  = document.getElementById(nameId);
        if (!area || !input) return;
        area.addEventListener('click', () => input.click());
        ['dragenter', 'dragover'].forEach(evt => area.addEventListener(evt, (e) => { e.preventDefault(); area.classList.add('dragover'); }));
        ['dragleave', 'drop'].forEach(evt => area.addEventListener(evt, (e) => { e.preventDefault(); area.classList.remove('dragover'); }));
        area.addEventListener('drop', (e) => {
            if (e.dataTransfer.files.length) { input.files = e.dataTransfer.files; if (name) name.textContent = e.dataTransfer.files[0].name; }
        });
        input.addEventListener('change', () => { if (input.files.length && name) name.textContent = input.files[0].name; });
    }

    /**
     * Helper: gắn live vote calculator giống cá nhân.
     * Trả về calcVote() để có thể gọi lại.
     */
    function attachVoteCalc(tongId, dongYId, resultId, ratioId, labelId, passLabel, failLabel) {
        const tongEl   = document.getElementById(tongId);
        const dongYEl  = document.getElementById(dongYId);
        const resultEl = document.getElementById(resultId);
        const ratioEl  = document.getElementById(ratioId);
        const labelEl  = document.getElementById(labelId);
        function calcVote() {
            const tong = parseInt(tongEl.value) || 0;
            const dong = parseInt(dongYEl.value) || 0;
            if (tong > 0 && dong >= 0) {
                const ratio = ((dong / tong) * 100).toFixed(2);
                ratioEl.textContent = `${ratio}%`;
                resultEl.style.display = 'block';
                resultEl.classList.remove('vote-pass', 'vote-fail');
                if (parseFloat(ratio) > 50) {
                    resultEl.classList.add('vote-pass');
                    labelEl.textContent = passLabel;
                } else {
                    resultEl.classList.add('vote-fail');
                    labelEl.textContent = failLabel;
                }
            } else {
                resultEl.style.display = 'none';
            }
        }
        tongEl.addEventListener('input', calcVote);
        dongYEl.addEventListener('input', calcVote);
        calcVote();
        return calcVote;
    }

    /**
     * Helper: tạo HTML danh sách hồ sơ đã chọn (hiện tối đa 5 dòng + "và N hồ sơ khác").
     */
    function buildSelectedListHtml(maxShow = 5) {
        const ids = [...selectedIds];
        const rows = ids.slice(0, maxShow).map(id => {
            const dv = allDoanVien.find(d => d.id === id || String(d.id) === String(id));
            if (!dv) return '';
            return `<tr><td style="padding:4px 8px; font-weight:600; color:#174f8c;">${esc(dv.ho_ten)}</td><td style="padding:4px 8px; color:#666;">${esc(dv.mssv)}</td><td style="padding:4px 8px; color:#888; font-size:0.8em;">${esc(dv.chi_doan)}</td></tr>`;
        }).join('');
        const rest = ids.length > maxShow ? `<tr><td colspan="3" style="padding:4px 8px; color:#888; font-style:italic;">… và ${ids.length - maxShow} hồ sơ khác</td></tr>` : '';
        return `<table style="width:100%;border-collapse:collapse;font-size:0.85em;margin-bottom:14px;">
            <thead><tr style="background:#f1f5f9;">
                <th style="padding:5px 8px;text-align:left;color:#374151;font-weight:700;">Họ tên</th>
                <th style="padding:5px 8px;text-align:left;color:#374151;font-weight:700;">MSSV</th>
                <th style="padding:5px 8px;text-align:left;color:#374151;font-weight:700;">Chi đoàn</th>
            </tr></thead>
            <tbody>${rows}${rest}</tbody>
        </table>`;
    }

    /**
     * BATCH: Chi Đoàn biểu quyết nhiều hồ sơ cùng một kết quả BQ.
     * Giao diện & điều kiện giống openChiDoanDuyetModal.
     */
    async function batchCDDuyet() {
        if (selectedIds.size === 0) return;

        let chiDoanMemberCount = 0;

        const { value: formData } = await Swal.fire({
            title: 'Chi Đoàn Biểu quyết hàng loạt',
            html: `
                <div style="text-align:left; font-family:'Montserrat',sans-serif;">
                    <div style="background:#eff6ff; border:1px solid #bfdbfe; border-radius:8px; padding:10px 14px; margin-bottom:14px; font-size:0.85em; color:#1e40af;">
                        <strong>📋 ${selectedIds.size} hồ sơ đã chọn</strong> — kết quả biểu quyết sẽ áp dụng cho tất cả.
                    </div>
                    ${buildSelectedListHtml(4)}

                    <div class="form-group">
                        <label>Tổng số Đoàn viên tham dự <span style="color:red;">*</span></label>
                        <input type="number" id="swal-b-tong" min="1" placeholder="Nhập tổng số người" class="swal2-input" style="margin:0;">
                        <div id="swal-b-hop-den-info" style="font-size:12px; color:#888; margin-top:6px; min-height:18px;">⏳ Đang tải dữ liệu Hộp đen...</div>
                    </div>
                    <div class="form-group">
                        <label>Số lượt đồng ý <span style="color:red;">*</span></label>
                        <input type="number" id="swal-b-dongY" min="0" placeholder="Nhập số lượt biểu quyết đồng ý" class="swal2-input" style="margin:0;">
                    </div>
                    <div class="vote-result-display" id="swal-b-vote-result" style="display:none;">
                        <div class="vote-ratio" id="swal-b-vote-ratio">0%</div>
                        <div class="vote-label" id="swal-b-vote-label">Tỷ lệ biểu quyết</div>
                    </div>

                    <div class="form-group" style="margin-top:16px;">
                        <label>Biên bản Hội nghị Chi Đoàn — Mẫu 03 <span style="color:red;">*</span></label>
                        <small>Upload file PDF hoặc DOCX (tối đa 5MB).</small>
                        <div class="swal-file-upload-area" id="swal-b-m03-area">
                            <span class="dashicons dashicons-media-document"></span>
                            <p>Nhấp hoặc kéo thả file Biên bản vào đây</p>
                            <div class="file-name" id="swal-b-m03-name"></div>
                        </div>
                        <input type="file" id="swal-b-m03-input" accept=".pdf,.doc,.docx" style="display:none;">
                    </div>
                </div>
            `,
            showCancelButton: true,
            confirmButtonText: 'Xác nhận kết quả',
            cancelButtonText: 'Hủy',
            width: 560,
            didOpen: () => {
                const calcVote = attachVoteCalc(
                    'swal-b-tong', 'swal-b-dongY',
                    'swal-b-vote-result', 'swal-b-vote-ratio', 'swal-b-vote-label',
                    '✓ Đạt tỷ lệ > 50% — Đủ điều kiện',
                    '✗ Không đạt tỷ lệ > 50%'
                );
                attachDragDrop('swal-b-m03-area', 'swal-b-m03-input', 'swal-b-m03-name');

                // Tự động lấy số lượng đoàn viên từ Hộp đen (theo chi đoàn của user)
                if (currentChiDoanId) {
                    ajaxRequest('dvut_get_chi_doan_member_count', { chi_doan_id: currentChiDoanId })
                        .then(cntRes => {
                            const infoEl = document.getElementById('swal-b-hop-den-info');
                            if (!infoEl) return;
                            if (cntRes?.success && cntRes.data?.total_members != null) {
                                const cnt = cntRes.data.total_members;
                                chiDoanMemberCount = cnt;
                                const minRequiredDisplay = Math.floor(cnt * 2 / 3);
                                infoEl.textContent = `Tổng số đoàn viên: ${cnt} (tối thiểu tham dự: ${minRequiredDisplay})`;
                                const tongEl = document.getElementById('swal-b-tong');
                                if (tongEl && !tongEl.value) { tongEl.value = cnt; calcVote(); }
                            } else {
                                infoEl.textContent = '(Không có dữ liệu từ Hộp đen)';
                            }
                        })
                        .catch(() => {
                            const infoEl = document.getElementById('swal-b-hop-den-info');
                            if (infoEl) infoEl.textContent = '(Không thể tải dữ liệu Hộp đen)';
                        });
                } else {
                    const infoEl = document.getElementById('swal-b-hop-den-info');
                    if (infoEl) infoEl.textContent = '';
                }
            },
            preConfirm: () => {
                const tong_so_nguoi  = parseInt(document.getElementById('swal-b-tong').value);
                const so_luot_dong_y = parseInt(document.getElementById('swal-b-dongY').value);
                if (!tong_so_nguoi || tong_so_nguoi < 1) {
                    Swal.showValidationMessage('Vui lòng nhập tổng số đoàn viên tham dự'); return false;
                }
                if (chiDoanMemberCount > 0) {
                    const minRequired = Math.floor(chiDoanMemberCount * 2 / 3);
                    if (tong_so_nguoi < minRequired) {
                        Swal.showValidationMessage(`Số đoàn viên tham dự phải đạt tối thiểu 2/3 tổng số đoàn viên chi đoàn (tối thiểu ${minRequired}/${chiDoanMemberCount})`);
                        return false;
                    }
                }
                if (isNaN(so_luot_dong_y) || so_luot_dong_y < 0) {
                    Swal.showValidationMessage('Vui lòng nhập số lượt đồng ý hợp lệ'); return false;
                }
                if (so_luot_dong_y > tong_so_nguoi) {
                    Swal.showValidationMessage('Số lượt đồng ý không thể lớn hơn tổng số người'); return false;
                }
                const m03Input = document.getElementById('swal-b-m03-input');
                if (!m03Input.files.length) {
                    Swal.showValidationMessage('Vui lòng upload Biên bản Hội nghị Chi Đoàn (Mẫu 03)'); return false;
                }
                return { tong_so_nguoi, so_luot_dong_y, bien_ban_chi_doan: m03Input.files[0] };
            }
        });

        if (!formData) return;

        const ratio  = ((formData.so_luot_dong_y / formData.tong_so_nguoi) * 100).toFixed(2);
        const isPass = parseFloat(ratio) > 50;

        const confirm = await Swal.fire({
            icon: isPass ? 'question' : 'warning',
            title: isPass ? `Chuyển ${selectedIds.size} hồ sơ lên Đoàn Khoa?` : 'Không đạt tỷ lệ!',
            html: `Kết quả biểu quyết: <strong>${ratio}%</strong> (${formData.so_luot_dong_y}/${formData.tong_so_nguoi})<br><br>
                <strong>${selectedIds.size} hồ sơ</strong> sẽ được ${isPass
                    ? 'chuyển lên <strong style="color:#198754;">Đoàn Khoa</strong> để xét duyệt.'
                    : 'chuyển sang trạng thái <strong style="color:#dc3545;">Từ chối</strong>.'}`,
            showCancelButton: true,
            confirmButtonText: isPass ? `Chuyển lên Đoàn Khoa (${selectedIds.size})` : `Xác nhận từ chối (${selectedIds.size})`,
            cancelButtonText: 'Quay lại',
            confirmButtonColor: isPass ? '#174f8c' : '#dc3545',
        });

        if (!confirm.isConfirmed) return;

        Swal.fire({ title: 'Đang xử lý...', allowOutsideClick: false, didOpen: () => Swal.showLoading() });
        const res = await sendBatchRequest('dvut_batch_chi_doan_duyet',
            { tong_so_nguoi: formData.tong_so_nguoi, so_luot_dong_y: formData.so_luot_dong_y },
            { bien_ban_chi_doan: formData.bien_ban_chi_doan }
        );
        if (res.success) {
            clearSelection();
            await loadData();
            Swal.fire({ icon: isPass ? 'success' : 'info', title: isPass ? 'Đã chuyển lên Đoàn Khoa!' : 'Hồ sơ bị từ chối', text: res.data.message, timer: 2500, showConfirmButton: false });
        } else {
            Swal.fire({ icon: 'error', title: 'Lỗi', text: res.data?.message || 'Có lỗi xảy ra.' });
        }
    }

    /**
     * BATCH: Đoàn Khoa phê duyệt nhiều hồ sơ CHO_DOAN_KHOA cùng lúc.
     * Giao diện & điều kiện giống doanKhoaPheduyet.
     */
    async function batchDKDuyet() {
        if (selectedIds.size === 0) return;

        const { value: formData } = await Swal.fire({
            title: 'Duyệt Công nhận ĐVƯT hàng loạt',
            html: `
                <div style="text-align:left; font-family:'Montserrat',sans-serif;">
                    <div style="background:#eff6ff; border:1px solid #bfdbfe; border-radius:8px; padding:10px 14px; margin-bottom:14px; font-size:0.85em; color:#1e40af;">
                        <strong>📋 ${selectedIds.size} hồ sơ đã chọn</strong> — kết quả BQ BCH sẽ áp dụng cho tất cả.
                    </div>
                    ${buildSelectedListHtml(4)}

                    <div class="form-group">
                        <label>Tổng số Ủy viên BCH có mặt <span style="color:red;">*</span></label>
                        <input type="number" id="swal-bdk-tong-uv" min="1" placeholder="Nhập tổng số UV BCH có mặt" class="swal2-input" style="margin:0;">
                    </div>
                    <div class="form-group">
                        <label>Số phiếu đồng ý <span style="color:red;">*</span></label>
                        <input type="number" id="swal-bdk-phieu-dy" min="0" placeholder="Nhập số phiếu đồng ý" class="swal2-input" style="margin:0;">
                    </div>
                    <div class="vote-result-display" id="swal-bdk-vote-result" style="display:none;">
                        <div class="vote-ratio" id="swal-bdk-vote-ratio">0%</div>
                        <div class="vote-label" id="swal-bdk-vote-label">Tỷ lệ biểu quyết BCH</div>
                    </div>

                    <div class="form-group" style="margin-top:16px;">
                        <label>Công văn đề nghị — Mẫu 04 <span style="color:red;">*</span></label>
                        <small>Upload file PDF hoặc DOCX (tối đa 5MB).</small>
                        <div class="swal-file-upload-area" id="swal-bdk-m04-area">
                            <span class="dashicons dashicons-media-document"></span>
                            <p>Nhấp hoặc kéo thả file vào đây</p>
                            <div class="file-name" id="swal-bdk-m04-name"></div>
                        </div>
                        <input type="file" id="swal-bdk-m04-input" accept=".pdf,.doc,.docx" style="display:none;">
                    </div>

                    <div class="form-group" style="margin-top:12px;">
                        <label>Biên bản họp BCH Đoàn Khoa — Mẫu 05 <span style="color:red;">*</span></label>
                        <small>Upload file PDF hoặc DOCX (tối đa 5MB).</small>
                        <div class="swal-file-upload-area" id="swal-bdk-m05-area">
                            <span class="dashicons dashicons-media-document"></span>
                            <p>Nhấp hoặc kéo thả file vào đây</p>
                            <div class="file-name" id="swal-bdk-m05-name"></div>
                        </div>
                        <input type="file" id="swal-bdk-m05-input" accept=".pdf,.doc,.docx" style="display:none;">
                    </div>
                </div>
            `,
            showCancelButton: true,
            confirmButtonText: 'Xác nhận kết quả',
            cancelButtonText: 'Hủy',
            width: 620,
            didOpen: () => {
                attachVoteCalc(
                    'swal-bdk-tong-uv', 'swal-bdk-phieu-dy',
                    'swal-bdk-vote-result', 'swal-bdk-vote-ratio', 'swal-bdk-vote-label',
                    '✓ Đạt tỷ lệ > 50% — Đủ điều kiện công nhận',
                    '✗ Không đạt tỷ lệ > 50% — Hồ sơ sẽ bị từ chối'
                );
                attachDragDrop('swal-bdk-m04-area', 'swal-bdk-m04-input', 'swal-bdk-m04-name');
                attachDragDrop('swal-bdk-m05-area', 'swal-bdk-m05-input', 'swal-bdk-m05-name');
            },
            preConfirm: () => {
                const tong_so_uy_vien = parseInt(document.getElementById('swal-bdk-tong-uv').value);
                const so_phieu_dong_y = parseInt(document.getElementById('swal-bdk-phieu-dy').value);
                if (!tong_so_uy_vien || tong_so_uy_vien < 1) {
                    Swal.showValidationMessage('Vui lòng nhập tổng số Ủy viên BCH có mặt'); return false;
                }
                if (isNaN(so_phieu_dong_y) || so_phieu_dong_y < 0) {
                    Swal.showValidationMessage('Vui lòng nhập số phiếu đồng ý hợp lệ'); return false;
                }
                if (so_phieu_dong_y > tong_so_uy_vien) {
                    Swal.showValidationMessage('Số phiếu đồng ý không thể lớn hơn tổng số Ủy viên'); return false;
                }
                const m04File = document.getElementById('swal-bdk-m04-input');
                if (!m04File.files.length) {
                    Swal.showValidationMessage('Vui lòng upload Công văn đề nghị (Mẫu 04)'); return false;
                }
                const m05File = document.getElementById('swal-bdk-m05-input');
                if (!m05File.files.length) {
                    Swal.showValidationMessage('Vui lòng upload Biên bản họp BCH Đoàn Khoa (Mẫu 05)'); return false;
                }
                return { tong_so_uy_vien, so_phieu_dong_y, cong_van_dk: m04File.files[0], bien_ban_dk: m05File.files[0] };
            }
        });

        if (!formData) return;

        const ratio  = ((formData.so_phieu_dong_y / formData.tong_so_uy_vien) * 100).toFixed(2);
        const isPass = parseFloat(ratio) > 50;

        const confirm = await Swal.fire({
            icon: isPass ? 'question' : 'warning',
            title: isPass ? `Chuyển ${selectedIds.size} hồ sơ lên Đoàn Trường?` : 'Không đạt tỷ lệ!',
            html: `Kết quả biểu quyết BCH: <strong>${ratio}%</strong> (${formData.so_phieu_dong_y}/${formData.tong_so_uy_vien})<br><br>
                <strong>${selectedIds.size} hồ sơ</strong> sẽ được ${isPass
                    ? '<strong style="color:#198754;">chuyển lên Đoàn Trường</strong> để ban hành QĐ.'
                    : 'chuyển sang trạng thái <strong style="color:#dc3545;">Từ chối</strong>.'}`,
            showCancelButton: true,
            confirmButtonText: isPass ? `Chuyển lên Đoàn Trường (${selectedIds.size})` : `Xác nhận từ chối (${selectedIds.size})`,
            cancelButtonText: 'Quay lại',
            confirmButtonColor: isPass ? '#174f8c' : '#dc3545',
        });

        if (!confirm.isConfirmed) return;

        Swal.fire({ title: 'Đang xử lý...', allowOutsideClick: false, didOpen: () => Swal.showLoading() });
        const res = await sendBatchRequest('dvut_batch_doan_khoa_duyet',
            { tong_so_dk: formData.tong_so_uy_vien, so_luot_dong_y_dk: formData.so_phieu_dong_y },
            { cong_van_dk: formData.cong_van_dk, bien_ban_dk: formData.bien_ban_dk }
        );
        if (res.success) {
            clearSelection();
            await loadData();
            Swal.fire({ icon: isPass ? 'success' : 'info', title: isPass ? 'Đã chuyển lên Đoàn Trường!' : 'Hồ sơ bị từ chối', text: res.data.message, timer: 2500, showConfirmButton: false });
        } else {
            Swal.fire({ icon: 'error', title: 'Lỗi', text: res.data?.message || 'Có lỗi xảy ra.' });
        }
    }

    /**
     * BATCH: Đoàn Khoa ra Nghị quyết GT vào Đảng hàng loạt.
     * Giao diện & điều kiện giống doanKhoaDuyetGioiThieu.
     */
    async function batchDKDuyetGT() {
        if (selectedIds.size === 0) return;

        const { value: formData } = await Swal.fire({
            title: 'Duyệt Giới thiệu vào Đảng hàng loạt',
            html: `
                <div style="text-align:left; font-family:'Montserrat',sans-serif;">
                    <div style="background:#f5f0ff; border:1px solid #d8b4fe; border-radius:8px; padding:10px 14px; margin-bottom:14px; font-size:0.85em; color:#6d28d9;">
                        <strong>🚩 ${selectedIds.size} hồ sơ đã chọn</strong> — kết quả BQ BCH sẽ áp dụng cho tất cả.
                    </div>
                    ${buildSelectedListHtml(4)}

                    <div class="form-group">
                        <label>Tổng số Ủy viên BCH Đoàn Khoa có mặt <span style="color:red;">*</span></label>
                        <input type="number" id="swal-bgt-tong-uv" min="1" placeholder="Nhập tổng số UV BCH có mặt" class="swal2-input" style="margin:0;">
                    </div>
                    <div class="form-group">
                        <label>Số phiếu đồng ý <span style="color:red;">*</span></label>
                        <input type="number" id="swal-bgt-phieu-dy" min="0" placeholder="Nhập số phiếu đồng ý" class="swal2-input" style="margin:0;">
                    </div>
                    <div class="vote-result-display" id="swal-bgt-vote-result" style="display:none;">
                        <div class="vote-ratio" id="swal-bgt-vote-ratio">0%</div>
                        <div class="vote-label" id="swal-bgt-vote-label">Tỷ lệ biểu quyết BCH</div>
                    </div>

                    <div class="form-group" style="margin-top:16px;">
                        <label>Tóm tắt ưu điểm / khuyết điểm <span style="color:#888; font-weight:400;">(tùy chọn)</span></label>
                        <textarea id="swal-bgt-nhan-xet" rows="3" placeholder="Nhập tóm tắt ưu điểm, khuyết điểm chung..." style="width:100%; padding:10px; border:1px solid #ddd; border-radius:8px; font-family:'Montserrat',sans-serif; margin-top:8px; resize:vertical; box-sizing:border-box;"></textarea>
                    </div>

                    <div class="form-group" style="margin-top:12px;">
                        <label>Nghị quyết giới thiệu ĐVƯT vào Đảng — Mẫu 07 <span style="color:red;">*</span></label>
                        <small>Upload file PDF hoặc DOCX (tối đa 5MB).</small>
                        <div class="swal-file-upload-area" id="swal-bgt-m07-area">
                            <span class="dashicons dashicons-media-document"></span>
                            <p>Nhấp hoặc kéo thả file Nghị quyết vào đây</p>
                            <div class="file-name" id="swal-bgt-m07-name"></div>
                        </div>
                        <input type="file" id="swal-bgt-m07-input" accept=".pdf,.doc,.docx" style="display:none;">
                    </div>
                </div>
            `,
            showCancelButton: true,
            confirmButtonText: 'Xác nhận kết quả',
            cancelButtonText: 'Hủy',
            width: 620,
            didOpen: () => {
                attachVoteCalc(
                    'swal-bgt-tong-uv', 'swal-bgt-phieu-dy',
                    'swal-bgt-vote-result', 'swal-bgt-vote-ratio', 'swal-bgt-vote-label',
                    '✓ Đạt tỷ lệ > 50% — Đủ điều kiện giới thiệu vào Đảng',
                    '✗ Không đạt tỷ lệ > 50%'
                );
                attachDragDrop('swal-bgt-m07-area', 'swal-bgt-m07-input', 'swal-bgt-m07-name');
            },
            preConfirm: () => {
                const tong_so_uy_vien = parseInt(document.getElementById('swal-bgt-tong-uv').value);
                const so_phieu_dong_y = parseInt(document.getElementById('swal-bgt-phieu-dy').value);
                if (!tong_so_uy_vien || tong_so_uy_vien < 1) {
                    Swal.showValidationMessage('Vui lòng nhập tổng số Ủy viên BCH có mặt'); return false;
                }
                if (isNaN(so_phieu_dong_y) || so_phieu_dong_y < 0) {
                    Swal.showValidationMessage('Vui lòng nhập số phiếu đồng ý hợp lệ'); return false;
                }
                if (so_phieu_dong_y > tong_so_uy_vien) {
                    Swal.showValidationMessage('Số phiếu đồng ý không thể lớn hơn tổng số Ủy viên'); return false;
                }
                const m07File = document.getElementById('swal-bgt-m07-input');
                if (!m07File.files.length) {
                    Swal.showValidationMessage('Vui lòng upload Nghị quyết giới thiệu ĐVƯT vào Đảng (Mẫu 07)'); return false;
                }
                return { tong_so_uy_vien, so_phieu_dong_y, nhan_xet: document.getElementById('swal-bgt-nhan-xet').value.trim(), nghi_quyet_dk: m07File.files[0] };
            }
        });

        if (!formData) return;

        const ratio  = ((formData.so_phieu_dong_y / formData.tong_so_uy_vien) * 100).toFixed(2);
        const isPass = parseFloat(ratio) > 50;

        const confirm = await Swal.fire({
            icon: isPass ? 'question' : 'warning',
            title: isPass ? `Chuyển ${selectedIds.size} hồ sơ lên Đoàn Trường xác nhận GT?` : 'Không đạt tỷ lệ!',
            html: `Kết quả biểu quyết BCH Đoàn Khoa: <strong>${ratio}%</strong> (${formData.so_phieu_dong_y}/${formData.tong_so_uy_vien})<br><br>
                <strong>${selectedIds.size} hồ sơ</strong> sẽ được ${isPass
                    ? '<strong style="color:#198754;">chuyển lên Đoàn Trường</strong> xác nhận giới thiệu Đảng.'
                    : 'chuyển sang trạng thái <strong style="color:#dc3545;">Từ chối</strong>.'}`,
            showCancelButton: true,
            confirmButtonText: isPass ? `Chuyển lên Đoàn Trường (${selectedIds.size})` : `Xác nhận từ chối (${selectedIds.size})`,
            cancelButtonText: 'Quay lại',
            confirmButtonColor: isPass ? '#6f42c1' : '#dc3545',
        });

        if (!confirm.isConfirmed) return;

        Swal.fire({ title: 'Đang xử lý...', allowOutsideClick: false, didOpen: () => Swal.showLoading() });
        const res = await sendBatchRequest('dvut_batch_dk_duyet_gioi_thieu',
            { tong_so_uy_vien: formData.tong_so_uy_vien, so_phieu_dong_y: formData.so_phieu_dong_y, nhan_xet: formData.nhan_xet },
            { nghi_quyet_dk: formData.nghi_quyet_dk }
        );
        if (res.success) {
            clearSelection();
            await loadData();
            Swal.fire({ icon: isPass ? 'success' : 'info', title: isPass ? 'Đã chuyển lên Đoàn Trường xác nhận GT Đảng!' : 'Hồ sơ bị từ chối', text: res.data.message, timer: 2500, showConfirmButton: false });
        } else {
            Swal.fire({ icon: 'error', title: 'Lỗi', text: res.data?.message || 'Có lỗi xảy ra.' });
        }
    }

    /**
     * BATCH: Đoàn Khoa xác nhận Chuyển Đảng chính thức hàng loạt.
     * Giao diện & điều kiện giống doanKhoaDuyetChuyenDang.
     */
    async function batchDKDuyetCD() {
        if (selectedIds.size === 0) return;

        const { value: formData } = await Swal.fire({
            title: 'Duyệt Chuyển Đảng chính thức hàng loạt',
            html: `
                <div style="text-align:left; font-family:'Montserrat',sans-serif;">
                    <div style="background:#fff1f2; border:1px solid #fecdd3; border-radius:8px; padding:10px 14px; margin-bottom:14px; font-size:0.85em; color:#be123c;">
                        <strong>⭐ ${selectedIds.size} hồ sơ đã chọn</strong> — kết quả BQ BCH sẽ áp dụng cho tất cả.
                    </div>
                    ${buildSelectedListHtml(4)}

                    <div class="form-group">
                        <label>Tổng số Ủy viên BCH Đoàn Khoa có mặt <span style="color:red;">*</span></label>
                        <input type="number" id="swal-bcd-tong-uv" min="1" placeholder="Nhập tổng số UV BCH có mặt" class="swal2-input" style="margin:0;">
                    </div>
                    <div class="form-group">
                        <label>Số phiếu đồng ý <span style="color:red;">*</span></label>
                        <input type="number" id="swal-bcd-phieu-dy" min="0" placeholder="Nhập số phiếu đồng ý" class="swal2-input" style="margin:0;">
                    </div>
                    <div class="vote-result-display" id="swal-bcd-vote-result" style="display:none;">
                        <div class="vote-ratio" id="swal-bcd-vote-ratio">0%</div>
                        <div class="vote-label" id="swal-bcd-vote-label">Tỷ lệ biểu quyết BCH</div>
                    </div>

                    <div class="form-group" style="margin-top:16px;">
                        <label>Ý kiến nhận xét — Mẫu 09 <span style="color:red;">*</span></label>
                        <small>Upload file PDF hoặc DOCX (tối đa 5MB).</small>
                        <div class="swal-file-upload-area" id="swal-bcd-m09-area">
                            <span class="dashicons dashicons-media-document"></span>
                            <p>Nhấp hoặc kéo thả file Ý kiến nhận xét vào đây</p>
                            <div class="file-name" id="swal-bcd-m09-name"></div>
                        </div>
                        <input type="file" id="swal-bcd-m09-input" accept=".pdf,.doc,.docx" style="display:none;">
                    </div>
                </div>
            `,
            showCancelButton: true,
            confirmButtonText: 'Xác nhận kết quả',
            cancelButtonText: 'Hủy',
            width: 620,
            didOpen: () => {
                attachVoteCalc(
                    'swal-bcd-tong-uv', 'swal-bcd-phieu-dy',
                    'swal-bcd-vote-result', 'swal-bcd-vote-ratio', 'swal-bcd-vote-label',
                    '✓ Đạt tỷ lệ > 50% — Đủ điều kiện chuyển Đảng chính thức',
                    '✗ Không đạt tỷ lệ > 50%'
                );
                attachDragDrop('swal-bcd-m09-area', 'swal-bcd-m09-input', 'swal-bcd-m09-name');
            },
            preConfirm: () => {
                const tong_so_uy_vien = parseInt(document.getElementById('swal-bcd-tong-uv').value);
                const so_phieu_dong_y = parseInt(document.getElementById('swal-bcd-phieu-dy').value);
                if (!tong_so_uy_vien || tong_so_uy_vien < 1) {
                    Swal.showValidationMessage('Vui lòng nhập tổng số Ủy viên BCH có mặt'); return false;
                }
                if (isNaN(so_phieu_dong_y) || so_phieu_dong_y < 0) {
                    Swal.showValidationMessage('Vui lòng nhập số phiếu đồng ý hợp lệ'); return false;
                }
                if (so_phieu_dong_y > tong_so_uy_vien) {
                    Swal.showValidationMessage('Số phiếu đồng ý không thể lớn hơn tổng số Ủy viên'); return false;
                }
                const m09File = document.getElementById('swal-bcd-m09-input');
                if (!m09File.files.length) {
                    Swal.showValidationMessage('Vui lòng upload Ý kiến nhận xét (Mẫu 09)'); return false;
                }
                return { tong_so_uy_vien, so_phieu_dong_y, y_kien_dk: m09File.files[0] };
            }
        });

        if (!formData) return;

        const ratio  = ((formData.so_phieu_dong_y / formData.tong_so_uy_vien) * 100).toFixed(2);
        const isPass = parseFloat(ratio) > 50;

        const confirm = await Swal.fire({
            icon: isPass ? 'question' : 'warning',
            title: isPass ? `Chuyển ${selectedIds.size} hồ sơ lên Đoàn Trường xác nhận CĐ?` : 'Không đạt tỷ lệ!',
            html: `Kết quả biểu quyết BCH Đoàn Khoa: <strong>${ratio}%</strong> (${formData.so_phieu_dong_y}/${formData.tong_so_uy_vien})<br><br>
                <strong>${selectedIds.size} hồ sơ</strong> sẽ được ${isPass
                    ? '<strong style="color:#198754;">chuyển lên Đoàn Trường</strong> xác nhận chuyển Đảng.'
                    : 'chuyển sang trạng thái <strong style="color:#dc3545;">Từ chối</strong>.'}`,
            showCancelButton: true,
            confirmButtonText: isPass ? `Chuyển lên Đoàn Trường (${selectedIds.size})` : `Xác nhận từ chối (${selectedIds.size})`,
            cancelButtonText: 'Quay lại',
            confirmButtonColor: isPass ? '#dc3545' : '#6c757d',
        });

        if (!confirm.isConfirmed) return;

        Swal.fire({ title: 'Đang xử lý...', allowOutsideClick: false, didOpen: () => Swal.showLoading() });
        const res = await sendBatchRequest('dvut_batch_dk_duyet_chuyen_dang',
            { tong_so_uy_vien: formData.tong_so_uy_vien, so_phieu_dong_y: formData.so_phieu_dong_y },
            { y_kien_dk: formData.y_kien_dk }
        );
        if (res.success) {
            clearSelection();
            await loadData();
            Swal.fire({ icon: isPass ? 'success' : 'info', title: isPass ? 'Đã chuyển lên Đoàn Trường xác nhận CĐ!' : 'Hồ sơ bị từ chối', text: res.data.message, timer: 2500, showConfirmButton: false });
        } else {
            Swal.fire({ icon: 'error', title: 'Lỗi', text: res.data?.message || 'Có lỗi xảy ra.' });
        }
    }

    /**
     * BATCH: Admin ban hành một QĐ chung cho nhiều hồ sơ.
     * Giao diện & điều kiện giống adminBanHanhQuyetDinh.
     */
    async function batchBanHanhQD() {
        if (selectedIds.size === 0) return;

        const { value: formData } = await Swal.fire({
            title: 'Ban hành Quyết định Công nhận ĐVƯT hàng loạt',
            html: `
                <div style="text-align:left; font-size:.9em; font-family:'Montserrat',sans-serif;">
                    <p style="margin-bottom:10px;">Một Quyết định chung sẽ được ban hành cho <strong style="color:#174f8c;">${selectedIds.size} Đoàn viên Ưu tú</strong>.</p>
                    ${buildSelectedListHtml(5)}

                    <div style="margin-bottom:12px;">
                        <label style="font-weight:600; color:#555; display:block; margin-bottom:4px;">Số Quyết định <span style="color:#888; font-weight:400;">(tùy chọn)</span></label>
                        <input id="swal-bqd-so" type="text" placeholder="VD: 01/QĐ-ĐTr" style="width:100%; padding:8px 10px; border:1px solid #dee2e6; border-radius:6px; font-size:.9em; box-sizing:border-box;">
                    </div>

                    <div>
                        <label style="font-weight:600; color:#174f8c; display:block; margin-bottom:6px;">📎 File Quyết định công nhận <span style="color:#dc3545;">*</span></label>
                        <div id="swal-bqd-area" style="border:2px dashed #174f8c; border-radius:8px; padding:18px; text-align:center; cursor:pointer; background:#f0f6ff; transition:background .2s;">
                            <div style="font-size:1.6em; margin-bottom:4px;">📄</div>
                            <div style="color:#174f8c; font-weight:600; font-size:.88em;">Kéo thả hoặc nhấn để chọn file QĐ</div>
                            <div id="swal-bqd-name" style="margin-top:6px; color:#198754; font-size:.85em; font-weight:600;"></div>
                            <input id="swal-bqd-input" type="file" accept=".pdf,.doc,.docx" style="display:none;">
                        </div>
                    </div>
                </div>
            `,
            width: 560,
            showCancelButton: true,
            confirmButtonText: `Ban hành QĐ cho ${selectedIds.size} hồ sơ`,
            cancelButtonText: 'Hủy',
            confirmButtonColor: '#174f8c',
            didOpen: () => {
                const area  = document.getElementById('swal-bqd-area');
                const input = document.getElementById('swal-bqd-input');
                const name  = document.getElementById('swal-bqd-name');
                area.addEventListener('click', () => input.click());
                ['dragover', 'dragenter'].forEach(evt => area.addEventListener(evt, (e) => { e.preventDefault(); area.style.background = '#dbeafe'; }));
                ['dragleave', 'dragend'].forEach(evt => area.addEventListener(evt, (e) => { e.preventDefault(); area.style.background = '#f0f6ff'; }));
                area.addEventListener('drop', (e) => {
                    e.preventDefault(); area.style.background = '#f0f6ff';
                    if (e.dataTransfer.files.length) { input.files = e.dataTransfer.files; name.textContent = e.dataTransfer.files[0].name; }
                });
                input.addEventListener('change', () => { if (input.files.length) name.textContent = input.files[0].name; });
            },
            preConfirm: () => {
                const qdInput = document.getElementById('swal-bqd-input');
                if (!qdInput.files.length) {
                    Swal.showValidationMessage('Vui lòng đính kèm file Quyết định công nhận'); return false;
                }
                return { so_quyet_dinh: document.getElementById('swal-bqd-so').value.trim(), file_qd_cong_nhan: qdInput.files[0] };
            }
        });

        if (!formData) return;

        Swal.fire({ title: 'Đang xử lý...', allowOutsideClick: false, didOpen: () => Swal.showLoading() });
        const res = await sendBatchRequest('dvut_batch_doan_truong_cong_nhan',
            { so_quyet_dinh: formData.so_quyet_dinh },
            { file_qd_cong_nhan: formData.file_qd_cong_nhan }
        );
        if (res.success) {
            clearSelection();
            await loadData();
            Swal.fire({ icon: 'success', title: 'Đã ban hành Quyết định!', text: res.data.message, timer: 2500, showConfirmButton: false });
        } else {
            Swal.fire({ icon: 'error', title: 'Lỗi', text: res.data?.message || 'Có lỗi xảy ra.' });
        }
    }


    // =================================================================
    // PUBLIC API (Exposed to global scope for onclick handlers)
    // =================================================================
    const _prev = window.DvutApp || {};
    window.DvutApp = Object.assign(_prev, {
        getAllData: () => [...allDoanVien],
        getVisibleData: () => getVisibleDoanVien(),
        getExcelData: () => [],  // Stub — blackbox data is on server, charts use fallback
        getVisibleChiDoan: () => getVisibleChiDoan(),
        getChiDoanList: () => [...CHI_DOAN_LIST],
        getRole: () => currentRole,
        /** Chỉ Quản trị viên (is_admin) mới được xem Hồ sơ cá nhân; Ban thường vụ đoàn trường không có chức năng này */
        getCanViewPersonalProfile: () => currentRole === 'admin_doan_truong' && isDevAdmin,
        getChiDoanId: () => currentChiDoanId,
        getKhoaId: () => currentKhoaId,
        nopHoSo: openNopHoSoModal,
        chiDoanDuyet: openChiDoanDuyetModal,
        doanKhoaDuyet: doanKhoaPheduyet,
        doanKhoaTuChoi: doanKhoaTuChoi,
        tuChoiHoSo: tuChoiHoSo,
        xemChiTiet: xemChiTiet,
        xoaHoSo: xoaHoSo,
        gioiThieuDang: openGioiThieuDangModal,
        chuyenDang: openChuyenDangModal,
        updateCamTinhDang: updateCamTinhDang,
        cbCapNhatKetNap: cbCapNhatKetNap,
        dkDuyetGioiThieu: doanKhoaDuyetGioiThieu,
        dkDuyetChuyenDang: doanKhoaDuyetChuyenDang,
        adminBanHanh: adminBanHanhQuyetDinh,
        goTuChoi: goTuChoi,
        deCuLai: deCuLai,
        doanVienUngCuLai: doanVienUngCuLai,

        importExcel: openImportExcel,
        submitHoSoDoanVien: submitHoSoDoanVien,
        // Admin tra cứu hồ sơ
        searchProfileForAdmin: searchProfileForAdmin,
        populateProfileForAdmin: populateProfileForAdmin,
        resetAdminProfileView: resetAdminProfileView,
        // Lịch sử chỉnh sửa
        openHistoryPanel: openHistoryPanel,
        closeHistoryPanel: closeHistoryPanel,

        historyGoPage: historyGoPage,
        getEditHistory: () => [...editHistory],
        goToPage: function (page) {
            currentPage = page;
            renderTable();
            document.getElementById('dvut-table')?.scrollIntoView({ behavior: 'smooth', block: 'start' });
        },
        reload: loadData,
        // Quản lý hệ thống
        sysSearchUsers: sysSearchUsers,
        sysAddUserRole: sysAddUserRole,
        sysEditRole: sysEditRole,
        sysDeleteRole: sysDeleteRole,
        sysHandleImportFile: sysHandleImportFile,
        sysConfirmImport: sysConfirmImport,
        sysCancelImport: sysCancelImport,
        sysLoadTable: sysLoadTable,
        sysEditRecord: sysEditRecord,
        sysLoadLogs: sysLoadLogs,
        // Nhận xét định kỳ
        sysLoadNhanXetPeriods: sysLoadNhanXetPeriods,
        sysAddNhanXetPeriod: sysAddNhanXetPeriod,
        sysToggleNhanXetPeriod: sysToggleNhanXetPeriod,
        openNhanXetModal: openNhanXetModal,
        loadNhanXetForDoanVien: loadNhanXetForDoanVien,
        // Batch actions
        toggleRowCb: toggleRowSelection,
        toggleSelectAll: toggleSelectAll,
        clearSelection: clearSelection,
        batchCDDuyet: batchCDDuyet,
        batchDKDuyet: batchDKDuyet,
        batchDKDuyetGT: batchDKDuyetGT,
        batchDKDuyetCD: batchDKDuyetCD,
        batchBanHanhQD: batchBanHanhQD,
    });

})(jQuery);
