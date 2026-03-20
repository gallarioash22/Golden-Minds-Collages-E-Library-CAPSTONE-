<?php
session_start();
include("db_connect.php");

// Only allow admin/librarian
if (!isset($_SESSION['student_id']) || ($_SESSION['student_role'] !== 'admin' && $_SESSION['student_role'] !== 'librarian')) {
    header("Location: login.php");
    exit;
}

// Helper: escape (basic)
function esc($conn, $str) {
    return mysqli_real_escape_string($conn, trim($str));
}

// Session storage for verified school QR per user
if (!isset($_SESSION['verified_school_qr'])) {
    $_SESSION['verified_school_qr'] = [];
}

$message = "";

// Default query + search
$search_query = $_GET['search_query'] ?? '';
$search_query = trim($search_query);

$where = "WHERE role IN ('student','user')";
if ($search_query !== '') {
    $q = esc($conn, $search_query);
    $where .= " AND (username LIKE '%$q%' OR full_name LIKE '%$q%' OR lrn LIKE '%$q%' OR school_qr LIKE '%$q%')";
}

// Pagination setup
$records_per_page = 15;
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;

if ($page < 1) {
    $page = 1;
}

// Count total users
$count_sql = "SELECT COUNT(*) AS total FROM tbl_users $where";
$count_result = mysqli_query($conn, $count_sql);

$total_records = 0;
if ($count_result && mysqli_num_rows($count_result) > 0) {
    $count_row = mysqli_fetch_assoc($count_result);
    $total_records = (int)$count_row['total'];
}

$total_pages = max(1, ceil($total_records / $records_per_page));

if ($page > $total_pages) {
    $page = $total_pages;
}

$offset = ($page - 1) * $records_per_page;

/*
|--------------------------------------------------------------------------
| HANDLE SCHOOL QR VERIFY
|--------------------------------------------------------------------------
*/
if (isset($_POST['verify_school_qr'])) {
    $user_id = (int)($_POST['user_id'] ?? 0);
    $input_school_qr = trim($_POST['input_school_qr'] ?? '');

    if ($user_id <= 0 || $input_school_qr === '') {
        $message = "<script>alert('Please type or scan the school QR first.');</script>";
    } else {
        $check_sql = "SELECT id, school_qr FROM tbl_users WHERE id = $user_id LIMIT 1";
        $check_result = mysqli_query($conn, $check_sql);

        if ($check_result && mysqli_num_rows($check_result) > 0) {
            $user_data = mysqli_fetch_assoc($check_result);
            $saved_school_qr = trim($user_data['school_qr'] ?? '');

            if ($saved_school_qr === '') {
                unset($_SESSION['verified_school_qr'][$user_id]);
                $message = "<script>alert('This user has no saved school QR yet. You may use Change QR to save the correct one.');</script>";
            } elseif ($input_school_qr === $saved_school_qr) {
                $_SESSION['verified_school_qr'][$user_id] = true;
                $message = "<script>alert('School QR matched successfully. User is now verified.');</script>";
            } else {
                unset($_SESSION['verified_school_qr'][$user_id]);
                $message = "<script>alert('QR mismatch. The scanned/typed school QR does not match the saved value.');</script>";
            }
        } else {
            $message = "<script>alert('User not found.');</script>";
        }
    }
}

/*
|--------------------------------------------------------------------------
| HANDLE CHANGE / REPLACE SCHOOL QR
|--------------------------------------------------------------------------
*/
if (isset($_POST['replace_school_qr'])) {
    $user_id = (int)($_POST['user_id'] ?? 0);
    $input_school_qr = trim($_POST['input_school_qr'] ?? '');

    if ($user_id <= 0 || $input_school_qr === '') {
        $message = "<script>alert('Please type or scan the new school QR first.');</script>";
    } else {
        $new_qr = esc($conn, $input_school_qr);
        $update_sql = "UPDATE tbl_users SET school_qr='$new_qr' WHERE id=$user_id";

        if (mysqli_query($conn, $update_sql)) {
            $_SESSION['verified_school_qr'][$user_id] = true;
            $message = "<script>alert('School QR updated successfully. User is now verified using the new QR value.'); window.location='manage_roles.php';</script>";
            echo $message;
            exit;
        } else {
            $message = "<script>alert('Error updating school QR.');</script>";
        }
    }
}

/*
|--------------------------------------------------------------------------
| HANDLE BULK STUDENT STATUS UPDATE
|--------------------------------------------------------------------------
*/
if (isset($_POST['bulk_update_student_status'])) {
    $bulk_status = esc($conn, $_POST['bulk_student_status'] ?? '');

    $allowed_status = ['active', 'deactivated', 'maintenance'];

    if (!in_array($bulk_status, $allowed_status, true)) {
        echo "<script>alert('Invalid bulk status selected');</script>";
    } else {
        $bulk_sql = "UPDATE tbl_users SET account_status='$bulk_status' WHERE role='student'";
        if (mysqli_query($conn, $bulk_sql)) {
            echo "<script>alert('All student accounts updated to " . ucfirst($bulk_status) . " successfully'); window.location='manage_roles.php';</script>";
            exit;
        } else {
            echo "<script>alert('Error updating all student accounts');</script>";
        }
    }
}

// Handle role change
if (isset($_POST['update_role'])) {
    $user_id = (int)($_POST['user_id'] ?? 0);
    $new_role = esc($conn, $_POST['role'] ?? 'user');

    $allowed_roles = ['user','student','admin','librarian'];
    if (!in_array($new_role, $allowed_roles, true)) {
        echo "<script>alert('Invalid role selected');</script>";
    } else {
        // Require QR verification first before assigning student role
        if ($new_role === 'student' && empty($_SESSION['verified_school_qr'][$user_id])) {
            echo "<script>alert('Please verify the school QR first before assigning Student role.');</script>";
        } else {
            $update_sql = "UPDATE tbl_users SET role='$new_role' WHERE id=$user_id";
            if (mysqli_query($conn, $update_sql)) {
                echo "<script>alert('User role updated successfully'); window.location='manage_roles.php';</script>";
                exit;
            } else {
                echo "<script>alert('Error updating user role');</script>";
            }
        }
    }
}

// Handle account status change
if (isset($_POST['update_status'])) {
    $user_id = (int)($_POST['user_id'] ?? 0);
    $new_status = esc($conn, $_POST['account_status'] ?? 'active');

    $allowed_status = ['active','deactivated','maintenance'];
    if (!in_array($new_status, $allowed_status, true)) {
        echo "<script>alert('Invalid status selected');</script>";
    } else {
        $update_sql = "UPDATE tbl_users SET account_status='$new_status' WHERE id=$user_id";
        if (mysqli_query($conn, $update_sql)) {
            echo "<script>alert('Account status updated successfully'); window.location='manage_roles.php';</script>";
            exit;
        } else {
            echo "<script>alert('Error updating account status');</script>";
        }
    }
}

// Handle password change
if (isset($_POST['update_password'])) {
    $user_id = (int)($_POST['user_id'] ?? 0);
    $plain = $_POST['new_password'] ?? '';

    if (strlen($plain) < 6) {
        echo "<script>alert('Password must be at least 6 characters');</script>";
    } else {
        $new_password = password_hash($plain, PASSWORD_DEFAULT);
        $update_pw_sql = "UPDATE tbl_users SET password='".esc($conn, $new_password)."' WHERE id=$user_id";
        if (mysqli_query($conn, $update_pw_sql)) {
            echo "<script>alert('Password updated successfully'); window.location='manage_roles.php';</script>";
            exit;
        } else {
            echo "<script>alert('Error updating password');</script>";
        }
    }
}

// Fetch users with pagination
$sql = "
    SELECT id, full_name, username, lrn, school_qr, role, account_status
    FROM tbl_users
    $where
    ORDER BY id DESC
    LIMIT $records_per_page OFFSET $offset
";
$result = mysqli_query($conn, $sql);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Manage Users</title>
<style>
    :root{
        --bg:#fff8e7;
        --brown:#8E4B30;
        --brown2:#6f3b3b;
        --gold:#E8A83A;
        --card:#ffffff;
        --muted:#6b6b6b;
        --line:#f0e2c6;
        --shadow:0 10px 24px rgba(0,0,0,.08);
        --green:#1f9d55;
        --orange:#d97706;
    }
    *{box-sizing:border-box}
    body{
        margin:0;
        font-family: Arial, sans-serif;
        background: var(--bg);
        color:#222;
    }
    header{
        background: linear-gradient(180deg, var(--brown2), var(--brown));
        color:#fff;
        padding:16px 20px;
        display:flex;
        align-items:center;
        justify-content:space-between;
        gap:12px;
        border-bottom: 3px solid var(--gold);
    }
    header h1{margin:0;font-size:20px;letter-spacing:.2px}
    .btn{
        display:inline-flex;
        align-items:center;
        gap:8px;
        padding:10px 12px;
        border-radius:10px;
        text-decoration:none;
        color:#fff;
        background:#8b4b2b;
        border:1px solid rgba(255,255,255,.15);
        transition:.15s ease;
        white-space:nowrap;
    }
    .btn:hover{transform:translateY(-1px);background:#7a3f27}
    .wrap{max-width:1600px;margin:0 auto;padding:18px}
    .topbar{
        display:flex;
        flex-wrap:wrap;
        gap:12px;
        align-items:center;
        justify-content:space-between;
        margin-bottom:14px;
    }
    .search{
        flex:1;
        min-width:260px;
        background:var(--card);
        border:1px solid var(--line);
        border-radius:14px;
        padding:12px;
        box-shadow: var(--shadow);
        display:flex;
        gap:10px;
        align-items:center;
    }
    .search input{
        flex:1;
        padding:12px 12px;
        border-radius:10px;
        border:1px solid #ddd;
        outline:none;
        background:#fafafa;
    }
    .search button{
        padding:12px 14px;
        border-radius:10px;
        border:none;
        cursor:pointer;
        background: var(--brown);
        color:#fff;
        font-weight:700;
    }
    .search button:hover{background:var(--brown2)}
    .card{
        background:var(--card);
        border:1px solid var(--line);
        border-radius:16px;
        box-shadow: var(--shadow);
        overflow:hidden;
    }
    table{
        width:100%;
        border-collapse:collapse;
    }
    thead th{
        background: #ac6346;
        color:#fff;
        text-transform:uppercase;
        letter-spacing:.6px;
        font-size:12px;
        padding:14px 12px;
        text-align:left;
    }
    tbody td{
        padding:14px 12px;
        border-top:1px solid #f3e8d3;
        vertical-align:top;
        background:#fff;
    }
    tbody tr:hover td{background:#fffaf0}
    .muted{color:var(--muted);font-size:12px;margin-top:4px}
    .grid{
        display:grid;
        grid-template-columns: 1fr;
        gap:8px;
    }
    select, input[type="password"], input[type="text"]{
        width:100%;
        padding:10px;
        border-radius:10px;
        border:1px solid #ddd;
        background:#f7f7f7;
        outline:none;
    }
    .action-btn{
        width:100%;
        padding:10px 12px;
        border:none;
        border-radius:10px;
        background: var(--brown);
        color:#fff;
        font-weight:700;
        cursor:pointer;
        transition:.15s ease;
    }
    .action-btn:hover{background:var(--brown2)}
    .verify-btn{
        background: var(--green);
    }
    .verify-btn:hover{
        background: #187944;
    }
    .change-btn{
        background: var(--brown);
    }
    .change-btn:hover{
        background: #b86608;
    }
    .pill{
        display:inline-block;
        padding:6px 10px;
        border-radius:999px;
        font-size:12px;
        font-weight:700;
        border:1px solid var(--line);
        background:#fff8e7;
        color:#6f3b3b;
        text-transform:capitalize;
    }
    .pill.active{background:#eaffea;border-color:#bfe8bf;color:#216b21}
    .pill.deactivated{background:#ffeaea;border-color:#f0b8b8;color:#8a1f1f}
    .pill.maintenance{background:#fff4d6;border-color:#f0d18a;color:#7a5200}
    .pill.verified{
        background:#dcfce7;
        border-color:#86efac;
        color:#166534;
        margin-top:8px;
    }
    .pw-row{
        display:flex;
        gap:8px;
        align-items:center;
    }
    .toggle{
        padding:10px 12px;
        border-radius:10px;
        border:1px solid #ddd;
        background:#fff;
        cursor:pointer;
        white-space:nowrap;
    }
    @media (max-width: 1100px){
        thead{display:none}
        table, tbody, tr, td{display:block;width:100%}
        tbody td{border-top:none;border-bottom:1px solid #f3e8d3}
        tbody tr{margin-bottom:12px}
        tbody td::before{
            content: attr(data-label);
            display:block;
            font-size:12px;
            color:var(--muted);
            text-transform:uppercase;
            letter-spacing:.5px;
            margin-bottom:6px;
            font-weight:700;
        }
    }
</style>
</head>
<body>

<?php if (!empty($message)) echo $message; ?>

<header>
    <h1>Manage Users (Roles, Status & Passwords)</h1>
    <a class="btn" href="dashboard.php">← Back to Dashboard</a>
</header>

<div class="wrap">
    <div class="topbar">
        <form class="search" method="GET">
            <input type="text" name="search_query" placeholder="Search by username, full name, LRN, or school QR…" value="<?= htmlspecialchars($search_query) ?>">
            <button type="submit" name="search">Search</button>
            <a href="manage_roles.php" class="btn" style="padding:12px 14px;">Reset</a>
        </form>
    </div>

    <div class="card" style="padding:16px; margin-bottom:14px;">
    <form method="POST" style="display:flex; gap:10px; flex-wrap:wrap; align-items:center;">
        <strong>Bulk Student Account Status:</strong>

        <select name="bulk_student_status" style="max-width:220px;">
            <option value="active">Set All Students Active</option>
            <option value="maintenance">Set All Students Maintenance</option>
            <option value="deactivated">Set All Students Deactivated</option>
        </select>

        <button
            type="submit"
            name="bulk_update_student_status"
            class="action-btn"
            style="max-width:260px;"
            onclick="return confirm('Are you sure you want to update ALL student accounts?');"
        >
            Apply to All Students
        </button>

        <div class="muted">This only affects users with role = student.</div>
    </form>
</div>

    <div class="card">
        <table>
            <thead>
                <tr>
                    <th style="width:22%;">User Info</th>
                    <th style="width:10%;">Role</th>
                    <th style="width:16%;">Account Status</th>
                    <th style="width:22%;">School QR Verify</th>
                    <th style="width:14%;">Change Role</th>
                    <th style="width:16%;">Change Password</th>
                </tr>
            </thead>
            <tbody>
    <?php if ($result && mysqli_num_rows($result) > 0): ?>
        <?php while ($user = mysqli_fetch_assoc($result)): 
            $st = strtolower($user['account_status'] ?? 'active');
            $is_verified = !empty($_SESSION['verified_school_qr'][$user['id']]);
        ?>
        <tr>
            <td data-label="User Info">
                <div style="font-weight:800;"><?= htmlspecialchars($user['username']) ?></div>
                <div class="muted"><?= htmlspecialchars($user['full_name'] ?? '') ?></div>
                <div class="muted"><strong>System LRN:</strong> <?= htmlspecialchars($user['lrn'] ?? '') ?></div>
                <div class="muted"><strong>School QR:</strong> <?= htmlspecialchars($user['school_qr'] ?? '') ?></div>

                <?php if ($is_verified): ?>
                    <div><span class="pill verified">QR Verified</span></div>
                <?php endif; ?>
            </td>

            <td data-label="Role">
                <span class="pill"><?= htmlspecialchars($user['role']) ?></span>
            </td>

            <td data-label="Account Status">
                <span class="pill <?= $st ?>"><?= htmlspecialchars($st) ?></span>

                <form method="POST" class="grid" style="margin-top:10px;">
                    <input type="hidden" name="user_id" value="<?= (int)$user['id'] ?>">
                    <select name="account_status">
                        <option value="active" <?= $st === 'active' ? 'selected' : '' ?>>Active</option>
                        <option value="maintenance" <?= $st === 'maintenance' ? 'selected' : '' ?>>Maintenance</option>
                        <option value="deactivated" <?= $st === 'deactivated' ? 'selected' : '' ?>>Deactivated</option>
                    </select>
                    <button type="submit" name="update_status" class="action-btn">Update Status</button>
                    <div class="muted">Maintenance/Deactivated accounts cannot login.</div>
                </form>
            </td>

            <td data-label="School QR Verify">
                <form method="POST" class="grid">
                    <input type="hidden" name="user_id" value="<?= (int)$user['id'] ?>">

                    <input 
                        type="text" 
                        name="input_school_qr" 
                        placeholder="Type or scan school QR"
                        autocomplete="off"
                    >

                    <button type="submit" name="verify_school_qr" class="action-btn verify-btn">Verify QR</button>
                    <button type="submit" name="replace_school_qr" class="action-btn change-btn" onclick="return confirm('Are you sure you want to replace this user\\'s saved school QR?');">Change QR</button>

                    <div class="muted">Scan/type the real school QR. If it mismatches, you can replace the saved one.</div>
                </form>
            </td>

            <td data-label="Change Role">
                <form method="POST" class="grid">
                    <input type="hidden" name="user_id" value="<?= (int)$user['id'] ?>">
                    <select name="role">
                        <option value="user" <?= $user['role'] === 'user' ? 'selected' : '' ?>>User</option>
                        <option value="student" <?= $user['role'] === 'student' ? 'selected' : '' ?>>Student</option>
                        <option value="admin" <?= $user['role'] === 'admin' ? 'selected' : '' ?>>Admin</option>
                        <option value="librarian" <?= $user['role'] === 'librarian' ? 'selected' : '' ?>>Librarian</option>
                    </select>
                    <button type="submit" name="update_role" class="action-btn">Update Role</button>
                    <div class="muted">Verify school QR first before promoting user to student.</div>
                </form>
            </td>

            <td data-label="Change Password">
                <form method="POST" class="grid">
                    <input type="hidden" name="user_id" value="<?= (int)$user['id'] ?>">

                    <div class="pw-row">
                        <input type="password" class="pw" name="new_password" placeholder="New Password (min 6 chars)" required>
                        <button type="button" class="toggle" onclick="togglePw(this)">Show</button>
                    </div>

                    <button type="submit" name="update_password" class="action-btn">Update Password</button>
                </form>
            </td>
        </tr>
        <?php endwhile; ?>
    <?php else: ?>
        <tr>
            <td colspan="6" style="text-align:center; padding:20px;">No users found.</td>
        </tr>
    <?php endif; ?>
</tbody>
    </div>

    <div class="pagination" style="margin-top:20px; text-align:center;">
    <?php if ($total_pages > 1): ?>
        <?php
        $query_base = 'search_query=' . urlencode($search_query);
        ?>

        <?php if ($page > 1): ?>
            <a href="manage_roles.php?<?= $query_base ?>&page=1" class="btn">First</a>
            <a href="manage_roles.php?<?= $query_base ?>&page=<?= $page - 1 ?>" class="btn">Previous</a>
        <?php else: ?>
            <span class="btn" style="background:#ccc; cursor:not-allowed; pointer-events:none;">First</span>
            <span class="btn" style="background:#ccc; cursor:not-allowed; pointer-events:none;">Previous</span>
        <?php endif; ?>

        <span style="margin:0 10px; font-weight:bold;">Page <?= $page ?> of <?= $total_pages ?></span>

        <?php if ($page < $total_pages): ?>
            <a href="manage_roles.php?<?= $query_base ?>&page=<?= $page + 1 ?>" class="btn">Next</a>
            <a href="manage_roles.php?<?= $query_base ?>&page=<?= $total_pages ?>" class="btn">Last</a>
        <?php else: ?>
            <span class="btn" style="background:#ccc; cursor:not-allowed; pointer-events:none;">Next</span>
            <span class="btn" style="background:#ccc; cursor:not-allowed; pointer-events:none;">Last</span>
        <?php endif; ?>
    <?php endif; ?>
</div>
</div>

<script>
function togglePw(btn){
    const input = btn.closest('.pw-row').querySelector('.pw');
    if (!input) return;
    if (input.type === 'password'){
        input.type = 'text';
        btn.textContent = 'Hide';
    } else {
        input.type = 'password';
        btn.textContent = 'Show';
    }
}
</script>

</body>
</html>