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

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['credential'])) {
    $jwt = $_POST['credential'];
    $url = 'https://oauth2.googleapis.com/tokeninfo?id_token=' . $jwt;
    $response = @file_get_contents($url);

    if ($response !== false) {
        $payload = json_decode($response, true);
        if (isset($payload['email'])) {
            $email = $payload['email'];
            $name = $payload['name'] ?? 'User';

            // Check in DB
            $stmt = $pdo->prepare("SELECT id, name, role FROM users WHERE email = ?");
            $stmt->execute([$email]);
            $user = $stmt->fetch();

            if ($user) {
                // Login existing user
                $_SESSION['user_id'] = $user['id'];
                $_SESSION['name'] = $user['name'];
                $_SESSION['role'] = $user['role'];
            } else {
                // Auto register as user if not found in db
                $stmt = $pdo->prepare("INSERT INTO users (name, email, role, password) VALUES (?, ?, 'user', '')");
                $stmt->execute([$name, $email]);

                $_SESSION['user_id'] = $pdo->lastInsertId();
                $_SESSION['name'] = $name;
                $_SESSION['role'] = 'user';
            }

            if ($_SESSION['role'] === 'admin') {
                redirect('/admin/dashboard.php');
            } else {
                redirect('/user/dashboard.php');
            }
        } else {
            setFlashMessage('danger', 'Gagal memverifikasi login Google. Email tidak ditemukan.');
        }
    } else {
        setFlashMessage('danger', 'Gagal menghubungi server Google.');
    }
}

require_once __DIR__ . '/../includes/header.php';
?>

<div class="row justify-content-center mt-5">
    <div class="col-md-5">
        <div class="card shadow-sm p-5 border-0" style="border-radius: 12px;">
            <div class="text-center mb-4">
                <img src="<?= BASE_URL ?>/assets/images/logo_unika.png" alt="Logo Unika" height="60" class="mb-3">
                <h3 class="fw-bold" style="color: #0b5b9e;">Login SSO</h3>
                <p class="text-muted">Gunakan akun Google Anda untuk masuk</p>
            </div>

            <div class="d-flex justify-content-center mb-3">
                <div id="g_id_onload"
                    data-client_id="870441525376-opon2s5buo26ernbmod9qh9l7esq9b1a.apps.googleusercontent.com"
                    data-context="signin" data-ux_mode="popup" data-callback="handleCredentialResponse"
                    data-auto_prompt="false">
                </div>

                <div class="g_id_signin" data-type="standard" data-shape="rectangular" data-theme="outline"
                    data-text="sign_in_with" data-size="large" data-logo_alignment="left">
                </div>
            </div>

            <form id="gsi_form" method="POST" action="">
                <input type="hidden" name="credential" id="credential">
            </form>

        </div>
    </div>
</div>

<script src="https://accounts.google.com/gsi/client" async defer></script>
<script>
    function handleCredentialResponse(response) {
        if (response.credential) {
            document.getElementById('credential').value = response.credential;
            document.getElementById('gsi_form').submit();
        }
    }
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>