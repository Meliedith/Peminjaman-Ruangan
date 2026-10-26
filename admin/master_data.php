<?php
// admin/master_data.php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth_middleware.php';
require_once __DIR__ . '/../includes/libs/SimpleXLSX.php';
require_once __DIR__ . '/../includes/libs/SimpleXLSXGen.php';

checkAuth('admin');

// Auto-create tables for locations and types if they don't exist
$pdo->exec("CREATE TABLE IF NOT EXISTS tool_locations (id INT AUTO_INCREMENT PRIMARY KEY, name VARCHAR(100) NOT NULL UNIQUE)");
$pdo->exec("CREATE TABLE IF NOT EXISTS tool_types (id INT AUTO_INCREMENT PRIMARY KEY, name VARCHAR(100) NOT NULL UNIQUE)");

// Handle Tool Actions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['type'])) {
    if (!validateCsrfToken()) {
        if (isset($_POST['action']) && $_POST['action'] === 'toggle_active') {
            header('Content-Type: application/json');
            http_response_code(403);
            exit(json_encode(['success' => false, 'error' => 'CSRF token mismatch']));
        }
        setFlashMessage('danger', 'Sesi keamanan tidak valid (CSRF token mismatch). Silakan muat ulang halaman.');
        redirect('/admin/master_data.php');
        exit;
    }

    if ($_POST['type'] === 'tool') {
        $action = $_POST['action'] ?? '';
    
    if ($action === 'add') {
        $kode = trim($_POST['kode'] ?? '');
        $name = trim($_POST['name'] ?? '');
        $jenis = trim($_POST['jenis'] ?? '');
        $lokasi = trim($_POST['lokasi'] ?? '');
        $stock = (int)($_POST['stock'] ?? 0);
        $kondisi = trim($_POST['kondisi'] ?? 'Baik');
        $is_active = isset($_POST['is_active']) ? 1 : 0;

        $image_filename = null;
        if (isset($_FILES['image']) && $_FILES['image']['error'] !== UPLOAD_ERR_NO_FILE) {
            $upload_res = handleToolImageUpload($_FILES['image']);
            if (!$upload_res['success']) {
                setFlashMessage('danger', 'Gagal upload gambar: ' . $upload_res['error']);
                redirect('/admin/master_data.php');
                exit;
            }
            $image_filename = $upload_res['filename'];
        }

        if (!empty($name) && $stock >= 0) {
            $stmt = $pdo->prepare("INSERT INTO tools (kode, name, jenis, lokasi, total_stock, kondisi, is_active, image) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([$kode, $name, $jenis, $lokasi, $stock, $kondisi, $is_active, $image_filename]);
            setFlashMessage('success', 'Alat berhasil ditambahkan.');
        } else {
            if ($image_filename) {
                deleteToolImage($image_filename);
            }
            setFlashMessage('danger', 'Data alat tidak valid.');
        }
        redirect('/admin/master_data.php');
        exit;
    } elseif ($action === 'edit') {
        $id = $_POST['id'] ?? '';
        $kode = trim($_POST['kode'] ?? '');
        $name = trim($_POST['name'] ?? '');
        $jenis = trim($_POST['jenis'] ?? '');
        $lokasi = trim($_POST['lokasi'] ?? '');
        $stock = (int)($_POST['stock'] ?? 0);
        $kondisi = trim($_POST['kondisi'] ?? 'Baik');
        $is_active = isset($_POST['is_active']) ? 1 : 0;
        $delete_image = isset($_POST['delete_image']) && $_POST['delete_image'] == '1';

        // Ambil gambar lama dari database
        $stmt_curr = $pdo->prepare("SELECT image FROM tools WHERE id = ?");
        $stmt_curr->execute([$id]);
        $curr_tool = $stmt_curr->fetch(PDO::FETCH_ASSOC);
        $curr_image = $curr_tool['image'] ?? null;
        $new_image = $curr_image;

        if ($delete_image) {
            deleteToolImage($curr_image);
            $new_image = null;
        }

        if (isset($_FILES['image']) && $_FILES['image']['error'] !== UPLOAD_ERR_NO_FILE) {
            $upload_res = handleToolImageUpload($_FILES['image'], $curr_image);
            if (!$upload_res['success']) {
                setFlashMessage('danger', 'Gagal upload gambar: ' . $upload_res['error']);
                redirect('/admin/master_data.php');
                exit;
            }
            $new_image = $upload_res['filename'];
        }

        if (!empty($name) && $stock >= 0) {
            $stmt = $pdo->prepare("UPDATE tools SET kode = ?, name = ?, jenis = ?, lokasi = ?, total_stock = ?, kondisi = ?, is_active = ?, image = ? WHERE id = ?");
            $stmt->execute([$kode, $name, $jenis, $lokasi, $stock, $kondisi, $is_active, $new_image, $id]);
            setFlashMessage('success', 'Alat berhasil diubah.');
        } else {
            setFlashMessage('danger', 'Data alat tidak valid.');
        }
        redirect('/admin/master_data.php');
        exit;
    } elseif ($action === 'delete') {
        $id = $_POST['id'] ?? '';
        $stmt = $pdo->prepare("UPDATE tools SET deleted_at = NOW() WHERE id = ?");
        $stmt->execute([$id]);
        setFlashMessage('success', 'Alat berhasil dihapus (dipindahkan ke Tempat Sampah). Riwayat peminjaman tetap aman.');
        redirect('/admin/master_data.php');
        exit;
    } elseif ($action === 'restore') {
        $id = $_POST['id'] ?? '';
        $stmt = $pdo->prepare("UPDATE tools SET deleted_at = NULL WHERE id = ?");
        $stmt->execute([$id]);
        setFlashMessage('success', 'Alat berhasil dipulihkan kembali ke daftar aktif.');
        redirect('/admin/master_data.php?view=trash');
        exit;
    } elseif ($action === 'force_delete') {
        $id = $_POST['id'] ?? '';
        // Cek apakah ada riwayat peminjaman
        $stmt_check = $pdo->prepare("SELECT COUNT(*) FROM tool_bookings WHERE tool_id = ?");
        $stmt_check->execute([$id]);
        if ($stmt_check->fetchColumn() > 0) {
            setFlashMessage('danger', 'Gagal: Alat tidak bisa dihapus permanen karena masih tercatat dalam riwayat peminjaman!');
        } else {
            $stmt_img = $pdo->prepare("SELECT image FROM tools WHERE id = ?");
            $stmt_img->execute([$id]);
            $img_to_del = $stmt_img->fetchColumn();
            if ($img_to_del) {
                deleteToolImage($img_to_del);
            }
            $stmt = $pdo->prepare("DELETE FROM tools WHERE id = ?");
            $stmt->execute([$id]);
            setFlashMessage('success', 'Alat berhasil dihapus permanen dari database.');
        }
        redirect('/admin/master_data.php?view=trash');
        exit;
    } elseif ($action === 'toggle_active') {
        $id = $_POST['id'] ?? '';
        $is_active = (int)($_POST['is_active'] ?? 0);
        $stmt = $pdo->prepare("UPDATE tools SET is_active = ? WHERE id = ?");
        $stmt->execute([$is_active, $id]);
        echo json_encode(['success' => true]);
        exit;
    } elseif ($action === 'activate_all') {
        $pdo->exec("UPDATE tools SET is_active = 1 WHERE deleted_at IS NULL");
        setFlashMessage('success', 'Semua alat aktif berhasil diaktifkan.');
        redirect('/admin/master_data.php');
    } elseif ($action === 'deactivate_all') {
        $pdo->exec("UPDATE tools SET is_active = 0 WHERE deleted_at IS NULL");
        setFlashMessage('success', 'Semua alat aktif berhasil dinonaktifkan.');
        redirect('/admin/master_data.php');
    } elseif ($action === 'export') {
        $stmt = $pdo->query("SELECT kode, name, jenis, lokasi, total_stock, kondisi, is_active FROM tools WHERE deleted_at IS NULL ORDER BY name ASC");
        $data = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        $excelData = [];
        $excelData[] = ['Kode', 'Nama Barang', 'Jenis', 'Lokasi', 'Total Stok', 'Kondisi', 'Status Aktif (1/0)'];
        foreach ($data as $row) {
            $excelData[] = [
                sanitizeExcelCell($row['kode']),
                sanitizeExcelCell($row['name']),
                sanitizeExcelCell($row['jenis']),
                sanitizeExcelCell($row['lokasi']),
                (int)$row['total_stock'],
                sanitizeExcelCell($row['kondisi']),
                (int)$row['is_active']
            ];
        }
        
        $xlsx = Shuchkin\SimpleXLSXGen::fromArray($excelData);
        $xlsx->downloadAs('Data_Alat_Lab_' . date('Ymd_His') . '.xlsx');
        exit;
    } elseif ($action === 'import') {
        if (isset($_FILES['file_excel']) && $_FILES['file_excel']['error'] === UPLOAD_ERR_OK) {
            if ($xlsx = Shuchkin\SimpleXLSX::parse($_FILES['file_excel']['tmp_name'])) {
                $rows = $xlsx->rows();
                $inserted = 0;
                $skipped = 0;
                
                // Skip header (index 0)
                for ($i = 1; $i < count($rows); $i++) {
                    $row = $rows[$i];
                    if (empty(trim($row[1]))) {
                        $skipped++;
                        continue; // Skip if Name is empty
                    }
                    
                    $kode = trim($row[0] ?? '');
                    $name = trim($row[1] ?? '');
                    $jenis = trim($row[2] ?? '');
                    $lokasi = trim($row[3] ?? '');
                    $stock = (int)($row[4] ?? 0);
                    $kondisi = trim($row[5] ?? 'Baik');
                    $is_active = (isset($row[6]) && trim($row[6]) !== '') ? (int)$row[6] : 1;
                    
                    try {
                        $stmt = $pdo->prepare("INSERT INTO tools (kode, name, jenis, lokasi, total_stock, kondisi, is_active) VALUES (?, ?, ?, ?, ?, ?, ?)");
                        $stmt->execute([$kode, $name, $jenis, $lokasi, $stock, $kondisi, $is_active]);
                        $inserted++;
                    } catch (PDOException $e) {
                        $skipped++;
                    }
                }
                setFlashMessage('success', "Berhasil mengimpor $inserted alat. Dilewati/Gagal: $skipped.");
            } else {
                setFlashMessage('danger', 'Gagal membaca file Excel. Pastikan formatnya benar (.xlsx).');
            }
        } else {
            setFlashMessage('danger', 'Gagal mengunggah file.');
        }
    }
    
    } elseif ($_POST['type'] === 'lokasi') {
        $action = $_POST['action'] ?? '';
        if ($action === 'add') {
            $name = trim($_POST['name'] ?? '');
            if (!empty($name)) {
                try {
                    $stmt = $pdo->prepare("INSERT INTO tool_locations (name) VALUES (?)");
                    $stmt->execute([$name]);
                    setFlashMessage('success', 'Lokasi berhasil ditambahkan.');
                } catch (PDOException $e) {
                    setFlashMessage('danger', 'Gagal menambahkan lokasi (mungkin sudah ada).');
                }
            }
        } elseif ($action === 'edit') {
            $id = $_POST['id'] ?? '';
            $name = trim($_POST['name'] ?? '');
            if (!empty($id) && !empty($name)) {
                $stmt = $pdo->prepare("UPDATE tool_locations SET name = ? WHERE id = ?");
                $stmt->execute([$name, $id]);
                setFlashMessage('success', 'Lokasi berhasil diperbarui.');
            }
        } elseif ($action === 'delete') {
            $id = $_POST['id'] ?? '';
            $stmt = $pdo->prepare("DELETE FROM tool_locations WHERE id = ?");
            $stmt->execute([$id]);
            setFlashMessage('success', 'Lokasi berhasil dihapus.');
        }
        redirect('/admin/master_data.php');
    } elseif ($_POST['type'] === 'jenis') {
        $action = $_POST['action'] ?? '';
        if ($action === 'add') {
            $name = trim($_POST['name'] ?? '');
            if (!empty($name)) {
                try {
                    $stmt = $pdo->prepare("INSERT INTO tool_types (name) VALUES (?)");
                    $stmt->execute([$name]);
                    setFlashMessage('success', 'Jenis berhasil ditambahkan.');
                } catch (PDOException $e) {
                    setFlashMessage('danger', 'Gagal menambahkan jenis (mungkin sudah ada).');
                }
            }
        } elseif ($action === 'edit') {
            $id = $_POST['id'] ?? '';
            $name = trim($_POST['name'] ?? '');
            if (!empty($id) && !empty($name)) {
                $stmt = $pdo->prepare("UPDATE tool_types SET name = ? WHERE id = ?");
                $stmt->execute([$name, $id]);
                setFlashMessage('success', 'Jenis berhasil diperbarui.');
            }
        } elseif ($action === 'delete') {
            $id = $_POST['id'] ?? '';
            $stmt = $pdo->prepare("DELETE FROM tool_types WHERE id = ?");
            $stmt->execute([$id]);
            setFlashMessage('success', 'Jenis berhasil dihapus.');
        }
        redirect('/admin/master_data.php');
    }
}

// Fetch Distinct Jenis & Lokasi for Filters & Dropdowns
$stmt_jenis = $pdo->query("SELECT id, name FROM tool_types ORDER BY name ASC");
$list_jenis = $stmt_jenis->fetchAll(PDO::FETCH_ASSOC);

// If table is empty, seed it from tools
if (empty($list_jenis)) {
    $stmt_jenis_fallback = $pdo->query("SELECT DISTINCT jenis FROM tools WHERE jenis IS NOT NULL AND jenis != ''");
    $fallback_jenis = $stmt_jenis_fallback->fetchAll(PDO::FETCH_COLUMN);
    foreach ($fallback_jenis as $j) {
        $pdo->prepare("INSERT IGNORE INTO tool_types (name) VALUES (?)")->execute([trim($j)]);
    }
    // Re-fetch
    $stmt_jenis = $pdo->query("SELECT id, name FROM tool_types ORDER BY name ASC");
    $list_jenis = $stmt_jenis->fetchAll(PDO::FETCH_ASSOC);
}

$stmt_lokasi = $pdo->query("SELECT id, name FROM tool_locations ORDER BY name ASC");
$list_lokasi = $stmt_lokasi->fetchAll(PDO::FETCH_ASSOC);

if (empty($list_lokasi)) {
    $stmt_lokasi_fallback = $pdo->query("SELECT DISTINCT lokasi FROM tools WHERE lokasi IS NOT NULL AND lokasi != ''");
    $fallback_lokasi = $stmt_lokasi_fallback->fetchAll(PDO::FETCH_COLUMN);
    foreach ($fallback_lokasi as $l) {
        $pdo->prepare("INSERT IGNORE INTO tool_locations (name) VALUES (?)")->execute([trim($l)]);
    }
    // Re-fetch
    $stmt_lokasi = $pdo->query("SELECT id, name FROM tool_locations ORDER BY name ASC");
    $list_lokasi = $stmt_lokasi->fetchAll(PDO::FETCH_ASSOC);
}

// View Tab Parameter: default = active, 'trash' = deleted items
$view_trash = isset($_GET['view']) && $_GET['view'] === 'trash';

// Get counts for tabs
$trashed_count = (int)$pdo->query("SELECT COUNT(*) FROM tools WHERE deleted_at IS NOT NULL")->fetchColumn();
$active_tools_count = (int)$pdo->query("SELECT COUNT(*) FROM tools WHERE deleted_at IS NULL")->fetchColumn();

// Filter Parameters
$search_query = trim($_GET['search'] ?? '');
$filter_jenis = $_GET['jenis'] ?? '';
$filter_lokasi = $_GET['lokasi'] ?? '';
$where_clauses = [];
$params = [];

if ($view_trash) {
    $where_clauses[] = "tools.deleted_at IS NOT NULL";
} else {
    $where_clauses[] = "tools.deleted_at IS NULL";
}

if (!empty($search_query)) {
    $where_clauses[] = "(tools.name LIKE ? OR tools.kode LIKE ?)";
    $params[] = "%$search_query%";
    $params[] = "%$search_query%";
}
if (!empty($filter_jenis)) {
    $where_clauses[] = "tools.jenis = ?";
    $params[] = $filter_jenis;
}
if (!empty($filter_lokasi)) {
    $where_clauses[] = "tools.lokasi = ?";
    $params[] = $filter_lokasi;
}

$where_sql = count($where_clauses) > 0 ? "WHERE " . implode(" AND ", $where_clauses) : "";

// Sorting Parameters
$allowed_sorts = ['kode', 'name', 'jenis', 'lokasi', 'total_stock', 'kondisi', 'is_active'];
$sort = isset($_GET['sort']) && in_array($_GET['sort'], $allowed_sorts) ? $_GET['sort'] : 'name';
$order = isset($_GET['order']) && strtoupper($_GET['order']) === 'DESC' ? 'DESC' : 'ASC';

// Fetch Tools with dynamic stock calculation
$limit = 10;
$page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$offset = ($page - 1) * $limit;

// Get total count
$stmt_count = $pdo->prepare("SELECT COUNT(*) FROM tools $where_sql");
$stmt_count->execute($params);
$total_records = $stmt_count->fetchColumn();
$total_pages = ceil($total_records / $limit);

$query = "
    SELECT tools.*, 
        (
            SELECT COALESCE(SUM(quantity), 0) 
            FROM tool_bookings 
            WHERE tool_bookings.tool_id = tools.id 
            AND status = 'approved' 
            AND CURDATE() BETWEEN booking_date AND IFNULL(end_date, booking_date) 
            AND CURTIME() BETWEEN start_time AND end_time
        ) as current_borrowed
    FROM tools 
    $where_sql 
    ORDER BY $sort $order
    LIMIT $limit OFFSET $offset
";
$stmt_tools = $pdo->prepare($query);
$stmt_tools->execute($params);
$tools = $stmt_tools->fetchAll();

require_once __DIR__ . '/../includes/header.php';
?>

<h3 class="mb-4">Kelola Master Data</h3>

<div class="card shadow-sm p-4 mb-5">
    <!-- Nav Tabs: Aktif vs Tempat Sampah -->
    <ul class="nav nav-pills mb-4 gap-2">
        <li class="nav-item">
            <a class="nav-link <?= !$view_trash ? 'active' : '' ?>" href="master_data.php">
                📦 Daftar Alat Aktif <span class="badge <?= !$view_trash ? 'bg-light text-dark' : 'bg-secondary' ?> ms-1"><?= $active_tools_count ?></span>
            </a>
        </li>
        <li class="nav-item">
            <a class="nav-link <?= $view_trash ? 'active bg-danger text-white' : 'text-danger' ?>" href="master_data.php?view=trash">
                🗑️ Tempat Sampah <span class="badge <?= $view_trash ? 'bg-light text-danger' : 'bg-danger text-white' ?> ms-1"><?= $trashed_count ?></span>
            </a>
        </li>
    </ul>

    <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center mb-3 gap-3">
        <h4 class="m-0"><?= $view_trash ? 'Tempat Sampah Alat (Terhapus)' : 'Daftar Alat Laboratorium' ?></h4>
        <?php if (!$view_trash): ?>
        <div class="d-flex flex-wrap gap-2">
            <!-- Dropdown Excel -->
            <div class="btn-group">
                <button type="button" class="btn btn-outline-success dropdown-toggle" data-bs-toggle="dropdown" aria-expanded="false">
                    <i class="bi bi-file-earmark-excel"></i> Excel
                </button>
                <ul class="dropdown-menu">
                    <li>
                        <form method="POST" action="" class="m-0">
                            <?= csrfField() ?>
                            <input type="hidden" name="type" value="tool">
                            <input type="hidden" name="action" value="export">
                            <button type="submit" class="dropdown-item">Export XLSX</button>
                        </form>
                    </li>
                    <li>
                        <button type="button" class="dropdown-item" data-bs-toggle="modal" data-bs-target="#importToolModal">Import XLSX</button>
                    </li>
                </ul>
            </div>
            
            <button type="button" class="btn btn-outline-info" data-bs-toggle="modal" data-bs-target="#manageLokasiModal">
                <i class="bi bi-geo-alt"></i> Kelola Lokasi
            </button>
            <button type="button" class="btn btn-outline-primary" data-bs-toggle="modal" data-bs-target="#manageJenisModal">
                <i class="bi bi-tags"></i> Kelola Jenis
            </button>
            <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addToolModal">Tambah Alat</button>
        </div>
        <?php endif; ?>
    </div>
    
    <!-- Filter Form -->
    <form method="GET" action="" class="row g-3 mb-4 bg-light p-3 rounded">
        <?php if ($view_trash): ?>
            <input type="hidden" name="view" value="trash">
        <?php endif; ?>
        <div class="col-md-3">
            <label class="form-label">Cari Alat</label>
            <input type="text" name="search" class="form-control" placeholder="Nama atau Kode..." value="<?= htmlspecialchars($search_query) ?>">
        </div>
        <div class="col-md-3">
            <label class="form-label">Filter Jenis</label>
            <select name="jenis" class="form-select">
                <option value="">Semua Jenis</option>
                <?php foreach($list_jenis as $j): ?>
                    <option value="<?= htmlspecialchars($j['name']) ?>" <?= $filter_jenis === $j['name'] ? 'selected' : '' ?>><?= htmlspecialchars($j['name']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-3">
            <label class="form-label">Filter Lokasi</label>
            <select name="lokasi" class="form-select">
                <option value="">Semua Lokasi</option>
                <?php foreach($list_lokasi as $l): ?>
                    <option value="<?= htmlspecialchars($l['name']) ?>" <?= $filter_lokasi === $l['name'] ? 'selected' : '' ?>><?= htmlspecialchars($l['name']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-3 d-flex align-items-end">
            <button type="submit" class="btn btn-secondary w-100 me-2">Terapkan</button>
            <a href="master_data.php<?= $view_trash ? '?view=trash' : '' ?>" class="btn btn-outline-secondary">Reset</a>
        </div>
    </form>
    
    <?php if (!$view_trash): ?>
    <div class="d-flex mb-3 gap-2">
        <form method="POST" action="" onsubmit="return confirm('Yakin ingin mengaktifkan semua alat?');">
            <?= csrfField() ?>
            <input type="hidden" name="type" value="tool">
            <input type="hidden" name="action" value="activate_all">
            <button type="submit" class="btn btn-sm btn-success"><i class="bi bi-check-circle"></i> Aktifkan Semua</button>
        </form>
        <form method="POST" action="" onsubmit="return confirm('Yakin ingin menonaktifkan semua alat?');">
            <?= csrfField() ?>
            <input type="hidden" name="type" value="tool">
            <input type="hidden" name="action" value="deactivate_all">
            <button type="submit" class="btn btn-sm btn-secondary"><i class="bi bi-x-circle"></i> Nonaktifkan Semua</button>
        </form>
    </div>
    <?php else: ?>
    <div class="alert alert-warning py-2 px-3 mb-3 d-flex align-items-center">
        <span class="me-2 fs-5">ℹ️</span>
        <div><strong>Tempat Sampah Alat:</strong> Alat di bawah ini telah dihapus (soft delete). Riwayat transaksi peminjaman mahasiswa tetap aman dan tidak rusak. Anda dapat memulihkannya kembali kapan saja.</div>
    </div>
    <?php endif; ?>

    <div class="table-responsive">
        <table class="table table-hover align-middle">
            <thead class="table-light">
                <?php 
                function sortLink($column, $label, $current_sort, $current_order, $filter_jenis, $filter_lokasi, $view_trash = false) {
                    $new_order = ($current_sort === $column && $current_order === 'ASC') ? 'DESC' : 'ASC';
                    $icon = '';
                    if ($current_sort === $column) {
                        $icon = $current_order === 'ASC' ? ' <small>&#9650;</small>' : ' <small>&#9660;</small>';
                    } else {
                        $icon = ' <small style="color: #ccc;">&#9830;</small>';
                    }
                    
                    $query_params = http_build_query(array_filter([
                        'view' => $view_trash ? 'trash' : null,
                        'search' => $_GET['search'] ?? '',
                        'jenis' => $filter_jenis,
                        'lokasi' => $filter_lokasi,
                        'sort' => $column,
                        'order' => $new_order
                    ]));
                    return "<a href='?$query_params' class='text-dark text-decoration-none d-flex justify-content-between align-items-center'><span>$label</span>$icon</a>";
                }
                ?>
                <tr>
                    <th style="width: 55px;" class="text-center">Foto</th>
                    <th><?= sortLink('kode', 'Kode', $sort, $order, $filter_jenis, $filter_lokasi, $view_trash) ?></th>
                    <th><?= sortLink('name', 'Nama Barang', $sort, $order, $filter_jenis, $filter_lokasi, $view_trash) ?></th>
                    <th><?= sortLink('jenis', 'Jenis', $sort, $order, $filter_jenis, $filter_lokasi, $view_trash) ?></th>
                    <th><?= sortLink('lokasi', 'Lokasi', $sort, $order, $filter_jenis, $filter_lokasi, $view_trash) ?></th>
                    <th><?= sortLink('total_stock', 'Total', $sort, $order, $filter_jenis, $filter_lokasi, $view_trash) ?></th>
                    <th>Tersedia</th>
                    <th><?= sortLink('kondisi', 'Kondisi', $sort, $order, $filter_jenis, $filter_lokasi, $view_trash) ?></th>
                    <th><?= $view_trash ? 'Waktu Dihapus' : sortLink('is_active', 'Status', $sort, $order, $filter_jenis, $filter_lokasi, $view_trash) ?></th>
                    <th>Aksi</th>
                </tr>
            </thead>
            <tbody>
                <?php if (count($tools) === 0): ?>
                    <tr>
                        <td colspan="10" class="text-center py-4 text-muted">
                            <?= $view_trash ? 'Tempat sampah kosong. Tidak ada alat yang terhapus.' : 'Tidak ada data alat ditemukan.' ?>
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach($tools as $t): 
                        $dynamic_available = max(0, $t['total_stock'] - $t['current_borrowed']);
                        $img_url = getToolImageUrl($t['image'] ?? null);
                    ?>
                        <tr>
                            <td class="text-center align-middle" style="width: 55px; min-width: 55px; max-width: 55px;">
                                <?php if ($img_url): ?>
                                    <img src="<?= $img_url ?>" alt="<?= htmlspecialchars($t['name']) ?>" class="tool-thumb btn-preview-tool" style="width: 44px; height: 44px; min-width: 44px; min-height: 44px; max-width: 44px; max-height: 44px; object-fit: cover; border-radius: 6px; cursor: pointer; display: inline-block; border: 1px solid #dee2e6;" data-img="<?= $img_url ?>" data-name="<?= htmlspecialchars($t['name']) ?>" title="Klik untuk lihat preview">
                                <?php else: ?>
                                    <span class="tool-thumb-placeholder" style="width: 44px; height: 44px; min-width: 44px; min-height: 44px; max-width: 44px; max-height: 44px; display: inline-flex; align-items: center; justify-content: center; border-radius: 6px; border: 1px dashed #ced4da; background: #f8f9fa; color: #adb5bd; font-size: 0.75rem;" title="Tidak ada foto">-</span>
                                <?php endif; ?>
                            </td>
                            <td style="color: #d85c82; font-family: monospace;"><?= htmlspecialchars($t['kode'] ?? '-') ?></td>
                            <td><strong><?= htmlspecialchars($t['name']) ?></strong></td>
                            <td><?= htmlspecialchars($t['jenis'] ?? '-') ?></td>
                            <td><?= htmlspecialchars($t['lokasi'] ?? '-') ?></td>
                            <td><?= htmlspecialchars($t['total_stock']) ?></td>
                            <td>
                                <?php if ($t['current_borrowed'] > 0): ?>
                                    <span class="badge bg-warning text-dark"><?= $dynamic_available ?> (<?= $t['current_borrowed'] ?> dipinjam)</span>
                                <?php else: ?>
                                    <?= $dynamic_available ?>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if(($t['kondisi'] ?? '') === 'Baik'): ?>
                                    <span class="badge bg-info text-dark">Baik</span>
                                <?php elseif(($t['kondisi'] ?? '') === 'Rusak Ringan'): ?>
                                    <span class="badge bg-info text-dark">Rusak Ringan</span>
                                <?php else: ?>
                                    <span class="badge bg-secondary"><?= htmlspecialchars($t['kondisi'] ?? 'Baik') ?></span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if (!$view_trash): ?>
                                    <div class="form-check form-switch">
                                        <input class="form-check-input toggle-active" type="checkbox" data-id="<?= $t['id'] ?>" <?= $t['is_active'] ? 'checked' : '' ?>>
                                        <label class="form-check-label"><?= $t['is_active'] ? 'Aktif' : 'Non-Aktif' ?></label>
                                    </div>
                                <?php else: ?>
                                    <span class="badge bg-danger">Terhapus</span>
                                    <div class="small text-muted" style="font-size: 0.78rem;"><?= htmlspecialchars($t['deleted_at'] ?? '-') ?></div>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if (!$view_trash): ?>
                                    <button type="button" class="btn btn-sm btn-warning" data-bs-toggle="modal" data-bs-target="#editToolModal<?= $t['id'] ?>">Edit</button>
                                    <form method="POST" action="" class="d-inline" onsubmit="return confirm('Pindahkan alat ini ke Tempat Sampah (Soft Delete)? Alat tidak akan muncul di daftar, tetapi histori transaksi tetap aman.');">
                                        <?= csrfField() ?>
                                        <input type="hidden" name="type" value="tool">
                                        <input type="hidden" name="action" value="delete">
                                        <input type="hidden" name="id" value="<?= $t['id'] ?>">
                                        <button type="submit" class="btn btn-sm btn-danger">Hapus</button>
                                    </form>
                                <?php else: ?>
                                    <form method="POST" action="" class="d-inline" onsubmit="return confirm('Pulihkan alat ini kembali ke daftar aktif?');">
                                        <?= csrfField() ?>
                                        <input type="hidden" name="type" value="tool">
                                        <input type="hidden" name="action" value="restore">
                                        <input type="hidden" name="id" value="<?= $t['id'] ?>">
                                        <button type="submit" class="btn btn-sm btn-success">♻️ Pulihkan</button>
                                    </form>
                                    <form method="POST" action="" class="d-inline" onsubmit="return confirm('Hapus permanen alat ini? PERHATIAN: Hanya berhasil jika alat belum pernah dipinjam!');">
                                        <?= csrfField() ?>
                                        <input type="hidden" name="type" value="tool">
                                        <input type="hidden" name="action" value="force_delete">
                                        <input type="hidden" name="id" value="<?= $t['id'] ?>">
                                        <button type="submit" class="btn btn-sm btn-outline-danger">Hapus Permanen</button>
                                    </form>
                                <?php endif; ?>
                            
                            <!-- Edit Modal -->
                            <div class="modal fade" id="editToolModal<?= $t['id'] ?>" tabindex="-1">
                              <div class="modal-dialog">
                                <div class="modal-content">
                                  <form method="POST" action="" enctype="multipart/form-data">
                                      <?= csrfField() ?>
                                      <div class="modal-header">
                                        <h5 class="modal-title">Edit Alat</h5>
                                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                      </div>
                                      <div class="modal-body">
                                        <input type="hidden" name="type" value="tool">
                                        <input type="hidden" name="action" value="edit">
                                        <input type="hidden" name="id" value="<?= $t['id'] ?>">

                                        <div class="mb-3">
                                            <label class="form-label">Kode Barang</label>
                                            <input type="text" name="kode" class="form-control" value="<?= htmlspecialchars($t['kode'] ?? '') ?>">
                                        </div>
                                        <div class="mb-3">
                                            <label class="form-label">Nama Alat</label>
                                            <input type="text" name="name" class="form-control" value="<?= htmlspecialchars($t['name']) ?>" required>
                                        </div>
                                        <div class="mb-3">
                                            <label class="form-label">Foto Alat <small class="text-muted">(Opsional, Max 3MB)</small></label>
                                            <?php if ($img_url): ?>
                                                <div class="d-flex align-items-center gap-3 mb-2 p-2 bg-light rounded border">
                                                    <div class="tool-small-preview-box" style="width: 50px !important; height: 50px !important; max-width: 50px !important; max-height: 50px !important;">
                                                        <img src="<?= $img_url ?>" alt="Foto saat ini" style="width: 50px !important; height: 50px !important;">
                                                    </div>
                                                    <div class="form-check">
                                                        <input class="form-check-input" type="checkbox" name="delete_image" value="1" id="del_img_<?= $t['id'] ?>">
                                                        <label class="form-check-label text-danger small" for="del_img_<?= $t['id'] ?>">
                                                            Hapus foto saat ini
                                                        </label>
                                                    </div>
                                                </div>
                                            <?php endif; ?>
                                            <input type="file" name="image" class="form-control" accept="image/jpeg,image/png,image/webp,image/gif">
                                            <small class="text-muted d-block mt-1">Pilih gambar baru jika ingin mengganti foto.</small>
                                        </div>
                                        <div class="mb-3">
                                            <label class="form-label">Jenis</label>
                                            <select name="jenis" class="form-select">
                                                <option value="">Pilih Jenis</option>
                                                <?php foreach($list_jenis as $j): ?>
                                                    <option value="<?= htmlspecialchars($j['name']) ?>" <?= ($t['jenis'] === $j['name']) ? 'selected' : '' ?>><?= htmlspecialchars($j['name']) ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                        <div class="mb-3">
                                            <label class="form-label">Lokasi</label>
                                            <select name="lokasi" class="form-select">
                                                <option value="">Pilih Lokasi</option>
                                                <?php foreach($list_lokasi as $l): ?>
                                                    <option value="<?= htmlspecialchars($l['name']) ?>" <?= ($t['lokasi'] === $l['name']) ? 'selected' : '' ?>><?= htmlspecialchars($l['name']) ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                        <div class="mb-3">
                                            <label class="form-label">Total Stok</label>
                                            <input type="number" name="stock" class="form-control" value="<?= htmlspecialchars($t['total_stock']) ?>" required min="0">
                                            <small class="text-muted">Ketersediaan akan dihitung secara dinamis dari Total Stok dikurangi jumlah yang sedang dipinjam.</small>
                                        </div>
                                        <div class="mb-3">
                                            <label class="form-label">Kondisi</label>
                                            <select name="kondisi" class="form-select">
                                                <option value="Baik" <?= ($t['kondisi'] ?? '') === 'Baik' ? 'selected' : '' ?>>Baik</option>
                                                <option value="Rusak Ringan" <?= ($t['kondisi'] ?? '') === 'Rusak Ringan' ? 'selected' : '' ?>>Rusak Ringan</option>
                                                <option value="Rusak Berat" <?= ($t['kondisi'] ?? '') === 'Rusak Berat' ? 'selected' : '' ?>>Rusak Berat</option>
                                            </select>
                                        </div>
                                        <div class="mb-3 form-check">
                                            <input type="checkbox" name="is_active" class="form-check-input" id="is_active<?= $t['id'] ?>" <?= $t['is_active'] ? 'checked' : '' ?>>
                                            <label class="form-check-label" for="is_active<?= $t['id'] ?>">Status Aktif (Tampil untuk User)</label>
                                        </div>
                                      </div>
                                      <div class="modal-footer">
                                        <button type="submit" class="btn btn-primary">Simpan Perubahan</button>
                                      </div>
                                  </form>
                                </div>
                              </div>
                            </div>

                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
        
        <div class="d-flex justify-content-between align-items-center mt-3">
            <?php 
                $start_record = ($total_records > 0) ? $offset + 1 : 0;
                $end_record = min($offset + $limit, $total_records);
            ?>
            <div class="text-muted" style="font-size: 0.9rem;">
                Menampilkan <?= $start_record ?> sampai <?= $end_record ?> dari <?= $total_records ?> entri
            </div>
            
            <?php if ($total_pages > 1): ?>
                <?php 
                    renderPagination($page, $total_pages, array_filter([
                        'view' => $view_trash ? 'trash' : null,
                        'search' => $search_query,
                        'jenis' => $filter_jenis,
                        'lokasi' => $filter_lokasi,
                        'sort' => $sort,
                        'order' => $order
                    ]));
                ?>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- Add Modal -->
<div class="modal fade" id="addToolModal" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content">
      <form method="POST" action="" enctype="multipart/form-data">
          <?= csrfField() ?>
          <div class="modal-header">
            <h5 class="modal-title">Tambah Alat Baru</h5>
            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
          </div>
          <div class="modal-body">
            <input type="hidden" name="type" value="tool">
            <input type="hidden" name="action" value="add">

            <div class="mb-3">
                <label class="form-label">Kode Barang</label>
                <input type="text" name="kode" class="form-control">
            </div>
            <div class="mb-3">
                <label class="form-label">Nama Alat</label>
                <input type="text" name="name" class="form-control" required>
            </div>
            <div class="mb-3">
                <label class="form-label">Foto Alat <small class="text-muted">(Opsional, Max 3MB: JPG, PNG, WEBP)</small></label>
                <input type="file" name="image" class="form-control" accept="image/jpeg,image/png,image/webp,image/gif">
            </div>
            <div class="mb-3">
                <label class="form-label">Jenis</label>
                <select name="jenis" class="form-select">
                    <option value="">Pilih Jenis</option>
                    <?php foreach($list_jenis as $j): ?>
                        <option value="<?= htmlspecialchars($j['name']) ?>"><?= htmlspecialchars($j['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="mb-3">
                <label class="form-label">Lokasi</label>
                <select name="lokasi" class="form-select">
                    <option value="">Pilih Lokasi</option>
                    <?php foreach($list_lokasi as $l): ?>
                        <option value="<?= htmlspecialchars($l['name']) ?>"><?= htmlspecialchars($l['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="mb-3">
                <label class="form-label">Total Stok</label>
                <input type="number" name="stock" class="form-control" required min="0" value="1">
                <small class="text-muted">Ketersediaan akan dihitung secara dinamis dari Total Stok dikurangi jumlah yang sedang dipinjam.</small>
            </div>
            <div class="mb-3">
                <label class="form-label">Kondisi</label>
                <select name="kondisi" class="form-select">
                    <option value="Baik">Baik</option>
                    <option value="Rusak Ringan">Rusak Ringan</option>
                    <option value="Rusak Berat">Rusak Berat</option>
                </select>
            </div>
            <div class="mb-3 form-check">
                <input type="checkbox" name="is_active" class="form-check-input" id="is_active_new" checked>
                <label class="form-check-label" for="is_active_new">Status Aktif (Tampil untuk User)</label>
            </div>
          </div>
          <div class="modal-footer">
            <button type="submit" class="btn btn-primary">Simpan Alat</button>
          </div>
      </form>
    </div>
  </div>
</div>

<!-- Import Modal -->
<div class="modal fade" id="importToolModal" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content">
      <form method="POST" action="" enctype="multipart/form-data">
          <?= csrfField() ?>
          <div class="modal-header">
            <h5 class="modal-title">Import Data Alat</h5>
            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
          </div>
          <div class="modal-body">
            <input type="hidden" name="type" value="tool">
            <input type="hidden" name="action" value="import">
            <div class="mb-3">
                <label>File Excel (.xlsx)</label>
                <input type="file" name="file_excel" class="form-control" accept=".xlsx" required>
                <small class="text-muted">Pastikan format kolom sesuai dengan template (bisa diunduh via tombol Export XLSX). Baris pertama akan dianggap sebagai header dan diabaikan.</small>
            </div>
          </div>
          <div class="modal-footer">
            <button type="submit" class="btn btn-success">Mulai Import</button>
          </div>
      </form>
    </div>
  </div>
</div>

<!-- Manage Lokasi Modal -->
<div class="modal fade" id="manageLokasiModal" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content">
        <div class="modal-header">
            <h5 class="modal-title">Kelola Lokasi Alat</h5>
            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
            <form method="POST" action="" class="d-flex mb-3">
                <?= csrfField() ?>
                <input type="hidden" name="type" value="lokasi">
                <input type="hidden" name="action" value="add">
                <input type="text" name="name" class="form-control me-2" placeholder="Nama Lokasi Baru" required>
                <button type="submit" class="btn btn-primary">Tambah</button>
            </form>
            <form id="editLokasiForm" method="POST" action="" style="display:none;">
                <?= csrfField() ?>
                <input type="hidden" name="type" value="lokasi">
                <input type="hidden" name="action" value="edit">
                <input type="hidden" name="id" id="editLokasiId">
                <input type="hidden" name="name" id="editLokasiName">
            </form>
            <ul class="list-group">
                <?php foreach($list_lokasi as $l): ?>
                    <?php if (isset($l['id'])): ?>
                    <li class="list-group-item d-flex justify-content-between align-items-center">
                        <?= htmlspecialchars($l['name']) ?>
                        <div class="d-flex">
                            <button type="button" class="btn btn-sm btn-warning me-1" onclick="editItem('lokasi', <?= $l['id'] ?>, '<?= htmlspecialchars(addslashes($l['name'])) ?>')">Edit</button>
                            <form method="POST" action="" class="m-0" onsubmit="return confirm('Hapus lokasi ini?');">
                                <?= csrfField() ?>
                                <input type="hidden" name="type" value="lokasi">
                                <input type="hidden" name="action" value="delete">
                                <input type="hidden" name="id" value="<?= $l['id'] ?>">
                                <button type="submit" class="btn btn-sm btn-danger">Hapus</button>
                            </form>
                        </div>
                    </li>
                    <?php endif; ?>
                <?php endforeach; ?>
            </ul>
        </div>
    </div>
  </div>
</div>

<!-- Manage Jenis Modal -->
<div class="modal fade" id="manageJenisModal" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content">
        <div class="modal-header">
            <h5 class="modal-title">Kelola Jenis Alat</h5>
            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
            <form method="POST" action="" class="d-flex mb-3">
                <?= csrfField() ?>
                <input type="hidden" name="type" value="jenis">
                <input type="hidden" name="action" value="add">
                <input type="text" name="name" class="form-control me-2" placeholder="Nama Jenis Baru" required>
                <button type="submit" class="btn btn-primary">Tambah</button>
            </form>
            <form id="editJenisForm" method="POST" action="" style="display:none;">
                <?= csrfField() ?>
                <input type="hidden" name="type" value="jenis">
                <input type="hidden" name="action" value="edit">
                <input type="hidden" name="id" id="editJenisId">
                <input type="hidden" name="name" id="editJenisName">
            </form>
            <ul class="list-group">
                <?php foreach($list_jenis as $j): ?>
                    <?php if (isset($j['id'])): ?>
                    <li class="list-group-item d-flex justify-content-between align-items-center">
                        <?= htmlspecialchars($j['name']) ?>
                        <div class="d-flex">
                            <button type="button" class="btn btn-sm btn-warning me-1" onclick="editItem('jenis', <?= $j['id'] ?>, '<?= htmlspecialchars(addslashes($j['name'])) ?>')">Edit</button>
                            <form method="POST" action="" class="m-0" onsubmit="return confirm('Hapus jenis ini?');">
                                <?= csrfField() ?>
                                <input type="hidden" name="type" value="jenis">
                                <input type="hidden" name="action" value="delete">
                                <input type="hidden" name="id" value="<?= $j['id'] ?>">
                                <button type="submit" class="btn btn-sm btn-danger">Hapus</button>
                            </form>
                        </div>
                    </li>
                    <?php endif; ?>
                <?php endforeach; ?>
            </ul>
        </div>
    </div>
  </div>
<!-- Modal Preview Foto Alat (Ukuran Kecil) -->
<div class="modal fade" id="toolPreviewModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-sm modal-dialog-centered" style="max-width: 220px; margin: 1.75rem auto;">
    <div class="modal-content shadow border-0" style="max-width: 220px;">
      <div class="modal-header py-1 px-3 bg-light">
        <h6 class="modal-title text-truncate fw-bold mb-0" id="toolPreviewTitle" style="font-size: 0.85rem;">Preview Alat</h6>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close" style="font-size: 0.7rem;"></button>
      </div>
      <div class="modal-body text-center p-3">
        <div class="tool-small-preview-box mx-auto" style="width: 140px; height: 140px; max-width: 140px; max-height: 140px; border-radius: 8px; overflow: hidden; border: 1px solid #dee2e6; display: flex; align-items: center; justify-content: center; background: #f8f9fa;">
          <img id="toolPreviewImg" src="" alt="Preview Alat" style="width: 140px; height: 140px; max-width: 140px; max-height: 140px; object-fit: cover; display: block;">
        </div>
      </div>
    </div>
  </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
<script>
function editItem(type, id, currentName) {
    let newName = prompt((type === 'lokasi' ? 'Ubah nama lokasi:' : 'Ubah nama jenis:'), currentName);
    if (newName !== null && newName.trim() !== "" && newName !== currentName) {
        if (type === 'lokasi') {
            document.getElementById('editLokasiId').value = id;
            document.getElementById('editLokasiName').value = newName;
            document.getElementById('editLokasiForm').submit();
        } else {
            document.getElementById('editJenisId').value = id;
            document.getElementById('editJenisName').value = newName;
            document.getElementById('editJenisForm').submit();
        }
    }
}

// Modal preview gambar alat (ukuran kecil tetap)
document.querySelectorAll('.btn-preview-tool').forEach(el => {
    el.addEventListener('click', function() {
        const imgUrl = this.getAttribute('data-img');
        const name = this.getAttribute('data-name');
        if (imgUrl) {
            document.getElementById('toolPreviewImg').src = imgUrl;
            document.getElementById('toolPreviewTitle').textContent = name || 'Preview Alat';
            const modalEl = document.getElementById('toolPreviewModal');
            const previewModal = bootstrap.Modal.getOrCreateInstance(modalEl);
            previewModal.show();
        }
    });
});

document.querySelectorAll('.toggle-active').forEach(item => {
    item.addEventListener('change', function() {
        const id = this.getAttribute('data-id');
        const isActive = this.checked ? 1 : 0;
        const label = this.nextElementSibling;
        
        label.textContent = isActive ? 'Aktif' : 'Non-Aktif';
        
        const formData = new FormData();
        formData.append('type', 'tool');
        formData.append('action', 'toggle_active');
        formData.append('id', id);
        formData.append('is_active', isActive);
        formData.append('csrf_token', '<?= generateCsrfToken() ?>');
        
        fetch('', {
            method: 'POST',
            body: formData
        }).then(res => res.json())
          .then(data => {
              if(!data.success) {
                  alert('Gagal mengubah status');
                  this.checked = !isActive;
                  label.textContent = this.checked ? 'Aktif' : 'Non-Aktif';
              }
          })
          .catch(err => {
              alert('Terjadi kesalahan koneksi');
              this.checked = !isActive;
              label.textContent = this.checked ? 'Aktif' : 'Non-Aktif';
          });
    });
});
</script>
