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
?>
