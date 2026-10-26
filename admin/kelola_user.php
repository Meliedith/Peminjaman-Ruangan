<?php
// admin/kelola_user.php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth_middleware.php';

checkAuth('admin');

$current_admin_id = (int)$_SESSION['user_id'];

// Handle Actions (Add, Edit Role/Data, Delete)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validateCsrfToken()) {
        setFlashMessage('danger', 'Sesi keamanan tidak valid (CSRF token mismatch). Silakan muat ulang halaman.');
        redirect('/admin/kelola_user.php');
    }

    $action = $_POST['action'] ?? '';

    if ($action === 'add') {
        $name = trim($_POST['name'] ?? '');
        $email = trim(strtolower($_POST['email'] ?? ''));
        $phone = trim($_POST['phone'] ?? '');
        $role = $_POST['role'] ?? 'user';
        $password = trim($_POST['password'] ?? '');

        // Validation
        if (empty($name)) {
            setFlashMessage('danger', 'Nama lengkap wajib diisi.');
        } elseif (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            setFlashMessage('danger', 'Format email tidak valid.');
        } elseif (!in_array($role, ['user', 'admin'])) {
            setFlashMessage('danger', 'Pilihan role tidak valid.');
        } else {
            // Check if email already exists
            $stmt_check = $pdo->prepare("SELECT id FROM users WHERE email = ?");
            $stmt_check->execute([$email]);
            if ($stmt_check->fetch()) {
                setFlashMessage('danger', 'Email sudah terdaftar di sistem. Gunakan email lain.');
            } else {
                // Hash password if provided, else use empty string or default hash
                $password_hash = !empty($password) ? password_hash($password, PASSWORD_DEFAULT) : '';
                
                $stmt_insert = $pdo->prepare("INSERT INTO users (name, email, password, role, phone) VALUES (?, ?, ?, ?, ?)");
                $stmt_insert->execute([$name, $email, $password_hash, $role, $phone]);

                setFlashMessage('success', "Pengguna baru berhasil ditambahkan sebagai " . ($role === 'admin' ? 'Administrator' : 'Mahasiswa') . ".");
            }
        }
        redirect('/admin/kelola_user.php');
    }

    elseif ($action === 'edit') {
        $id = (int)($_POST['id'] ?? 0);
        $name = trim($_POST['name'] ?? '');
        $phone = trim($_POST['phone'] ?? '');
        $role = $_POST['role'] ?? 'user';

        if ($id <= 0 || empty($name)) {
            setFlashMessage('danger', 'Data pengguna tidak valid.');
        } elseif (!in_array($role, ['user', 'admin'])) {
            setFlashMessage('danger', 'Pilihan role tidak valid.');
        } elseif ($id === $current_admin_id && $role !== 'admin') {
            // Prevent admin from demoting themselves
            setFlashMessage('danger', 'Anda tidak dapat menurunkan role akun Anda sendiri menjadi Mahasiswa.');
        } else {
            $stmt_update = $pdo->prepare("UPDATE users SET name = ?, phone = ?, role = ? WHERE id = ?");
            $stmt_update->execute([$name, $phone, $role, $id]);

            // If updating currently logged in user, update session name
            if ($id === $current_admin_id) {
                $_SESSION['name'] = $name;
            }

            setFlashMessage('success', 'Data dan role pengguna berhasil diperbarui.');
        }
        redirect('/admin/kelola_user.php');
    }

    elseif ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);

        if ($id <= 0) {
            setFlashMessage('danger', 'ID pengguna tidak valid.');
        } elseif ($id === $current_admin_id) {
            setFlashMessage('danger', 'Anda tidak dapat menghapus akun Anda sendiri saat sedang login!');
        } else {
            // Check if user has active/ongoing bookings
            $stmt_active_room = $pdo->prepare("SELECT COUNT(*) FROM room_bookings WHERE user_id = ? AND status IN ('pending', 'approved')");
            $stmt_active_room->execute([$id]);
            $has_active_room = (int)$stmt_active_room->fetchColumn() > 0;

            $stmt_active_tool = $pdo->prepare("SELECT COUNT(*) FROM tool_bookings WHERE user_id = ? AND status IN ('pending', 'approved')");
            $stmt_active_tool->execute([$id]);
            $has_active_tool = (int)$stmt_active_tool->fetchColumn() > 0;

            if ($has_active_room || $has_active_tool) {
                setFlashMessage('danger', 'Gagal: Pengguna masih memiliki peminjaman ruangan atau alat yang sedang aktif atau menunggu persetujuan. Selesaikan atau tolak peminjaman terlebih dahulu.');
            } else {
                $stmt_delete = $pdo->prepare("DELETE FROM users WHERE id = ?");
                $stmt_delete->execute([$id]);
                setFlashMessage('success', 'Pengguna berhasil dihapus dari sistem.');
            }
        }
        redirect('/admin/kelola_user.php');
    }
}

// Summary Statistics
$total_users_count = (int)$pdo->query("SELECT COUNT(*) FROM users")->fetchColumn();
$total_admin_count = (int)$pdo->query("SELECT COUNT(*) FROM users WHERE role = 'admin'")->fetchColumn();
$total_student_count = (int)$pdo->query("SELECT COUNT(*) FROM users WHERE role = 'user'")->fetchColumn();

// Filter & Search Parameters
$search_query = trim($_GET['search'] ?? '');
$filter_role = trim($_GET['role'] ?? '');
$where_clauses = [];
$params = [];

if (!empty($search_query)) {
    $where_clauses[] = "(name LIKE ? OR email LIKE ? OR phone LIKE ?)";
    $params[] = "%$search_query%";
    $params[] = "%$search_query%";
    $params[] = "%$search_query%";
}

if (!empty($filter_role) && in_array($filter_role, ['user', 'admin'])) {
    $where_clauses[] = "role = ?";
    $params[] = $filter_role;
}

$where_sql = count($where_clauses) > 0 ? "WHERE " . implode(" AND ", $where_clauses) : "";

// Pagination
$limit = 10;
$page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$offset = ($page - 1) * $limit;

$stmt_count = $pdo->prepare("SELECT COUNT(*) FROM users $where_sql");
$stmt_count->execute($params);
$total_records = (int)$stmt_count->fetchColumn();
$total_pages = ceil($total_records / $limit);

// Fetch Users with booking activity count
$query = "
    SELECT u.*, 
        (SELECT COUNT(*) FROM room_bookings rb WHERE rb.user_id = u.id) as total_room_bookings,
        (SELECT COUNT(*) FROM tool_bookings tb WHERE tb.user_id = u.id) as total_tool_bookings
    FROM users u
    $where_sql
    ORDER BY u.created_at DESC
    LIMIT $limit OFFSET $offset
";
$stmt_users = $pdo->prepare($query);
$stmt_users->execute($params);
$users = $stmt_users->fetchAll(PDO::FETCH_ASSOC);

require_once __DIR__ . '/../includes/header.php';
?>

<div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center mb-4 gap-2">
    <div>
        <h3 class="m-0 fw-bold">Kelola Pengguna</h3>
        <p class="text-muted mb-0 small">Lihat daftar seluruh pengguna, atur role (admin/mahasiswa), tambahkan atau hapus akun.</p>
    </div>
    <div>
        <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addUserModal">
            Tambah Pengguna Baru
        </button>
    </div>
</div>

<!-- Stat Cards -->
<div class="row g-3 mb-4">
    <div class="col-md-4">
        <div class="card shadow-sm border-0 border-start border-primary border-4 p-3 bg-white">
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <div class="text-muted small fw-semibold">Total Pengguna</div>
                    <h3 class="fw-bold mb-0 text-dark"><?= $total_users_count ?></h3>
                </div>
                <div class="bg-primary bg-opacity-10 text-primary rounded-circle p-3 d-flex align-items-center justify-content-center" style="width: 48px; height: 48px;">
                    <span class="fw-bold fs-5">👥</span>
                </div>
            </div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="card shadow-sm border-0 border-start border-warning border-4 p-3 bg-white">
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <div class="text-muted small fw-semibold">Administrator</div>
                    <h3 class="fw-bold mb-0 text-warning"><?= $total_admin_count ?></h3>
                </div>
                <div class="bg-warning bg-opacity-10 text-warning rounded-circle p-3 d-flex align-items-center justify-content-center" style="width: 48px; height: 48px;">
                    <span class="fw-bold fs-5">⚡</span>
                </div>
            </div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="card shadow-sm border-0 border-start border-info border-4 p-3 bg-white">
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <div class="text-muted small fw-semibold">Mahasiswa / Pengguna</div>
                    <h3 class="fw-bold mb-0 text-info"><?= $total_student_count ?></h3>
                </div>
                <div class="bg-info bg-opacity-10 text-info rounded-circle p-3 d-flex align-items-center justify-content-center" style="width: 48px; height: 48px;">
                    <span class="fw-bold fs-5">🎓</span>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="card shadow-sm border-0 p-4 mb-5">
    <!-- Filter Form -->
    <form method="GET" action="" class="row g-3 mb-4 bg-light p-3 rounded border">
        <div class="col-md-6">
            <label class="form-label fw-semibold">Cari Pengguna</label>
            <input type="text" name="search" class="form-control" placeholder="Cari nama, email, atau no. telepon..." value="<?= htmlspecialchars($search_query) ?>">
        </div>
        <div class="col-md-3">
            <label class="form-label fw-semibold">Filter Role</label>
            <select name="role" class="form-select">
                <option value="">Semua Role</option>
                <option value="user" <?= $filter_role === 'user' ? 'selected' : '' ?>>Mahasiswa (User)</option>
                <option value="admin" <?= $filter_role === 'admin' ? 'selected' : '' ?>>Administrator</option>
            </select>
        </div>
        <div class="col-md-3 d-flex align-items-end">
            <button type="submit" class="btn btn-primary w-100 me-2">Terapkan</button>
            <a href="kelola_user.php" class="btn btn-outline-secondary">Reset</a>
        </div>
    </form>

    <!-- Users Table -->
    <div class="table-responsive">
        <table class="table table-hover align-middle">
            <thead class="table-light">
                <tr>
                    <th>Pengguna</th>
                    <th>Email</th>
                    <th>No. Telepon / WA</th>
                    <th>Role</th>
                    <th>Riwayat Transaksi</th>
                    <th>Terdaftar Sejak</th>
                    <th style="width: 150px;" class="text-center">Aksi</th>
                </tr>
            </thead>
            <tbody>
                <?php if (count($users) === 0): ?>
                    <tr>
                        <td colspan="7" class="text-center py-4 text-muted">
                            Tidak ada data pengguna yang sesuai dengan pencarian atau filter.
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($users as $u): 
                        $is_self = ((int)$u['id'] === $current_admin_id);
                        $initial = strtoupper(substr($u['name'], 0, 1));
                    ?>
                    <tr>
                        <td>
                            <div class="d-flex align-items-center gap-3">
                                <div class="rounded-circle d-flex align-items-center justify-content-center text-white fw-bold shadow-sm" 
                                     style="width: 40px; height: 40px; background-color: <?= $u['role'] === 'admin' ? '#0b5b9e' : '#6c757d' ?>;">
                                    <?= $initial ?>
                                </div>
                                <div>
                                    <div class="fw-bold text-dark">
                                        <?= htmlspecialchars($u['name']) ?>
                                        <?php if ($is_self): ?>
                                            <span class="badge bg-secondary ms-1 small">Akun Anda</span>
                                        <?php endif; ?>
                                    </div>
                                    <div class="text-muted small">ID: #<?= $u['id'] ?></div>
                                </div>
                            </div>
                        </td>
                        <td>
                            <span class="font-monospace small"><?= htmlspecialchars($u['email']) ?></span>
                        </td>
                        <td>
                            <?php if (!empty($u['phone'])): ?>
                                <span class="fw-semibold text-dark"><?= htmlspecialchars($u['phone']) ?></span>
                            <?php else: ?>
                                <span class="text-muted small fst-italic">Belum diisi</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ($u['role'] === 'admin'): ?>
                                <span class="badge bg-warning text-dark px-2 py-1">Administrator</span>
                            <?php else: ?>
                                <span class="badge bg-primary px-2 py-1">Mahasiswa</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <div class="small text-secondary">
                                <div>Ruangan: <strong><?= $u['total_room_bookings'] ?>x</strong></div>
                                <div>Alat: <strong><?= $u['total_tool_bookings'] ?>x</strong></div>
                            </div>
                        </td>
                        <td class="small text-muted">
                            <?= date('d M Y, H:i', strtotime($u['created_at'])) ?>
                        </td>
                        <td class="text-center">
                            <!-- Edit Button -->
                            <button type="button" class="btn btn-sm btn-outline-primary me-1" 
                                    data-bs-toggle="modal" 
                                    data-bs-target="#editUserModal<?= $u['id'] ?>" 
                                    title="Ubah Data & Role">
                                Edit
                            </button>

                            <!-- Delete Button -->
                            <?php if ($is_self): ?>
                                <button type="button" class="btn btn-sm btn-outline-secondary disabled" title="Anda tidak dapat menghapus akun Anda sendiri">
                                    Hapus
                                </button>
                            <?php else: ?>
                                <form method="POST" action="" class="d-inline" onsubmit="return confirm('Apakah Anda yakin ingin menghapus pengguna <?= htmlspecialchars(addslashes($u['name'])) ?>?');">
                                    <?= csrfField() ?>
                                    <input type="hidden" name="action" value="delete">
                                    <input type="hidden" name="id" value="<?= $u['id'] ?>">
                                    <button type="submit" class="btn btn-sm btn-outline-danger" title="Hapus Pengguna">
                                        Hapus
                                    </button>
                                </form>
                            <?php endif; ?>

                            <!-- Edit User Modal -->
                            <div class="modal fade" id="editUserModal<?= $u['id'] ?>" tabindex="-1" aria-hidden="true">
                                <div class="modal-dialog text-start">
                                    <div class="modal-content">
                                        <form method="POST" action="">
                                            <?= csrfField() ?>
                                            <div class="modal-header">
                                                <h5 class="modal-title fw-bold">Edit Pengguna & Role</h5>
                                                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                                            </div>
                                            <div class="modal-body">
                                                <input type="hidden" name="action" value="edit">
                                                <input type="hidden" name="id" value="<?= $u['id'] ?>">

                                                <div class="mb-3">
                                                    <label class="form-label fw-semibold">Email Terdaftar</label>
                                                    <input type="email" class="form-control bg-light text-muted" value="<?= htmlspecialchars($u['email']) ?>" readonly>
                                                    <div class="form-text small">Email akun SSO Google tidak dapat diubah.</div>
                                                </div>

                                                <div class="mb-3">
                                                    <label class="form-label fw-semibold">Nama Lengkap <span class="text-danger">*</span></label>
                                                    <input type="text" name="name" class="form-control" value="<?= htmlspecialchars($u['name']) ?>" required>
                                                </div>

                                                <div class="mb-3">
                                                    <label class="form-label fw-semibold">Nomor Telepon / WhatsApp</label>
                                                    <input type="tel" name="phone" class="form-control" placeholder="Contoh: 081234567890" value="<?= htmlspecialchars($u['phone'] ?? '') ?>">
                                                </div>

                                                <div class="mb-3">
                                                    <label class="form-label fw-semibold">Hak Akses (Role) <span class="text-danger">*</span></label>
                                                    <select name="role" class="form-select" <?= $is_self ? 'disabled' : '' ?>>
                                                        <option value="user" <?= $u['role'] === 'user' ? 'selected' : '' ?>>Mahasiswa / Pengguna Standar</option>
                                                        <option value="admin" <?= $u['role'] === 'admin' ? 'selected' : '' ?>>Administrator (Akses Penuh)</option>
                                                    </select>
                                                    <?php if ($is_self): ?>
                                                        <input type="hidden" name="role" value="admin">
                                                        <div class="form-text text-warning small">Anda tidak dapat menurunkan role akun Anda sendiri saat sedang login.</div>
                                                    <?php else: ?>
                                                        <div class="form-text small">Pilih role Administrator untuk memberikan akses kelola ruangan, alat, dan pengguna.</div>
                                                    <?php endif; ?>
                                                </div>
                                            </div>
                                            <div class="modal-footer">
                                                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Batal</button>
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

        <!-- Pagination -->
        <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mt-3">
            <?php 
                $start_record = ($total_records > 0) ? $offset + 1 : 0;
                $end_record = min($offset + $limit, $total_records);
            ?>
            <div class="text-muted small">
                Menampilkan <?= $start_record ?> sampai <?= $end_record ?> dari <?= $total_records ?> pengguna
            </div>
            
            <?php if ($total_pages > 1): ?>
                <?php 
                    renderPagination($page, $total_pages, array_filter([
                        'search' => $search_query,
                        'role' => $filter_role
                    ]));
                ?>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- Modal Tambah Pengguna Baru -->
<div class="modal fade" id="addUserModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST" action="">
                <?= csrfField() ?>
                <div class="modal-header">
                    <h5 class="modal-title fw-bold">Tambah Pengguna Baru</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" name="action" value="add">

                    <div class="mb-3">
                        <label class="form-label fw-semibold">Nama Lengkap <span class="text-danger">*</span></label>
                        <input type="text" name="name" class="form-control" placeholder="Nama mahasiswa / admin..." required>
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-semibold">Alamat Email <span class="text-danger">*</span></label>
                        <input type="email" name="email" class="form-control" placeholder="contoh@student.unika.ac.id" required>
                        <div class="form-text small">Pastikan email sesuai dengan akun Google yang digunakan pengguna untuk login SSO.</div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-semibold">Nomor Telepon / WhatsApp</label>
                        <input type="tel" name="phone" class="form-control" placeholder="Contoh: 081234567890">
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-semibold">Hak Akses (Role) <span class="text-danger">*</span></label>
                        <select name="role" class="form-select" required>
                            <option value="user" selected>Mahasiswa / Pengguna Standar</option>
                            <option value="admin">Administrator (Akses Penuh)</option>
                        </select>
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-semibold">Password (Opsional)</label>
                        <input type="password" name="password" class="form-control" placeholder="Boleh dikosongkan jika menggunakan Google Login">
                        <div class="form-text small">Jika dikosongkan, pengguna tetap bisa masuk menggunakan tombol login Google dengan email di atas.</div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Batal</button>
                    <button type="submit" class="btn btn-primary">Simpan Pengguna</button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
