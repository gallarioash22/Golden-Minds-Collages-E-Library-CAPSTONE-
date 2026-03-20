<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
session_start();
include("db_connect.php");

// Check if logged in
if (!isset($_SESSION['student_id'])) {
    header("Location: login.php");
    exit;
}

// Admin or librarian only
if (!isset($_SESSION['student_role']) || 
    ($_SESSION['student_role'] !== 'admin' && $_SESSION['student_role'] !== 'librarian')) {
    echo "<script>alert('Access denied. Admins and librarians only.'); window.location='dashboard.php';</script>";
    exit;
}

$user_id = (int)$_SESSION['student_id'];

// Fetch logged user info
$user_q = mysqli_query($conn, "SELECT * FROM tbl_users WHERE id = {$user_id} LIMIT 1");
$user = mysqli_fetch_assoc($user_q);

// Safety check
if (!$user) {
    session_destroy();
    echo "<script>alert('Session invalid. Please login again.'); window.location='login.php';</script>";
    exit;
}

// Block inactive account
$acctStatus = strtolower(trim($user['account_status'] ?? 'active'));
if ($acctStatus !== 'active') {
    $_SESSION = [];
    session_destroy();
    echo "<script>
        alert('🚫 Your account is not active (Maintenance/Deactivated). Please contact admin/librarian.');
        window.location='login.php';
    </script>";
    exit;
}

// Enforce session id
if (isset($_SESSION['user_session_id']) && isset($user['user_session_id'])) {
    if ($_SESSION['user_session_id'] !== $user['user_session_id']) {
        $_SESSION = [];
        session_destroy();
        echo "<script>
            alert('⚠️ Your session expired (logged in from another tab/device). Please login again.');
            window.location='login.php';
        </script>";
        exit;
    }
}

// Logout
if (isset($_GET['logout'])) {
    $_SESSION = [];
    session_destroy();

    if (ini_get("session.use_cookies")) {
        $params = session_get_cookie_params();
        setcookie(
            session_name(),
            '',
            time() - 42000,
            $params["path"],
            $params["domain"],
            $params["secure"],
            $params["httponly"]
        );
    }

    echo "<script>
        alert('Successful Logout!');
        window.location='login.php';
    </script>";
    exit;
}

// =========================
// HELPER FUNCTION
// =========================
function getSingleValue($conn, $sql, $default = 0) {
    $result = mysqli_query($conn, $sql);
    if ($result && $row = mysqli_fetch_row($result)) {
        return ($row[0] !== null) ? $row[0] : $default;
    }
    return $default;
}

// =========================
// BOOK STATISTICS
// =========================
$totalTitles = getSingleValue($conn, "SELECT COUNT(*) FROM tbl_books", 0);
$totalAvailable = getSingleValue($conn, "SELECT COALESCE(SUM(quantity),0) FROM tbl_books", 0);
$totalBorrowed = getSingleValue($conn, "SELECT COALESCE(SUM(borrowed),0) FROM tbl_books", 0);
$totalCopies = $totalAvailable + $totalBorrowed;

$totalWithQr = getSingleValue($conn, "
    SELECT COUNT(*)
    FROM tbl_books
    WHERE qr_code IS NOT NULL AND qr_code <> ''
", 0);

$totalWithoutQr = getSingleValue($conn, "
    SELECT COUNT(*)
    FROM tbl_books
    WHERE qr_code IS NULL OR qr_code = ''
", 0);

$totalActiveBooks = getSingleValue($conn, "
    SELECT COUNT(*)
    FROM tbl_books
    WHERE status = 'active'
", 0);

$totalUnavailableBooks = getSingleValue($conn, "
    SELECT COUNT(*)
    FROM tbl_books
    WHERE status = 'unavailable'
", 0);

// =========================
// USER STATISTICS
// =========================
$totalUsers = getSingleValue($conn, "SELECT COUNT(*) FROM tbl_users", 0);
$totalStudents = getSingleValue($conn, "SELECT COUNT(*) FROM tbl_users WHERE role IN ('student','user')", 0);
$totalAdmins = getSingleValue($conn, "SELECT COUNT(*) FROM tbl_users WHERE role = 'admin'", 0);
$totalLibrarians = getSingleValue($conn, "SELECT COUNT(*) FROM tbl_users WHERE role = 'librarian'", 0);

$totalApprovedUsers = getSingleValue($conn, "SELECT COUNT(*) FROM tbl_users WHERE status = 'approved'", 0);
$totalPendingUsers = getSingleValue($conn, "SELECT COUNT(*) FROM tbl_users WHERE status = 'pending'", 0);
$totalRejectedUsers = getSingleValue($conn, "SELECT COUNT(*) FROM tbl_users WHERE status = 'rejected'", 0);
$totalMaintenanceUsers = getSingleValue($conn, "SELECT COUNT(*) FROM tbl_users WHERE account_status = 'maintenance'", 0);
$totalDeactivatedUsers = getSingleValue($conn, "SELECT COUNT(*) FROM tbl_users WHERE account_status = 'deactivated'", 0);

// =========================
// BORROW RECORD STATISTICS
// =========================
$totalBorrowRecords = getSingleValue($conn, "SELECT COUNT(*) FROM tbl_borrowed_records", 0);
$totalPendingBorrows = getSingleValue($conn, "SELECT COUNT(*) FROM tbl_borrowed_records WHERE status = 'pending'", 0);
$totalBorrowedNow = getSingleValue($conn, "SELECT COUNT(*) FROM tbl_borrowed_records WHERE status = 'borrowed'", 0);
$totalReturned = getSingleValue($conn, "SELECT COUNT(*) FROM tbl_borrowed_records WHERE status = 'returned'", 0);
$totalRejected = getSingleValue($conn, "SELECT COUNT(*) FROM tbl_borrowed_records WHERE status = 'rejected'", 0);

$totalOverdue = getSingleValue($conn, "
    SELECT COUNT(*)
    FROM tbl_borrowed_records
    WHERE status = 'borrowed'
      AND due_date IS NOT NULL
      AND due_date < NOW()
", 0);

// =========================
// RECENTLY ADDED BOOKS
// =========================
$recentBooksSql = "
    SELECT title, author, category, quantity, borrowed, status, created_at
    FROM tbl_books
    ORDER BY created_at DESC, id DESC
    LIMIT 10
";
$recentBooksResult = mysqli_query($conn, $recentBooksSql);

// =========================
// TOP BORROWED BOOKS
// =========================
$topBorrowedBooksSql = "
    SELECT 
        b.title,
        b.author,
        b.category,
        b.shelf_location,
        b.quantity,
        b.borrowed,
        COUNT(r.id) AS total_transactions
    FROM tbl_borrowed_records r
    INNER JOIN tbl_books b ON r.book_id = b.id
    WHERE r.status IN ('borrowed', 'returned')
    GROUP BY b.id, b.title, b.author, b.category, b.shelf_location, b.quantity, b.borrowed
    ORDER BY total_transactions DESC, b.title ASC
    LIMIT 10
";
$topBorrowedBooksResult = mysqli_query($conn, $topBorrowedBooksSql);

// =========================
// TOP ACTIVE STUDENTS
// =========================
$topStudentsSql = "
    SELECT
        u.full_name,
        u.username,
        u.lrn,
        u.section,
        u.strand,
        COUNT(r.id) AS total_transactions
    FROM tbl_borrowed_records r
    INNER JOIN tbl_users u ON r.user_id = u.id
    WHERE r.status IN ('borrowed', 'returned')
    GROUP BY u.id, u.full_name, u.username, u.lrn, u.section, u.strand
    ORDER BY total_transactions DESC, u.full_name ASC
    LIMIT 10
";
$topStudentsResult = mysqli_query($conn, $topStudentsSql);

// =========================
// RECENT BORROW ACTIVITY
// =========================
$recentBorrowSql = "
    SELECT
        r.id,
        u.full_name,
        u.lrn,
        b.title,
        r.borrow_date,
        r.due_date,
        r.return_date,
        r.status
    FROM tbl_borrowed_records r
    INNER JOIN tbl_users u ON r.user_id = u.id
    INNER JOIN tbl_books b ON r.book_id = b.id
    ORDER BY r.id DESC
    LIMIT 10
";
$recentBorrowResult = mysqli_query($conn, $recentBorrowSql);
?>

<!doctype html>
<html>
<head>
    <meta charset="utf-8">
    <title>Library Statistics — Golden Minds E-Library</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="stylesheet" href="assets/css/style.css">

    <style>
        .stats-wrapper{
            max-width: 1250px;
            margin: 0 auto;
        }

        .back-dashboard-btn{
            display: inline-block;
            background: #b5651d;
            color: #fff !important;
            padding: 10px 16px;
            border-radius: 8px;
            text-decoration: none;
            font-size: 15px;
            font-weight: bold;
            margin-bottom: 22px;
            box-shadow: 0 4px 8px rgba(0,0,0,0.12);
            transition: background 0.2s ease, transform 0.2s ease;
        }

        .back-dashboard-btn:hover{
            background: #8b4b2b;
            transform: translateY(-2px);
        }

        .stats-header{
            text-align: center;
            margin-bottom: 25px;
        }

        .stats-header h2{
            margin: 0 0 8px;
            font-size: 34px;
            color: #2f1d12;
        }

        .stats-header p{
            margin: 0;
            color: #7a5a43;
            font-size: 15px;
        }

        .dashboard-stats{
            display: grid;
            grid-template-columns: repeat(4, minmax(220px, 1fr));
            gap: 22px;
            margin-top: 25px;
        }

        .stat-card{
            background: linear-gradient(180deg, #fffdf9, #fff6e8);
            border-radius: 14px;
            padding: 22px 18px;
            box-shadow: 0 6px 14px rgba(0,0,0,0.08);
            border-top: 5px solid #b5651d;
            transition: transform 0.2s ease, box-shadow 0.2s ease;
            text-align: center;
        }

        .stat-card:hover{
            transform: translateY(-4px);
            box-shadow: 0 10px 20px rgba(0,0,0,0.12);
        }

        .stat-icon{
            font-size: 28px;
            margin-bottom: 10px;
        }

        .stat-label{
            font-size: 15px;
            color: #7a5a43;
            margin-bottom: 8px;
            font-weight: 600;
        }

        .stat-value{
            font-size: 36px;
            font-weight: 700;
            color: #6f3b3b;
            line-height: 1.1;
        }

        .stats-section{
            margin-top: 35px;
        }

        .section-title{
            font-size: 24px;
            color: #2f1d12;
            margin-bottom: 15px;
            font-weight: 700;
            text-align: left;
        }

        .table-card{
            background: #fffdf9;
            border-radius: 14px;
            padding: 18px;
            box-shadow: 0 6px 14px rgba(0,0,0,0.08);
            overflow-x: auto;
            margin-bottom: 22px;
        }

        .stats-table{
            width: 100%;
            border-collapse: collapse;
            min-width: 800px;
        }

        .stats-table th{
            background: #f7ead7;
            color: #5c3921;
            padding: 12px;
            text-align: left;
            font-size: 14px;
            border-bottom: 2px solid #ead2b3;
        }

        .stats-table td{
            padding: 12px;
            border-bottom: 1px solid #f0e2cf;
            font-size: 14px;
            color: #4a3426;
        }

        .stats-table tr:hover{
            background: #fff8ef;
        }

        .mini-grid{
            display: grid;
            grid-template-columns: repeat(5, minmax(180px, 1fr));
            gap: 18px;
            margin-top: 18px;
        }

        .mini-card{
            background: #fffdf9;
            border-radius: 12px;
            padding: 16px;
            text-align: center;
            box-shadow: 0 5px 12px rgba(0,0,0,0.07);
            border-top: 4px solid #d89b5b;
        }

        .mini-card .mini-label{
            font-size: 14px;
            color: #7a5a43;
            margin-bottom: 6px;
            font-weight: 600;
        }

        .mini-card .mini-value{
            font-size: 28px;
            font-weight: 700;
            color: #6f3b3b;
        }

        .status-badge{
            display: inline-block;
            padding: 5px 10px;
            border-radius: 999px;
            font-size: 12px;
            font-weight: 700;
            text-transform: capitalize;
        }

        .status-active{
            background: #daf5dd;
            color: #1f6b31;
        }

        .status-unavailable{
            background: #ffe0e0;
            color: #a12b2b;
        }

        .status-pending{
            background: #fff1c9;
            color: #946200;
        }

        .status-borrowed{
            background: #dbeeff;
            color: #1a5fa8;
        }

        .status-returned{
            background: #daf5dd;
            color: #1f6b31;
        }

        .status-rejected{
            background: #ffe0e0;
            color: #a12b2b;
        }

        .status-overdue{
            background: #ffd6d6;
            color: #9b1c1c;
        }

        .empty-note{
            text-align: center;
            color: #7a5a43;
            font-size: 14px;
            padding: 12px;
        }

        @media (max-width: 1100px){
            .dashboard-stats{
                grid-template-columns: repeat(3, minmax(220px, 1fr));
            }

            .mini-grid{
                grid-template-columns: repeat(2, minmax(180px, 1fr));
            }
        }

        @media (max-width: 860px){
            .dashboard-stats{
                grid-template-columns: repeat(2, minmax(220px, 1fr));
            }
        }

        @media (max-width: 640px){
            .dashboard-stats{
                grid-template-columns: 1fr;
            }

            .mini-grid{
                grid-template-columns: 1fr;
            }

            .stats-wrapper{
                max-width: 100%;
            }

            .stats-header h2{
                font-size: 28px;
            }
        }
    </style>
</head>
<body>

<header class="topbar">
    <div class="topbar-left">
        <h1 class="topbar-title">Golden Minds E-Library System</h1>
        <div class="topbar-welcome">
            Welcome, <strong><?php echo htmlspecialchars($user['full_name']); ?></strong>
        </div>
    </div>

    <div class="topbar-right">
        <div class="topbar-meta">
            <div><?php echo htmlspecialchars($user['section']); ?> — <?php echo htmlspecialchars($user['strand']); ?></div>
        </div>

        <img
            src="<?php echo htmlspecialchars(!empty($user['profile_pic']) ? $user['profile_pic'] : 'uploads/profile/default.png'); ?>"
            alt="Profile"
            class="profile-pic"
        >

        <a href="admin_statistics.php?logout=1" class="logout-btn">Logout</a>
    </div>
</header>

<div class="wrap">
    <<aside class="sidebar">
    <h3>Navigation</h3>
    <ul class="sidebar-list">

        <?php if ($_SESSION['student_role'] === 'admin'): ?>
            <li><a href="dashboard.php">Main Dashboard</a></li>
            <li><a href="main_add_book.php">Add Book</a></li>
            <li><a href="admin_statistics.php">Dashboard Statistics</a></li>
            <li><a href="manage_roles.php">User Management</a></li>
            <li><a href="quotes_feedback.php">View Quotes & Feedback</a></li>
            <li><a href="manage_quotes.php">Manage Quotes & Feedback</a></li>

        <?php elseif ($_SESSION['student_role'] === 'librarian'): ?>
            <li><a href="borrow_approval.php">Borrow Approval</a></li>
            <li><a href="admin_statistics.php">Dashboard Statistics</a></li>
            <li><a href="manage_roles.php">User Management</a></li>
            <li><a href="quotes_feedback.php">View Quotes & Feedback</a></li>
            <li><a href="manage_quotes.php">Manage Quotes & Feedback</a></li>
        <?php endif; ?>

    </ul>
</aside>

<section class="main">
    <div class="stats-wrapper">

        <a href="dashboard.php" class="back-dashboard-btn">← Back to Dashboard</a>

        <div class="stats-header">
            <h2>Library Dashboard Statistics</h2>
            <p>Advanced overview of your library inventory, users, and borrow activity.</p>
        </div>

        <div class="dashboard-stats">
            <div class="stat-card">
                <div class="stat-icon">📚</div>
                <div class="stat-label">Total Book Titles</div>
                <div class="stat-value"><?php echo $totalTitles; ?></div>
            </div>

            <div class="stat-card">
                <div class="stat-icon">📦</div>
                <div class="stat-label">Total Copies</div>
                <div class="stat-value"><?php echo $totalCopies; ?></div>
            </div>

            <div class="stat-card">
                <div class="stat-icon">📕</div>
                <div class="stat-label">Borrowed Copies</div>
                <div class="stat-value"><?php echo $totalBorrowed; ?></div>
            </div>

            <div class="stat-card">
                <div class="stat-icon">✅</div>
                <div class="stat-label">Available Copies</div>
                <div class="stat-value"><?php echo $totalAvailable; ?></div>
            </div>

            <div class="stat-card">
                <div class="stat-icon">🔳</div>
                <div class="stat-label">Books With QR</div>
                <div class="stat-value"><?php echo $totalWithQr; ?></div>
            </div>

            <div class="stat-card">
                <div class="stat-icon">⚠️</div>
                <div class="stat-label">Books Without QR</div>
                <div class="stat-value"><?php echo $totalWithoutQr; ?></div>
            </div>

            <div class="stat-card">
                <div class="stat-icon">🟢</div>
                <div class="stat-label">Active Books</div>
                <div class="stat-value"><?php echo $totalActiveBooks; ?></div>
            </div>

            <div class="stat-card">
                <div class="stat-icon">🔴</div>
                <div class="stat-label">Unavailable Books</div>
                <div class="stat-value"><?php echo $totalUnavailableBooks; ?></div>
            </div>

            <div class="stat-card">
                <div class="stat-icon">👥</div>
                <div class="stat-label">Total Users</div>
                <div class="stat-value"><?php echo $totalUsers; ?></div>
            </div>

            <div class="stat-card">
                <div class="stat-icon">📝</div>
                <div class="stat-label">Total Borrow Records</div>
                <div class="stat-value"><?php echo $totalBorrowRecords; ?></div>
            </div>

            <div class="stat-card">
                <div class="stat-icon">⏳</div>
                <div class="stat-label">Pending Requests</div>
                <div class="stat-value"><?php echo $totalPendingBorrows; ?></div>
            </div>

            <div class="stat-card">
                <div class="stat-icon">🚨</div>
                <div class="stat-label">Overdue Books</div>
                <div class="stat-value"><?php echo $totalOverdue; ?></div>
            </div>
        </div>

        <div class="stats-section">
            <div class="section-title">User Summary</div>
            <div class="mini-grid">
                <div class="mini-card">
                    <div class="mini-label">Students</div>
                    <div class="mini-value"><?php echo $totalStudents; ?></div>
                </div>

                <div class="mini-card">
                    <div class="mini-label">Admins</div>
                    <div class="mini-value"><?php echo $totalAdmins; ?></div>
                </div>

                <div class="mini-card">
                    <div class="mini-label">Librarians</div>
                    <div class="mini-value"><?php echo $totalLibrarians; ?></div>
                </div>

                <div class="mini-card">
                    <div class="mini-label">Approved Users</div>
                    <div class="mini-value"><?php echo $totalApprovedUsers; ?></div>
                </div>

                <div class="mini-card">
                    <div class="mini-label">Pending Users</div>
                    <div class="mini-value"><?php echo $totalPendingUsers; ?></div>
                </div>

                <div class="mini-card">
                    <div class="mini-label">Rejected Users</div>
                    <div class="mini-value"><?php echo $totalRejectedUsers; ?></div>
                </div>

                <div class="mini-card">
                    <div class="mini-label">Maintenance</div>
                    <div class="mini-value"><?php echo $totalMaintenanceUsers; ?></div>
                </div>

                <div class="mini-card">
                    <div class="mini-label">Deactivated</div>
                    <div class="mini-value"><?php echo $totalDeactivatedUsers; ?></div>
                </div>

                <div class="mini-card">
                    <div class="mini-label">Currently Borrowed</div>
                    <div class="mini-value"><?php echo $totalBorrowedNow; ?></div>
                </div>

                <div class="mini-card">
                    <div class="mini-label">Returned</div>
                    <div class="mini-value"><?php echo $totalReturned; ?></div>
                </div>

                <div class="mini-card">
                    <div class="mini-label">Rejected Requests</div>
                    <div class="mini-value"><?php echo $totalRejected; ?></div>
                </div>
            </div>
        </div>

        <div class="stats-section">
            <div class="section-title">Top Borrowed Books</div>
            <div class="table-card">
                <table class="stats-table">
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>Book Title</th>
                            <th>Author</th>
                            <th>Category</th>
                            <th>Shelf</th>
                            <th>Total Transactions</th>
                            <th>Currently Borrowed</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ($topBorrowedBooksResult && mysqli_num_rows($topBorrowedBooksResult) > 0): ?>
                            <?php $rank = 1; ?>
                            <?php while ($row = mysqli_fetch_assoc($topBorrowedBooksResult)): ?>
                                <tr>
                                    <td><?php echo $rank++; ?></td>
                                    <td><?php echo htmlspecialchars($row['title']); ?></td>
                                    <td><?php echo htmlspecialchars($row['author']); ?></td>
                                    <td><?php echo htmlspecialchars($row['category']); ?></td>
                                    <td><?php echo htmlspecialchars($row['shelf_location']); ?></td>
                                    <td><?php echo (int)$row['total_transactions']; ?></td>
                                    <td><?php echo (int)$row['borrowed']; ?></td>
                                </tr>
                            <?php endwhile; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="7" class="empty-note">No borrow data yet.</td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <div class="stats-section">
            <div class="section-title">Top Active Students</div>
            <div class="table-card">
                <table class="stats-table">
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>Full Name</th>
                            <th>Username</th>
                            <th>LRN</th>
                            <th>Section / Strand</th>
                            <th>Total Transactions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ($topStudentsResult && mysqli_num_rows($topStudentsResult) > 0): ?>
                            <?php $rank = 1; ?>
                            <?php while ($row = mysqli_fetch_assoc($topStudentsResult)): ?>
                                <tr>
                                    <td><?php echo $rank++; ?></td>
                                    <td><?php echo htmlspecialchars($row['full_name']); ?></td>
                                    <td><?php echo htmlspecialchars($row['username']); ?></td>
                                    <td><?php echo htmlspecialchars($row['lrn']); ?></td>
                                    <td><?php echo htmlspecialchars($row['section'] . ' / ' . $row['strand']); ?></td>
                                    <td><?php echo (int)$row['total_transactions']; ?></td>
                                </tr>
                            <?php endwhile; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="6" class="empty-note">No student borrow activity yet.</td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <div class="stats-section">
            <div class="section-title">Recent Borrow Activity</div>
            <div class="table-card">
                <table class="stats-table">
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>Student</th>
                            <th>LRN</th>
                            <th>Book Title</th>
                            <th>Borrow Date</th>
                            <th>Due Date</th>
                            <th>Return Date</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ($recentBorrowResult && mysqli_num_rows($recentBorrowResult) > 0): ?>
                            <?php $rank = 1; ?>
                            <?php while ($row = mysqli_fetch_assoc($recentBorrowResult)): ?>
                                <?php
                                    $badgeClass = 'status-pending';
                                    if ($row['status'] === 'borrowed') $badgeClass = 'status-borrowed';
                                    elseif ($row['status'] === 'returned') $badgeClass = 'status-returned';
                                    elseif ($row['status'] === 'rejected') $badgeClass = 'status-rejected';

                                    if ($row['status'] === 'borrowed' && !empty($row['due_date']) && strtotime($row['due_date']) < time()) {
                                        $badgeClass = 'status-overdue';
                                    }
                                ?>
                                <tr>
                                    <td><?php echo $rank++; ?></td>
                                    <td><?php echo htmlspecialchars($row['full_name']); ?></td>
                                    <td><?php echo htmlspecialchars($row['lrn']); ?></td>
                                    <td><?php echo htmlspecialchars($row['title']); ?></td>
                                    <td><?php echo !empty($row['borrow_date']) ? htmlspecialchars($row['borrow_date']) : '-'; ?></td>
                                    <td><?php echo !empty($row['due_date']) ? htmlspecialchars($row['due_date']) : '-'; ?></td>
                                    <td><?php echo !empty($row['return_date']) ? htmlspecialchars($row['return_date']) : '-'; ?></td>
                                    <td>
                                        <span class="status-badge <?php echo $badgeClass; ?>">
                                            <?php
                                                if ($badgeClass === 'status-overdue') {
                                                    echo 'overdue';
                                                } else {
                                                    echo htmlspecialchars($row['status']);
                                                }
                                            ?>
                                        </span>
                                    </td>
                                </tr>
                            <?php endwhile; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="8" class="empty-note">No borrow activity yet.</td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <div class="stats-section">
            <div class="section-title">Recently Added Books</div>
            <div class="table-card">
                <table class="stats-table">
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>Book Title</th>
                            <th>Author</th>
                            <th>Category</th>
                            <th>Available</th>
                            <th>Borrowed</th>
                            <th>Status</th>
                            <th>Created At</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ($recentBooksResult && mysqli_num_rows($recentBooksResult) > 0): ?>
                            <?php $rank = 1; ?>
                            <?php while ($row = mysqli_fetch_assoc($recentBooksResult)): ?>
                                <tr>
                                    <td><?php echo $rank++; ?></td>
                                    <td><?php echo htmlspecialchars($row['title']); ?></td>
                                    <td><?php echo htmlspecialchars($row['author']); ?></td>
                                    <td><?php echo htmlspecialchars($row['category']); ?></td>
                                    <td><?php echo (int)$row['quantity']; ?></td>
                                    <td><?php echo (int)$row['borrowed']; ?></td>
                                    <td>
                                        <span class="status-badge <?php echo ($row['status'] === 'active') ? 'status-active' : 'status-unavailable'; ?>">
                                            <?php echo htmlspecialchars($row['status']); ?>
                                        </span>
                                    </td>
                                    <td><?php echo !empty($row['created_at']) ? htmlspecialchars($row['created_at']) : '-'; ?></td>
                                </tr>
                            <?php endwhile; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="8" class="empty-note">No recently added books yet.</td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

    </div>
</section>
</div>

</body>
</html>