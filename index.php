<?php
session_start();

include 'config.php';

$isAdmin = isset($_SESSION['admin_logged_in']) && $_SESSION['admin_logged_in'] === true;

// 2. จัดการคำขอต่างๆ (Handle POST Actions)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    // ระบบ Login ด้วย PHP (ใช้ password_verify สำหรับ Bcrypt)
    if ($action === 'login') {
        $user = trim($_POST['username']);
        $pass = trim($_POST['password']);

        $stmt = $db->prepare("SELECT * FROM admins WHERE username = ? LIMIT 1");
        $stmt->execute([$user]);
        $admin = $stmt->fetch(PDO::FETCH_ASSOC);

        // เช็ครหัสผ่าน
        if ($admin && password_verify($pass, $admin['password_hash'])) {
            $_SESSION['admin_logged_in'] = true;
            $_SESSION['swal'] = ['icon' => 'success', 'title' => 'เข้าสู่ระบบสำเร็จ'];
        } else {
            $_SESSION['swal'] = ['icon' => 'error', 'title' => 'ชื่อผู้ใช้หรือรหัสผ่านไม่ถูกต้อง'];
        }
        header("Location: index.php");
        exit;
    }

    // ระบบ Logout
    if ($action === 'logout') {
        session_destroy();
        session_start();
        $_SESSION['swal'] = ['icon' => 'success', 'title' => 'ออกจากระบบแล้ว'];
        header("Location: index.php");
        exit;
    }

    // ระบบเพิ่มและแก้ไขข้อมูล (Admin เท่านั้น)
    if ($isAdmin && $action === 'save_project') {
        $id = $_POST['id'] ?? '';
        $name = htmlspecialchars(trim($_POST['name']));
        $desc = htmlspecialchars(trim($_POST['desc']));
        $date = $_POST['date'];
        $status = $_POST['status'];
        $link = filter_var($_POST['link'], FILTER_SANITIZE_URL);

        // จัดการอัปโหลดรูปภาพที่ถูกแปลงมาจาก JS Canvas (Base64)
        $image_path = $_POST['old_image'] ?? '';
        $base64_image = $_POST['imageBase64'] ?? '';
        
        if (!empty($base64_image)) {
            $upload_dir = 'uploads/';
            if (!file_exists($upload_dir)) {
                mkdir($upload_dir, 0777, true); // สร้างโฟลเดอร์ uploads ถ้ายังไม่มี
            }
            
            // แยก Header Base64 และบันทึกเป็นไฟล์ภาพ
            if (strpos($base64_image, 'data:image') === 0) {
                list($type, $data) = explode(';', $base64_image);
                list(, $data)      = explode(',', $data);
                $data = base64_decode($data);
                
                $filename = time() . '_' . uniqid() . '.jpg';
                $target_file = $upload_dir . $filename;
                
                if (file_put_contents($target_file, $data)) {
                    // ลบรูปภาพเดิมทิ้ง เพื่อประหยัดพื้นที่เซิร์ฟเวอร์ (ใช้ Absolute Path แก้ปัญหาบน Server จริง)
                    if (!empty($image_path) && strpos($image_path, 'uploads/') === 0) {
                        $abs_old_path = __DIR__ . '/' . $image_path;
                        if (file_exists($abs_old_path)) {
                            @unlink($abs_old_path);
                        }
                    }
                    $image_path = $target_file;
                }
            }
        }

        try {
            if ($id !== '') { // [แก้ไข] เปลี่ยนจากแค่เช็ค empty เผื่อกรณี id เป็น 0
                // อัปเดตข้อมูล (Update)
                $stmt = $db->prepare("UPDATE projects SET name=?, description=?, start_date=?, status=?, image_url=?, project_link=? WHERE id=?");
                $stmt->execute([$name, $desc, $date, $status, $image_path, $link, $id]);
                $_SESSION['swal'] = ['icon' => 'success', 'title' => 'แก้ไขข้อมูลสำเร็จ'];
            } else {
                // เพิ่มข้อมูลใหม่ (Insert)
                $stmt = $db->prepare("INSERT INTO projects (name, description, start_date, status, image_url, project_link) VALUES (?, ?, ?, ?, ?, ?)");
                $stmt->execute([$name, $desc, $date, $status, $image_path, $link]);
                $_SESSION['swal'] = ['icon' => 'success', 'title' => 'เพิ่มโปรเจคสำเร็จ'];
            }
        } catch (PDOException $e) {
            $_SESSION['swal'] = ['icon' => 'error', 'title' => 'เกิดข้อผิดพลาด', 'text' => 'ไม่สามารถบันทึกข้อมูลฐานข้อมูลได้: ' . $e->getMessage()];
        }
        header("Location: index.php");
        exit;
    }

    // ระบบลบข้อมูล (Delete)
    if ($isAdmin && $action === 'delete_project') {
        $id = $_POST['id'] ?? ''; // เอาฟังก์ชัน intval() ออก รับค่าตรงๆ ป้องกันปัญหาการแปลงข้อมูล
        
        if ($id !== '') { // [แก้ไข] เปลี่ยนจาก !empty($id) เป็น $id !== '' เพื่อให้รองรับกรณี id เป็นเลข 0
            try {
                // ค้นหาพาธของรูปภาพเพื่อลบออกจากโฟลเดอร์
                $stmt = $db->prepare("SELECT image_url FROM projects WHERE id=?");
                $stmt->execute([$id]);
                $proj = $stmt->fetch(PDO::FETCH_ASSOC);
                
                if ($proj && !empty($proj['image_url']) && strpos($proj['image_url'], 'uploads/') === 0) {
                    // ใช้ __DIR__ ต่อกับ Path เพื่อสร้าง Absolute Path (จำเป็นมากบน Linux Server)
                    $abs_path = __DIR__ . '/' . $proj['image_url'];
                    if (file_exists($abs_path)) {
                        @unlink($abs_path); // ใส่ @ เพื่อซ่อน Error ป้องกันการขัดขวางระบบ Redirect
                    }
                }

                // ลบข้อมูลออกจากฐานข้อมูล
                $stmt = $db->prepare("DELETE FROM projects WHERE id=?");
                $stmt->execute([$id]);
                $_SESSION['swal'] = ['icon' => 'success', 'title' => 'ลบข้อมูลสำเร็จ'];
            } catch (PDOException $e) {
                $_SESSION['swal'] = ['icon' => 'error', 'title' => 'ลบไม่สำเร็จ', 'text' => 'มีข้อผิดพลาดฝั่งเซิร์ฟเวอร์: ' . $e->getMessage()];
            }
        } else {
            $_SESSION['swal'] = ['icon' => 'warning', 'title' => 'เกิดข้อผิดพลาด', 'text' => 'ไม่พบรหัสโปรเจคที่ต้องการลบ'];
        }
        header("Location: index.php");
        exit;
    }
}

// 3. ดึงข้อมูลโปรเจคสำหรับแสดงผล
$search = $_GET['search'] ?? '';
$status_filter = $_GET['status'] ?? 'all';

$query = "SELECT * FROM projects WHERE 1=1";
$params = [];

if ($search !== '') {
    $query .= " AND (name LIKE ? OR description LIKE ?)";
    $params[] = "%$search%";
    $params[] = "%$search%";
}
if ($status_filter !== 'all') {
    $query .= " AND status = ?";
    $params[] = $status_filter;
}

$query .= " ORDER BY created_at DESC";
$stmt = $db->prepare($query);
$stmt->execute($params);
$projects = $stmt->fetchAll(PDO::FETCH_ASSOC);

// ฟังก์ชันสำหรับกำหนดสีของสถานะ (Helper Function)
function getStatusConfig($status) {
    switch($status) {
        case 'เสร็จสิ้น': return ['bg' => 'bg-emerald-100', 'text' => 'text-emerald-700', 'icon' => 'fa-check-circle'];
        case 'กำลังดำเนินการ': return ['bg' => 'bg-amber-100', 'text' => 'text-amber-700', 'icon' => 'fa-spinner'];
        case 'แผนงาน': return ['bg' => 'bg-slate-100', 'text' => 'text-slate-700', 'icon' => 'fa-clipboard-list'];
        default: return ['bg' => 'bg-slate-100', 'text' => 'text-slate-700', 'icon' => 'fa-circle'];
    }
}
function formatDateThai($dateStr) {
    if(!$dateStr) return '';
    $time = strtotime($dateStr);
    $thaiMonths = ['', 'ม.ค.', 'ก.พ.', 'มี.ค.', 'เม.ย.', 'พ.ค.', 'มิ.ย.', 'ก.ค.', 'ส.ค.', 'ก.ย.', 'ต.ค.', 'พ.ย.', 'ธ.ค.'];
    return date('j', $time) . ' ' . $thaiMonths[date('n', $time)] . ' ' . (date('Y', $time) + 543);
}
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Little Project - Portal (PHP)</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Google+Sans:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    
    <script>
        tailwind.config = {
            theme: {
                extend: {
                    fontFamily: { sans: ['Google Sans', 'sans-serif'] },
                    colors: { brand: { 50: '#f0f9ff', 400: '#38bdf8', 500: '#0ea5e9', 600: '#0284c7', accent: '#f43f5e' } }
                }
            }
        }
    </script>
    <style>
        body { background-color: #f8fafc; }
        .hide { display: none !important; }
        div:where(.swal2-container) div:where(.swal2-popup) { border-radius: 32px !important; padding: 1.5rem !important; }
        div:where(.swal2-container) button:where(.swal2-styled) { border-radius: 16px !important; font-weight: 500 !important; }
    </style>
</head>
<body class="text-slate-800 antialiased min-h-screen flex flex-col">

    <!-- Navbar -->
    <nav class="bg-white shadow-sm sticky top-0 z-40">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="flex justify-between h-16">
                <a href="index.php" class="flex items-center cursor-pointer">
                    <div class="w-10 h-10 rounded-xl bg-gradient-to-tr from-brand-500 to-brand-400 flex items-center justify-center text-white font-bold text-xl shadow-lg">
                        <i class="fa-solid fa-rocket"></i>
                    </div>
                    <span class="ml-3 font-bold text-xl tracking-tight text-slate-800">Little <span class="text-brand-500">Project</span></span>
                </a>
                <div class="flex items-center space-x-4">
                    <?php if(!$isAdmin): ?>
                        <button onclick="openModal('loginModal')" class="text-sm font-medium text-slate-500 hover:text-brand-500 transition-colors">
                            <i class="fa-solid fa-lock mr-1"></i> ผู้ดูแลระบบ
                        </button>
                    <?php else: ?>
                        <form method="POST" style="margin: 0;">
                            <input type="hidden" name="action" value="logout">
                            <button type="submit" class="text-sm font-medium text-brand-accent hover:text-red-700 transition-colors bg-red-50 px-4 py-2 rounded-full">
                                <i class="fa-solid fa-sign-out-alt mr-1"></i> ออกจากระบบ
                            </button>
                        </form>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </nav>

    <main class="flex-grow max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8 w-full">
        
        <?php if(!$isAdmin): ?>
        <!-- ================= PUBLIC VIEW ================= -->
        <section id="publicView">
            <div class="bg-gradient-to-r from-brand-500 to-indigo-600 rounded-3xl p-8 sm:p-12 mb-10 text-center shadow-xl relative overflow-hidden">
                <h1 class="text-3xl sm:text-4xl font-bold text-white mb-4 relative z-10">ค้นพบโปรเจคที่น่าสนใจ</h1>
                
                <!-- Search Form (PHP GET Method) -->
                <form method="GET" action="index.php" class="max-w-3xl mx-auto flex flex-col sm:flex-row gap-4 relative z-10 mt-8">
                    <div class="relative flex-grow">
                        <input type="text" name="search" value="<?= htmlspecialchars($search) ?>" placeholder="ค้นหาชื่อโปรเจค..." class="block w-full pl-4 pr-4 py-3 border-0 rounded-2xl text-slate-800 shadow-lg outline-none">
                    </div>
                    <select name="status" onchange="this.form.submit()" class="block w-full sm:w-48 pl-4 pr-8 py-3 border-0 rounded-2xl text-slate-700 bg-white shadow-lg outline-none">
                        <option value="all" <?= $status_filter === 'all' ? 'selected' : '' ?>>สถานะทั้งหมด</option>
                        <option value="เสร็จสิ้น" <?= $status_filter === 'เสร็จสิ้น' ? 'selected' : '' ?>>เสร็จสิ้น</option>
                        <option value="กำลังดำเนินการ" <?= $status_filter === 'กำลังดำเนินการ' ? 'selected' : '' ?>>กำลังดำเนินการ</option>
                        <option value="แผนงาน" <?= $status_filter === 'แผนงาน' ? 'selected' : '' ?>>แผนงาน</option>
                    </select>
                    <button type="submit" class="bg-slate-800 text-white px-6 py-3 rounded-2xl font-medium shadow-lg hover:bg-slate-900">ค้นหา</button>
                </form>
            </div>

            <!-- Project Grid -->
            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-8">
                <?php if(count($projects) === 0): ?>
                    <div class="col-span-full text-center py-20">
                        <h3 class="text-xl text-slate-500">ไม่พบโปรเจค</h3>
                    </div>
                <?php endif; ?>

                <?php foreach($projects as $p): $cfg = getStatusConfig($p['status']); $img = $p['image_url'] ?: 'https://via.placeholder.com/800'; ?>
                    <div class="bg-white rounded-3xl overflow-hidden shadow-sm hover:shadow-xl transition-all flex flex-col">
                        <div class="h-48 relative overflow-hidden">
                            <img src="<?= htmlspecialchars($img) ?>" class="w-full h-full object-cover">
                            <div class="absolute top-4 right-4 <?= $cfg['bg'] ?> <?= $cfg['text'] ?> px-3 py-1 rounded-full text-xs font-bold">
                                <i class="fa-solid <?= $cfg['icon'] ?>"></i> <?= $p['status'] ?>
                            </div>
                        </div>
                        <div class="p-6 flex flex-col flex-grow">
                            <div class="text-xs text-slate-400 mb-2 font-medium"><i class="fa-regular fa-calendar-alt"></i> <?= formatDateThai($p['start_date']) ?></div>
                            <h3 class="text-xl font-bold text-slate-800 mb-2"><?= htmlspecialchars($p['name']) ?></h3>
                            <p class="text-slate-600 text-sm mb-6 flex-grow"><?= mb_substr(htmlspecialchars($p['description']), 0, 80) ?>...</p>
                            <button onclick="viewDetail(<?= htmlspecialchars(json_encode($p)) ?>)" class="w-full py-2.5 rounded-xl bg-slate-50 text-brand-600 font-medium hover:bg-brand-500 hover:text-white transition-all">
                                ดูรายละเอียด <i class="fa-solid fa-arrow-right ml-1"></i>
                            </button>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </section>

        <?php else: ?>
        <!-- ================= ADMIN VIEW ================= -->
        <section id="adminView">
            <div class="flex justify-between items-center mb-8">
                <div>
                    <h2 class="text-2xl font-bold text-slate-800">ระบบจัดการโปรเจค</h2>
                </div>
                <button onclick="openProjectModal()" class="bg-brand-500 text-white px-6 py-2.5 rounded-full font-medium hover:bg-brand-600 shadow-lg">
                    <i class="fa-solid fa-plus mr-2"></i> เพิ่มโปรเจค
                </button>
            </div>

            <div class="bg-white rounded-2xl shadow-sm border border-slate-100 overflow-hidden">
                <table class="w-full text-left">
                    <thead class="bg-slate-50 border-b border-slate-100 text-slate-500 text-sm">
                        <tr>
                            <th class="p-4 font-medium">ชื่อโปรเจค</th>
                            <th class="p-4 font-medium">วันที่เริ่ม</th>
                            <th class="p-4 font-medium">สถานะ</th>
                            <th class="p-4 font-medium text-right">จัดการ</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        <?php foreach($projects as $p): $cfg = getStatusConfig($p['status']); ?>
                        <tr class="hover:bg-slate-50">
                            <td class="p-4">
                                <div class="font-semibold text-slate-800"><?= htmlspecialchars($p['name']) ?></div>
                            </td>
                            <td class="p-4 text-sm text-slate-600"><?= formatDateThai($p['start_date']) ?></td>
                            <td class="p-4">
                                <span class="<?= $cfg['bg'] ?> <?= $cfg['text'] ?> px-2.5 py-1 rounded-full text-xs font-medium">
                                    <?= $p['status'] ?>
                                </span>
                            </td>
                            <td class="p-4 text-right whitespace-nowrap">
                                <!-- ฟอร์มแก้ไข / ลบข้อมูล -->
                                <button onclick="editProject(<?= htmlspecialchars(json_encode($p)) ?>)" class="w-8 h-8 rounded-full bg-blue-50 text-blue-600 hover:bg-blue-600 hover:text-white transition-colors">
                                    <i class="fa-solid fa-pen text-sm"></i>
                                </button>
                                <form method="POST" class="inline-block" onsubmit="return confirm('ยืนยันการลบโปรเจคนี้ รวมถึงรูปภาพที่เกี่ยวข้องด้วย?');">
                                    <input type="hidden" name="action" value="delete_project">
                                    <input type="hidden" name="id" value="<?= htmlspecialchars($p['id']) ?>">
                                    <button type="submit" class="w-8 h-8 rounded-full bg-red-50 text-red-600 hover:bg-red-600 hover:text-white transition-colors">
                                        <i class="fa-solid fa-trash text-sm"></i>
                                    </button>
                                </form>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </section>
        <?php endif; ?>

    </main>

    <!-- Footer -->
    <footer class="bg-white border-t border-slate-100 mt-auto w-full z-10 relative">
        <div class="max-w-7xl mx-auto px-4 py-6 text-center text-sm text-slate-500">
            &copy; <?= date('Y') ?> Little Project Portal. Designed with <i class="fa-solid fa-heart text-brand-accent mx-1"></i> by ครูติ๊ก.
        </div>
    </footer>

    <!-- ================= MODALS (Forms) ================= -->

    <!-- Login Modal -->
    <div id="loginModal" class="hide fixed inset-0 z-50 bg-slate-900/50 backdrop-blur-sm flex items-center justify-center p-4">
        <div class="bg-white rounded-3xl shadow-2xl w-full max-w-md p-8 relative">
            <button onclick="closeModals()" class="absolute top-4 right-4 text-slate-400 hover:bg-slate-100 w-8 h-8 rounded-full"><i class="fa-solid fa-times"></i></button>
            <h3 class="text-2xl font-bold text-center text-slate-800 mb-6">เข้าสู่ระบบ</h3>
            <!-- ส่งข้อมูลไปยัง PHP ผ่าน POST -->
            <form method="POST" action="index.php" class="space-y-5">
                <input type="hidden" name="action" value="login">
                <div>
                    <label class="block text-sm font-medium mb-1">Username</label>
                    <input type="text" name="username" required class="w-full px-4 py-3 rounded-xl border focus:border-brand-500 outline-none">
                </div>
                <div>
                    <label class="block text-sm font-medium mb-1">Password</label>
                    <input type="password" name="password" required class="w-full px-4 py-3 rounded-xl border focus:border-brand-500 outline-none">
                </div>
                <button type="submit" class="w-full bg-slate-800 text-white font-medium py-3 rounded-xl shadow-lg">เข้าสู่ระบบ</button>
            </form>
        </div>
    </div>

    <!-- Project Form Modal (Add/Edit) -->
    <div id="projectModal" class="hide fixed inset-0 z-50 bg-slate-900/50 backdrop-blur-sm flex items-center justify-center p-4">
        <div class="bg-white rounded-3xl shadow-2xl w-full max-w-2xl p-6 relative">
            <button onclick="closeModals()" class="absolute top-4 right-4 text-slate-400 hover:bg-slate-100 w-8 h-8 rounded-full"><i class="fa-solid fa-times"></i></button>
            <h3 id="modalTitle" class="text-2xl font-bold text-slate-800 mb-4">ฟอร์มโปรเจค</h3>
            
            <form method="POST" action="index.php">
                <input type="hidden" name="action" value="save_project">
                <input type="hidden" name="id" id="pId">
                
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4 mb-4">
                    <div class="sm:col-span-2">
                        <label class="block text-sm font-medium mb-1">ชื่อโปรเจค</label>
                        <input type="text" name="name" id="pName" required class="w-full px-4 py-2 rounded-xl border outline-none">
                    </div>
                    
                    <div>
                        <label class="block text-sm font-medium mb-1">วันที่เริ่ม</label>
                        <input type="date" name="date" id="pDate" required class="w-full px-4 py-2 rounded-xl border outline-none">
                    </div>
                    <div>
                        <label class="block text-sm font-medium mb-1">สถานะ</label>
                        <select name="status" id="pStatus" class="w-full px-4 py-2 rounded-xl border outline-none bg-white">
                            <option value="แผนงาน">แผนงาน</option><option value="กำลังดำเนินการ">กำลังดำเนินการ</option><option value="เสร็จสิ้น">เสร็จสิ้น</option>
                        </select>
                    </div>

                    <div class="sm:col-span-2">
                        <label class="block text-sm font-medium mb-1">รายละเอียด</label>
                        <textarea name="desc" id="pDesc" rows="2" class="w-full px-4 py-2 rounded-xl border outline-none"></textarea>
                    </div>
                    
                    <!-- ส่วนอัปโหลดรูปภาพใหม่ -->
                    <div>
                        <label class="block text-sm font-medium mb-1">รูปภาพ (เลือกใหม่หากต้องการเปลี่ยน)</label>
                        <input type="file" id="pImageFile" accept="image/*" onchange="processImage(this)" class="w-full px-4 py-1.5 rounded-xl border outline-none bg-white text-sm">
                        
                        <!-- ซ่อนข้อมูล Base64 และพาธรูปเดิมไว้ส่งไปให้ PHP -->
                        <input type="hidden" name="imageBase64" id="imageBase64">
                        <input type="hidden" name="old_image" id="pOldImage">
                        
                        <!-- แจ้งเตือนว่ามีรูปอยู่แล้ว -->
                        <div id="currentImageName" class="hide text-xs font-medium text-emerald-600 mt-1">
                            <i class="fa-solid fa-check-circle"></i> มีรูปภาพเดิมแล้ว
                        </div>
                    </div>

                    <div>
                        <label class="block text-sm font-medium mb-1">URL โปรเจค</label>
                        <input type="url" name="link" id="pLink" class="w-full px-4 py-2 rounded-xl border outline-none">
                    </div>
                </div>
                <div class="flex justify-end space-x-3 mt-6">
                    <button type="button" onclick="closeModals()" class="px-6 py-2 rounded-xl bg-slate-100">ยกเลิก</button>
                    <button type="submit" class="px-6 py-2 rounded-xl text-white bg-brand-500 shadow-lg">บันทึก</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Detail Modal -->
    <div id="detailModal" class="hide fixed inset-0 z-50 bg-slate-900/60 backdrop-blur-sm flex items-center justify-center p-4">
        <div class="bg-white rounded-3xl w-full max-w-3xl overflow-hidden relative">
            <button onclick="closeModals()" class="absolute top-4 right-4 z-10 bg-black/20 text-white w-10 h-10 rounded-full hover:bg-black/40 transition-colors"><i class="fa-solid fa-times"></i></button>
            <div id="detailImage" class="w-full h-64 sm:h-80 bg-cover bg-center relative bg-slate-200">
                <div class="absolute inset-0 bg-gradient-to-t from-black/80 to-transparent"></div>
                <div class="absolute bottom-6 left-6 right-6">
                    <h2 id="detailTitle" class="text-3xl sm:text-4xl font-bold text-white mb-2"></h2>
                    <p class="text-slate-300 text-sm"><i class="fa-regular fa-calendar mr-1"></i> <span id="detailDate"></span></p>
                </div>
            </div>
            <div class="p-6 sm:p-8">
                <p id="detailDesc" class="text-slate-600 mb-8 whitespace-pre-wrap"></p>
                <div class="text-right border-t border-slate-100 pt-6">
                    <a id="detailLink" href="#" target="_blank" class="px-6 py-3 bg-brand-500 hover:bg-brand-600 text-white rounded-xl shadow-lg transition-colors">เข้าชมโปรเจค <i class="fa-solid fa-arrow-right ml-1"></i></a>
                </div>
            </div>
        </div>
    </div>

    <!-- UI Scripts for Modals & Image Processing -->
    <script>
        function openModal(id) { document.getElementById(id).classList.remove('hide'); }
        function closeModals() { document.querySelectorAll('.fixed.z-50').forEach(el => el.classList.add('hide')); }

        // ฟังก์ชันอ่านไฟล์รูปและลดขนาดก่อนส่ง (Client-Side Resizing)
        function processImage(input) {
            const file = input.files[0];
            if(!file) return;
            
            // แสดงสถานะโหลดระหว่างที่ JS กำลังย่อรูป
            Swal.fire({ title: 'กำลังประมวลผลรูปภาพ...', allowOutsideClick: false, didOpen: () => { Swal.showLoading(); }});

            const reader = new FileReader();
            reader.onload = function(e) {
                const img = new Image();
                img.onload = function() {
                    const canvas = document.createElement('canvas');
                    const MAX_WIDTH = 800; // กำหนดความกว้างสูงสุด 800px
                    let width = img.width;
                    let height = img.height;
                    
                    // ลดสัดส่วนให้พอดี
                    if (width > MAX_WIDTH) {
                        height = Math.round(height * (MAX_WIDTH / width));
                        width = MAX_WIDTH;
                    }
                    
                    canvas.width = width;
                    canvas.height = height;
                    const ctx = canvas.getContext('2d');
                    ctx.drawImage(img, 0, 0, width, height);
                    
                    // แปลง Canvas กลับเป็น Base64 (คุณภาพ 80%) เพื่อให้พร้อมบันทึกลง Database
                    const dataUrl = canvas.toDataURL('image/jpeg', 0.8);
                    document.getElementById('imageBase64').value = dataUrl;
                    
                    // ปิดสถานะโหลด
                    Swal.close();
                }
                img.src = e.target.result;
            }
            reader.readAsDataURL(file);
        }

        function openProjectModal() {
            document.getElementById('pId').value = '';
            document.getElementById('pName').value = '';
            document.getElementById('pDesc').value = '';
            document.getElementById('pDate').value = '';
            document.getElementById('pLink').value = '';
            
            // ล้างข้อมูลรูปภาพ
            document.getElementById('pImageFile').value = '';
            document.getElementById('imageBase64').value = '';
            document.getElementById('pOldImage').value = '';
            document.getElementById('currentImageName').classList.add('hide');
            
            document.getElementById('modalTitle').innerText = 'เพิ่มโปรเจคใหม่';
            openModal('projectModal');
        }

        function editProject(p) {
            document.getElementById('pId').value = p.id;
            document.getElementById('pName').value = p.name;
            document.getElementById('pDesc').value = p.description;
            document.getElementById('pDate').value = p.start_date;
            document.getElementById('pStatus').value = p.status;
            document.getElementById('pLink').value = p.project_link;
            
            // ตั้งค่าเกี่ยวกับรูปภาพ
            document.getElementById('pImageFile').value = '';
            document.getElementById('imageBase64').value = '';
            document.getElementById('pOldImage').value = p.image_url || '';
            
            if(p.image_url) {
                document.getElementById('currentImageName').classList.remove('hide');
            } else {
                document.getElementById('currentImageName').classList.add('hide');
            }
            
            document.getElementById('modalTitle').innerText = 'แก้ไขโปรเจค';
            openModal('projectModal');
        }

        function viewDetail(p) {
            document.getElementById('detailTitle').innerText = p.name;
            document.getElementById('detailDesc').innerText = p.description;
            document.getElementById('detailDate').innerText = formatDateToThai(p.start_date);
            document.getElementById('detailImage').style.backgroundImage = `url('${p.image_url || 'https://via.placeholder.com/800'}')`;
            
            const linkBtn = document.getElementById('detailLink');
            if(p.project_link) { linkBtn.href = p.project_link; linkBtn.style.display = 'inline-block'; } 
            else { linkBtn.style.display = 'none'; }
            
            openModal('detailModal');
        }
        
        // JS Helper แปลงวันที่ (ใช้ตอนโชว์ Detail)
        function formatDateToThai(dateStr) {
            if(!dateStr) return '';
            const date = new Date(dateStr);
            const thaiMonths = ['ม.ค.', 'ก.พ.', 'มี.ค.', 'เม.ย.', 'พ.ค.', 'มิ.ย.', 'ก.ค.', 'ส.ค.', 'ก.ย.', 'ต.ค.', 'พ.ย.', 'ธ.ค.'];
            return `${date.getDate()} ${thaiMonths[date.getMonth()]} ${date.getFullYear() + 543}`;
        }
    </script>

    <!-- SweetAlert Trigger from PHP Session -->
    <?php if(isset($_SESSION['swal'])): ?>
    <script>
        document.addEventListener('DOMContentLoaded', () => {
            Swal.fire({
                icon: '<?= $_SESSION['swal']['icon'] ?>',
                title: '<?= $_SESSION['swal']['title'] ?>',
                text: '<?= $_SESSION['swal']['text'] ?? '' ?>',
                timer: <?= isset($_SESSION['swal']['text']) ? '4000' : '1500' ?>,
                showConfirmButton: <?= isset($_SESSION['swal']['text']) ? 'true' : 'false' ?>
            });
        });
    </script>
    <?php unset($_SESSION['swal']); endif; ?>

</body>
</html>
