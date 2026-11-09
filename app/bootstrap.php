<?php
ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);
session_start();
$c = require __DIR__ . '/config.php';
function db()
{
    static $p = null;
    global $c;
    if (!$p) $p = new PDO("mysql:host={$c['db_host']};dbname={$c['db_name']};charset=utf8mb4", $c['db_user'], $c['db_pass'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
    return $p;
}
function e($v)
{
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}
function money($n)
{
    return number_format((float)$n, 0, ',', '.') . ' ₫';
}
function csrf()
{
    return $_SESSION['csrf'] ?? ($_SESSION['csrf'] = bin2hex(random_bytes(32)));
}
function verify()
{
    if (!hash_equals($_SESSION['csrf'] ?? '', $_POST['csrf'] ?? '')) die('Phiên hết hạn, tải lại trang.');
}
function admin()
{
    return ($_SESSION['user']['role'] ?? '') === 'admin';
}
function flash($v = null)
{
    if ($v !== null) {
        $_SESSION['flash'] = $v;
        return;
    }
    $x = $_SESSION['flash'] ?? '';
    unset($_SESSION['flash']);
    return $x;
}
function images($id)
{
    $s = db()->prepare("SELECT path FROM product_images WHERE product_id=?");
    $s->execute([$id]);
    return array_column($s->fetchAll(), 'path');
}
