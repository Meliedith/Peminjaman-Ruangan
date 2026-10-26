<?php
// profile.php
require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/auth_middleware.php';

// Ensure user is logged in (either user or admin)
if (!isset($_SESSION['user_id'])) {
    redirect('/auth/login.php');
}

$user_id = (int)$_SESSION['user_id'];
$redirect_to = $_GET['redirect'] ?? '';

// Handle Profile Update
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validateCsrfToken()) {
        setFlashMessage('danger', 'Sesi keamanan tidak valid (CSRF token mismatch). Silakan coba lagi.');
        redirect('/profile.php' . (!empty($redirect_to) ? '?redirect=' . urlencode($redirect_to) : ''));
    }

    $name = trim($_POST['name'] ?? '');
    $phone = trim($_POST['phone'] ?? '');

    // Normalize phone number (remove spaces, dashes)
    $clean_phone = preg_replace('/[\s\-]/', '', $phone);

    // Basic Validation
    if (empty($name)) {
        setFlashMessage('danger', 'Nama lengkap tidak boleh kosong.');
    } elseif (empty($clean_phone)) {
        setFlashMessage('danger', 'Nomor telepon wajib diisi untuk keperluan verifikasi peminjaman.');
    } elseif (!preg_match('/^(\+62|62|08)[0-9]{7,13}$/', $clean_phone)) {
        setFlashMessage('danger', 'Format nomor telepon tidak valid. Gunakan format Indonesia yang benar (contoh: 08123456789 atau +628123456789).');
    } else {
        try {
            $stmt_update = $pdo->prepare("UPDATE users SET name = ?, phone = ? WHERE id = ?");
            $stmt_update->execute([$name, $clean_phone, $user_id]);

            // Update session name
            $_SESSION['name'] = $name;

            setFlashMessage('success', 'Profil dan nomor telepon berhasil diperbarui.');

            // Redirect back if user was sent here from booking form
            if ($redirect_to === 'pinjam_ruangan') {
                redirect('/user/pinjam_ruangan.php');
            } elseif ($redirect_to === 'pinjam_alat') {
                redirect('/user/pinjam_alat.php');
            } else {
                redirect('/profile.php');
            }
        } catch (Exception $e) {
            error_log("Profile update error: " . $e->getMessage());
            setFlashMessage('danger', 'Gagal memperbarui profil karena kesalahan sistem.');
        }
    }
}

// Fetch Latest User Data
$stmt = $pdo->prepare("SELECT id, name, email, role, phone, created_at FROM users WHERE id = ?");
$stmt->execute([$user_id]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$user) {
    setFlashMessage('danger', 'Data pengguna tidak ditemukan.');
    redirect('/auth/logout.php');
}

$user_phone = $user['phone'] ?? '';
$has_phone = !empty(trim($user_phone));

require_once __DIR__ . '/includes/header.php';
?>

<div class="row justify-content-center">
    <div class="col-12 col-md-8 col-lg-6">
        
        <?php if (!$has_phone): ?>
            <div class="alert alert-warning border-warning shadow-sm p-3 mb-4 rounded-3 d-flex align-items-center">
                <div>
                    <strong class="text-dark">Nomor Telepon Belum Diisi!</strong>
                    <div class="small text-muted mt-1">
                        Sistem mewajibkan nomor telepon aktif (WhatsApp) agar Anda dapat mengajukan peminjaman ruangan/meja maupun alat lab. Silakan lengkapi nomor telepon di bawah ini.
                    </div>
                </div>
            </div>
        <?php endif; ?>

        <div class="card shadow-sm border-0 rounded-4 overflow-hidden mb-4">
            <div class="card-header bg-primary text-white p-4 text-center" style="background: linear-gradient(135deg, #0b5b9e 0%, #177cd1 100%);">
                <div class="bg-white text-primary rounded-circle d-inline-flex align-items-center justify-content-center shadow mb-2" style="width: 72px; height: 72px; font-size: 2rem; font-weight: bold;">
                    <?= strtoupper(substr($user['name'], 0, 1)) ?>
                </div>
                <h4 class="mb-0 fw-bold"><?= htmlspecialchars($user['name']) ?></h4>
                <div class="badge <?= $user['role'] === 'admin' ? 'bg-warning text-dark' : 'bg-light text-primary' ?> px-3 py-1 rounded-pill mt-2">
                    <?= $user['role'] === 'admin' ? 'Administrator' : 'Mahasiswa / Pengguna' ?>
                </div>
            </div>

            <div class="card-body p-4">
                <form method="POST" action="">
                    <?= csrfField() ?>
                    
                    <div class="mb-3">
                        <label for="name" class="form-label fw-semibold text-secondary">Nama Lengkap <span class="text-danger">*</span></label>
                        <input type="text" name="name" id="name" class="form-control form-control-lg" value="<?= htmlspecialchars($user['name']) ?>" required>
                    </div>

                    <div class="mb-3">
                        <label for="email" class="form-label fw-semibold text-secondary">Email Terdaftar</label>
                        <input type="email" id="email" class="form-control bg-light text-muted" value="<?= htmlspecialchars($user['email']) ?>" readonly>
                        <div class="form-text small">Email ditautkan dengan akun SSO Google Anda.</div>
                    </div>

                    <div class="mb-4">
                        <div class="d-flex justify-content-between align-items-center mb-1">
                            <label for="phone" class="form-label fw-semibold text-secondary mb-0">
                                Nomor Telepon / WhatsApp <span class="text-danger">*</span>
                            </label>
                            <?php if ($has_phone): ?>
                                <span class="badge bg-success small">✓ Terverifikasi</span>
                            <?php else: ?>
                                <span class="badge bg-danger small">Belum Diisi</span>
                            <?php endif; ?>
                        </div>
                        <input type="tel" name="phone" id="phone" class="form-control form-control-lg" placeholder="Contoh: 081234567890" value="<?= htmlspecialchars($user_phone) ?>" required>
                        <div class="form-text small text-muted mt-1">
                            Gunakan nomor aktif (WhatsApp). Nomor ini akan tercantum saat Anda mengajukan pinjaman meja/alat agar admin dapat menghubungi Anda.
                        </div>
                    </div>

                    <div class="d-grid gap-2">
                        <button type="submit" class="btn btn-primary btn-lg rounded-3 fw-semibold shadow-sm">
                            Simpan Perubahan Profil
                        </button>
                    </div>

                </form>
            </div>
            
            <div class="card-footer bg-light text-center py-3 text-muted small">
                Terdaftar sejak: <?= date('d M Y', strtotime($user['created_at'])) ?>
            </div>
        </div>

    </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
