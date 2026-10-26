<?php
// includes/functions.php

function setFlashMessage($type, $message) {
    $_SESSION['flash_message'] = [
        'type' => $type, // 'success', 'danger', 'warning', 'info'
        'message' => $message
    ];
}

function displayFlashMessage() {
    if (isset($_SESSION['flash_message'])) {
        $type = htmlspecialchars($_SESSION['flash_message']['type']);
        $message = htmlspecialchars($_SESSION['flash_message']['message']);
        
        echo "<div class='alert alert-{$type} alert-dismissible fade show' role='alert'>
                {$message}
                <button type='button' class='btn-close' data-bs-dismiss='alert' aria-label='Close'></button>
              </div>";
        
        unset($_SESSION['flash_message']);
    }
}

// Generate time slots 08:00 to 16:00
function getTimeSlots() {
    $slots = [];
    $start = strtotime('08:00');
    $end = strtotime('16:00');
    
    while ($start <= $end) {
        $slots[] = date('H:i', $start);
        $start = strtotime('+30 minutes', $start);
    }
    return $slots;
}

function redirect($url) {
    header("Location: " . BASE_URL . $url);
    exit;
}

/**
 * Render pagination with smart windowing (ringkas / compact pagination)
 * Menampilkan nomor halaman ringkas dengan elipsis (...) agar tidak melebar/terlalu banyak.
 *
 * @param int $currentPage
 * @param int $totalPages
 * @param array $queryParams
 * @param string $pageParam
 */
function renderPagination($currentPage, $totalPages, $queryParams = [], $pageParam = 'page') {
    if ($totalPages <= 1) {
        return;
    }

    $currentPage = max(1, (int)$currentPage);
    $totalPages = max(1, (int)$totalPages);

    $filteredParams = array_filter($queryParams, function($val) {
        return $val !== '' && $val !== null;
    });

    $buildUrl = function($pageNum) use ($filteredParams, $pageParam) {
        $params = array_merge($filteredParams, [$pageParam => $pageNum]);
        return '?' . http_build_query($params);
    };

    echo '<nav aria-label="Navigasi Halaman">';
    echo '<ul class="pagination pagination-sm m-0">';

    // Tombol Sebelumnya
    $prevDisabled = ($currentPage <= 1);
    echo '<li class="page-item ' . ($prevDisabled ? 'disabled' : '') . '">';
    echo '<a class="page-link" href="' . ($prevDisabled ? '#' : htmlspecialchars($buildUrl($currentPage - 1))) . '" ' . ($prevDisabled ? 'tabindex="-1" aria-disabled="true"' : '') . '>Sebelumnya</a>';
    echo '</li>';

    // Nomor Halaman Ringkas
    if ($totalPages <= 7) {
        for ($i = 1; $i <= $totalPages; $i++) {
            $active = ($i === $currentPage) ? 'active' : '';
            echo '<li class="page-item ' . $active . '">';
            echo '<a class="page-link" href="' . htmlspecialchars($buildUrl($i)) . '">' . $i . '</a>';
            echo '</li>';
        }
    } else {
        if ($currentPage <= 4) {
            for ($i = 1; $i <= 5; $i++) {
                $active = ($i === $currentPage) ? 'active' : '';
                echo '<li class="page-item ' . $active . '">';
                echo '<a class="page-link" href="' . htmlspecialchars($buildUrl($i)) . '">' . $i . '</a>';
                echo '</li>';
            }
            echo '<li class="page-item disabled"><span class="page-link">&hellip;</span></li>';
            echo '<li class="page-item"><a class="page-link" href="' . htmlspecialchars($buildUrl($totalPages)) . '">' . $totalPages . '</a></li>';
        } elseif ($currentPage >= $totalPages - 3) {
            echo '<li class="page-item"><a class="page-link" href="' . htmlspecialchars($buildUrl(1)) . '">1</a></li>';
            echo '<li class="page-item disabled"><span class="page-link">&hellip;</span></li>';
            for ($i = $totalPages - 4; $i <= $totalPages; $i++) {
                $active = ($i === $currentPage) ? 'active' : '';
                echo '<li class="page-item ' . $active . '">';
                echo '<a class="page-link" href="' . htmlspecialchars($buildUrl($i)) . '">' . $i . '</a>';
                echo '</li>';
            }
        } else {
            echo '<li class="page-item"><a class="page-link" href="' . htmlspecialchars($buildUrl(1)) . '">1</a></li>';
            echo '<li class="page-item disabled"><span class="page-link">&hellip;</span></li>';
            for ($i = $currentPage - 1; $i <= $currentPage + 1; $i++) {
                $active = ($i === $currentPage) ? 'active' : '';
                echo '<li class="page-item ' . $active . '">';
                echo '<a class="page-link" href="' . htmlspecialchars($buildUrl($i)) . '">' . $i . '</a>';
                echo '</li>';
            }
            echo '<li class="page-item disabled"><span class="page-link">&hellip;</span></li>';
            echo '<li class="page-item"><a class="page-link" href="' . htmlspecialchars($buildUrl($totalPages)) . '">' . $totalPages . '</a></li>';
        }
    }

    // Tombol Selanjutnya
    $nextDisabled = ($currentPage >= $totalPages);
    echo '<li class="page-item ' . ($nextDisabled ? 'disabled' : '') . '">';
    echo '<a class="page-link" href="' . ($nextDisabled ? '#' : htmlspecialchars($buildUrl($currentPage + 1))) . '" ' . ($nextDisabled ? 'tabindex="-1" aria-disabled="true"' : '') . '>Selanjutnya</a>';
    echo '</li>';

    echo '</ul>';
    echo '</nav>';
}

/**
 * Upload gambar alat lab
 *
 * @param array $file $_FILES['image']
 * @param string|null $old_filename
 * @return array ['success' => bool, 'filename' => string|null, 'error' => string|null]
 */
function handleToolImageUpload($file, $old_filename = null) {
    if (!isset($file['error']) || is_array($file['error'])) {
        return ['success' => false, 'error' => 'Parameter file tidak valid.'];
    }

    if ($file['error'] === UPLOAD_ERR_NO_FILE) {
        return ['success' => true, 'filename' => $old_filename];
    }

    if ($file['error'] !== UPLOAD_ERR_OK) {
        return ['success' => false, 'error' => 'Terjadi error saat upload file (Kode: ' . $file['error'] . ').'];
    }

    // Limit size 3MB
    if ($file['size'] > 3 * 1024 * 1024) {
        return ['success' => false, 'error' => 'Ukuran file gambar maksimal 3MB.'];
    }

    // Validate MIME type
    $allowed_mimes = [
        'image/jpeg' => 'jpg',
        'image/png'  => 'png',
        'image/webp' => 'webp',
        'image/gif'  => 'gif'
    ];

    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = $finfo->file($file['tmp_name']);

    if (!array_key_exists($mime, $allowed_mimes)) {
        return ['success' => false, 'error' => 'Format file tidak didukung. Harap upload gambar JPG, PNG, WEBP, atau GIF.'];
    }

    $ext = $allowed_mimes[$mime];
    $upload_dir = __DIR__ . '/../assets/images/tools/';

    if (!is_dir($upload_dir)) {
        @mkdir($upload_dir, 0755, true);
    }

    $filename = 'tool_' . bin2hex(random_bytes(8)) . '_' . time() . '.' . $ext;
    $destination = $upload_dir . $filename;

    if (!move_uploaded_file($file['tmp_name'], $destination)) {
        return ['success' => false, 'error' => 'Gagal memindahkan file yang diunggah ke server.'];
    }

    // Delete old file if present
    if (!empty($old_filename)) {
        deleteToolImage($old_filename);
    }

    return ['success' => true, 'filename' => $filename];
}

/**
 * Hapus gambar alat lab dari disk
 *
 * @param string|null $filename
 * @return bool
 */
function deleteToolImage($filename) {
    if (empty($filename)) return false;
    $filepath = __DIR__ . '/../assets/images/tools/' . basename($filename);
    if (file_exists($filepath) && is_file($filepath)) {
        return @unlink($filepath);
    }
    return false;
}

/**
 * Dapatkan URL gambar alat lab (atau null jika tidak ada)
 *
 * @param string|null $filename
 * @return string|null
 */
function getToolImageUrl($filename) {
    if (empty($filename)) return null;
    $filepath = __DIR__ . '/../assets/images/tools/' . basename($filename);
    if (file_exists($filepath) && is_file($filepath)) {
        return BASE_URL . '/assets/images/tools/' . htmlspecialchars(basename($filename));
    }
    return null;
}

/**
 * Generate CSRF Token for current session
 *
 * @return string
 */
function generateCsrfToken() {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

/**
 * Render HTML input hidden for CSRF token
 *
 * @return string
 */
function csrfField() {
    $token = htmlspecialchars(generateCsrfToken());
    return '<input type="hidden" name="csrf_token" value="' . $token . '">';
}

/**
 * Validate CSRF token from POST request
 *
 * @param string|null $token
 * @return bool
 */
function validateCsrfToken($token = null) {
    if ($token === null) {
        $token = $_POST['csrf_token'] ?? '';
    }
    if (empty($_SESSION['csrf_token']) || empty($token)) {
        return false;
    }
    return hash_equals($_SESSION['csrf_token'], $token);
}

/**
 * Sanitize cell value to prevent Excel / CSV formula injection (CWE-1236)
 *
 * @param mixed $val
 * @return mixed
 */
function sanitizeExcelCell($val) {
    if (is_string($val) && strlen($val) > 0) {
        $firstChar = $val[0];
        if (in_array($firstChar, ['=', '+', '-', '@', "\t", "\r"])) {
            return "'" . $val;
        }
    }
    return $val;
}
?>
