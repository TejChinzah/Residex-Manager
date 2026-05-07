<?php
// Start session only if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

define('DB_HOST', 'localhost');
define('DB_USER', 'root');         
define('DB_PASS', '');             
define('DB_NAME', 'hostel');

define('SITE_NAME', 'Residex Manager');
define('SITE_URL', 'http://localhost/residex');

function getDB() {
    static $conn = null;
    if ($conn === null) {
        $conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
        if ($conn->connect_error) {
            die('<div style="font-family:sans-serif;padding:30px;background:#1a0a0a;color:#ff6b6b;border:1px solid #ff6b6b;border-radius:8px;margin:20px;">
                <h2>&#9888; Database Connection Failed</h2>
                <p><strong>Error:</strong> ' . htmlspecialchars($conn->connect_error) . '</p>
                <p>Please check:</p>
                <ul>
                    <li>MySQL is running in XAMPP Control Panel</li>
                    <li>Database <strong>hostel</strong> exists (import database.sql)</li>
                    <li>Username is <strong>root</strong> and password is <strong>blank</strong></li>
                </ul>
            </div>');
        }
        $conn->set_charset('utf8mb4');
    }
    return $conn;
}

function sanitize($data) {
    $conn = getDB();
    return $conn->real_escape_string(strip_tags(trim($data)));
}

function redirect($url) {
    if (!headers_sent()) {
        header("Location: $url");
        exit();
    } else {
        echo '<script>window.location.href="' . htmlspecialchars($url) . '";</script>';
        exit();
    }
}

function isLoggedIn($type = 'user') {
    if ($type === 'admin') return isset($_SESSION['admin_id']);
    return isset($_SESSION['user_id']);
}

function requireLogin($type = 'user') {
    if ($type === 'admin') {
        if (!isset($_SESSION['admin_id'])) redirect(SITE_URL . '/admin/login.php');
    } else {
        if (!isset($_SESSION['user_id'])) redirect(SITE_URL . '/user/login.php');
    }
}

function dbCount($sql) {
    $r = getDB()->query($sql);
    if (!$r) return 0;
    $row = $r->fetch_assoc();
    return (int)($row['c'] ?? 0);
}