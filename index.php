<?php
// =====================================================
// نظام إدارة صيانة الحاسوب (CMMS)
// الملف: index.php — لوحة التحكم + كل الصفحات (توجيه عبر ?page=)
// =====================================================

require_once __DIR__ . '/config.php';
requireLogin();

$currentUser = currentUser();
$role = (string)$currentUser['role'];
$myId = (int)$currentUser['id'];
$db = getDB();

// ---------- الصفحة المطلوبة ----------
$page = (string)($_GET['page'] ?? 'dashboard');
$validPages = ['dashboard', 'equipment', 'requests', 'request_add', 'request_view', 'users'];
if (!in_array($page, $validPages, true)) {
    $page = 'dashboard';
}
// صفحات المدير فقط
if (($page === 'equipment' || $page === 'users') && $role !== 'admin') {
    setFlash('غير مصرح لك بالوصول إلى هذه الصفحة.', 'error');
    redirect('index.php?page=dashboard');
}
// صفحة إنشاء طلب: للمستخدم والمدير فقط (الفني لا ينشئ طلبات)
if ($page === 'request_add' && !in_array($role, ['user', 'admin'], true)) {
    setFlash('غير مصرح لك بإنشاء طلبات صيانة.', 'error');
    redirect('index.php?page=dashboard');
}

// =====================================================
// معالجة نماذج POST (قبل أي مخرجات)
// =====================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrf()) {
        setFlash('انتهت صلاحية النموذج، يرجى المحاولة مرة أخرى.', 'error');
        redirect('index.php?page=' . urlencode($page));
    }

    $form = (string)($_POST['form'] ?? '');

    // ----- إضافة جهاز (مدير) -----
    if ($form === 'equipment_add') {
        requireRole(['admin']);
        $name = clean($_POST['name'] ?? '');
        $location = clean($_POST['location'] ?? '');
        $status = (string)($_POST['status'] ?? 'working');
        if (mb_strlen($name) < 3 || mb_strlen($name) > 100) {
            setFlash('اسم الجهاز يجب أن يكون بين 3 و 100 حرف.', 'error');
        } elseif (mb_strlen($location) < 2 || mb_strlen($location) > 100) {
            setFlash('الموقع يجب أن يكون بين 2 و 100 حرف.', 'error');
        } elseif (!in_array($status, equipmentStatuses(), true)) {
            setFlash('حالة الجهاز غير صالحة.', 'error');
        } else {
            $stmt = $db->prepare('INSERT INTO equipment (name, location, status) VALUES (?, ?, ?)');
            $stmt->execute([$name, $location, $status]);
            setFlash('تمت إضافة الجهاز بنجاح.');
        }
        redirect('index.php?page=equipment');
    }

    // ----- تعديل جهاز (مدير) -----
    if ($form === 'equipment_edit') {
        requireRole(['admin']);
        $id = (int)($_POST['id'] ?? 0);
        $name = clean($_POST['name'] ?? '');
        $location = clean($_POST['location'] ?? '');
        $status = (string)($_POST['status'] ?? 'working');
        if ($id <= 0) {
            setFlash('معرف الجهاز غير صالح.', 'error');
        } elseif (mb_strlen($name) < 3 || mb_strlen($name) > 100) {
            setFlash('اسم الجهاز يجب أن يكون بين 3 و 100 حرف.', 'error');
        } elseif (mb_strlen($location) < 2 || mb_strlen($location) > 100) {
            setFlash('الموقع يجب أن يكون بين 2 و 100 حرف.', 'error');
        } elseif (!in_array($status, equipmentStatuses(), true)) {
            setFlash('حالة الجهاز غير صالحة.', 'error');
        } else {
            $stmt = $db->prepare('UPDATE equipment SET name = ?, location = ?, status = ? WHERE id = ?');
            $stmt->execute([$name, $location, $status, $id]);
            setFlash('تم تحديث الجهاز بنجاح.');
        }
        redirect('index.php?page=equipment');
    }

    // ----- حذف جهاز (مدير) -----
    if ($form === 'equipment_delete') {
        requireRole(['admin']);
        $id = (int)($_POST['id'] ?? 0);
        if ($id > 0) {
            $stmt = $db->prepare('DELETE FROM equipment WHERE id = ?');
            $stmt->execute([$id]);
            setFlash('تم حذف الجهاز بنجاح.');
        } else {
            setFlash('معرف الجهاز غير صالح.', 'error');
        }
        redirect('index.php?page=equipment');
    }

    // ----- إنشاء طلب صيانة (مستخدم / مدير) -----
    if ($form === 'request_add') {
        requireRole(['user', 'admin']);
        $title = clean($_POST['title'] ?? '');
        $description = clean($_POST['description'] ?? '');
        $equipmentId = ($_POST['equipment_id'] ?? '') !== '' ? (int)$_POST['equipment_id'] : null;
        $priority = (string)($_POST['priority'] ?? 'medium');
        if (mb_strlen($title) < 5 || mb_strlen($title) > 150) {
            setFlash('عنوان الطلب يجب أن يكون بين 5 و 150 حرف.', 'error');
            redirect('index.php?page=request_add');
        }
        if (mb_strlen($description) < 5) {
            setFlash('يرجى كتابة وصف واضح للعطل (5 أحرف على الأقل).', 'error');
            redirect('index.php?page=request_add');
        }
        if (!in_array($priority, priorities(), true)) {
            setFlash('الأولوية غير صالحة.', 'error');
            redirect('index.php?page=request_add');
        }
        if ($equipmentId !== null) {
            $check = $db->prepare('SELECT id FROM equipment WHERE id = ?');
            $check->execute([$equipmentId]);
            if (!$check->fetch()) {
                setFlash('الجهاز المحدد غير موجود.', 'error');
                redirect('index.php?page=request_add');
            }
        }
        $stmt = $db->prepare('INSERT INTO requests (title, description, equipment_id, requested_by, priority, status) VALUES (?, ?, ?, ?, ?, ?)');
        $stmt->execute([$title, $description, $equipmentId, $myId, $priority, 'new']);
        setFlash('تم إرسال طلب الصيانة بنجاح وسيتم مراجعته قريباً.');
        redirect('index.php?page=requests');
    }

    // ----- إسناد طلب لفني (مدير) -----
    if ($form === 'request_assign') {
        requireRole(['admin']);
        $id = (int)($_POST['id'] ?? 0);
        $assignedTo = ($_POST['assigned_to'] ?? '') !== '' ? (int)$_POST['assigned_to'] : null;
        $status = (string)($_POST['status'] ?? 'new');
        $priority = (string)($_POST['priority'] ?? 'medium');
        if ($id <= 0) {
            setFlash('معرف الطلب غير صالح.', 'error');
            redirect('index.php?page=requests');
        }
        if (!in_array($status, requestStatuses(), true) || !in_array($priority, priorities(), true)) {
            setFlash('الحالة أو الأولوية غير صالحة.', 'error');
            redirect('index.php?page=request_view&id=' . $id);
        }
        if ($assignedTo !== null) {
            $check = $db->prepare("SELECT id FROM users WHERE id = ? AND role = 'technician'");
            $check->execute([$assignedTo]);
            if (!$check->fetch()) {
                setFlash('الفني المحدد غير موجود.', 'error');
                redirect('index.php?page=request_view&id=' . $id);
            }
        }
        $stmt = $db->prepare('UPDATE requests SET assigned_to = ?, status = ?, priority = ? WHERE id = ?');
        $stmt->execute([$assignedTo, $status, $priority, $id]);
        setFlash('تم حفظ الإسناد والحالة بنجاح.');
        redirect('index.php?page=request_view&id=' . $id);
    }

    // ----- تحديث الحالة + ملاحظات الإصلاح (فني / مدير) -----
    if ($form === 'request_status') {
        requireRole(['technician', 'admin']);
        $id = (int)($_POST['id'] ?? 0);
        $status = (string)($_POST['status'] ?? '');
        $repairNotes = clean($_POST['repair_notes'] ?? '');
        $allowed = $role === 'admin' ? requestStatuses() : ['new', 'in_progress', 'completed'];
        if ($id <= 0) {
            setFlash('معرف الطلب غير صالح.', 'error');
            redirect('index.php?page=requests');
        }
        if (!in_array($status, $allowed, true)) {
            setFlash('الحالة غير صالحة أو غير مسموحة لدورك.', 'error');
            redirect('index.php?page=request_view&id=' . $id);
        }
        if (mb_strlen($repairNotes) > 2000) {
            setFlash('ملاحظات الإصلاح طويلة جداً (الحد 2000 حرف).', 'error');
            redirect('index.php?page=request_view&id=' . $id);
        }
        // الفني يحدث فقط الطلبات المسندة إليه
        if ($role === 'technician') {
            $check = $db->prepare('SELECT id FROM requests WHERE id = ? AND assigned_to = ?');
            $check->execute([$id, $myId]);
            if (!$check->fetch()) {
                setFlash('غير مصرح لك بتحديث هذا الطلب (غير مسند إليك).', 'error');
                redirect('index.php?page=requests');
            }
        }
        $stmt = $db->prepare('UPDATE requests SET status = ?, repair_notes = ? WHERE id = ?');
        $stmt->execute([$status, ($repairNotes !== '' ? $repairNotes : null), $id]);
        setFlash('تم تحديث حالة الطلب وملاحظات الإصلاح بنجاح.');
        redirect('index.php?page=request_view&id=' . $id);
    }

    // ----- حذف طلب (مدير) -----
    if ($form === 'request_delete') {
        requireRole(['admin']);
        $id = (int)($_POST['id'] ?? 0);
        if ($id > 0) {
            $stmt = $db->prepare('DELETE FROM requests WHERE id = ?');
            $stmt->execute([$id]);
            setFlash('تم حذف الطلب بنجاح.');
        } else {
            setFlash('معرف الطلب غير صالح.', 'error');
        }
        redirect('index.php?page=requests');
    }

    // ----- إضافة مستخدم (مدير) -----
    if ($form === 'user_add') {
        requireRole(['admin']);
        $name = clean($_POST['name'] ?? '');
        $username = clean($_POST['username'] ?? '');
        $password = (string)($_POST['password'] ?? '');
        $newRole = (string)($_POST['role'] ?? 'user');
        if (mb_strlen($name) < 3 || mb_strlen($name) > 100) {
            setFlash('الاسم يجب أن يكون بين 3 و 100 حرف.', 'error');
        } elseif (!preg_match('/^[a-zA-Z0-9_]{3,50}$/', $username)) {
            setFlash('اسم الدخول يجب أن يكون إنجليزياً (3-50 حرف: أحرف وأرقام و _ فقط).', 'error');
        } elseif (strlen($password) < 6) {
            setFlash('كلمة المرور يجب أن تكون 6 أحرف على الأقل.', 'error');
        } elseif (!in_array($newRole, roles(), true)) {
            setFlash('الدور غير صالح.', 'error');
        } else {
            $check = $db->prepare('SELECT id FROM users WHERE username = ?');
            $check->execute([$username]);
            if ($check->fetch()) {
                setFlash('اسم الدخول مستخدم مسبقاً، اختر اسماً آخر.', 'error');
            } else {
                $hash = password_hash($password, PASSWORD_DEFAULT);
                $stmt = $db->prepare('INSERT INTO users (name, username, password, role) VALUES (?, ?, ?, ?)');
                $stmt->execute([$name, $username, $hash, $newRole]);
                setFlash('تمت إضافة المستخدم بنجاح.');
            }
        }
        redirect('index.php?page=users');
    }

    // ----- حذف مستخدم (مدير) -----
    if ($form === 'user_delete') {
        requireRole(['admin']);
        $id = (int)($_POST['id'] ?? 0);
        if ($id <= 0) {
            setFlash('معرف المستخدم غير صالح.', 'error');
        } elseif ($id === $myId) {
            setFlash('لا يمكنك حذف حسابك الخاص.', 'error');
        } else {
            $stmt = $db->prepare('DELETE FROM users WHERE id = ?');
            $stmt->execute([$id]);
            setFlash('تم حذف المستخدم بنجاح.');
        }
        redirect('index.php?page=users');
    }
}

// ---------- جلب رسالة التنبيه ----------
$flash = getFlash();

// ---------- دوال جلب البيانات ----------

function fetchCounts(string $sql, array $params = []): array {
    $db = getDB();
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

function countByStatus(array $rows): array {
    $out = ['new' => 0, 'in_progress' => 0, 'completed' => 0, 'closed' => 0];
    foreach ($rows as $row) {
        $status = (string)($row['status'] ?? '');
        if (isset($out[$status])) {
            $out[$status] = (int)$row['c'];
        }
    }
    return $out;
}

// ---------- تجهيز بيانات كل صفحة ----------
$dashboardData = [];
$equipmentList = [];
$editEquipment = null;
$requestList = [];
$technicians = [];
$equipmentOptions = [];
$viewRequest = null;
$userList = [];

if ($page === 'dashboard') {
    if ($role === 'admin') {
        $dashboardData['totalEquipment'] = (int)$db->query('SELECT COUNT(*) AS c FROM equipment')->fetch()['c'];
        $dashboardData['totalRequests'] = (int)$db->query('SELECT COUNT(*) AS c FROM requests')->fetch()['c'];
        $dashboardData['totalUsers'] = (int)$db->query('SELECT COUNT(*) AS c FROM users')->fetch()['c'];
        $dashboardData['byStatus'] = countByStatus(fetchCounts('SELECT status, COUNT(*) AS c FROM requests GROUP BY status'));
        $dashboardData['byPriority'] = fetchCounts('SELECT priority, COUNT(*) AS c FROM requests GROUP BY priority');
        $dashboardData['byTechnician'] = fetchCounts("SELECT u.name AS name, COUNT(r.id) AS total FROM users u LEFT JOIN requests r ON r.assigned_to = u.id WHERE u.role = 'technician' GROUP BY u.id, u.name ORDER BY total DESC");
        $dashboardData['byEquipment'] = fetchCounts('SELECT eq.name AS name, COUNT(r.id) AS total FROM equipment eq LEFT JOIN requests r ON r.equipment_id = eq.id GROUP BY eq.id, eq.name ORDER BY total DESC LIMIT 5');
        $dashboardData['recent'] = fetchCounts('SELECT r.*, eq.name AS equipment_name, u1.name AS requester_name, u2.name AS tech_name FROM requests r LEFT JOIN equipment eq ON eq.id = r.equipment_id LEFT JOIN users u1 ON u1.id = r.requested_by LEFT JOIN users u2 ON u2.id = r.assigned_to ORDER BY r.created_at DESC LIMIT 5');
    } elseif ($role === 'technician') {
        $dashboardData['byStatus'] = countByStatus(fetchCounts('SELECT status, COUNT(*) AS c FROM requests WHERE assigned_to = ? GROUP BY status', [$myId]));
        $dashboardData['totalMine'] = array_sum($dashboardData['byStatus']);
        $dashboardData['recent'] = fetchCounts('SELECT r.*, eq.name AS equipment_name, u1.name AS requester_name FROM requests r LEFT JOIN equipment eq ON eq.id = r.equipment_id LEFT JOIN users u1 ON u1.id = r.requested_by WHERE r.assigned_to = ? ORDER BY r.created_at DESC LIMIT 10', [$myId]);
    } else {
        $dashboardData['byStatus'] = countByStatus(fetchCounts('SELECT status, COUNT(*) AS c FROM requests WHERE requested_by = ? GROUP BY status', [$myId]));
        $dashboardData['totalMine'] = array_sum($dashboardData['byStatus']);
        $dashboardData['recent'] = fetchCounts('SELECT r.*, eq.name AS equipment_name, u2.name AS tech_name FROM requests r LEFT JOIN equipment eq ON eq.id = r.equipment_id LEFT JOIN users u2 ON u2.id = r.assigned_to WHERE r.requested_by = ? ORDER BY r.created_at DESC LIMIT 10', [$myId]);
    }
}

if ($page === 'equipment' && $role === 'admin') {
    $equipmentList = fetchCounts('SELECT e.*, (SELECT COUNT(*) FROM requests r WHERE r.equipment_id = e.id) AS request_count FROM equipment e ORDER BY e.id DESC');
    if (isset($_GET['edit'])) {
        $editId = (int)$_GET['edit'];
        $stmt = $db->prepare('SELECT * FROM equipment WHERE id = ?');
        $stmt->execute([$editId]);
        $editEquipment = $stmt->fetch() ?: null;
    }
}

if ($page === 'requests') {
    $statusFilter = (string)($_GET['status'] ?? '');
    $where = [];
    $params = [];
    if ($role === 'technician') {
        $where[] = 'r.assigned_to = ?';
        $params[] = $myId;
    } elseif ($role === 'user') {
        $where[] = 'r.requested_by = ?';
        $params[] = $myId;
    }
    if ($statusFilter !== '' && in_array($statusFilter, requestStatuses(), true)) {
        $where[] = 'r.status = ?';
        $params[] = $statusFilter;
    }
    $whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';
    $requestList = fetchCounts('SELECT r.*, eq.name AS equipment_name, u1.name AS requester_name, u2.name AS tech_name FROM requests r LEFT JOIN equipment eq ON eq.id = r.equipment_id LEFT JOIN users u1 ON u1.id = r.requested_by LEFT JOIN users u2 ON u2.id = r.assigned_to ' . $whereSql . ' ORDER BY r.created_at DESC', $params);
}

if ($page === 'request_add') {
    $equipmentOptions = fetchCounts('SELECT id, name, location FROM equipment ORDER BY name');
}

if ($page === 'request_view') {
    $viewId = (int)($_GET['id'] ?? 0);
    if ($viewId > 0) {
        $stmt = $db->prepare('SELECT r.*, eq.name AS equipment_name, eq.location AS equipment_location, u1.name AS requester_name, u2.name AS tech_name FROM requests r LEFT JOIN equipment eq ON eq.id = r.equipment_id LEFT JOIN users u1 ON u1.id = r.requested_by LEFT JOIN users u2 ON u2.id = r.assigned_to WHERE r.id = ?');
        $stmt->execute([$viewId]);
        $row = $stmt->fetch();
        if ($row) {
            $canView = $role === 'admin'
                || ($role === 'technician' && (int)($row['assigned_to'] ?? 0) === $myId)
                || ($role === 'user' && (int)($row['requested_by'] ?? 0) === $myId);
            if ($canView) {
                $viewRequest = $row;
                $technicians = fetchCounts("SELECT id, name FROM users WHERE role = 'technician' ORDER BY name");
            } else {
                setFlash('غير مصرح لك بعرض هذا الطلب.', 'error');
                redirect('index.php?page=requests');
            }
        }
    }
    if ($viewRequest === null && !isset($_SESSION['flash'])) {
        setFlash('الطلب غير موجود.', 'error');
        redirect('index.php?page=requests');
    }
}

if ($page === 'users' && $role === 'admin') {
    $userList = fetchCounts('SELECT id, name, username, role FROM users ORDER BY id');
}

// عنوان الصفحة
$pageTitles = [
    'dashboard' => 'لوحة التحكم',
    'equipment' => 'إدارة الأجهزة',
    'requests' => $role === 'admin' ? 'كل طلبات الصيانة' : ($role === 'technician' ? 'مهامي المسندة' : 'طلباتي'),
    'request_add' => 'إنشاء طلب صيانة جديد',
    'request_view' => 'تفاصيل الطلب',
    'users' => 'إدارة المستخدمين',
];
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?php echo e($pageTitles[$page] ?? 'لوحة التحكم'); ?> — <?php echo e(APP_NAME); ?></title>
<link rel="stylesheet" href="style.css">
</head>
<body>

<!-- الشريط العلوي -->
<nav class="navbar">
  <a class="brand" href="index.php"><?php echo e(APP_NAME); ?></a>
  <div class="links">
    <a class="nav-link <?php echo $page === 'dashboard' ? 'active' : ''; ?>" href="index.php?page=dashboard">لوحة التحكم</a>
    <a class="nav-link <?php echo ($page === 'requests' || $page === 'request_view') ? 'active' : ''; ?>" href="index.php?page=requests"><?php echo $role === 'admin' ? 'كل الطلبات' : ($role === 'technician' ? 'مهامي' : 'طلباتي'); ?></a>
    <?php if (in_array($role, ['user', 'admin'], true)): ?>
      <a class="nav-link <?php echo $page === 'request_add' ? 'active' : ''; ?>" href="index.php?page=request_add">طلب جديد</a>
    <?php endif; ?>
    <?php if ($role === 'admin'): ?>
      <a class="nav-link <?php echo $page === 'equipment' ? 'active' : ''; ?>" href="index.php?page=equipment">الأجهزة</a>
      <a class="nav-link <?php echo $page === 'users' ? 'active' : ''; ?>" href="index.php?page=users">المستخدمون</a>
    <?php endif; ?>
  </div>
  <div class="user-box">
    <span><?php echo e($currentUser['name']); ?> (<?php echo e(roleLabel($role)); ?>)</span>
    <a class="logout-btn" href="login.php?action=logout">خروج</a>
  </div>
</nav>

<div class="container">

  <?php if ($flash): ?>
    <div class="alert alert-<?php echo e($flash['type']); ?>"><?php echo e($flash['message']); ?></div>
  <?php endif; ?>

  <!-- ================= لوحة التحكم ================= -->
  <?php if ($page === 'dashboard'): ?>
    <div class="page-title">
      <h1>لوحة التحكم — مرحباً <?php echo e($currentUser['name']); ?></h1>
    </div>

    <?php if ($role === 'admin'): ?>
      <div class="stats-grid">
        <div class="stat-card"><div class="number"><?php echo (int)$dashboardData['totalEquipment']; ?></div><div class="label">إجمالي الأجهزة</div></div>
        <div class="stat-card"><div class="number"><?php echo (int)$dashboardData['totalRequests']; ?></div><div class="label">إجمالي الطلبات</div></div>
        <div class="stat-card"><div class="number"><?php echo (int)$dashboardData['byStatus']['new']; ?></div><div class="label">طلبات جديدة</div></div>
        <div class="stat-card"><div class="number"><?php echo (int)$dashboardData['byStatus']['in_progress']; ?></div><div class="label">قيد التنفيذ</div></div>
        <div class="stat-card"><div class="number"><?php echo (int)$dashboardData['byStatus']['completed']; ?></div><div class="label">مكتملة</div></div>
        <div class="stat-card"><div class="number"><?php echo (int)$dashboardData['byStatus']['closed']; ?></div><div class="label">مغلقة</div></div>
        <div class="stat-card"><div class="number"><?php echo (int)$dashboardData['totalUsers']; ?></div><div class="label">المستخدمون</div></div>
      </div>

      <div class="card">
        <h3>أحدث الطلبات</h3>
        <div class="table-wrap">
        <table class="table">
          <tr><th>الرقم</th><th>العنوان</th><th>الجهاز</th><th>مقدم الطلب</th><th>الفني</th><th>الحالة</th><th>الإجراء</th></tr>
          <?php if (!$dashboardData['recent']): ?>
            <tr><td colspan="7">لا توجد طلبات بعد.</td></tr>
          <?php endif; ?>
          <?php foreach ($dashboardData['recent'] as $r): ?>
            <tr>
              <td>#<?php echo (int)$r['id']; ?></td>
              <td><?php echo e($r['title']); ?></td>
              <td><?php echo e($r['equipment_name'] ?? '—'); ?></td>
              <td><?php echo e($r['requester_name'] ?? '—'); ?></td>
              <td><?php echo e($r['tech_name'] ?? 'غير مسند'); ?></td>
              <td><span class="badge badge-<?php echo e($r['status']); ?>"><?php echo e(requestStatusLabel($r['status'])); ?></span></td>
              <td><a class="btn btn-primary btn-small" href="index.php?page=request_view&id=<?php echo (int)$r['id']; ?>">عرض</a></td>
            </tr>
          <?php endforeach; ?>
        </table>
        </div>
      </div>

      <div class="form-row">
        <div class="card">
          <h3>تقرير: الطلبات حسب الأولوية</h3>
          <div class="table-wrap">
          <table class="table">
            <tr><th>الأولوية</th><th>العدد</th></tr>
            <?php foreach ($dashboardData['byPriority'] as $row): ?>
              <tr><td><span class="badge badge-<?php echo e($row['priority']); ?>"><?php echo e(priorityLabel($row['priority'])); ?></span></td><td><?php echo (int)$row['c']; ?></td></tr>
            <?php endforeach; ?>
          </table>
          </div>
        </div>
        <div class="card">
          <h3>تقرير: عبء العمل حسب الفني</h3>
          <div class="table-wrap">
          <table class="table">
            <tr><th>الفني</th><th>عدد الطلبات المسندة</th></tr>
            <?php if (!$dashboardData['byTechnician']): ?>
              <tr><td colspan="2">لا يوجد فنيون بعد.</td></tr>
            <?php endif; ?>
            <?php foreach ($dashboardData['byTechnician'] as $row): ?>
              <tr><td><?php echo e($row['name']); ?></td><td><?php echo (int)$row['total']; ?></td></tr>
            <?php endforeach; ?>
          </table>
          </div>
        </div>
      </div>

      <div class="card">
        <h3>تقرير: أكثر الأجهزة طلباً للصيانة</h3>
        <div class="table-wrap">
        <table class="table">
          <tr><th>الجهاز</th><th>عدد الطلبات</th></tr>
          <?php foreach ($dashboardData['byEquipment'] as $row): ?>
            <tr><td><?php echo e($row['name']); ?></td><td><?php echo (int)$row['total']; ?></td></tr>
          <?php endforeach; ?>
        </table>
        </div>
      </div>

    <?php elseif ($role === 'technician'): ?>
      <div class="stats-grid">
        <div class="stat-card"><div class="number"><?php echo (int)$dashboardData['totalMine']; ?></div><div class="label">إجمالي مهامي</div></div>
        <div class="stat-card"><div class="number"><?php echo (int)$dashboardData['byStatus']['new']; ?></div><div class="label">بانتظار المعالجة</div></div>
        <div class="stat-card"><div class="number"><?php echo (int)$dashboardData['byStatus']['in_progress']; ?></div><div class="label">قيد التنفيذ</div></div>
        <div class="stat-card"><div class="number"><?php echo (int)$dashboardData['byStatus']['completed']; ?></div><div class="label">مكتملة</div></div>
      </div>
      <div class="card">
        <h3>مهامي الحالية</h3>
        <div class="table-wrap">
        <table class="table">
          <tr><th>الرقم</th><th>العنوان</th><th>الجهاز</th><th>الأولوية</th><th>الحالة</th><th>الإجراء</th></tr>
          <?php if (!$dashboardData['recent']): ?>
            <tr><td colspan="6">لا توجد مهام مسندة إليك حالياً.</td></tr>
          <?php endif; ?>
          <?php foreach ($dashboardData['recent'] as $r): ?>
            <tr>
              <td>#<?php echo (int)$r['id']; ?></td>
              <td><?php echo e($r['title']); ?></td>
              <td><?php echo e($r['equipment_name'] ?? '—'); ?></td>
              <td><span class="badge badge-<?php echo e($r['priority']); ?>"><?php echo e(priorityLabel($r['priority'])); ?></span></td>
              <td><span class="badge badge-<?php echo e($r['status']); ?>"><?php echo e(requestStatusLabel($r['status'])); ?></span></td>
              <td><a class="btn btn-primary btn-small" href="index.php?page=request_view&id=<?php echo (int)$r['id']; ?>">تحديث</a></td>
            </tr>
          <?php endforeach; ?>
        </table>
        </div>
      </div>

    <?php else: ?>
      <div class="stats-grid">
        <div class="stat-card"><div class="number"><?php echo (int)$dashboardData['totalMine']; ?></div><div class="label">طلباتي</div></div>
        <div class="stat-card"><div class="number"><?php echo (int)$dashboardData['byStatus']['new']; ?></div><div class="label">جديدة</div></div>
        <div class="stat-card"><div class="number"><?php echo (int)$dashboardData['byStatus']['in_progress']; ?></div><div class="label">قيد التنفيذ</div></div>
        <div class="stat-card"><div class="number"><?php echo (int)$dashboardData['byStatus']['completed']; ?></div><div class="label">مكتملة</div></div>
        <div class="stat-card"><div class="number"><?php echo (int)$dashboardData['byStatus']['closed']; ?></div><div class="label">مغلقة</div></div>
      </div>
      <div class="card">
        <div class="page-title"><h3>أحدث طلباتي</h3><a class="btn btn-primary" href="index.php?page=request_add">+ طلب جديد</a></div>
        <div class="table-wrap">
        <table class="table">
          <tr><th>الرقم</th><th>العنوان</th><th>الجهاز</th><th>الحالة</th><th>التاريخ</th><th>الإجراء</th></tr>
          <?php if (!$dashboardData['recent']): ?>
            <tr><td colspan="6">لم تقدم أي طلب بعد. <a href="index.php?page=request_add">أنشئ طلبك الأول</a>.</td></tr>
          <?php endif; ?>
          <?php foreach ($dashboardData['recent'] as $r): ?>
            <tr>
              <td>#<?php echo (int)$r['id']; ?></td>
              <td><?php echo e($r['title']); ?></td>
              <td><?php echo e($r['equipment_name'] ?? '—'); ?></td>
              <td><span class="badge badge-<?php echo e($r['status']); ?>"><?php echo e(requestStatusLabel($r['status'])); ?></span></td>
              <td><?php echo e($r['created_at']); ?></td>
              <td><a class="btn btn-primary btn-small" href="index.php?page=request_view&id=<?php echo (int)$r['id']; ?>">عرض</a></td>
            </tr>
          <?php endforeach; ?>
        </table>
        </div>
      </div>
    <?php endif; ?>

  <!-- ================= إدارة الأجهزة (مدير) ================= -->
  <?php elseif ($page === 'equipment'): ?>
    <div class="page-title"><h1>إدارة الأجهزة</h1></div>

    <?php if ($editEquipment): ?>
      <div class="card">
        <h3>تعديل الجهاز #<?php echo (int)$editEquipment['id']; ?></h3>
        <form method="post" action="index.php?page=equipment" class="validate-form">
          <?php echo csrfField(); ?>
          <input type="hidden" name="form" value="equipment_edit">
          <input type="hidden" name="id" value="<?php echo (int)$editEquipment['id']; ?>">
          <div class="form-row">
            <div class="form-group">
              <label>اسم الجهاز</label>
              <input type="text" name="name" class="form-control" required minlength="3" maxlength="100" value="<?php echo e($editEquipment['name']); ?>">
            </div>
            <div class="form-group">
              <label>الموقع</label>
              <input type="text" name="location" class="form-control" required minlength="2" maxlength="100" value="<?php echo e($editEquipment['location']); ?>">
            </div>
          </div>
          <div class="form-group">
            <label>الحالة</label>
            <select name="status" class="form-control" required>
              <?php foreach (equipmentStatuses() as $s): ?>
                <option value="<?php echo e($s); ?>" <?php echo $editEquipment['status'] === $s ? 'selected' : ''; ?>><?php echo e(equipmentStatusLabel($s)); ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <button type="submit" class="btn btn-success">حفظ التعديلات</button>
          <a class="btn btn-secondary" href="index.php?page=equipment">إلغاء</a>
        </form>
      </div>
    <?php else: ?>
      <div class="card">
        <h3>إضافة جهاز جديد</h3>
        <form method="post" action="index.php?page=equipment" class="validate-form">
          <?php echo csrfField(); ?>
          <input type="hidden" name="form" value="equipment_add">
          <div class="form-row">
            <div class="form-group">
              <label>اسم الجهاز</label>
              <input type="text" name="name" class="form-control" required minlength="3" maxlength="100" placeholder="مثال: حاسوب مكتبي HP - رقم 4">
            </div>
            <div class="form-group">
              <label>الموقع</label>
              <input type="text" name="location" class="form-control" required minlength="2" maxlength="100" placeholder="مثال: مختبر 2">
            </div>
          </div>
          <div class="form-group">
            <label>الحالة</label>
            <select name="status" class="form-control" required>
              <?php foreach (equipmentStatuses() as $s): ?>
                <option value="<?php echo e($s); ?>"><?php echo e(equipmentStatusLabel($s)); ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <button type="submit" class="btn btn-primary">إضافة الجهاز</button>
        </form>
      </div>
    <?php endif; ?>

    <div class="card">
      <h3>قائمة الأجهزة (<?php echo count($equipmentList); ?>)</h3>
      <input type="text" id="tableSearch" class="form-control search-box" placeholder="بحث في الجدول...">
      <div class="table-wrap">
      <table class="table" id="dataTable">
        <tr><th>الرقم</th><th>الاسم</th><th>الموقع</th><th>الحالة</th><th>عدد الطلبات</th><th>إجراءات</th></tr>
        <?php if (!$equipmentList): ?>
          <tr><td colspan="6">لا توجد أجهزة مسجلة.</td></tr>
        <?php endif; ?>
        <?php foreach ($equipmentList as $eq): ?>
          <tr>
            <td>#<?php echo (int)$eq['id']; ?></td>
            <td><?php echo e($eq['name']); ?></td>
            <td><?php echo e($eq['location']); ?></td>
            <td><span class="badge badge-<?php echo e($eq['status']); ?>"><?php echo e(equipmentStatusLabel($eq['status'])); ?></span></td>
            <td><?php echo (int)$eq['request_count']; ?></td>
            <td>
              <a class="btn btn-warning btn-small" href="index.php?page=equipment&edit=<?php echo (int)$eq['id']; ?>">تعديل</a>
              <form method="post" action="index.php?page=equipment" style="display:inline;" class="confirm-form" data-confirm="هل أنت متأكد من حذف هذا الجهاز؟">
                <?php echo csrfField(); ?>
                <input type="hidden" name="form" value="equipment_delete">
                <input type="hidden" name="id" value="<?php echo (int)$eq['id']; ?>">
                <button type="submit" class="btn btn-danger btn-small">حذف</button>
              </form>
            </td>
          </tr>
        <?php endforeach; ?>
      </table>
      </div>
    </div>

  <!-- ================= قائمة الطلبات ================= -->
  <?php elseif ($page === 'requests'): ?>
    <div class="page-title">
      <h1><?php echo e($pageTitles['requests']); ?> (<?php echo count($requestList); ?>)</h1>
      <?php if (in_array($role, ['user', 'admin'], true)): ?>
        <a class="btn btn-primary" href="index.php?page=request_add">+ طلب جديد</a>
      <?php endif; ?>
    </div>
    <div class="card">
      <form method="get" action="index.php" style="display:flex;gap:8px;flex-wrap:wrap;align-items:end;">
        <input type="hidden" name="page" value="requests">
        <div class="form-group" style="margin:0;">
          <label>تصفية حسب الحالة</label>
          <select name="status" class="form-control" onchange="this.form.submit()">
            <option value="">— الكل —</option>
            <?php foreach (requestStatuses() as $s): ?>
              <option value="<?php echo e($s); ?>" <?php echo (($_GET['status'] ?? '') === $s) ? 'selected' : ''; ?>><?php echo e(requestStatusLabel($s)); ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="form-group" style="margin:0;">
          <label>بحث</label>
          <input type="text" id="tableSearch" class="form-control" placeholder="بحث في الجدول...">
        </div>
      </form>
    </div>
    <div class="card">
      <div class="table-wrap">
      <table class="table" id="dataTable">
        <tr>
          <th>الرقم</th><th>العنوان</th><th>الجهاز</th>
          <?php if ($role === 'admin'): ?><th>مقدم الطلب</th><th>الفني</th><?php endif; ?>
          <?php if ($role === 'user'): ?><th>الفني</th><?php endif; ?>
          <?php if ($role === 'technician'): ?><th>مقدم الطلب</th><?php endif; ?>
          <th>الأولوية</th><th>الحالة</th><th>التاريخ</th><th>الإجراء</th>
        </tr>
        <?php if (!$requestList): ?>
          <tr><td colspan="9">لا توجد طلبات مطابقة.</td></tr>
        <?php endif; ?>
        <?php foreach ($requestList as $r): ?>
          <tr>
            <td>#<?php echo (int)$r['id']; ?></td>
            <td><?php echo e($r['title']); ?></td>
            <td><?php echo e($r['equipment_name'] ?? '—'); ?></td>
            <?php if ($role === 'admin'): ?>
              <td><?php echo e($r['requester_name'] ?? '—'); ?></td>
              <td><?php echo e($r['tech_name'] ?? 'غير مسند'); ?></td>
            <?php elseif ($role === 'user'): ?>
              <td><?php echo e($r['tech_name'] ?? 'بانتظار الإسناد'); ?></td>
            <?php else: ?>
              <td><?php echo e($r['requester_name'] ?? '—'); ?></td>
            <?php endif; ?>
            <td><span class="badge badge-<?php echo e($r['priority']); ?>"><?php echo e(priorityLabel($r['priority'])); ?></span></td>
            <td><span class="badge badge-<?php echo e($r['status']); ?>"><?php echo e(requestStatusLabel($r['status'])); ?></span></td>
            <td><?php echo e($r['created_at']); ?></td>
            <td><a class="btn btn-primary btn-small" href="index.php?page=request_view&id=<?php echo (int)$r['id']; ?>"><?php echo $role === 'technician' ? 'تحديث' : 'عرض'; ?></a></td>
          </tr>
        <?php endforeach; ?>
      </table>
      </div>
    </div>

  <!-- ================= إنشاء طلب جديد ================= -->
  <?php elseif ($page === 'request_add'): ?>
    <div class="page-title"><h1>إنشاء طلب صيانة جديد</h1></div>
    <div class="card">
      <form method="post" action="index.php?page=request_add" class="validate-form">
        <?php echo csrfField(); ?>
        <input type="hidden" name="form" value="request_add">
        <div class="form-group">
          <label>عنوان الطلب</label>
          <input type="text" name="title" class="form-control" required minlength="5" maxlength="150" placeholder="مثال: الطابعة لا تعمل">
        </div>
        <div class="form-row">
          <div class="form-group">
            <label>الجهاز المعني</label>
            <select name="equipment_id" class="form-control">
              <option value="">— بدون تحديد جهاز —</option>
              <?php foreach ($equipmentOptions as $eq): ?>
                <option value="<?php echo (int)$eq['id']; ?>"><?php echo e($eq['name']); ?> (<?php echo e($eq['location']); ?>)</option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="form-group">
            <label>الأولوية</label>
            <select name="priority" class="form-control" required>
              <?php foreach (priorities() as $p): ?>
                <option value="<?php echo e($p); ?>" <?php echo $p === 'medium' ? 'selected' : ''; ?>><?php echo e(priorityLabel($p)); ?></option>
              <?php endforeach; ?>
            </select>
          </div>
        </div>
        <div class="form-group">
          <label>وصف العطل بالتفصيل</label>
          <textarea name="description" class="form-control" required minlength="5" placeholder="اشرح المشكلة: ماذا يحدث؟ متى بدأت؟ أي رسائل خطأ تظهر؟"></textarea>
        </div>
        <button type="submit" class="btn btn-primary">إرسال الطلب</button>
        <a class="btn btn-secondary" href="index.php?page=requests">رجوع</a>
      </form>
    </div>

  <!-- ================= تفاصيل الطلب ================= -->
  <?php elseif ($page === 'request_view' && $viewRequest): ?>
    <?php $vr = $viewRequest; ?>
    <div class="page-title">
      <h1>الطلب #<?php echo (int)$vr['id']; ?>: <?php echo e($vr['title']); ?></h1>
      <a class="btn btn-secondary" href="index.php?page=requests">رجوع للقائمة</a>
    </div>

    <div class="card">
      <dl class="detail-grid">
        <dt>الحالة</dt><dd><span class="badge badge-<?php echo e($vr['status']); ?>"><?php echo e(requestStatusLabel($vr['status'])); ?></span></dd>
        <dt>الأولوية</dt><dd><span class="badge badge-<?php echo e($vr['priority']); ?>"><?php echo e(priorityLabel($vr['priority'])); ?></span></dd>
        <dt>الجهاز</dt><dd><?php echo e($vr['equipment_name'] ?? '—'); ?><?php echo !empty($vr['equipment_location']) ? ' (' . e($vr['equipment_location']) . ')' : ''; ?></dd>
        <dt>مقدم الطلب</dt><dd><?php echo e($vr['requester_name'] ?? '—'); ?></dd>
        <dt>الفني المسند</dt><dd><?php echo e($vr['tech_name'] ?? 'غير مسند بعد'); ?></dd>
        <dt>تاريخ الإنشاء</dt><dd><?php echo e($vr['created_at']); ?></dd>
        <dt>آخر تحديث</dt><dd><?php echo e($vr['updated_at']); ?></dd>
        <dt>وصف العطل</dt><dd><?php echo nl2br(e($vr['description'] ?? '')); ?></dd>
      </dl>
    </div>

    <div class="card">
      <h3>ملاحظات الإصلاح (من الفني)</h3>
      <?php if (!empty($vr['repair_notes'])): ?>
        <div class="notes-box"><?php echo e($vr['repair_notes']); ?></div>
      <?php else: ?>
        <p style="color:var(--muted);">لا توجد ملاحظات إصلاح بعد.</p>
      <?php endif; ?>
    </div>

    <?php if ($role === 'admin'): ?>
      <div class="card">
        <h3>إسناد وتحديث (المدير)</h3>
        <form method="post" action="index.php?page=request_view&id=<?php echo (int)$vr['id']; ?>" class="validate-form">
          <?php echo csrfField(); ?>
          <input type="hidden" name="form" value="request_assign">
          <input type="hidden" name="id" value="<?php echo (int)$vr['id']; ?>">
          <div class="form-row">
            <div class="form-group">
              <label>الفني المسند</label>
              <select name="assigned_to" class="form-control">
                <option value="">— بدون إسناد —</option>
                <?php foreach ($technicians as $t): ?>
                  <option value="<?php echo (int)$t['id']; ?>" <?php echo ((int)($vr['assigned_to'] ?? 0) === (int)$t['id']) ? 'selected' : ''; ?>><?php echo e($t['name']); ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="form-group">
              <label>الحالة</label>
              <select name="status" class="form-control" required>
                <?php foreach (requestStatuses() as $s): ?>
                  <option value="<?php echo e($s); ?>" <?php echo $vr['status'] === $s ? 'selected' : ''; ?>><?php echo e(requestStatusLabel($s)); ?></option>
                <?php endforeach; ?>
              </select>
            </div>
          </div>
          <div class="form-group">
            <label>الأولوية</label>
            <select name="priority" class="form-control" required>
              <?php foreach (priorities() as $p): ?>
                <option value="<?php echo e($p); ?>" <?php echo $vr['priority'] === $p ? 'selected' : ''; ?>><?php echo e(priorityLabel($p)); ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <button type="submit" class="btn btn-success">حفظ الإسناد والحالة</button>
        </form>
      </div>
      <div class="card">
        <h3>ملاحظات الإصلاح</h3>
        <form method="post" action="index.php?page=request_view&id=<?php echo (int)$vr['id']; ?>">
          <?php echo csrfField(); ?>
          <input type="hidden" name="form" value="request_status">
          <input type="hidden" name="id" value="<?php echo (int)$vr['id']; ?>">
          <input type="hidden" name="status" value="<?php echo e($vr['status']); ?>">
          <div class="form-group">
            <label>تعديل ملاحظات الإصلاح</label>
            <textarea name="repair_notes" class="form-control" maxlength="2000" placeholder="اكتب ملاحظات الإصلاح هنا..."><?php echo e($vr['repair_notes'] ?? ''); ?></textarea>
          </div>
          <button type="submit" class="btn btn-primary">حفظ الملاحظات</button>
        </form>
      </div>
      <div class="card">
        <h3>حذف الطلب</h3>
        <form method="post" action="index.php?page=request_view&id=<?php echo (int)$vr['id']; ?>" class="confirm-form" data-confirm="هل أنت متأكد من حذف هذا الطلب نهائياً؟">
          <?php echo csrfField(); ?>
          <input type="hidden" name="form" value="request_delete">
          <input type="hidden" name="id" value="<?php echo (int)$vr['id']; ?>">
          <button type="submit" class="btn btn-danger">حذف الطلب نهائياً</button>
        </form>
      </div>
    <?php endif; ?>

    <?php if ($role === 'technician'): ?>
      <div class="card">
        <h3>تحديث الحالة وملاحظات الإصلاح (الفني)</h3>
        <form method="post" action="index.php?page=request_view&id=<?php echo (int)$vr['id']; ?>" class="validate-form">
          <?php echo csrfField(); ?>
          <input type="hidden" name="form" value="request_status">
          <input type="hidden" name="id" value="<?php echo (int)$vr['id']; ?>">
          <div class="form-group">
            <label>حالة المهمة</label>
            <select name="status" class="form-control" required>
              <option value="new" <?php echo $vr['status'] === 'new' ? 'selected' : ''; ?>>بانتظار المعالجة</option>
              <option value="in_progress" <?php echo $vr['status'] === 'in_progress' ? 'selected' : ''; ?>>قيد التنفيذ</option>
              <option value="completed" <?php echo $vr['status'] === 'completed' ? 'selected' : ''; ?>>مكتملة</option>
            </select>
          </div>
          <div class="form-group">
            <label>ملاحظات الإصلاح</label>
            <textarea name="repair_notes" class="form-control" maxlength="2000" placeholder="مثال: تم استبدال كابل الطابعة وتنظيف رأس الطباعة، والجهاز يعمل الآن."><?php echo e($vr['repair_notes'] ?? ''); ?></textarea>
          </div>
          <button type="submit" class="btn btn-success">حفظ التحديث</button>
        </form>
      </div>
    <?php endif; ?>

  <!-- ================= إدارة المستخدمين (مدير) ================= -->
  <?php elseif ($page === 'users'): ?>
    <div class="page-title"><h1>إدارة المستخدمين</h1></div>
    <div class="card">
      <h3>إضافة مستخدم جديد</h3>
      <form method="post" action="index.php?page=users" class="validate-form">
        <?php echo csrfField(); ?>
        <input type="hidden" name="form" value="user_add">
        <div class="form-row">
          <div class="form-group">
            <label>الاسم الكامل</label>
            <input type="text" name="name" class="form-control" required minlength="3" maxlength="100" placeholder="مثال: أحمد محمد">
          </div>
          <div class="form-group">
            <label>اسم الدخول (إنجليزي)</label>
            <input type="text" name="username" class="form-control" required minlength="3" maxlength="50" pattern="[a-zA-Z0-9_]+" placeholder="مثال: ahmed_tech">
          </div>
        </div>
        <div class="form-row">
          <div class="form-group">
            <label>كلمة المرور</label>
            <input type="password" name="password" class="form-control" required minlength="6" placeholder="6 أحرف على الأقل">
          </div>
          <div class="form-group">
            <label>الدور</label>
            <select name="role" class="form-control" required>
              <?php foreach (roles() as $r): ?>
                <option value="<?php echo e($r); ?>"><?php echo e(roleLabel($r)); ?></option>
              <?php endforeach; ?>
            </select>
          </div>
        </div>
        <button type="submit" class="btn btn-primary">إضافة المستخدم</button>
      </form>
    </div>
    <div class="card">
      <h3>قائمة المستخدمين (<?php echo count($userList); ?>)</h3>
      <div class="table-wrap">
      <table class="table">
        <tr><th>الرقم</th><th>الاسم</th><th>اسم الدخول</th><th>الدور</th><th>إجراء</th></tr>
        <?php foreach ($userList as $u): ?>
          <tr>
            <td>#<?php echo (int)$u['id']; ?></td>
            <td><?php echo e($u['name']); ?></td>
            <td><code><?php echo e($u['username']); ?></code></td>
            <td><span class="badge badge-<?php echo e($u['role']); ?>"><?php echo e(roleLabel($u['role'])); ?></span></td>
            <td>
              <?php if ((int)$u['id'] !== $myId): ?>
                <form method="post" action="index.php?page=users" style="display:inline;" class="confirm-form" data-confirm="هل أنت متأكد من حذف هذا المستخدم؟ (سيتم حذف طلباته أيضاً)">
                  <?php echo csrfField(); ?>
                  <input type="hidden" name="form" value="user_delete">
                  <input type="hidden" name="id" value="<?php echo (int)$u['id']; ?>">
                  <button type="submit" class="btn btn-danger btn-small">حذف</button>
                </form>
              <?php else: ?>
                <span style="color:var(--muted);font-size:12px;">حسابك الحالي</span>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
      </table>
      </div>
    </div>
  <?php endif; ?>

</div>

<div class="footer"><?php echo e(APP_NAME); ?> — مشروع مقرر هندسة البرمجيات</div>

<script>
// تأكيد الحذف
document.querySelectorAll('.confirm-form').forEach(function (form) {
  form.addEventListener('submit', function (event) {
    var message = form.getAttribute('data-confirm') || 'هل أنت متأكد؟';
    if (!confirm(message)) {
      event.preventDefault();
    }
  });
});

// بحث فوري في الجداول
var searchInput = document.getElementById('tableSearch');
var dataTable = document.getElementById('dataTable');
if (searchInput && dataTable) {
  searchInput.addEventListener('input', function () {
    var keyword = searchInput.value.trim().toLowerCase();
    var rows = dataTable.querySelectorAll('tr');
    for (var i = 1; i < rows.length; i++) {
      var text = rows[i].textContent.toLowerCase();
      rows[i].style.display = text.indexOf(keyword) !== -1 ? '' : 'none';
    }
  });
}

// تحقق إضافي من جهة العميل
document.querySelectorAll('.validate-form').forEach(function (form) {
  form.addEventListener('submit', function (event) {
    var invalid = false;
    form.querySelectorAll('[required]').forEach(function (field) {
      if (field.value.trim() === '') {
        invalid = true;
        field.style.borderColor = '#e02424';
      } else {
        field.style.borderColor = '';
      }
    });
    if (invalid) {
      alert('يرجى تعبئة جميع الحقول المطلوبة.');
      event.preventDefault();
    }
  });
});
</script>
</body>
</html>
