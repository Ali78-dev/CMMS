<?php
// =====================================================
// نظام إدارة صيانة الحاسوب (CMMS)
// الملف: login.php — تسجيل الدخول + تسجيل الخروج
// =====================================================

require_once __DIR__ . '/config.php';

// ---------- تسجيل الخروج ----------
if (isset($_GET['action']) && $_GET['action'] === 'logout') {
    session_unset();
    session_destroy();
    secureSessionStart();
    redirect('login.php?message=logout');
}

// إذا كان مسجلاً already فاذهب للرئيسية
if (isLoggedIn()) {
    redirect('index.php');
}

$errors = [];
$username = '';

// ---------- معالجة نموذج الدخول ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrf()) {
        $errors[] = 'انتهت صلاحية النموذج، يرجى المحاولة مرة أخرى.';
    } else {
        $username = clean($_POST['username'] ?? '');
        $password = (string)($_POST['password'] ?? '');

        // تحقق من الخادم
        if ($username === '' || $password === '') {
            $errors[] = 'يرجى إدخال اسم المستخدم وكلمة المرور.';
        } elseif (strlen($username) < 3 || strlen($username) > 50) {
            $errors[] = 'اسم المستخدم غير صالح.';
        } else {
            try {
                $db = getDB();
                $stmt = $db->prepare('SELECT id, name, username, password, role FROM users WHERE username = ? LIMIT 1');
                $stmt->execute([$username]);
                $foundUser = $stmt->fetch();

                if ($foundUser && password_verify($password, (string)$foundUser['password'])) {
                    session_regenerate_id(true);
                    $_SESSION['user_id'] = (int)$foundUser['id'];
                    $_SESSION['user_name'] = (string)$foundUser['name'];
                    $_SESSION['username'] = (string)$foundUser['username'];
                    $_SESSION['user_role'] = (string)$foundUser['role'];
                    $_SESSION['fingerprint'] = hash('sha256', ($_SERVER['HTTP_USER_AGENT'] ?? 'unknown'));
                    $_SESSION['last_activity'] = time();
                    redirect('index.php');
                } else {
                    $errors[] = 'اسم المستخدم أو كلمة المرور غير صحيحة.';
                }
            } catch (PDOException $e) {
                $errors[] = 'تعذر الاتصال بقاعدة البيانات. تأكد من تشغيل الخادم واستيراد ملف cmms_db.sql.';
            }
        }
    }
}

// رسائل من الرابط
$infoMessage = '';
if (isset($_GET['message']) && $_GET['message'] === 'logout') {
    $infoMessage = 'تم تسجيل الخروج بنجاح.';
} elseif (isset($_GET['error']) && $_GET['error'] === 'expired') {
    $infoMessage = 'انتهت الجلسة بسبب الخمول، يرجى تسجيل الدخول مجدداً.';
} elseif (isset($_GET['error']) && $_GET['error'] === 'session') {
    $infoMessage = 'تم إنهاء الجلسة لأسباب أمنية، يرجى تسجيل الدخول مجدداً.';
}
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>تسجيل الدخول — <?php echo e(APP_NAME); ?></title>
<link rel="stylesheet" href="style.css">
</head>
<body>
<div class="login-wrapper">
  <div class="login-box">
    <h1><?php echo e(APP_NAME); ?></h1>
    <p class="subtitle">تسجيل الدخول إلى النظام</p>

    <?php if ($infoMessage !== ''): ?>
      <div class="alert alert-info"><?php echo e($infoMessage); ?></div>
    <?php endif; ?>

    <?php foreach ($errors as $error): ?>
      <div class="alert alert-error"><?php echo e($error); ?></div>
    <?php endforeach; ?>

    <form method="post" action="login.php" id="loginForm" novalidate>
      <?php echo csrfField(); ?>
      <div class="form-group">
        <label for="username">اسم المستخدم</label>
        <input type="text" id="username" name="username" class="form-control"
               value="<?php echo e($username); ?>" required minlength="3" maxlength="50"
               autocomplete="username" placeholder="مثال: admin">
      </div>
      <div class="form-group">
        <label for="password">كلمة المرور</label>
        <input type="password" id="password" name="password" class="form-control"
               required minlength="6" autocomplete="current-password" placeholder="••••••">
      </div>
      <button type="submit" class="btn btn-primary">دخول</button>
    </form>

    <!-- <div class="demo-box">
      <strong>حسابات تجريبية:</strong><br>
      مدير: <code>admin</code> / <code>admin123</code><br>
      فني: <code>tech</code> / <code>tech123</code><br>
      مستخدم: <code>user</code> / <code>user123</code>
    </div> -->
  </div>
</div>

<script>
// تحقق من جهة العميل
document.getElementById('loginForm').addEventListener('submit', function (event) {
  var username = document.getElementById('username').value.trim();
  var password = document.getElementById('password').value;
  if (username.length < 3) {
    alert('يرجى إدخال اسم مستخدم صحيح (3 أحرف على الأقل).');
    event.preventDefault();
    return;
  }
  if (password.length < 6) {
    alert('كلمة المرور قصيرة جداً (6 أحرف على الأقل).');
    event.preventDefault();
  }
});
</script>
</body>
</html>
