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
        
        if (!empty($name) && $stock >= 0) {
            $stmt = $pdo->prepare("INSERT INTO tools (kode, name, jenis, lokasi, total_stock, kondisi, is_active) VALUES (?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([$kode, $name, $jenis, $lokasi, $stock, $kondisi, $is_active]);
            setFlashMessage('success', 'Alat berhasil ditambahkan.');
        } else {
            setFlashMessage('danger', 'Data alat tidak valid.');
        }
    } elseif ($action === 'edit') {
        $id = $_POST['id'] ?? '';
        $kode = trim($_POST['kode'] ?? '');
        $name = trim($_POST['name'] ?? '');
        $jenis = trim($_POST['jenis'] ?? '');
        $lokasi = trim($_POST['lokasi'] ?? '');
        $stock = (int)($_POST['stock'] ?? 0);
        $kondisi = trim($_POST['kondisi'] ?? 'Baik');
        $is_active = isset($_POST['is_active']) ? 1 : 0;
        
        if (!empty($name) && $stock >= 0) {
            $stmt = $pdo->prepare("UPDATE tools SET kode = ?, name = ?, jenis = ?, lokasi = ?, total_stock = ?, kondisi = ?, is_active = ? WHERE id = ?");
            $stmt->execute([$kode, $name, $jenis, $lokasi, $stock, $kondisi, $is_active, $id]);
            setFlashMessage('success', 'Alat berhasil diubah.');
        }
    } elseif ($action === 'delete') {
        $id = $_POST['id'] ?? '';
        // Try to delete if not referenced, otherwise soft delete (deactivate)
        try {
            $stmt = $pdo->prepare("DELETE FROM tools WHERE id = ?");
            $stmt->execute([$id]);
            setFlashMessage('success', 'Alat berhasil dihapus.');
        } catch (PDOException $e) {
            $stmt = $pdo->prepare("UPDATE tools SET is_active = 0 WHERE id = ?");
            $stmt->execute([$id]);
            setFlashMessage('warning', 'Alat tidak bisa dihapus permanen karena ada histori peminjaman. Status diubah menjadi non-aktif.');
        }
    } elseif ($action === 'export') {
        $stmt = $pdo->query("SELECT kode, name, jenis, lokasi, total_stock, kondisi, is_active FROM tools ORDER BY name ASC");
        $data = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        $excelData = [];
        $excelData[] = ['Kode', 'Nama Barang', 'Jenis', 'Lokasi', 'Total Stok', 'Kondisi', 'Status Aktif (1/0)'];
        foreach ($data as $row) {
            $excelData[] = [$row['kode'], $row['name'], $row['jenis'], $row['lokasi'], $row['total_stock'], $row['kondisi'], $row['is_active']];
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

// Filter Parameters
$search_query = trim($_GET['search'] ?? '');
$filter_jenis = $_GET['jenis'] ?? '';
$filter_lokasi = $_GET['lokasi'] ?? '';
$where_clauses = [];
$params = [];

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
            AND booking_date = CURDATE() 
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
    <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center mb-3 gap-3">
        <h4 class="m-0">Daftar Alat Laboratorium</h4>
        <div class="d-flex flex-wrap gap-2">
            <!-- Dropdown Excel -->
            <div class="btn-group">
                <button type="button" class="btn btn-outline-success dropdown-toggle" data-bs-toggle="dropdown" aria-expanded="false">
                    <i class="bi bi-file-earmark-excel"></i> Excel
                </button>
                <ul class="dropdown-menu">
                    <li>
                        <form method="POST" action="" class="m-0">
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
    </div>
    
    <!-- Filter Form -->
    <form method="GET" action="" class="row g-3 mb-4 bg-light p-3 rounded">
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
            <a href="master_data.php" class="btn btn-outline-secondary">Reset</a>
        </div>
    </form>
    
    <div class="table-responsive">
        <table class="table table-hover align-middle">
            <thead class="table-light">
                <?php 
                function sortLink($column, $label, $current_sort, $current_order, $filter_jenis, $filter_lokasi) {
                    $new_order = ($current_sort === $column && $current_order === 'ASC') ? 'DESC' : 'ASC';
                    $icon = '';
                    if ($current_sort === $column) {
                        $icon = $current_order === 'ASC' ? ' <small>&#9650;</small>' : ' <small>&#9660;</small>';
                    } else {
                        $icon = ' <small style="color: #ccc;">&#9830;</small>';
                    }
                    
                    $query_params = http_build_query(array_filter([
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
                    <th><?= sortLink('kode', 'Kode', $sort, $order, $filter_jenis, $filter_lokasi) ?></th>
                    <th><?= sortLink('name', 'Nama Barang', $sort, $order, $filter_jenis, $filter_lokasi) ?></th>
                    <th><?= sortLink('jenis', 'Jenis', $sort, $order, $filter_jenis, $filter_lokasi) ?></th>
                    <th><?= sortLink('lokasi', 'Lokasi', $sort, $order, $filter_jenis, $filter_lokasi) ?></th>
                    <th><?= sortLink('total_stock', 'Total', $sort, $order, $filter_jenis, $filter_lokasi) ?></th>
                    <th>Tersedia</th>
                    <th><?= sortLink('kondisi', 'Kondisi', $sort, $order, $filter_jenis, $filter_lokasi) ?></th>
                    <th><?= sortLink('is_active', 'Status', $sort, $order, $filter_jenis, $filter_lokasi) ?></th>
                    <th>Aksi</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach($tools as $t): 
                    $dynamic_available = max(0, $t['total_stock'] - $t['current_borrowed']);
                ?>
                    <tr>
                        <td style="color: #d85c82; font-family: monospace;"><?= htmlspecialchars($t['kode'] ?? '-') ?></td>
                        <td><?= htmlspecialchars($t['name']) ?></td>
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
                            <?php if($t['is_active']): ?>
                                <span class="badge bg-success">Tersedia</span>
                            <?php else: ?>
                                <span class="badge bg-secondary">Tidak Tersedia</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <button type="button" class="btn btn-sm btn-warning" data-bs-toggle="modal" data-bs-target="#editToolModal<?= $t['id'] ?>">Edit</button>
                            <form method="POST" action="" class="d-inline" onsubmit="return confirm('Hapus / Nonaktifkan alat ini?');">
                                <input type="hidden" name="type" value="tool">
                                <input type="hidden" name="action" value="delete">
                                <input type="hidden" name="id" value="<?= $t['id'] ?>">
                                <button type="submit" class="btn btn-sm btn-danger">Hapus</button>
                            </form>
                            
                            <!-- Edit Modal -->
                            <div class="modal fade" id="editToolModal<?= $t['id'] ?>" tabindex="-1">
                              <div class="modal-dialog">
                                <div class="modal-content">
                                  <form method="POST" action="">
                                      <div class="modal-header">
                                        <h5 class="modal-title">Edit Alat</h5>
                                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                      </div>
                                      <div class="modal-body">
                                        <input type="hidden" name="type" value="tool">
                                        <input type="hidden" name="action" value="edit">
                                        <input type="hidden" name="id" value="<?= $t['id'] ?>">
                                        <div class="mb-3">
                                            <label>Kode Barang</label>
                                            <input type="text" name="kode" class="form-control" value="<?= htmlspecialchars($t['kode'] ?? '') ?>">
                                        </div>
                                        <div class="mb-3">
                                            <label>Nama Alat</label>
                                            <input type="text" name="name" class="form-control" value="<?= htmlspecialchars($t['name']) ?>" required>
                                        </div>
                                        <div class="mb-3">
                                            <label>Jenis</label>
                                            <select name="jenis" class="form-select">
                                                <option value="">Pilih Jenis</option>
                                                <?php foreach($list_jenis as $j): ?>
                                                    <option value="<?= htmlspecialchars($j['name']) ?>" <?= ($t['jenis'] === $j['name']) ? 'selected' : '' ?>><?= htmlspecialchars($j['name']) ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                        <div class="mb-3">
                                            <label>Lokasi</label>
                                            <select name="lokasi" class="form-select">
                                                <option value="">Pilih Lokasi</option>
                                                <?php foreach($list_lokasi as $l): ?>
                                                    <option value="<?= htmlspecialchars($l['name']) ?>" <?= ($t['lokasi'] === $l['name']) ? 'selected' : '' ?>><?= htmlspecialchars($l['name']) ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                        <div class="mb-3">
                                            <label>Total Stok</label>
                                            <input type="number" name="stock" class="form-control" value="<?= htmlspecialchars($t['total_stock']) ?>" required min="0">
                                            <small class="text-muted">Ketersediaan akan dihitung secara dinamis dari Total Stok dikurangi jumlah yang sedang dipinjam.</small>
                                        </div>
                                        <div class="mb-3">
                                            <label>Kondisi</label>
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
            <nav aria-label="Page navigation">
                <ul class="pagination pagination-sm m-0">
                    <?php 
                        $query_params = array_filter([
                            'search' => $search_query,
                            'jenis' => $filter_jenis,
                            'lokasi' => $filter_lokasi,
                            'sort' => $sort,
                            'order' => $order
                        ]);
                    ?>
                    
                    <!-- Sebelumnya -->
                    <li class="page-item <?= ($page <= 1) ? 'disabled' : '' ?>">
                        <a class="page-link" href="?<?= http_build_query(array_merge($query_params, ['page' => $page - 1])) ?>">Sebelumnya</a>
                    </li>
                    
                    <!-- Page Numbers -->
                    <?php 
                        $start_page = max(1, $page - 2);
                        $end_page = min($total_pages, $page + 2);
                        
                        if ($start_page > 1) {
                            echo '<li class="page-item"><a class="page-link" href="?' . http_build_query(array_merge($query_params, ['page' => 1])) . '">1</a></li>';
                            if ($start_page > 2) {
                                echo '<li class="page-item disabled"><span class="page-link">...</span></li>';
                            }
                        }
                        
                        for ($i = $start_page; $i <= $end_page; $i++) {
                            $active = ($i === $page) ? 'active' : '';
                            echo '<li class="page-item ' . $active . '"><a class="page-link" href="?' . http_build_query(array_merge($query_params, ['page' => $i])) . '">' . $i . '</a></li>';
                        }
                        
                        if ($end_page < $total_pages) {
                            if ($end_page < $total_pages - 1) {
                                echo '<li class="page-item disabled"><span class="page-link">...</span></li>';
                            }
                            echo '<li class="page-item"><a class="page-link" href="?' . http_build_query(array_merge($query_params, ['page' => $total_pages])) . '">' . $total_pages . '</a></li>';
                        }
                    ?>
                    
                    <!-- Selanjutnya -->
                    <li class="page-item <?= ($page >= $total_pages) ? 'disabled' : '' ?>">
                        <a class="page-link" href="?<?= http_build_query(array_merge($query_params, ['page' => $page + 1])) ?>">Selanjutnya</a>
                    </li>
                </ul>
            </nav>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- Add Modal -->
<div class="modal fade" id="addToolModal" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content">
      <form method="POST" action="">
          <div class="modal-header">
            <h5 class="modal-title">Tambah Alat Baru</h5>
            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
          </div>
          <div class="modal-body">
            <input type="hidden" name="type" value="tool">
            <input type="hidden" name="action" value="add">
            <div class="mb-3">
                <label>Kode Barang</label>
                <input type="text" name="kode" class="form-control">
            </div>
            <div class="mb-3">
                <label>Nama Alat</label>
                <input type="text" name="name" class="form-control" required>
            </div>
            <div class="mb-3">
                <label>Jenis</label>
                <select name="jenis" class="form-select">
                    <option value="">Pilih Jenis</option>
                    <?php foreach($list_jenis as $j): ?>
                        <option value="<?= htmlspecialchars($j['name']) ?>"><?= htmlspecialchars($j['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="mb-3">
                <label>Lokasi</label>
                <select name="lokasi" class="form-select">
                    <option value="">Pilih Lokasi</option>
                    <?php foreach($list_lokasi as $l): ?>
                        <option value="<?= htmlspecialchars($l['name']) ?>"><?= htmlspecialchars($l['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="mb-3">
                <label>Total Stok</label>
                <input type="number" name="stock" class="form-control" required min="0" value="1">
                <small class="text-muted">Ketersediaan akan dihitung secara dinamis dari Total Stok dikurangi jumlah yang sedang dipinjam.</small>
            </div>
            <div class="mb-3">
                <label>Kondisi</label>
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
                <input type="hidden" name="type" value="lokasi">
                <input type="hidden" name="action" value="add">
                <input type="text" name="name" class="form-control me-2" placeholder="Nama Lokasi Baru" required>
                <button type="submit" class="btn btn-primary">Tambah</button>
            </form>
            <form id="editLokasiForm" method="POST" action="" style="display:none;">
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
                <input type="hidden" name="type" value="jenis">
                <input type="hidden" name="action" value="add">
                <input type="text" name="name" class="form-control me-2" placeholder="Nama Jenis Baru" required>
                <button type="submit" class="btn btn-primary">Tambah</button>
            </form>
            <form id="editJenisForm" method="POST" action="" style="display:none;">
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
</script>
