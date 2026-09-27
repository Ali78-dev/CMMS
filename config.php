<?php
// =====================================================
// نظام إدارة صيانة الحاسوب (CMMS)
// الملف: config.php — الاتصال + الجلسات + الحماية + الدوال
// =====================================================

declare(strict_types=1);

// ---------- إعدادات قاعدة البيانات ----------
define('DB_HOST', 'localhost');
define('DB_NAME', 'cmms_db');
define('DB_USER', 'root');
define('DB_PASS', '');
define('APP_NAME', 'نظام إدارة صيانة الحاسوب');

// ---------- بدء الجلسة الآمنة ----------
function secureSessionStart(): void {
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }
    $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'domain' => '',
        'secure' => $isHttps,
        'httponly' => true,
        'samesite' => 'Lax'
    ]);
    session_start();
}
secureSessionStart();

// ---------- الحماية من اختطاف الجلسة + انتهاء الخمول ----------
define('SESSION_TIMEOUT', 7200); // ساعتان

if (isset($_SESSION['user_id'])) {
    // بصمة المتصفح
    $fingerprint = hash('sha256', ($_SERVER['HTTP_USER_AGENT'] ?? 'unknown'));
    if (!isset($_SESSION['fingerprint'])) {
        $_SESSION['fingerprint'] = $fingerprint;
    } elseif (!hash_equals($_SESSION['fingerprint'], $fingerprint)) {
        session_unset();
        session_destroy();
        secureSessionStart();
        header('Location: login.php?error=session');
        exit;
    }
    // انتهاء الجلسة بعد الخمول
    if (isset($_SESSION['last_activity']) && (time() - (int)$_SESSION['last_activity'] > SESSION_TIMEOUT)) {
        session_unset();
        session_destroy();
        secureSessionStart();
        header('Location: login.php?error=expired');
        exit;
    }
    $_SESSION['last_activity'] = time();
}

// ---------- الاتصال بقاعدة البيانات (PDO) ----------
function getDB(): PDO {
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }
    $dsn = 'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4';
    $options = [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ];
    $pdo = new PDO($dsn, DB_USER, DB_PASS, $options);
    ensureSchema($pdo);
    return $pdo;
}

// ---------- التأكد من وجود عمود ملاحظات الإصلاح ----------
function ensureSchema(PDO $pdo): void {
    try {
        $stmt = $pdo->query("SHOW COLUMNS FROM requests LIKE 'repair_notes'");
        if ($stmt && $stmt->rowCount() === 0) {
            $pdo->exec("ALTER TABLE requests ADD COLUMN repair_notes TEXT NULL AFTER description");
        }
    } catch (PDOException $e) {
        // تجاهل بصمت: سيظهر الخطأ عند أول استعلام فعلي
    }
}

// ---------- دوال مساعدة عامة ----------

// تهرب المخرجات لمنع XSS
function e($value): string {
    return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
}

// تنظيف المدخلات النصية
function clean(string $value): string {
    return trim(strip_tags($value));
}

// تحويل لصفحة أخرى
function redirect(string $url): void {
    header('Location: ' . $url);
    exit;
}

// هل المستخدم مسجل الدخول؟
function isLoggedIn(): bool {
    return isset($_SESSION['user_id']);
}

// بيانات المستخدم الحالي من الجلسة
function currentUser(): ?array {
    if (!isLoggedIn()) {
        return null;
    }
    return [
        'id' => (int)$_SESSION['user_id'],
        'name' => (string)($_SESSION['user_name'] ?? ''),
        'username' => (string)($_SESSION['username'] ?? ''),
        'role' => (string)($_SESSION['user_role'] ?? ''),
    ];
}

// اشتراط تسجيل الدخول
function requireLogin(): void {
    if (!isLoggedIn()) {
        redirect('login.php');
    }
}

// اشتراط دور معين (تفويض)
function requireRole(array $roles): void {
    requireLogin();
    $role = (string)($_SESSION['user_role'] ?? '');
    if (!in_array($role, $roles, true)) {
        http_response_code(403);
        die('<!DOCTYPE html><html lang="ar" dir="rtl"><head><meta charset="UTF-8"><title>ممنوع</title></head><body style="font-family:Tahoma;text-align:center;padding:50px;"><h2>غير مصرح لك بالوصول إلى هذه الصفحة</h2><a href="index.php">العودة للرئيسية</a></body></html>');
    }
}

// ---------- الحماية من CSRF ----------
function csrfToken(): string {
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return (string)$_SESSION['csrf'];
}

function csrfField(): string {
    return '<input type="hidden" name="csrf" value="' . e(csrfToken()) . '">';
}

function verifyCsrf(): bool {
    $sent = (string)($_POST['csrf'] ?? '');
    $saved = (string)($_SESSION['csrf'] ?? '');
    return $sent !== '' && $saved !== '' && hash_equals($saved, $sent);
}

// ---------- رسائل التنبيه (Flash) ----------
function setFlash(string $message, string $type = 'success'): void {
    $_SESSION['flash'] = ['message' => $message, 'type' => $type];
}

function getFlash(): ?array {
    if (empty($_SESSION['flash'])) {
        return null;
    }
    $flash = $_SESSION['flash'];
    unset($_SESSION['flash']);
    return $flash;
}

// ---------- القوائم والترجمات العربية ----------

function equipmentStatuses(): array {
    return ['working', 'faulty', 'under_maintenance'];
}

function equipmentStatusLabel(string $status): string {
    $map = [
        'working' => 'سليم',
        'faulty' => 'معطل',
        'under_maintenance' => 'قيد الصيانة',
    ];
    return $map[$status] ?? $status;
}

function requestStatuses(): array {
    return ['new', 'in_progress', 'completed', 'closed'];
}

function requestStatusLabel(string $status): string {
    $map = [
        'new' => 'جديد (بانتظار المعالجة)',
        'in_progress' => 'قيد التنفيذ',
        'completed' => 'مكتمل',
        'closed' => 'مغلق',
    ];
    return $map[$status] ?? $status;
}

function priorities(): array {
    return ['low', 'medium', 'high'];
}

function priorityLabel(string $priority): string {
    $map = [
        'low' => 'منخفضة',
        'medium' => 'متوسطة',
        'high' => 'عالية',
    ];
    return $map[$priority] ?? $priority;
}

function roles(): array {
    return ['admin', 'technician', 'user'];
}

function roleLabel(string $role): string {
    $map = [
        'admin' => 'مدير',
        'technician' => 'فني',
        'user' => 'مستخدم',
    ];
    return $map[$role] ?? $role;
}
