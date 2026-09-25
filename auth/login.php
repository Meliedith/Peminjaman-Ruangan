<?php
// auth/login.php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';

// If already logged in
if (isset($_SESSION['user_id'])) {
    if ($_SESSION['role'] === 'admin') {
        redirect('/admin/dashboard.php');
    } else {
        redirect('/user/dashboard.php');
    }
}

$role = isset($_GET['role']) && $_GET['role'] === 'admin' ? 'admin' : 'user';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    $login_role = $_POST['role'] ?? 'user';

    if (empty($email) || empty($password)) {
        setFlashMessage('danger', 'Email dan password wajib diisi.');
    } else {
        $stmt = $pdo->prepare("SELECT id, name, password, role FROM users WHERE email = ? AND role = ?");
        $stmt->execute([$email, $login_role]);
        $user = $stmt->fetch();

        if ($user && password_verify($password, $user['password'])) {
            $_SESSION['user_id'] = $user['id'];
            $_SESSION['name'] = $user['name'];
            $_SESSION['role'] = $user['role'];
            
            if ($user['role'] === 'admin') {
                redirect('/admin/dashboard.php');
            } else {
                redirect('/user/dashboard.php');
            }
        } else {
            setFlashMessage('danger', 'Email atau password salah, atau role tidak sesuai.');
        }
    }
}

require_once __DIR__ . '/../includes/header.php';
?>

<div class="row justify-content-center mt-4">
    <div class="col-md-5">
        <div class="card shadow-sm p-4">
            <h3 class="text-center mb-4">Login <?= ucfirst($role) ?></h3>
            <form method="POST" action="">
                <input type="hidden" name="role" value="<?= htmlspecialchars($role) ?>">
                
                <div class="mb-3">
                    <label class="form-label">Email address</label>
                    <input type="email" name="email" class="form-control" required autofocus>
                </div>
                
                <div class="mb-4">
                    <label class="form-label">Password</label>
                    <input type="password" name="password" class="form-control" required>
                </div>
                
                <button type="submit" class="btn btn-primary w-100">Login</button>
            </form>
            
            <?php if ($role === 'user'): ?>
            <div class="text-center mt-3">
                <p>Belum punya akun? <a href="<?= BASE_URL ?>/auth/register.php">Daftar di sini</a></p>
            </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
