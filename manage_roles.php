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

// Default query
$search_query = '';
$where = "WHERE role IN ('student','user')";
if (isset($_POST['search'])) {
    $search_query = $_POST['search_query'] ?? '';
    $q = esc($conn, $search_query);
    $where .= " AND username LIKE '%$q%'";
}

// Handle role change
if (isset($_POST['update_role'])) {
    $user_id = (int)($_POST['user_id'] ?? 0);
    $new_role = esc($conn, $_POST['role'] ?? 'user');

    $allowed_roles = ['user','student','admin','librarian'];
    if (!in_array($new_role, $allowed_roles, true)) {
        echo "<script>alert('Invalid role selected');</script>";
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

// Fetch users (limit 20)
$sql = "SELECT id, full_name, username, role, account_status FROM tbl_users $where ORDER BY id DESC LIMIT 20";
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
    .wrap{max-width:1200px;margin:0 auto;padding:18px}
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
    @media (max-width: 900px){
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

<header>
    <h1>Manage Users (Roles, Status & Passwords)</h1>
    <a class="btn" href="dashboard.php">← Back to Dashboard</a>
</header>

<div class="wrap">
    <div class="topbar">
        <form class="search" method="POST">
            <input type="text" name="search_query" placeholder="Search by username…" value="<?= htmlspecialchars($search_query) ?>">
            <button type="submit" name="search">Search</button>
        </form>
    </div>

    <div class="card">
        <table>
            <thead>
                <tr>
                    <th style="width:22%;">Username</th>
                    <th style="width:16%;">Role</th>
                    <th style="width:18%;">Account Status</th>
                    <th style="width:22%;">Change Role</th>
                    <th style="width:22%;">Change Password</th>
                </tr>
            </thead>
            <tbody>
                <?php while ($user = mysqli_fetch_assoc($result)): 
                    $st = strtolower($user['account_status'] ?? 'active');
                ?>
                <tr>
                    <td data-label="Username">
                        <div style="font-weight:800;"><?= htmlspecialchars($user['username']) ?></div>
                        <div class="muted"><?= htmlspecialchars($user['full_name'] ?? '') ?></div>
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
            </tbody>
        </table>
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