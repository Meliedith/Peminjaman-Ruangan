<?php
// auth/register.php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';

// Only users can register
if (isset($_SESSION['user_id'])) {
    redirect('/');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = trim($_POST['name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    $phone = trim($_POST['phone'] ?? '');

    if (empty($name) || empty($email) || empty($password)) {
        setFlashMessage('danger', 'Nama, email, dan password wajib diisi.');
    } else {
        // Check if email exists
        $stmt = $pdo->prepare("SELECT id FROM users WHERE email = ?");
        $stmt->execute([$email]);
        if ($stmt->fetch()) {
            setFlashMessage('danger', 'Email sudah terdaftar. Silakan gunakan email lain.');
        } else {
            $hashedPassword = password_hash($password, PASSWORD_DEFAULT);
            $stmt = $pdo->prepare("INSERT INTO users (name, email, password, role, phone) VALUES (?, ?, ?, 'user', ?)");
            if ($stmt->execute([$name, $email, $hashedPassword, $phone])) {
                setFlashMessage('success', 'Registrasi berhasil. Silakan login.');
                redirect('/auth/login.php?role=user');
            } else {
                setFlashMessage('danger', 'Terjadi kesalahan saat registrasi.');
            }
        }
    }
}

require_once __DIR__ . '/../includes/header.php';
?>

<div class="row justify-content-center mt-4">
    <div class="col-md-6">
        <div class="card shadow-sm p-4">
            <h3 class="text-center mb-4">Registrasi User</h3>
            <form method="POST" action="">
                <div class="mb-3">
                    <label class="form-label">Nama Lengkap *</label>
                    <input type="text" name="name" class="form-control" required autofocus>
                </div>
                
                <div class="mb-3">
                    <label class="form-label">Email *</label>
                    <input type="email" name="email" class="form-control" required>
                </div>
                
                <div class="mb-3">
                    <label class="form-label">Password *</label>
                    <input type="password" name="password" class="form-control" required minlength="6">
                </div>

                <div class="mb-4">
                    <label class="form-label">No HP / NIM (Opsional)</label>
                    <input type="text" name="phone" class="form-control">
                </div>
                
                <button type="submit" class="btn btn-primary w-100">Daftar</button>
            </form>
            
            <div class="text-center mt-3">
                <p>Sudah punya akun? <a href="<?= BASE_URL ?>/auth/login.php?role=user">Login di sini</a></p>
            </div>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
