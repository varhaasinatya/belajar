settings.php ( PHP script, ASCII text, with CRLF line terminators )
<?php
session_start();
ini_set('display_errors', 1);
error_reporting(E_ALL);

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/database.php';
require_once __DIR__ . '/../includes/functions.php';
// Tambahkan require untuk file security.php dan reward_system.php
require_once __DIR__ . '/../includes/security.php';
require_once __DIR__ . '/../includes/reward_system.php';

$conn = Database::getInstance()->getConnection();

// Check user role
$is_superadmin = !empty($_SESSION['is_superadmin']);
$is_admin = !empty($_SESSION['is_admin']);

// Only allow admin/superadmin
if (!$is_superadmin && !$is_admin) {
    header('Location: ../index.php');
    exit;
}

// CSRF Protection
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
function csrf_field() {
    return '<input type="hidden" name="csrf_token" value="' . htmlspecialchars($_SESSION['csrf_token']) . '">';
}
function csrf_verify($token) {
    return isset($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $token);
}

// Fungsi helper untuk reward level
function get_network_type($conn) {
    try {
        $stmt = $conn->prepare("SELECT network_type FROM network_settings WHERE active = 1 LIMIT 1");
        $stmt->execute();
        $result = $stmt->fetchColumn();
        return $result ?: 'trinary'; // Default trinary jika tidak ditemukan
    } catch (PDOException $e) {
        error_log('Error in get_network_type: ' . $e->getMessage());
        return 'trinary'; // Default trinary jika error
    }
}

function calculate_full_level_positions($level, $network_type) {
    // Tentukan multiplier berdasarkan tipe jaringan
    switch (strtolower($network_type)) {
        case 'binary':
            $base = 2;
            break;
        case 'trinary':
            $base = 3;
            break;
        case 'matrix4':
        case '4x4':
            $base = 4;
            break;
        case 'matrix5':
        case '5x5':
            $base = 5;
            break;
        default:
            $base = 3; // Default trinary
    }
    
    // Hitung jumlah posisi pada level tertentu
    // Rumus: base^level
    return pow($base, $level);
}

function get_level_rewards($conn, $product_id) {
    try {
        $stmt = $conn->prepare("SELECT * FROM product_rewards 
                              WHERE product_id = ? 
                              AND reward_type = 'level' 
                              ORDER BY level ASC");
        $stmt->execute([$product_id]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        error_log('Error in get_level_rewards: ' . $e->getMessage());
        return [];
    }
}

$msg = $error = "";
// Tambahkan kolom root_member_id jika belum ada (safe to repeat)
try {
    $col = $conn->query("SHOW COLUMNS FROM network_settings LIKE 'root_member_id'");
    if ($col !== false && $col->rowCount() == 0) {
        $conn->exec("ALTER TABLE network_settings ADD COLUMN root_member_id INT DEFAULT NULL");
    }
} catch (PDOException $e) {
    // Kolom sudah ada atau error lain, abaikan
}
// Ambil data member
$member_options = $conn->query("SELECT id, username, nama_lengkap FROM users WHERE role='member' ORDER BY id ASC")->fetchAll(PDO::FETCH_ASSOC);

/* ======== SUPERADMIN ONLY: Global Settings ======== */
if ($is_superadmin) {
    // MAX COMMISSION LEVEL
    if (isset($_POST['save_maxlevel']) && csrf_verify($_POST['csrf_token'])) {
        // Terapkan pencegahan resubmit
        if (prevent_form_resubmission('save_maxlevel', 'settings.php', ['success' => 1, 'msg' => 'Maximum level updated'])) {
            $max_level = intval($_POST['max_level']);
            $stmt = $conn->prepare("REPLACE INTO settings (name, value) VALUES ('komisi_level', ?)");
            $stmt->execute([$max_level]);
            $msg = "Maximum commission/point level updated successfully!";
        }
    }
    $max_level_setting = $conn->query("SELECT value FROM settings WHERE name='komisi_level' LIMIT 1")->fetchColumn();
    if ($max_level_setting === false) $max_level_setting = 10;

    // NETWORK SETTINGS
    if (isset($_POST['save_network_settings']) && csrf_verify($_POST['csrf_token'])) {
        // Terapkan pencegahan resubmit
        if (prevent_form_resubmission('save_network_settings', 'settings.php', ['success' => 1, 'msg' => 'Network settings updated'])) {
            // Ambil dari POST dengan fallback default
            $network_type = $_POST['network_type'] ?? 'binary'; // default 'binary' jika kosong
            $max_legs = isset($_POST['max_legs']) ? intval($_POST['max_legs']) : 2; // default 2 jika kosong
            $hybrid_config = trim($_POST['hybrid_config'] ?? '');
            $autoplacement_enabled = isset($_POST['autoplacement_enabled']) ? intval($_POST['autoplacement_enabled']) : 1;
            $autoplacement_mode = $_POST['autoplacement_mode'] ?? 'referral';
            if (!in_array($autoplacement_mode, ['referral', 'global'])) $autoplacement_mode = 'referral';
            $description = trim($_POST['description'] ?? '');
            $root_member_id = intval($_POST['root_member_id'] ?? 0);

            // Validasi wajib
            if (!$network_type || !$max_legs) {
                $error = "Network type dan max_legs wajib diisi!";
            } else {
                // Nonaktifkan setting lama
                $conn->exec("UPDATE network_settings SET active=0 WHERE active=1");
                $stmt = $conn->prepare("INSERT INTO network_settings 
                    (network_type, max_legs, hybrid_config, autoplacement_enabled, autoplacement_mode, root_member_id, description, active) 
                    VALUES (?, ?, ?, ?, ?, ?, ?, 1)");
                $stmt->execute([
                    $network_type, 
                    $max_legs, 
                    $hybrid_config, 
                    $autoplacement_enabled, 
                    $autoplacement_mode, 
                    $root_member_id > 0 ? $root_member_id : null, 
                    $description
                ]);
                // Sync to settings table
                $stmt = $conn->prepare("REPLACE INTO settings (name, value) VALUES ('autoplacement', ?)");
                $stmt->execute([$autoplacement_enabled ? 'YES' : 'NO']);
                $msg = "Network settings updated successfully!";
            }
        }
    }
    $setting = $conn->query("SELECT * FROM network_settings WHERE active=1 ORDER BY id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);

    // GLOBAL COMMISSION SETTINGS
    if (isset($_POST['save_global_commission']) && csrf_verify($_POST['csrf_token'])) {
        // Terapkan pencegahan resubmit
        if (prevent_form_resubmission('save_global_commission', 'settings.php', ['success' => 1, 'msg' => 'Global commission settings updated'])) {
            $default_type = $_POST['default_type'];
            $default_value = floatval($_POST['default_value']);
            $max_level = intval($_POST['max_level']);
            $is_active = isset($_POST['is_active']) ? intval($_POST['is_active']) : 1;
            
            $conn->exec("CREATE TABLE IF NOT EXISTS commission_settings (
                id INT PRIMARY KEY,
                default_type ENUM('percent', 'fixed') NOT NULL,
                default_value DECIMAL(10,2) NOT NULL,
                max_level INT DEFAULT 10,
                is_active TINYINT(1) DEFAULT 1
            )");
            $stmt = $conn->prepare("REPLACE INTO commission_settings (id, default_type, default_value, max_level, is_active)
                                    VALUES (1, ?, ?, ?, ?)");
            $stmt->execute([$default_type, $default_value, $max_level, $is_active]);
            $msg = "Global commission settings updated!";
        }
    }
    $global_cfg = $conn->query("SELECT * FROM commission_settings LIMIT 1")->fetch(PDO::FETCH_ASSOC);
} else {
    // For regular admins, use default values for global settings
    $max_level_setting = $conn->query("SELECT value FROM settings WHERE name='komisi_level' LIMIT 1")->fetchColumn();
    if ($max_level_setting === false) $max_level_setting = 10;
    $setting = $conn->query("SELECT * FROM network_settings WHERE active=1 ORDER BY id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    $global_cfg = $conn->query("SELECT * FROM commission_settings LIMIT 1")->fetch(PDO::FETCH_ASSOC);
}


/* ======== ADMIN & SUPERADMIN: PRODUCTS, LEVELS, REWARDS ======== */
// PRODUCTS CRUD
$conn->exec("CREATE TABLE IF NOT EXISTS products (
    id INT AUTO_INCREMENT PRIMARY KEY,
    kode_produk VARCHAR(40) NOT NULL,
    nama_produk VARCHAR(128) NOT NULL,
    harga DECIMAL(15,2) NOT NULL,
    direct_commission DECIMAL(15,2) DEFAULT 0,
    currency VARCHAR(10) DEFAULT 'IDR',
    status ENUM('aktif','nonaktif') DEFAULT 'aktif',
    upgrade_to INT DEFAULT NULL
)");

if (isset($_POST['produk_save']) && csrf_verify($_POST['csrf_token'])) {
    // Terapkan pencegahan resubmit
    if (prevent_form_resubmission('produk_save', 'settings.php', ['success' => 1])) {
        $id = intval($_POST['produk_id'] ?? 0);
        $kode_produk = trim($_POST['kode_produk'] ?? '');
        $nama_produk = trim($_POST['nama_produk']);
        $harga = floatval($_POST['harga']);
        $direct_commission = floatval($_POST['direct_commission'] ?? 0);
        $currency = trim($_POST['currency'] ?? 'IDR');
        $status = $_POST['status'] ?? 'aktif';
        $upgrade_to = $_POST['upgrade_to'] ?? null;
        if ($upgrade_to === '') $upgrade_to = null;

        if (!$kode_produk || !$nama_produk || $harga < 0) {
            $msg = "Product code, name and price are required!";
        } else {
            try {
                if ($id == 0) {
                    $cek = $conn->prepare("SELECT COUNT(*) FROM products WHERE kode_produk=?");
                    $cek->execute([$kode_produk]);
                    if ($cek->fetchColumn() > 0) {
                        $msg = "Product code already exists!";
                    } else {
                        $stmt = $conn->prepare("INSERT INTO products (kode_produk, nama_produk, harga, direct_commission, currency, status, upgrade_to)
                            VALUES (?, ?, ?, ?, ?, ?, ?)");
                        $stmt->execute([$kode_produk, $nama_produk, $harga, $direct_commission, $currency, $status, $upgrade_to]);
                        header("Location: settings.php?success=1&msg=" . urlencode("Product added successfully"));
                        exit;
                    }
                } else {
                    $cek = $conn->prepare("SELECT COUNT(*) FROM products WHERE kode_produk=? AND id <> ?");
                    $cek->execute([$kode_produk, $id]);
                    if ($cek->fetchColumn() > 0) {
                        $msg = "Product code already used by another product!";
                    } else {
                        $stmt = $conn->prepare("UPDATE products SET kode_produk=?, nama_produk=?, harga=?, direct_commission=?, currency=?, status=?, upgrade_to=? WHERE id=?");
                        $stmt->execute([$kode_produk, $nama_produk, $harga, $direct_commission, $currency, $status, $upgrade_to, $id]);
                        header("Location: settings.php?edit_produk=$id&success=1&msg=" . urlencode("Product updated successfully"));
                        exit;
                    }
                }
            } catch (PDOException $e) {
                $error = $e->getMessage();
            }
        }
    }
}

if (isset($_GET['nonaktif_produk']) && intval($_GET['nonaktif_produk']) > 0) {
    $id = intval($_GET['nonaktif_produk']);
    $stmt = $conn->prepare("UPDATE products SET status='nonaktif' WHERE id=?");
    $stmt->execute([$id]);
    $msg = "Product deactivated successfully!";
}

// COMMISSION PER LEVEL
function log_commission_change($admin_id, $product_id, $level, $old, $new, $action, $conn) {
    $conn->exec("CREATE TABLE IF NOT EXISTS commission_log (
        id INT AUTO_INCREMENT PRIMARY KEY,
        admin_id INT,
        product_id INT,
        level INT,
        old_value DECIMAL(15,2),
        new_value DECIMAL(15,2),
        action VARCHAR(32),
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )");
    $stmt = $conn->prepare("INSERT INTO commission_log (admin_id, product_id, level, old_value, new_value, action) VALUES (?, ?, ?, ?, ?, ?)");
    $stmt->execute([$admin_id, $product_id, $level, $old, $new, $action]);
}

if (isset($_POST['komisi_save']) && csrf_verify($_POST['csrf_token'])) {
    // Terapkan pencegahan resubmit
    if (prevent_form_resubmission('komisi_save', 'settings.php', ['success' => 1, 'msg' => 'Commission settings updated'])) {
        $pid = intval($_POST['komisi_product_id']);
        $max_level = intval($_POST['max_level']);
        
        $conn->exec("CREATE TABLE IF NOT EXISTS product_commissions (
            id INT AUTO_INCREMENT PRIMARY KEY,
            product_id INT NOT NULL,
            level INT NOT NULL,
            commission_amount DECIMAL(15,2) DEFAULT 0,
            is_active TINYINT(1) DEFAULT 1
        )");
        
        for ($i = 1; $i <= $max_level; $i++) {
            $nilai = floatval($_POST['komisi'][$i] ?? 0);
            $is_active = isset($_POST['is_active'][$i]) ? intval($_POST['is_active'][$i]) : 1;
            
            $cek = $conn->prepare("SELECT commission_amount FROM product_commissions WHERE product_id=? AND level=?");
            $cek->execute([$pid, $i]);
            $old = $cek->fetchColumn();
            
            if ($old !== false) {
                $stmt = $conn->prepare("UPDATE product_commissions SET commission_amount=?, is_active=? WHERE product_id=? AND level=?");
                $stmt->execute([$nilai, $is_active, $pid, $i]);
                if ($old != $nilai) log_commission_change($_SESSION['user_id'], $pid, $i, $old, $nilai, 'update', $conn);
            } else {
                $stmt = $conn->prepare("INSERT INTO product_commissions (product_id, level, commission_amount, is_active) VALUES (?, ?, ?, ?)");
                $stmt->execute([$pid, $i, $nilai, $is_active]);
                log_commission_change($_SESSION['user_id'], $pid, $i, null, $nilai, 'insert', $conn);
            }
        }
        header("Location: settings.php?komisi_produk=$pid&success=1&msg=" . urlencode("Product level commissions updated"));
        exit;
    }
}

// FORM HANDLER UNTUK REWARD LEVEL (TRINARY FULL LEVEL)
if (isset($_POST['level_reward_save']) && csrf_verify($_POST['csrf_token'])) {
    // Terapkan pencegahan resubmit
    if (prevent_form_resubmission('level_reward_save', 'settings.php', ['komisi_produk' => $_POST['reward_product_id'] ?? 0, 'success' => 1])) {
        $reward_id = intval($_POST['level_reward_id'] ?? 0);
        $product_id = intval($_POST['reward_product_id'] ?? 0);
        $level = intval($_POST['reward_level'] ?? 0);
        $reward_amount = floatval($_POST['level_reward_amount'] ?? 0);
        $is_active = intval($_POST['level_is_active'] ?? 1);
        $keterangan = trim($_POST['level_keterangan'] ?? '');
        
        // Dapatkan network type dari database
        $network_type = get_network_type($conn);
        
        // Hitung jumlah posisi di level
        $positions = calculate_full_level_positions($level, $network_type);
        
        // Siapkan data reward
        if (empty($keterangan)) {
            $keterangan = "Level $level Penuh ($positions posisi) - Reward";
        }
        
        try {
            if ($reward_id > 0) {
                // Update existing record
                $stmt = $conn->prepare("UPDATE product_rewards SET 
                                      level = ?,
                                      point = ?,
                                      reward_amount = ?,
                                      is_active = ?,
                                      keterangan = ?
                                      WHERE id = ? AND product_id = ? AND reward_type = 'level'");
                $result = $stmt->execute([
                    $level,
                    $positions,
                    $reward_amount,
                    $is_active,
                    $keterangan,
                    $reward_id,
                    $product_id
                ]);
                
                if ($result) {
                    header("Location: settings.php?komisi_produk=$product_id&success=1&msg=" . urlencode("Reward level penuh berhasil diperbarui"));
                    exit;
                } else {
                    $error = "Gagal memperbarui reward level";
                }
            } else {
                // Insert new record
                $stmt = $conn->prepare("INSERT INTO product_rewards 
                                      (product_id, reward_type, level, point, reward_amount, is_active, keterangan)
                                      VALUES (?, 'level', ?, ?, ?, ?, ?)");
                $result = $stmt->execute([
                    $product_id,
                    $level,
                    $positions,
                    $reward_amount,
                    $is_active,
                    $keterangan
                ]);
                
                if ($result) {
                    header("Location: settings.php?komisi_produk=$product_id&success=1&msg=" . urlencode("Reward level penuh berhasil ditambahkan"));
                    exit;
                } else {
                    $error = "Gagal menambahkan reward level baru";
                }
            }
        } catch (PDOException $e) {
            $error = "Database error: " . $e->getMessage();
        }
    }
}

// LOAD DATA UNTUK EDIT REWARD LEVEL
$level_reward_edit = null;
if (isset($_GET['edit_level_reward']) && intval($_GET['edit_level_reward']) > 0) {
    $reward_id = intval($_GET['edit_level_reward']);
    $stmt = $conn->prepare("SELECT * FROM product_rewards WHERE id = ? AND reward_type = 'level'");
    $stmt->execute([$reward_id]);
    $level_reward_edit = $stmt->fetch(PDO::FETCH_ASSOC);
}

// DELETE REWARD LEVEL
if (isset($_GET['delete_level_reward']) && intval($_GET['delete_level_reward']) > 0) {
    $reward_id = intval($_GET['delete_level_reward']);
    $stmt = $conn->prepare("DELETE FROM product_rewards WHERE id = ? AND reward_type = 'level'");
    $stmt->execute([$reward_id]);
    
    $product_id = isset($_GET['komisi_produk']) ? intval($_GET['komisi_produk']) : 0;
    if ($product_id > 0) {
        header("Location: settings.php?komisi_produk=$product_id&success=1&msg=" . urlencode("Reward level berhasil dihapus"));
        exit;
    } else {
        $msg = "Reward level berhasil dihapus";
    }
}

// LOAD ALL LEVEL REWARDS FOR PRODUCT
$level_rewards = [];
if (isset($_GET['komisi_produk']) && intval($_GET['komisi_produk']) > 0) {
    $product_id = intval($_GET['komisi_produk']);
    $level_rewards = get_level_rewards($conn, $product_id);
}

// REWARDS SYSTEM - PEMBARUAN STRUKTUR TABEL
$conn->exec("CREATE TABLE IF NOT EXISTS product_rewards (
    id INT AUTO_INCREMENT PRIMARY KEY,
    product_id INT NOT NULL,
    reward_type ENUM('referral','level','transaksi','upgrade','binary_pair','point_growth','hybrid') NOT NULL DEFAULT 'referral',
    level INT DEFAULT NULL,
    point INT DEFAULT 0,
    left_leg INT DEFAULT NULL,
    right_leg INT DEFAULT NULL,
    milestone_point INT DEFAULT NULL,
    reward_amount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    is_active TINYINT(1) DEFAULT 1,
    keterangan VARCHAR(255) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

// PERBAIKAN SISTEM REWARD: Menangani reward referral dengan multiple qualification
if (isset($_POST['reward_save']) && csrf_verify($_POST['csrf_token'])) {
    // Terapkan pencegahan resubmit
    if (prevent_form_resubmission('reward_save', 'settings.php', ['komisi_produk' => $_POST['reward_product_id'] ?? 0, 'success' => 1])) {
        $id = intval($_POST['reward_id'] ?? 0);
        $product_id = intval($_POST['reward_product_id'] ?? 0);
        $reward_type = $_POST['reward_type'];
        
        // Validasi khusus per tipe reward
        $error_validation = false;
        
        // Validasi wajib untuk semua tipe
        if (!$product_id || !$reward_type) {
            $error = "Product ID and reward type are required!";
            $error_validation = true;
        }
        // Validasi khusus referral dengan multiple qualification
        else if ($reward_type == 'referral') {
            // Periksa apakah ada qualification input
            if (!isset($_POST['qualification']) || !is_array($_POST['qualification']) || count($_POST['qualification']) === 0) {
                $error = "Minimal satu jumlah referral kualifikasi wajib diisi!";
                $error_validation = true;
            } else {
                // Validasi semua qualification input
                foreach ($_POST['qualification'] as $index => $qualification) {
                    if (empty($qualification) || intval($qualification) <= 0) {
                        $error = "Semua jumlah referral kualifikasi wajib diisi dan > 0!";
                        $error_validation = true;
                        break;
                    }
                    
                    // Validasi corresponding reward
                    if (!isset($_POST['qualification_reward'][$index]) || $_POST['qualification_reward'][$index] === '') {
                        $error = "Semua nilai reward wajib diisi!";
                        $error_validation = true;
                        break;
                    }
                }
            }
        }
        // Validasi khusus level
        else if ($reward_type == 'level') {
            if (empty($_POST['reward_level']) || intval($_POST['reward_level']) <= 0) {
                $error = "Level wajib diisi dan > 0!";
                $error_validation = true;
            }
        }
        // Validasi khusus binary_pair
        else if ($reward_type == 'binary_pair') {
            if (empty($_POST['left_leg']) || intval($_POST['left_leg']) <= 0 || 
                empty($_POST['right_leg']) || intval($_POST['right_leg']) <= 0) {
                $error = "Left leg dan right leg wajib diisi dan > 0!";
                $error_validation = true;
            }
        }
        // Validasi khusus point_growth dan hybrid
        else if ($reward_type == 'point_growth' || $reward_type == 'hybrid') {
            if (empty($_POST['milestone_point']) || intval($_POST['milestone_point']) <= 0) {
                $error = "Milestone point wajib diisi dan > 0!";
                $error_validation = true;
            }
        }
        
        // Validasi reward_amount untuk semua tipe
        if (!isset($_POST['reward_amount']) || $_POST['reward_amount'] === '') {
            $error = "Reward amount wajib diisi!";
            $error_validation = true;
        }
        
        if (!$error_validation) {
            // Jika ini adalah reward referral dengan multiple qualification
            if ($reward_type == 'referral' && isset($_POST['qualification']) && is_array($_POST['qualification'])) {
                $qualifications = $_POST['qualification'];
                $qualification_rewards = $_POST['qualification_reward'] ?? [];
                $base_description = trim($_POST['keterangan'] ?? '');
                
                // Hapus semua qualification yang ada (akan diganti dengan yang baru)
                if ($id > 0) {
                    // Ambil info reward untuk referensi
                    $stmt = $conn->prepare("SELECT keterangan FROM product_rewards WHERE id = ?");
                    $stmt->execute([$id]);
                    $old_keterangan = $stmt->fetchColumn();
                    
                    // Hapus semua reward dengan deskripsi yang sama (kecuali yang sedang diedit)
                    if ($old_keterangan) {
                        $base_old_description = trim(preg_replace('/\s*\(Kualifikasi: \d+ referral\)/', '', $old_keterangan));
                        
                        // Hapus semua reward dengan deskripsi dasar yang sama
                        $stmt = $conn->prepare("DELETE FROM product_rewards 
                                              WHERE product_id = ? 
                                              AND reward_type = 'referral' 
                                              AND id <> ? 
                                              AND keterangan LIKE ?");
                        $stmt->execute([$product_id, $id, '%' . $base_old_description . '%']);
                    }
                }
                
                // Simpan reward untuk setiap kualifikasi
                $success = true;
                $message = "Reward referral berhasil disimpan dengan kualifikasi: ";
                $qualification_messages = [];
                
                foreach ($qualifications as $index => $qualification) {
                    $qual_value = intval($qualification);
                    $reward_value = isset($qualification_rewards[$index]) ? floatval($qualification_rewards[$index]) : 0;
                    $reward_description = $base_description;
                    
                    // Jika ada lebih dari 1 kualifikasi, tambahkan info ke deskripsi
                    if (count($qualifications) > 1) {
                        $reward_description .= " (Kualifikasi: $qual_value referral)";
                    }
                    
                    // Siapkan data reward
                    $reward_data = [
                        'product_id' => $product_id,
                        'reward_type' => 'referral',
                        'point' => $qual_value,
                        'reward_amount' => $reward_value,
                        'is_active' => isset($_POST['is_active']) ? intval($_POST['is_active']) : 1,
                        'keterangan' => $reward_description
                    ];
                    
                    // Jika ini adalah qualification pertama dan sedang edit, update ID yang ada
                    if ($index === 0 && $id > 0) {
                        $reward_data['id'] = $id;
                        
                        // Update reward yang ada
                        $stmt = $conn->prepare("UPDATE product_rewards SET 
                                              point = ?, 
                                              reward_amount = ?, 
                                              is_active = ?, 
                                              keterangan = ? 
                                              WHERE id = ?");
                        $result = $stmt->execute([
                            $qual_value,
                            $reward_value,
                            $reward_data['is_active'],
                            $reward_description,
                            $id
                        ]);
                    } else {
                        // Insert reward baru
                        $stmt = $conn->prepare("INSERT INTO product_rewards (
                                              product_id, 
                                              reward_type, 
                                              point, 
                                              reward_amount, 
                                              is_active, 
                                              keterangan) 
                                              VALUES (?, ?, ?, ?, ?, ?)");
                        $result = $stmt->execute([
                            $product_id,
                            'referral',
                            $qual_value,
                            $reward_value,
                            $reward_data['is_active'],
                            $reward_description
                        ]);
                    }
                    
                    if ($result) {
                        $qualification_messages[] = "$qual_value referral = " . number_format($reward_value, 0, ',', '.');
                    } else {
                        $success = false;
                    }
                }
                
                if ($success) {
                    $message .= implode(', ', $qualification_messages);
                    header("Location: settings.php?komisi_produk=$product_id&success=1&msg=" . urlencode($message));
                    exit;
                } else {
                    $error = "Gagal menyimpan beberapa reward kualifikasi referral";
                }
            } else {
                // Untuk tipe reward lainnya, proses seperti biasa
                $level = ($reward_type == 'level') ? intval($_POST['reward_level'] ?? 0) : null;
                $point = ($reward_type == 'level') ? intval($_POST['point'] ?? 0) : null;
                $left_leg = ($reward_type == 'binary_pair') ? intval($_POST['left_leg'] ?? 0) : null;
                $right_leg = ($reward_type == 'binary_pair') ? intval($_POST['right_leg'] ?? 0) : null;
                $milestone_point = ($reward_type == 'point_growth' || $reward_type == 'hybrid') ? intval($_POST['milestone_point'] ?? 0) : null;
                $reward_amount = floatval($_POST['reward_amount'] ?? 0);
                $is_active = isset($_POST['is_active']) ? intval($_POST['is_active']) : 1;
                $keterangan = trim($_POST['keterangan'] ?? '');

                if ($id > 0) {
                    $stmt = $conn->prepare("UPDATE product_rewards SET 
                        reward_type=?, 
                        level=?, 
                        point=?, 
                        left_leg=?, 
                        right_leg=?, 
                        milestone_point=?, 
                        reward_amount=?, 
                        is_active=?, 
                        keterangan=? 
                    WHERE id=?");
                    $stmt->execute([
                        $reward_type, 
                        $level, 
                        $point, 
                        $left_leg, 
                        $right_leg, 
                        $milestone_point, 
                        $reward_amount, 
                        $is_active, 
                        $keterangan,
                        $id
                    ]);
                    header("Location: settings.php?komisi_produk=$product_id&success=1&msg=" . urlencode("Reward updated successfully"));
                    exit;
                } else {
                    $stmt = $conn->prepare("INSERT INTO product_rewards (
                        product_id, 
                        reward_type, 
                        level, 
                        point, 
                        left_leg, 
                        right_leg, 
                        milestone_point, 
                        reward_amount, 
                        is_active, 
                        keterangan
                    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                    $stmt->execute([
                        $product_id, 
                        $reward_type, 
                        $level, 
                        $point, 
                        $left_leg, 
                        $right_leg, 
                        $milestone_point, 
                        $reward_amount, 
                        $is_active, 
                        $keterangan
                    ]);
                    header("Location: settings.php?komisi_produk=$product_id&success=1&msg=" . urlencode("New reward added successfully"));
                    exit;
                }
            }
        }
    }
}

// Hapus reward dengan opsi untuk menghapus semua yang terkait
if (isset($_GET['hapus_reward']) && intval($_GET['hapus_reward']) > 0) {
    $id = intval($_GET['hapus_reward']);
    $product_id = 0;
    
    // Periksa apakah ini reward referral dan perlu hapus terkait
    if (isset($_GET['hapus_related']) && $_GET['hapus_related'] == 1) {
        // Ambil informasi reward
        $stmt = $conn->prepare("SELECT product_id, reward_type, keterangan FROM product_rewards WHERE id = ?");
        $stmt->execute([$id]);
        $reward_info = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($reward_info && $reward_info['reward_type'] == 'referral') {
            $product_id = $reward_info['product_id'];
            
            // Hapus semua reward referral dengan keterangan yang sama (kelompok yang sama)
            $base_description = trim(preg_replace('/\s*\(Kualifikasi: \d+ referral\)/', '', $reward_info['keterangan']));
            
            $stmt = $conn->prepare("DELETE FROM product_rewards 
                                  WHERE product_id = ? 
                                  AND reward_type = 'referral' 
                                  AND keterangan LIKE ?");
            $stmt->execute([$reward_info['product_id'], '%' . $base_description . '%']);
            
            $msg = "Semua reward referral terkait berhasil dihapus!";
        } else {
            // Hapus reward biasa
            $stmt = $conn->prepare("SELECT product_id FROM product_rewards WHERE id = ?");
            $stmt->execute([$id]);
            $product_id = $stmt->fetchColumn();
            
            $stmt = $conn->prepare("DELETE FROM product_rewards WHERE id = ?");
            $stmt->execute([$id]);
            $msg = "Reward deleted!";
        }
    } else {
        // Ambil product_id terlebih dahulu untuk redirect
        $stmt = $conn->prepare("SELECT product_id FROM product_rewards WHERE id = ?");
        $stmt->execute([$id]);
        $product_id = $stmt->fetchColumn();
        
        // Hapus reward biasa
        $stmt = $conn->prepare("DELETE FROM product_rewards WHERE id = ?");
        $stmt->execute([$id]);
        $msg = "Reward deleted!";
    }
    
    // Redirect kembali ke halaman reward produk
    if ($product_id) {
        header("Location: settings.php?komisi_produk=$product_id&success=1&msg=" . urlencode($msg));
        exit;
    }
}

// LEVEL/COACH/REFERRAL SYSTEM
$conn->exec("CREATE TABLE IF NOT EXISTS settings_level (
    id INT AUTO_INCREMENT PRIMARY KEY,
    nama_level VARCHAR(100) NOT NULL,
    level INT NOT NULL,
    jenis_komisi ENUM('nominal','persen') NOT NULL DEFAULT 'nominal',
    nilai_komisi DECIMAL(12,2) NOT NULL,
    catatan VARCHAR(255) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

if (isset($_POST['level_save']) && csrf_verify($_POST['csrf_token'])) {
    // Terapkan pencegahan resubmit
    if (prevent_form_resubmission('level_save', 'settings.php', ['success' => 1, 'msg' => 'Level settings updated'])) {
        $id = intval($_POST['level_id'] ?? 0);
        $nama_level = trim($_POST['nama_level']);
        $level = intval($_POST['level']);
        $jenis_komisi = $_POST['jenis_komisi'];
        $nilai_komisi = floatval($_POST['nilai_komisi']);
        $catatan = trim($_POST['catatan'] ?? '');

        if (!$nama_level || !$jenis_komisi || !$nilai_komisi || !$level) {
            $error = "All level fields are required!";
        } else {
            try {
                if ($id > 0) {
                    $stmt = $conn->prepare("UPDATE settings_level SET nama_level=?, level=?, jenis_komisi=?, nilai_komisi=?, catatan=? WHERE id=?");
                    $stmt->execute([$nama_level, $level, $jenis_komisi, $nilai_komisi, $catatan, $id]);
                    header("Location: settings.php?success=1&msg=" . urlencode("Level updated successfully"));
                    exit;
                } else {
                    $stmt = $conn->prepare("INSERT INTO settings_level (nama_level, level, jenis_komisi, nilai_komisi, catatan) VALUES (?, ?, ?, ?, ?)");
                    $stmt->execute([$nama_level, $level, $jenis_komisi, $nilai_komisi, $catatan]);
                    header("Location: settings.php?success=1&msg=" . urlencode("New level added successfully"));
                    exit;
                }
            } catch (PDOException $e) {
                $error = $e->getMessage();
            }
        }
    }
}

if (isset($_GET['hapus_level']) && intval($_GET['hapus_level']) > 0) {
    $id = intval($_GET['hapus_level']);
    $stmt = $conn->prepare("DELETE FROM settings_level WHERE id=?");
    $stmt->execute([$id]);
    $msg = "Level deleted!";
}

// LOAD MAIN DATA
$stmt = $conn->prepare("SELECT * FROM products ORDER BY nama_produk ASC");
$stmt->execute();
$produk_list = $stmt->fetchAll(PDO::FETCH_ASSOC);

$stmt = $conn->prepare("SELECT * FROM products WHERE status='aktif' ORDER BY nama_produk ASC");
$stmt->execute();
$produk_list_aktif = $stmt->fetchAll(PDO::FETCH_ASSOC);

$stmt = $conn->prepare("SELECT * FROM settings_level ORDER BY level ASC, id ASC");
$stmt->execute();
$level_list = $stmt->fetchAll(PDO::FETCH_ASSOC);

$produk_edit = isset($_GET['edit_produk']) ? get_product(intval($_GET['edit_produk'])) : null;
$komisi_edit = isset($_GET['komisi_produk']) ? get_product(intval($_GET['komisi_produk'])) : null;
$level_edit = null;

if (isset($_GET['edit_level'])) {
    foreach ($level_list as $lv) {
        if ($lv['id'] == intval($_GET['edit_level'])) {
            $level_edit = $lv;
            break;
        }
    }
}

// Load reward data dan reward terkait untuk referral
$reward_edit = null;
$reward_related = [];

if (isset($_GET['edit_reward'])) {
    $stmt = $conn->prepare("SELECT * FROM product_rewards WHERE id=?");
    $stmt->execute([intval($_GET['edit_reward'])]);
    $reward_edit = $stmt->fetch(PDO::FETCH_ASSOC);
    
    // Jika reward type adalah referral, ambil semua reward terkait
    if ($reward_edit && $reward_edit['reward_type'] == 'referral') {
        // Ambil base description dengan menghapus bagian "Kualifikasi: x referral"
        $base_description = trim(preg_replace('/\s*\(Kualifikasi: \d+ referral\)/', '', $reward_edit['keterangan'] ?? ''));
        
        // Ambil semua reward dengan deskripsi yang sama kecuali yang sedang diedit
        $stmt = $conn->prepare("SELECT * FROM product_rewards 
                               WHERE product_id = ? 
                               AND reward_type = 'referral' 
                               AND id <> ? 
                               AND keterangan LIKE ? 
                               ORDER BY point ASC");
        $stmt->execute([$reward_edit['product_id'], $reward_edit['id'], '%' . $base_description . '%']);
        $reward_related = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}

// Load commission per level
$komisi_per_level = [];
$max_level = $max_level_setting;
if ($komisi_edit) {
    if (isset($_POST['max_level'])) $max_level = intval($_POST['max_level']);
    for ($lv = 1; $lv <= $max_level; $lv++) {
        $stmt = $conn->prepare("SELECT commission_amount, is_active FROM product_commissions WHERE product_id=? AND level=?");
        $stmt->execute([$komisi_edit['id'], $lv]);
        $komisi_per_level[$lv] = $stmt->fetch(PDO::FETCH_ASSOC);
    }
}

// Load product rewards
$product_rewards = [];
if ($komisi_edit) {
    $stmt = $conn->prepare("SELECT * FROM product_rewards WHERE product_id=? ORDER BY reward_type, level ASC, point ASC");
    $stmt->execute([$komisi_edit['id']]);
    $product_rewards = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// Fungsi helper untuk mengelompokkan reward referral
function group_referral_rewards($rewards) {
    $grouped = [];
    $grouped_ids = [];
    
    foreach ($rewards as $reward) {
        if ($reward['reward_type'] == 'referral') {
            // Ambil deskripsi dasar tanpa info kualifikasi
            $base_description = trim(preg_replace('/\s*\(Kualifikasi: \d+ referral\)/', '', $reward['keterangan'] ?? ''));
            
            // Gunakan deskripsi dasar sebagai key
            if (!isset($grouped[$base_description])) {
                $grouped[$base_description] = [];
                $grouped_ids[$base_description] = [];
            }
            
            $grouped[$base_description][] = $reward;
            $grouped_ids[$base_description][] = $reward['id'];
        }
    }
    
    return ['groups' => $grouped, 'ids' => $grouped_ids];
}

function show_empty($val) {
    return ($val === null || $val === '' || $val === 0) ? '' : $val;
}

// Root member
$current_root_id = @$setting['root_member_id'];
$current_root_info = null;
if ($current_root_id) {
    $stmt = $conn->prepare("SELECT username, nama_lengkap FROM users WHERE id=?");
    $stmt->execute([$current_root_id]);
    $current_root_info = $stmt->fetch(PDO::FETCH_ASSOC);
}

// Ambil pesan sukses dari URL jika ada
$success_message = '';
if (isset($_GET['success']) && $_GET['success'] == 1) {
    $success_message = isset($_GET['msg']) ? urldecode($_GET['msg']) : "Operation completed successfully!";
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <title>Pengaturan Admin Panel</title>
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        body { background: #f8f9fa; color: #333; min-height: 100vh; font-family: 'Segoe UI', Arial, sans-serif;}
        .container { max-width: 1200px; }
        .form-label, .form-check-label, .section-title { color: #2c3e50; font-weight:600; }
        .form-control, .form-select, textarea { background: #fff; color: #333; border: 1px solid #ced4da; }
        .form-control:focus, .form-select:focus { border-color: #80bdff; box-shadow: 0 0 0 0.2rem rgba(0,123,255,.25); }
        .btn-primary { background: #3498db; border-color: #3498db; }
        .btn-warning { background: #f39c12; border-color: #f39c12; }
        .btn-success { background: #2ecc71; border-color: #2ecc71; }
        .btn-danger { background: #e74c3c; border-color: #e74c3c; }
        .btn-info { background: #17a2b8; border-color: #17a2b8; }
        .alert { background: #e3f2fd; color: #0c5460; border: none; }
        .section-title { color: #2c3e50; font-weight: 700; margin-top: 2rem; margin-bottom: 1rem; border-bottom: 2px solid #3498db; padding-bottom: 0.5rem; }
        .bg-setting { background: #fff; border-radius: 5px; padding: 20px; box-shadow: 0 0.125rem 0.25rem rgba(0,0,0,.075); margin-bottom: 20px; }
        .text-small { font-size: .85em; color: #6c757d; }
        .badge-info { background: #17a2b8; }
        .badge-warning { background: #f39c12; }
        .back-btn { position: absolute; left: 20px; top: 15px; z-index:20;}
        .clock {
            position: fixed;
            top: 15px;
            right: 20px;
            background: #2c3e50;
            color: #fff;
            font-size: 1rem;
            font-family: 'Consolas', monospace;
            padding: 5px 15px;
            border-radius: 5px;
            z-index: 30;
            box-shadow: 0 2px 5px rgba(0,0,0,0.1);
        }
        @media (max-width: 768px) {
            .container { max-width: 100%; padding: 0 15px; }
            .bg-setting { padding: 15px; }
            .back-btn { left: 10px; top: 10px; }
            h2 { font-size: 1.25rem;}
            .clock { font-size: .9rem; right:10px; top: 10px;}
        }
        .desc-box {
            background: #e3f2fd;
            color: #0c5460;
            border-left: 4px solid #3498db;
            padding: 10px 15px;
            margin-top: 1rem;
            font-size: .95em;
            border-radius: 4px;
        }
        .table-sm th, .table-sm td {font-size: .9em; vertical-align: middle;}
        .hybrid-tip { color: #0c5460; background: #e3f2fd; border-left: 4px solid #3498db; padding: 10px 15px; border-radius: 4px; margin-bottom: 15px;}
        .table thead th {background: #2c3e50; color: #fff;}
        .btn-action {margin-right: 5px; margin-bottom: 5px;}
        .responsive-table {overflow-x: auto; -webkit-overflow-scrolling: touch;}
        .card-header { font-weight: 600; }
        .nav-tabs .nav-link.active { font-weight: bold; }
        .referral-qualification-group {
            background-color: #f8f9fa;
            border: 1px solid #e9ecef;
            border-radius: 5px;
            padding: 10px;
            margin-bottom: 10px;
        }
        .qualification-badge {
            font-size: 0.85rem;
            padding: 3px 8px;
            margin-right: 5px;
            margin-bottom: 5px;
            display: inline-block;
        }
        #alert-container {
            position: fixed;
            top: 15px;
            right: 15px;
            z-index: 1050;
            max-width: 350px;
        }
        /* Tombol untuk menambah qualification */
        .add-qualification-btn {
            padding: 0.25rem 0.5rem;
        }
        .remove-qualification-btn {
            padding: 0.25rem 0.5rem;
        }
    </style>
</head>
<body>
<a href="dashboard.php" class="btn btn-primary back-btn" onclick="clearFormTokens()"><i class="fas fa-arrow-left"></i> Dashboard</a>
<div class="clock" id="clock"></div>
<div class="container py-4">
    <h2 class="mb-4 text-center">Pengaturan Produk & Reward</h2>
    
    <!-- Alert container untuk notifikasi -->
    <div id="alert-container"></div>
    
    <?php if($success_message): ?>
        <div class="alert alert-success mb-3"><?= htmlspecialchars($success_message) ?></div>
    <?php endif; ?>
    
    <?php if($msg): ?><div class="alert alert-success mb-3"><?= htmlspecialchars($msg) ?></div><?php endif; ?>
    <?php if($error): ?><div class="alert alert-danger mb-3"><?= htmlspecialchars($error) ?></div><?php endif; ?>
    
    <?php if($is_superadmin): ?>
    <!-- =========  SUPERADMIN ONLY: GLOBAL SETTINGS  ========= -->
    <div class="bg-setting shadow mb-4">
      <form method="post" autocomplete="off">
        <?= csrf_field() ?>
        <?= generate_form_token('save_maxlevel') ?>
        <div class="section-title">Maximum Commission & Point Levels</div>
        <div class="mb-3">
            <label class="form-label">Maximum Levels (Commission & Points)</label>
            <input type="number" min="1" max="30" name="max_level" class="form-control" required value="<?= htmlspecialchars($max_level_setting) ?>">
            <div class="text-small mt-1">
              Applies to level commissions and point distribution to upline (synchronized, <b>changing this will automatically update all calculations</b>).
            </div>
        </div>
        <button type="submit" name="save_maxlevel" class="btn btn-primary px-4">Save Max Level</button>
      </form>
    </div>
    
     <!-- 1. Network Settings -->
    <div class="bg-setting shadow mb-4">
    <form method="post" autocomplete="off">
        <?= csrf_field() ?>
        <?= generate_form_token('save_network_settings') ?>
        <div class="section-title">1. Team Structure (Genealogy)</div>
        <div class="mb-3">
            <label class="form-label">Teamwork Type</label>
            <select name="network_type" class="form-select" required id="network_type" onchange="onTypeChange()">
                <option value="binary" <?= @$setting['network_type']=='binary'?'selected':''; ?>>Binary (2 legs)</option>
                <option value="trinary" <?= @$setting['network_type']=='trinary'?'selected':''; ?>>Trinary (3 legs)</option>
                <option value="n-legs" <?= @$setting['network_type']=='n-legs'?'selected':''; ?>>N-Legs (Custom)</option>
                <option value="monoline" <?= @$setting['network_type']=='monoline'?'selected':''; ?>>Monoline (Single Line)</option>
                <option value="hybrid" <?= @$setting['network_type']=='hybrid'?'selected':''; ?>>Hybrid (Flexible/Level/Product)</option>
            </select>
            <div class="text-small mt-1">
                Select main team structure (binary, trinary, n-legs, monoline, hybrid etc).
            </div>
        </div>
        <div class="mb-3" id="max_legs_wrap">
            <label class="form-label">Direct Legs Count (Max Legs)</label>
            <input type="number" name="max_legs" class="form-control" min="1" max="20" value="<?= @$setting['max_legs'] ?? 2 ?>" id="max_legs">
            <div class="text-small mt-1">
                For custom/hybrid, set maximum legs per member.
            </div>
        </div>
        <div class="mb-3" id="hybrid_config_wrap" style="display:<?= (isset($setting['network_type']) && $setting['network_type'] == 'hybrid') ? 'block' : 'none'; ?>">
            <label class="form-label">Hybrid Config (Optional, JSON format)</label>
            <textarea name="hybrid_config" class="form-control" rows="3" placeholder='{"level1":2, "level2":3, "level3":5}'><?= @$setting['hybrid_config'] ?? '' ?></textarea>
            <div class="text-small mt-1">Example: {"level1":2,"level2":3} means level 1 binary, level 2 trinary.</div>
        </div>
        <div class="mb-3">
            <label class="form-label">Setting Description (optional)</label>
            <input type="text" name="description" class="form-control" maxlength="128" placeholder="Notes: products, active period, etc" value="<?= @$setting['description'] ?? '' ?>">
        </div>
        <div class="mb-3">
            <label class="form-label">Autoplacement</label><br>
            <div class="form-check form-check-inline">
                <input class="form-check-input" type="radio" name="autoplacement_enabled" id="autoplace_yes" value="1" <?= (!isset($setting['autoplacement_enabled']) || $setting['autoplacement_enabled']) ? 'checked' : ''; ?>>
                <label class="form-check-label" for="autoplace_yes">Yes (Automatic)</label>
            </div>
            <div class="form-check form-check-inline">
                <input class="form-check-input" type="radio" name="autoplacement_enabled" id="autoplace_no" value="0" <?= (isset($setting['autoplacement_enabled']) && !$setting['autoplacement_enabled']) ? 'checked' : ''; ?>>
                <label class="form-check-label" for="autoplace_no">No (Manual)</label>
            </div>
        </div>
        <div class="mb-3" id="autoplacement_mode_wrap" style="<?= (!isset($setting['autoplacement_enabled']) || $setting['autoplacement_enabled']) ? '' : 'display:none;' ?>">
            <label class="form-label">Autoplacement Mode</label><br>
            <div class="form-check">
                <input class="form-check-input" type="radio" name="autoplacement_mode" id="apl_mode_referral" value="referral"
                    <?= (!isset($setting['autoplacement_mode']) || $setting['autoplacement_mode']=='referral') ? 'checked' : ''; ?>>
                <label class="form-check-label" for="apl_mode_referral">
                    Berdasarkan Referral<br>
                    <span class="text-small">Slot kosong dicari mulai dari referral/upline yang mengajak (spillover lokal).</span>
                </label>
            </div>
            <div class="form-check mt-1">
                <input class="form-check-input" type="radio" name="autoplacement_mode" id="apl_mode_global" value="global"
                    <?= (isset($setting['autoplacement_mode']) && $setting['autoplacement_mode']=='global') ? 'checked' : ''; ?>>
                <label class="form-check-label" for="apl_mode_global">
                    Global (Mulai dari Root)<br>
                    <span class="text-small">Slot kosong dicari dari root/top (spillover global untuk seluruh jaringan).</span>
                </label>
            </div>
        </div>
        <!-- === PILIH ROOT MEMBER === -->
        <div class="mb-3" id="root_member_id_wrap">
            <label class="form-label">Root Member (Top Jaringan untuk Autoplacement Global)</label>
            <select name="root_member_id" class="form-select">
                <option value="">-- Pilih Root Member --</option>
                <?php foreach($member_options as $m): ?>
                    <option value="<?= $m['id'] ?>" <?= $current_root_id == $m['id'] ? 'selected' : '' ?>>
                        <?= htmlspecialchars($m['username']) ?> (<?= htmlspecialchars($m['nama_lengkap']) ?>) [ID:<?= $m['id'] ?>]
                    </option>
                <?php endforeach; ?>
            </select>
            <div class="text-small mt-1">Root/top member ini akan dipakai untuk autoplacement global.<br>
                Jika kosong, sistem akan pakai member pertama parent_id NULL/0.
            </div>
        </div>
        <button type="submit" name="save_network_settings" class="btn btn-primary px-5">Save Settings</button>
    </form>
    <?php if($current_root_info): ?>
        <div class='alert alert-info mt-3'>Root member saat ini: <b><?= htmlspecialchars($current_root_info['username']) ?></b> (<?= htmlspecialchars($current_root_info['nama_lengkap']) ?>) [ID: <?= $current_root_id ?>]</div>
    <?php endif; ?>
    </div>

    <!-- 2. Global Commission -->
    <div class="bg-setting shadow mb-4">
        <form method="post" class="row g-2">
            <?= csrf_field() ?>
            <?= generate_form_token('save_global_commission') ?>
            <div class="section-title">2. Global Commission Settings</div>
            <div class="col-md-3">
                <label>Default Commission Type *</label>
                <select name="default_type" class="form-select" required>
                    <option value="fixed" <?= (isset($global_cfg['default_type']) && $global_cfg['default_type']=='fixed') ? 'selected' : '' ?>>Fixed (Rp)</option>
                    <option value="percent" <?= (isset($global_cfg['default_type']) && $global_cfg['default_type']=='percent') ? 'selected' : '' ?>>Percentage (%)</option>
                </select>
            </div>
            <div class="col-md-2">
                <label>Default Value *</label>
                <input type="number" step="0.01" min="0" class="form-control" name="default_value" required value="<?= htmlspecialchars($global_cfg['default_value'] ?? '0') ?>">
            </div>
            <div class="col-md-2">
                <label>Max Level *</label>
                <input type="number" min="1" max="30" class="form-control" name="max_level" required value="<?= htmlspecialchars($global_cfg['max_level'] ?? '10') ?>">
            </div>
            <div class="col-md-2">
                <label>Commission Status *</label>
                <select name="is_active" class="form-select">
                    <option value="1" <?= (!isset($global_cfg['is_active']) || $global_cfg['is_active']==1) ? 'selected' : '' ?>>Active</option>
                    <option value="0" <?= (isset($global_cfg['is_active']) && $global_cfg['is_active']==0) ? 'selected' : '' ?>>Inactive</option>
                </select>
            </div>
            <div class="col-md-3 d-flex align-items-end">
                <button type="submit" name="save_global_commission" class="btn btn-primary w-100">Save Commission</button>
            </div>
        </form>
    </div>
    <?php endif; ?>

       <!-- 3. PRODUCTS CRUD -->
    <div class="bg-setting shadow mb-4">
        <div class="section-title"><?= $produk_edit ? "Edit Product" : "Add New Product" ?></div>
		        <form method="post" id="produkForm" class="row g-2">
            <?= csrf_field() ?>
            <?= generate_form_token('produk_save') ?>
            <input type="hidden" name="produk_id" value="<?= $produk_edit['id'] ?? '' ?>">
            <div class="col-md-2">
                <label>Product Code *</label>
                <input type="text" class="form-control" name="kode_produk" required value="<?= htmlspecialchars($produk_edit['kode_produk'] ?? '') ?>">
            </div>
            <div class="col-md-3">
                <label>Product Name *</label>
                <input type="text" class="form-control" name="nama_produk" required value="<?= htmlspecialchars($produk_edit['nama_produk'] ?? '') ?>">
            </div>
            <div class="col-md-2">
                <label>Price *</label>
                <input type="number" step="0.01" min="0" class="form-control" name="harga" required value="<?= htmlspecialchars($produk_edit['harga'] ?? '') ?>">
            </div>
            <div class="col-md-2">
                <label>Currency</label>
                <select class="form-select" name="currency">
                    <option value="IDR" <?= ($produk_edit['currency']??'IDR')==='IDR'?'selected':'' ?>>IDR</option>
                    <option value="USD" <?= ($produk_edit['currency']??'')==='USD'?'selected':'' ?>>USD</option>
                    <option value="EUR" <?= ($produk_edit['currency']??'')==='EUR'?'selected':'' ?>>EUR</option>
                </select>
            </div>
            <div class="col-md-2">
                <label>Direct Commission</label>
                <input type="number" step="0.01" class="form-control" name="direct_commission" min="0" 
                       value="<?= htmlspecialchars($produk_edit['direct_commission'] ?? '0') ?>">
            </div>
            <div class="col-md-2">
                <label>Status</label>
                <select class="form-select" name="status">
                    <option value="aktif" <?= ($produk_edit['status']??'aktif')==='aktif'?'selected':'' ?>>Active</option>
                    <option value="nonaktif" <?= ($produk_edit['status']??'')==='nonaktif'?'selected':'' ?>>Inactive</option>
                </select>
            </div>
            <div class="col-md-3">
                <label>Upgrade To Product</label>
                <select class="form-select" name="upgrade_to">
                    <option value="">- None -</option>
                    <?php foreach($produk_list_aktif as $prd): 
                        if(isset($produk_edit['id']) && $prd['id'] == $produk_edit['id']) continue; ?>
                        <option value="<?= $prd['id'] ?>" <?= (isset($produk_edit['upgrade_to']) && $produk_edit['upgrade_to'] == $prd['id'])?'selected':'' ?>>
                            <?= htmlspecialchars($prd['nama_produk']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2 d-flex align-items-end">
                <button type="submit" name="produk_save" class="btn btn-primary me-2"><?= $produk_edit ? "Update" : "Add" ?></button>
                <?php if ($produk_edit): ?>
                <a href="settings.php" class="btn btn-success"><i class="fas fa-plus"></i> Add New Product</a>
                <?php endif; ?>
            </div>
        </form>
    </div>
    <div class="bg-setting shadow mb-4">
        <b>Product List</b>
        <div class="responsive-table">
        <table class="table table-bordered table-sm produk-table">
            <thead>
                <tr>
                    <th>#</th>
                    <th>Code</th>
                    <th>Product Name</th>
                    <th>Price</th>
                    <th>Currency</th>
                    <th>Direct Commission</th>
                    <th>Status</th>
                    <th>Upgrade To</th>
                    <th width="200">Actions</th>
                </tr>
            </thead>
            <tbody>
            <?php if($produk_list): $no=1; foreach($produk_list as $prd): ?>
                <tr>
                    <td><?= $no++ ?></td>
                    <td><?= htmlspecialchars($prd['kode_produk']) ?></td>
                    <td><?= htmlspecialchars($prd['nama_produk']) ?></td>
                    <td><?= number_format($prd['harga'],0,',','.') ?></td>
                    <td><?= htmlspecialchars($prd['currency']??'IDR') ?></td>
                    <td><?= number_format($prd['direct_commission'],0,',','.') ?></td>
                    <td><?= htmlspecialchars($prd['status']) ?></td>
                    <td>
                        <?php
                        if ($prd['upgrade_to']) {
                            foreach($produk_list as $p2) {
                                if($p2['id'] == $prd['upgrade_to']) {
                                    echo htmlspecialchars($p2['nama_produk']);
                                    break;
                                }
                            }
                        } else {
                            echo '-';
                        }
                        ?>
                    </td>
                    <td>
                        <a href="settings.php?edit_produk=<?= $prd['id'] ?>" class="btn btn-sm btn-warning btn-action">Edit</a>
                        <a href="settings.php?komisi_produk=<?= $prd['id'] ?>" class="btn btn-sm btn-info btn-action">Commission & Reward</a>
                        <?php if($prd['status']=='aktif'): ?>
                            <a href="settings.php?nonaktif_produk=<?= $prd['id'] ?>" class="btn btn-sm btn-danger btn-action" onclick="return confirm('Deactivate this product?')">Deactivate</a>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; else: ?>
                <tr><td colspan="9" class="text-center text-muted">No products yet.</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
        </div>
    </div>

    <!-- 4. PRODUCT LEVEL COMMISSIONS & REWARDS -->
    <?php if ($komisi_edit): ?>
    <div class="bg-setting shadow mb-4">
        <div class="section-title">Level Commissions & Reward Points: <b><?= htmlspecialchars($komisi_edit['nama_produk']) ?></b></div>
        <form method="post" class="row g-3">
            <?= csrf_field() ?>
            <?= generate_form_token('komisi_save') ?>
            <input type="hidden" name="komisi_product_id" value="<?= $komisi_edit['id'] ?>">
            <div class="col-md-3">
                <label>Level Count</label>
                <input type="number" name="max_level" min="1" max="30" value="<?= $max_level ?>" class="form-control" onchange="this.form.submit()">
            </div>
            <div class="col-12">
                <div class="row row-cols-1 row-cols-md-2 row-cols-lg-4 g-4">
                    <?php for($lv=1; $lv<=$max_level; $lv++): ?>
                    <div class="col">
                        <div class="card h-100 shadow-sm">
                            <div class="card-header bg-primary text-white">
                                <h6 class="card-title mb-0">Level <?= $lv ?></h6>
                            </div>
                            <div class="card-body">
                                <div class="mb-3">
                                    <label class="form-label">Commission Amount</label>
                                    <input type="number" step="0.01" name="komisi[<?= $lv ?>]" class="form-control" min="0"
                                        value="<?= isset($komisi_per_level[$lv]['commission_amount']) ? htmlspecialchars($komisi_per_level[$lv]['commission_amount']) : 0 ?>">
                                </div>
                                <div class="mb-0">
                                    <label class="form-label">Status</label>
                                    <select name="is_active[<?= $lv ?>]" class="form-select">
                                        <option value="1" <?= (!isset($komisi_per_level[$lv]) || (isset($komisi_per_level[$lv]['is_active']) && $komisi_per_level[$lv]['is_active']==1)) ? 'selected' : '' ?>>Active</option>
                                        <option value="0" <?= (isset($komisi_per_level[$lv]) && isset($komisi_per_level[$lv]['is_active']) && $komisi_per_level[$lv]['is_active']==0) ? 'selected' : '' ?>>Inactive</option>
                                    </select>
                                </div>
                            </div>
                        </div>
                    </div>
                    <?php endfor; ?>
                </div>
            </div>
            <div class="col-12 mt-4">
                <button type="submit" name="komisi_save" class="btn btn-success px-4">
                    Save Level Commissions
                </button>
                <a href="settings.php" class="btn btn-secondary px-4">
                    Back to Product List
                </a>
            </div>
        </form>
        
        <!-- Form Reward Level (Trinary) -->
        <div class="bg-setting shadow mb-4">
            <div class="section-title">
                <?= $level_reward_edit ? "Edit Reward Level Penuh" : "Tambah Reward Level Penuh" ?>
            </div>
            
            <div class="alert alert-info">
                <strong>Info Reward Level Penuh:</strong><br>
                Reward akan diberikan ketika semua posisi di level tertentu terisi. Jumlah posisi tergantung network type yang aktif.<br>
                <strong>Untuk Trinary:</strong> Level 1 = 3 posisi, Level 2 = 9 posisi, Level 3 = 27 posisi, Level 4 = 81 posisi, Level 5 = 243 posisi
            </div>
            
            <form method="post" class="row g-3">
                <?= csrf_field() ?>
                <?= generate_form_token('level_reward_save') ?>
                <input type="hidden" name="reward_product_id" value="<?= $komisi_edit['id'] ?>">
                <?php if ($level_reward_edit): ?>
                <input type="hidden" name="level_reward_id" value="<?= $level_reward_edit['id'] ?>">
                <?php endif; ?>
                
                <div class="col-md-2">
                    <label class="form-label">Level *</label>
                    <select name="reward_level" class="form-select" required>
                        <?php for ($i = 1; $i <= 10; $i++): ?>
                            <option value="<?= $i ?>" <?= ($level_reward_edit && $level_reward_edit['level'] == $i) ? 'selected' : '' ?>>
                                Level <?= $i ?>
                            </option>
                        <?php endfor; ?>
                    </select>
                </div>
                
                <div class="col-md-3">
                    <label class="form-label">Reward Amount *</label>
                    <div class="input-group">
                        <span class="input-group-text">Rp</span>
                        <input type="number" name="level_reward_amount" class="form-control" required min="0" 
                               value="<?= htmlspecialchars($level_reward_edit['reward_amount'] ?? '') ?>">
                    </div>
                </div>
                
                <div class="col-md-2">
                    <label class="form-label">Status</label>
                    <select name="level_is_active" class="form-select">
                        <option value="1" <?= (!$level_reward_edit || $level_reward_edit['is_active'] == 1) ? 'selected' : '' ?>>Active</option>
                        <option value="0" <?= ($level_reward_edit && $level_reward_edit['is_active'] == 0) ? 'selected' : '' ?>>Inactive</option>
                    </select>
                </div>
                
                <div class="col-md-5">
                    <label class="form-label">Keterangan (Opsional)</label>
                    <input type="text" name="level_keterangan" class="form-control" 
                           value="<?= htmlspecialchars($level_reward_edit['keterangan'] ?? '') ?>"
                           placeholder="Misal: Reward Level 4 Penuh">
                    <small class="text-muted">Jika kosong, akan diisi otomatis "Level X Penuh (Y posisi) - Reward"</small>
                </div>
                
                <div class="col-12">
                    <button type="submit" name="level_reward_save" class="btn btn-primary">
                        <?= $level_reward_edit ? "Update Reward Level" : "Simpan Reward Level" ?>
                    </button>
                    <?php if ($level_reward_edit): ?>
                        <a href="settings.php?komisi_produk=<?= $komisi_edit['id'] ?>" class="btn btn-success">
                            Tambah Reward Level Baru
                        </a>
                    <?php endif; ?>
                </div>
            </form>
        </div>

        <!-- Tabel daftar reward level -->
        <div class="bg-setting shadow mb-4">
            <div class="section-title">Daftar Reward Level Penuh</div>
            <div class="responsive-table">
                <table class="table table-bordered table-sm">
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>Level</th>
                            <th>Jumlah Posisi</th>
                            <th>Reward Amount</th>
                            <th>Status</th>
                            <th>Keterangan</th>
                            <th width="150">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if($level_rewards): $no=1; foreach($level_rewards as $reward): ?>
                            <tr>
                                <td><?= $no++ ?></td>
                                <td>Level <?= htmlspecialchars($reward['level']) ?></td>
                                <td>
                                    <?php
                                    $network_type = get_network_type($conn);
                                    $positions = calculate_full_level_positions($reward['level'], $network_type);
                                    echo $positions . ' posisi';
                                    ?>
                                </td>
                                <td>Rp <?= number_format($reward['reward_amount'], 0, ',', '.') ?></td>
                                <td><?= $reward['is_active'] ? 'Active' : 'Inactive' ?></td>
                                <td><?= htmlspecialchars($reward['keterangan'] ?? '') ?></td>
                                <td>
                                    <a href="settings.php?komisi_produk=<?= $komisi_edit['id'] ?>&edit_level_reward=<?= $reward['id'] ?>" 
                                       class="btn btn-sm btn-warning btn-action">
                                        Edit
                                    </a>
                                    <a href="settings.php?komisi_produk=<?= $komisi_edit['id'] ?>&delete_level_reward=<?= $reward['id'] ?>" 
                                       class="btn btn-sm btn-danger btn-action"
                                       onclick="return confirm('Yakin ingin menghapus reward ini?')">
                                        Delete
                                    </a>
                                </td>
                            </tr>
                        <?php endforeach; else: ?>
                            <tr>
                                <td colspan="7" class="text-center text-muted">Belum ada reward level yang ditambahkan</td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!--5. === PRODUCT REWARDS (HYBRID) === -->
        <hr>
        <div class="section-title">Product Reward Settings: <b><?= htmlspecialchars($komisi_edit['nama_produk']) ?></b></div>
        <div class="hybrid-tip mb-3">
            <b>Reward Types:</b>
            <ul class="mb-1">
                <li>Referral, Level, Transaction, Upgrade (conventional)</li>
                <li>Binary Pair: reward based on left-right pairs (milestone balance)</li>
                <li>Point Growth: reward based on point/team growth without balance (monoline, N-legs, hybrid, etc)</li>
                <li>Hybrid: flexible milestone & reward, suitable for mixed systems</li>
            </ul>
        </div>
        <form method="post" class="row g-3 mb-4" id="rewardForm">
            <?= csrf_field() ?>
            <?= generate_form_token('reward_save') ?>
            <input type="hidden" name="reward_id" value="<?= $reward_edit['id'] ?? '' ?>">
            <input type="hidden" name="reward_product_id" value="<?= $komisi_edit['id'] ?>">
            <div class="col-md-3">
                <label>Reward Type *</label>
                <select name="reward_type" class="form-select" required onchange="showRewardFields(this.value)">
                    <option value="referral" <?= ($reward_edit['reward_type']??'referral')=='referral'?'selected':'' ?>>Referral</option>
                    <option value="level" <?= ($reward_edit['reward_type']??'')=='level'?'selected':'' ?>>Level</option>
                    <option value="transaksi" <?= ($reward_edit['reward_type']??'')=='transaksi'?'selected':'' ?>>Transaction</option>
                    <option value="upgrade" <?= ($reward_edit['reward_type']??'')=='upgrade'?'selected':'' ?>>Upgrade</option>
                    <option value="binary_pair" <?= ($reward_edit['reward_type']??'')=='binary_pair'?'selected':'' ?>>Binary Pair (Left-Right)</option>
                    <option value="point_growth" <?= ($reward_edit['reward_type']??'')=='point_growth'?'selected':'' ?>>Point/Team Growth</option>
                    <option value="hybrid" <?= ($reward_edit['reward_type']??'')=='hybrid'?'selected':'' ?>>Hybrid Milestone</option>
                </select>
            </div>

            <!-- Reward Referral Fields dengan Multiple Qualification -->
            <div class="col-md-6 reward-field reward-referral" style="display:none;">
                <label class="form-label">Jumlah Referral Langsung Kualifikasi *</label>
                <div class="referral-qualification-inputs">
                    <div class="row mb-2">
                        <div class="col-md-6">
                            <div class="input-group">
                                <span class="input-group-text">Kualifikasi 1</span>
                                <input type="number" min="1" class="form-control" name="qualification[]" value="<?= ($reward_edit['reward_type']??'')=='referral' ? htmlspecialchars($reward_edit['point']) : '' ?>" required>
                                <span class="input-group-text">referral</span>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="input-group">
                                <span class="input-group-text">Reward</span>
                                <input type="number" min="0" class="form-control" name="qualification_reward[]" value="<?= ($reward_edit['reward_type']??'')=='referral' ? htmlspecialchars($reward_edit['reward_amount']) : '' ?>">
                                <button type="button" class="btn btn-success add-qualification-btn">
                                    <i class="fas fa-plus"></i>
                                </button>
                            </div>
                        </div>
                    </div>
                    <?php 
                    // Tampilkan qualification tambahan jika sedang edit
                    if (($reward_edit['reward_type']??'')=='referral' && isset($reward_related) && !empty($reward_related)) {
                        $q_count = 2;
                        foreach ($reward_related as $related) {
                            echo '<div class="row mb-2 qualification-row">';
                            echo '<div class="col-md-6">';
                            echo '<div class="input-group">';
                            echo '<span class="input-group-text">Kualifikasi '.$q_count.'</span>';
                            echo '<input type="number" min="1" class="form-control" name="qualification[]" value="'.htmlspecialchars($related['point']).'" required>';
                            echo '<span class="input-group-text">referral</span>';
                            echo '</div>';
                            echo '</div>';
                            echo '<div class="col-md-6">';
                            echo '<div class="input-group">';
                            echo '<span class="input-group-text">Reward</span>';
                            echo '<input type="number" min="0" class="form-control" name="qualification_reward[]" value="'.htmlspecialchars($related['reward_amount']).'">';
                            echo '<button type="button" class="btn btn-danger remove-qualification-btn">';
                            echo '<i class="fas fa-minus"></i>';
                            echo '</button>';
                            echo '</div>';
                            echo '</div>';
                            echo '</div>';
                            $q_count++;
                        }
                    }
                    ?>
                </div>
                <small class="text-muted mt-1 d-block">Anda bisa menambahkan beberapa level kualifikasi referral dengan reward yang berbeda.</small>
            </div>
            
            <!-- LEVEL -->
            <div class="col-md-2 reward-field reward-level" style="display:none;">
                <label>Level (for level reward)</label>
                <input type="number" min="1" max="30" class="form-control" name="reward_level" value="<?= htmlspecialchars($reward_edit['level'] ?? '') ?>">
            </div>
            <div class="col-md-2 reward-field reward-level" style="display:none;">
                <label>Points (for level reward)</label>
                <input type="number" min="0" class="form-control" name="point"
                       value="<?= ($reward_edit['reward_type']??'')=='level' ? htmlspecialchars($reward_edit['point'] ?? 0) : '' ?>">
            </div>
            <!-- BINARY PAIR -->
            <div class="col-md-2 reward-field reward-binary" style="display:none;">
                <label>Left (pair milestone)</label>
                <input type="number" min="1" class="form-control" name="left_leg" value="<?= htmlspecialchars($reward_edit['left_leg'] ?? '') ?>">
            </div>
            <div class="col-md-2 reward-field reward-binary" style="display:none;">
                <label>Right (pair milestone)</label>
                <input type="number" min="1" class="form-control" name="right_leg" value="<?= htmlspecialchars($reward_edit['right_leg'] ?? '') ?>">
            </div>
            <!-- POINT GROWTH / HYBRID -->
            <div class="col-md-2 reward-field reward-growth" style="display:none;">
                <label>Point/Team (milestone)</label>
                <input type="number" min="1" class="form-control" name="milestone_point" value="<?= htmlspecialchars($reward_edit['milestone_point'] ?? '') ?>">
            </div>
            <!-- COMMON -->
            <div class="col-md-2">
                <label>Reward Amount *</label>
                <input type="number" class="form-control" name="reward_amount" required min="0" value="<?= htmlspecialchars($reward_edit['reward_amount'] ?? 0) ?>">
                <small class="text-muted">Untuk Referral, gunakan input di atas</small>
            </div>
            <div class="col-md-2">
                <label>Status</label>
                <select name="is_active" class="form-select">
                    <option value="1" <?= ($reward_edit['is_active']??1)==1?'selected':'' ?>>Active</option>
                    <option value="0" <?= ($reward_edit['is_active']??'')==0?'selected':'' ?>>Inactive</option>
                </select>
            </div>
            <div class="col-md-3">
                <label>Description</label>
                <input type="text" class="form-control" name="keterangan" value="<?= htmlspecialchars($reward_edit['keterangan'] ?? '') ?>">
                <small class="text-muted">Untuk Referral, keterangan akan ditambahkan info kualifikasi</small>
            </div>
            <div class="col-md-2 d-flex align-items-end">
                <button type="submit" name="reward_save" class="btn btn-primary px-4"><?= $reward_edit ? "Update" : "Add" ?></button>
                <?php if($reward_edit): ?>
                    <a href="settings.php?komisi_produk=<?= $komisi_edit['id'] ?>" class="btn btn-success ms-2">Add New Reward</a>
                <?php endif; ?>
            </div>
        </form>
        
        <div class="responsive-table">
        <table class="table table-bordered table-sm">
            <thead>
                <tr>
                    <th>#</th>
                    <th>Reward Type</th>
                    <th>Qualification / Detail</th>
                    <th>Status</th>
                    <th>Description</th>
                    <th width="120">Actions</th>
                </tr>
            </thead>
            <tbody>
            <?php 
            if($product_rewards): 
                $no=1; 
                // Kelompokkan reward referral
                $grouped_rewards = group_referral_rewards($product_rewards);
                $grouped_referrals = $grouped_rewards['groups'];
                $grouped_ids = $grouped_rewards['ids'];
                $processed_ids = [];
                
                foreach($product_rewards as $rw): 
                    // Skip reward referral yang sudah diproses
                    if ($rw['reward_type'] == 'referral') {
                        // Cari di grup mana reward ini berada
                        $found = false;
                        foreach ($grouped_ids as $desc => $ids) {
                            if (in_array($rw['id'], $ids)) {
                                // Jika ID ini sudah ditampilkan dalam grup, skip
                                if (in_array($rw['id'], $processed_ids)) {
                                    $found = true;
                                    break;
                                }
                                
                                // Tampilkan sebagai grup
                                echo '<tr>';
                                echo '<td>' . $no++ . '</td>';
                                echo '<td>Referral</td>';
                                
                                // Tampilkan semua kualifikasi dalam grup
                                echo '<td>';
                                foreach ($grouped_referrals[$desc] as $related) {
                                    echo '<div class="qualification-badge badge bg-info">';
                                    echo intval($related['point']) . ' referral = Rp ' . number_format($related['reward_amount'], 0, ',', '.');
                                    echo '</div> ';
                                    // Tandai sebagai telah diproses
                                    $processed_ids[] = $related['id'];
                                }
                                echo '</td>';
                                
                                // Status (gunakan status dari reward pertama dalam grup)
                                $group_status = $grouped_referrals[$desc][0]['is_active'];
                                echo '<td>' . ($group_status ? 'Active' : 'Inactive') . '</td>';
                                
                                // Deskripsi (tanpa bagian kualifikasi)
                                echo '<td>' . htmlspecialchars($desc) . '</td>';
                                
                                // Tombol aksi
                                $first_id = $grouped_referrals[$desc][0]['id'];
                                echo '<td>';
                                echo '<a href="settings.php?komisi_produk=' . $komisi_edit['id'] . '&edit_reward=' . $first_id . '" class="btn btn-sm btn-warning btn-action">Edit</a> ';
                                echo '<a href="settings.php?komisi_produk=' . $komisi_edit['id'] . '&hapus_reward=' . $first_id . '&hapus_related=1" class="btn btn-sm btn-danger btn-action" onclick="return confirm(\'Delete all related qualifications?\')">Delete</a>';
                                echo '</td>';
                                echo '</tr>';
                                
                                $found = true;
                                break;
                            }
                        }
                        
                        if ($found) continue; // Lanjut ke reward berikutnya
                    }
                    
                    // Untuk reward selain referral yang dikelompokkan
                    echo '<tr>';
                    echo '<td>' . $no++ . '</td>';
                    echo '<td>' . htmlspecialchars($rw['reward_type']) . '</td>';
                    
                    // Detail sesuai reward_type
                    echo '<td>';
                    switch($rw['reward_type']) {
                        case 'level':
                            echo 'Level ' . intval($rw['level']);
                            if ($rw['point'] > 0) {
                                echo ', Points: ' . intval($rw['point']);
                            }
                            echo ', Amount: ' . number_format($rw['reward_amount'], 0, ',', '.');
                            break;
                            
                        case 'binary_pair':
                            echo 'Left: ' . intval($rw['left_leg']) . ', Right: ' . intval($rw['right_leg']);
                            echo ', Amount: ' . number_format($rw['reward_amount'], 0, ',', '.');
                            break;
                            
                        case 'point_growth':
                        case 'hybrid':
                            echo 'Milestone: ' . intval($rw['milestone_point']) . ' points';
                            echo ', Amount: ' . number_format($rw['reward_amount'], 0, ',', '.');
                            break;
                            
                        default:
                            echo 'Amount: ' . number_format($rw['reward_amount'], 0, ',', '.');
                    }
                    echo '</td>';
                    
                    // Status dan deskripsi
                    echo '<td>' . ($rw['is_active'] ? 'Active' : 'Inactive') . '</td>';
                    echo '<td>' . htmlspecialchars($rw['keterangan'] ?? '') . '</td>';
                    
                    // Tombol aksi
                    echo '<td>';
                    echo '<a href="settings.php?komisi_produk=' . $komisi_edit['id'] . '&edit_reward=' . $rw['id'] . '" class="btn btn-sm btn-warning btn-action">Edit</a> ';
                    echo '<a href="settings.php?komisi_produk=' . $komisi_edit['id'] . '&hapus_reward=' . $rw['id'] . '" class="btn btn-sm btn-danger btn-action" onclick="return confirm(\'Delete this reward?\')">Delete</a>';
                    echo '</td>';
                    echo '</tr>';
                endforeach; 
            else: 
            ?>
                <tr><td colspan="6" class="text-center text-muted">No rewards for this product yet.</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
        </div>
    </div>
    <?php endif; ?>

    <!-- 6. LEVEL/COACH/REFERRAL SYSTEM -->
    <div class="bg-setting shadow mb-4">
        <div class="section-title"><?= $level_edit ? "Edit Level/Referral Commission" : "Add Level/Referral Commission" ?></div>
        <form method="post" class="row g-2">
            <?= csrf_field() ?>
            <?= generate_form_token('level_save') ?>
            <input type="hidden" name="level_id" value="<?= $level_edit['id'] ?? '' ?>">
            <div class="col-md-3">
                <label>Level/Coach Name *</label>
                <input type="text" class="form-control" name="nama_level" required value="<?= htmlspecialchars($level_edit['nama_level'] ?? '') ?>">
            </div>
            <div class="col-md-2">
                <label>Level *</label>
                <select name="level" class="form-select" required>
                    <?php for($i=1;$i<=30;$i++): ?>
                    <option value="<?= $i ?>" <?= (isset($level_edit['level']) && $level_edit['level']==$i)?'selected':'' ?>>Level <?= $i ?></option>
                    <?php endfor; ?>
                </select>
            </div>
            <div class="col-md-2">
                <label>Commission Type *</label>
                <select name="jenis_komisi" class="form-select" required>
                    <option value="nominal" <?= (isset($level_edit['jenis_komisi']) && $level_edit['jenis_komisi']=='nominal')?'selected':'' ?>>Fixed (Rp)</option>
                    <option value="persen" <?= (isset($level_edit['jenis_komisi']) && $level_edit['jenis_komisi']=='persen')?'selected':'' ?>>Percentage (%)</option>
                </select>
            </div>
            <div class="col-md-2">
                <label>Commission Value *</label>
                <input type="number" step="0.01" min="0" class="form-control" name="nilai_komisi" required value="<?= htmlspecialchars($level_edit['nilai_komisi'] ?? '') ?>">
            </div>
            <div class="col-md-3">
                <label>Notes</label>
                <input type="text" class="form-control" name="catatan" value="<?= htmlspecialchars($level_edit['catatan'] ?? '') ?>">
            </div>
            <div class="col-12">
                <button type="submit" name="level_save" class="btn btn-primary px-4"><?= $level_edit ? "Update" : "Add" ?></button>
                <?php if ($level_edit): ?>
                <a href="settings.php" class="btn btn-success">Add New Level</a>
                <?php endif; ?>
            </div>
        </form>
    </div>
    <div class="bg-setting shadow mb-4">
        <b>Level/Coach & Referral Commission List</b>
        <div class="responsive-table">
        <table class="table table-bordered table-sm align-middle">
            <thead>
                <tr>
                    <th>#</th>
                    <th>Level Name</th>
                    <th>Level</th>
                    <th>Commission Type</th>
                    <th>Commission Value</th>
                    <th>Notes</th>
                    <th width="120">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if($level_list): $no=1; foreach($level_list as $row): ?>
                <tr>
                    <td><?= $no++ ?></td>
                    <td><?= htmlspecialchars($row['nama_level']) ?></td>
                    <td><?= htmlspecialchars($row['level']) ?></td>
                    <td>
                        <?php
                        if($row['jenis_komisi']=='nominal'){
                            echo 'Fixed';
                        } elseif($row['jenis_komisi']=='persen'){
                            echo 'Percentage';
                        }
                        ?>
                    </td>
                    <td>
                        <?php
                        if($row['jenis_komisi']=='nominal'){
                            echo 'Rp '.number_format($row['nilai_komisi'],0,',','.');
                        } elseif($row['jenis_komisi']=='persen'){
                            echo $row['nilai_komisi'].'%';
                        }
                        ?>
                    </td>
                    <td><?= htmlspecialchars($row['catatan']) ?></td>
                    <td>
                        <a href="settings.php?edit_level=<?= $row['id'] ?>" class="btn btn-sm btn-warning btn-action">Edit</a>
                        <a href="settings.php?hapus_level=<?= $row['id'] ?>" class="btn btn-sm btn-danger btn-action"
                           onclick="return confirm('Delete this level?')">
                           Delete
                        </a>
                    </td>
                </tr>
                <?php endforeach; else: ?>
                <tr><td colspan="7" class="text-center text-muted">No data yet.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
        </div>
        <div class="alert alert-info mt-3" style="font-size: 0.97em;">
            <b>Notes:</b><br>
            <ul>
                <li>Fixed: Commission as fixed amount (e.g. Rp 10,000).</li>
                <li>Percentage: Commission as % of transaction value.</li>
                <li>Level used for sponsor, referral, or coach hierarchy.</li>
            </ul>
        </div>
    </div>

    <div class="desc-box mt-4">
        <b>All product settings, affiliate team, commissions, rewards, and levels/coaches are centralized here.<br>
        <br>One system for all affiliate teams: binary, trinary, n-legs, monoline, hybrid, with flexible commissions and rewards (including team growth milestones, points, pairs, etc).<br>
        Make this system useful for many people!</b>
    </div>
</div>

<!-- Modal untuk Reward Referral Multi-Level -->
<div class="modal fade" id="referralModal" tabindex="-1" aria-labelledby="referralModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="referralModalLabel">Multi-Level Reward Referral</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div id="referral-levels-container">
                    <!-- Levels will be added here -->
                </div>
                <button type="button" class="btn btn-success mt-3" id="add-level-btn">
                    <i class="fas fa-plus-circle"></i> Add Level
                </button>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                <button type="button" class="btn btn-primary" id="save-levels-btn">Save All Levels</button>
            </div>
        </div>
    </div>
</div>

<script>
// function ontypeChange
function onTypeChange() {
    const type = document.getElementById('network_type').value;
    document.getElementById('hybrid_config_wrap').style.display = (type === 'hybrid') ? 'block' : 'none';
    document.getElementById('max_legs_wrap').style.display = (type !== 'monoline') ? 'block' : 'none';
}
document.addEventListener('DOMContentLoaded', onTypeChange);

// Toggle autoplacement mode
function toggleAutoplacementMode() {
    let enabled = document.querySelector('input[name="autoplacement_enabled"]:checked').value;
    document.getElementById('autoplacement_mode_wrap').style.display = (enabled === '1') ? '' : 'none';
    updateRootDropdownDisplay();
}

// Update root dropdown display based on autoplacement and mode
function updateRootDropdownDisplay() {
    var autoInput = document.querySelector('input[name="autoplacement_enabled"]:checked');
    var modeInput = document.querySelector('input[name="autoplacement_mode"]:checked');
    var rootWrap = document.getElementById('root_member_id_wrap');
    if (!autoInput || !rootWrap) return;
    var auto = autoInput.value;
    var mode = modeInput ? modeInput.value : 'referral';
    // Tampilkan hanya jika auto == "1" dan mode == "global"
    rootWrap.style.display = (auto === "1" && mode === "global") ? '' : 'none';
}

// Bind event ke radio autoplacement & mode
document.addEventListener('DOMContentLoaded', function() {
    document.querySelectorAll('input[name="autoplacement_enabled"]').forEach(function(el){
        el.addEventListener('change', toggleAutoplacementMode);
    });
    document.querySelectorAll('input[name="autoplacement_mode"]').forEach(function(el){
        el.addEventListener('change', updateRootDropdownDisplay);
    });
    
    // Jalankan sekali saat halaman selesai load
    toggleAutoplacementMode();
    updateRootDropdownDisplay();
});

// Toggle showRewardFields
function showRewardFields(val) {
    document.querySelectorAll('.reward-field').forEach(el => el.style.display = 'none');
    if (val === 'referral') {
        document.querySelectorAll('.reward-referral').forEach(el => el.style.display = 'block');
    } else if (val === 'level') {
        document.querySelectorAll('.reward-level').forEach(el => el.style.display = 'block');
    } else if (val === 'binary_pair') {
        document.querySelectorAll('.reward-binary').forEach(el => el.style.display = 'block');
    } else if (val === 'point_growth' || val === 'hybrid') {
        document.querySelectorAll('.reward-growth').forEach(el => el.style.display = 'block');
    }
}

document.addEventListener('DOMContentLoaded', function(){
    var sel = document.querySelector('select[name="reward_type"]');
    if(sel) showRewardFields(sel.value);
    
    // Event listener untuk tombol add qualification
    document.body.addEventListener('click', function(e) {
        if (e.target.classList.contains('add-qualification-btn') || 
            e.target.parentElement.classList.contains('add-qualification-btn')) {
            
            const button = e.target.classList.contains('add-qualification-btn') ? 
                           e.target : e.target.parentElement;
            const container = document.querySelector('.referral-qualification-inputs');
            const rows = container.querySelectorAll('.row');
            const newRowNum = rows.length + 1;
            
            // Buat row baru
            const newRow = document.createElement('div');
            newRow.className = 'row mb-2 qualification-row';
            newRow.innerHTML = `
                <div class="col-md-6">
                    <div class="input-group">
                        <span class="input-group-text">Kualifikasi ${newRowNum}</span>
                        <input type="number" min="1" class="form-control" name="qualification[]" required>
                        <span class="input-group-text">referral</span>
                    </div>
                </div>
                <div class="col-md-6">
                    <div class="input-group">
                        <span class="input-group-text">Reward</span>
                        <input type="number" min="0" class="form-control" name="qualification_reward[]">
                        <button type="button" class="btn btn-danger remove-qualification-btn">
                            <i class="fas fa-minus"></i>
                        </button>
                    </div>
                </div>
            `;
            
            // Tambahkan ke container
            container.appendChild(newRow);
        }
        
        // Event listener untuk tombol remove qualification
        if (e.target.classList.contains('remove-qualification-btn') || 
            e.target.parentElement.classList.contains('remove-qualification-btn')) {
            
            const button = e.target.classList.contains('remove-qualification-btn') ? 
                           e.target : e.target.parentElement;
            const row = button.closest('.qualification-row');
            
            // Hapus row
            if (row) {
                row.remove();
                
                // Update nomor kualifikasi
                const container = document.querySelector('.referral-qualification-inputs');
                const rows = container.querySelectorAll('.row');
                rows.forEach((row, index) => {
                    const label = row.querySelector('.input-group-text');
                    if (label && label.textContent.includes('Kualifikasi')) {
                        label.textContent = `Kualifikasi ${index + 1}`;
                    }
                });
            }
        }
    });
});

// Fungsi untuk membersihkan token form saat navigasi ke halaman lain
function clearFormTokens() {
    // Gunakan Ajax untuk menghapus token form dari session
    fetch('clear_tokens.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
        },
        body: JSON.stringify({action: 'clear_form_tokens'})
    }).catch(error => console.error('Error:', error));
}

// Tambahkan event listener untuk membersihkan token saat user meninggalkan halaman
window.addEventListener('beforeunload', function() {
    localStorage.removeItem('formSubmitted');
});

// fungsi Update Jam 
function updateClock() {
    var now = new Date();
    var d = now.getDate().toString().padStart(2, '0');
    var m = (now.getMonth()+1).toString().padStart(2, '0');
    var y = now.getFullYear();
    var h = now.getHours().toString().padStart(2, '0');
    var min = now.getMinutes().toString().padStart(2, '0');
    var s = now.getSeconds().toString().padStart(2, '0');
    document.getElementById("clock").innerHTML = d+'/'+m+'/'+y+' '+h+':'+min+':'+s;
}
setInterval(updateClock, 1000);
updateClock();
</script>
</body>
</html>
