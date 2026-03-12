<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

session_start();
include("db_connect.php");

header("Cache-Control: no-cache, no-store, must-revalidate");
header("Pragma: no-cache");
header("Expires: 0");

// Check if the user is logged in as a librarian, otherwise redirect to login page
if (!isset($_SESSION['student_id']) || $_SESSION['student_role'] !== 'librarian') {
    header("Location: login.php");
    exit;
}

/*
|--------------------------------------------------------------------------
| SMART SINGLE SCAN LOGIC (BACKUP)
|--------------------------------------------------------------------------
| Step 1: scan student QR / LRN
| Step 2: scan book QR / Book ID
| Auto-detect:
|   - approve pending request
|   - return borrowed book
*/
$smart_message = "";
$smart_message_type = "";
$current_scan_lrn = $_SESSION['smart_scan_lrn'] ?? '';

if (isset($_POST['reset_smart_scan'])) {
    unset($_SESSION['smart_scan_lrn']);
    header("Location: borrow_approval.php");
    exit;
}

if (isset($_POST['smart_scan_submit'])) {
    $scan_value = trim($_POST['smart_scan_value'] ?? '');

    if ($scan_value === '') {
        $smart_message = "Scan field is empty.";
        $smart_message_type = "error";
    } else {
        // STEP 1: first scan = student LRN
        if (empty($_SESSION['smart_scan_lrn'])) {
            $safe_lrn = mysqli_real_escape_string($conn, $scan_value);

            $user_check = mysqli_query($conn, "
                SELECT id, full_name, lrn
                FROM tbl_users
                WHERE lrn = '$safe_lrn'
                LIMIT 1
            ");

            if ($user_check && mysqli_num_rows($user_check) > 0) {
                $_SESSION['smart_scan_lrn'] = $scan_value;
                $current_scan_lrn = $scan_value;
                $smart_message = "Student QR scanned successfully. Now scan the Book QR.";
                $smart_message_type = "success";
            } else {
                $smart_message = "Student not found using scanned LRN.";
                $smart_message_type = "error";
            }
        } else {
            // STEP 2: second scan = book ID
            $student_lrn = trim($_SESSION['smart_scan_lrn']);
            $book_id = intval($scan_value);

            if ($book_id <= 0) {
                $smart_message = "Invalid Book ID scanned.";
                $smart_message_type = "error";
            } else {
                $safe_lrn = mysqli_real_escape_string($conn, $student_lrn);

                mysqli_begin_transaction($conn);

                try {
                    // Find student first
                    $user_query = mysqli_query($conn, "
                        SELECT id, full_name, lrn
                        FROM tbl_users
                        WHERE lrn = '$safe_lrn'
                        LIMIT 1
                    ");

                    if (!$user_query || mysqli_num_rows($user_query) <= 0) {
                        throw new Exception("Student not found using scanned LRN.");
                    }

                    $user = mysqli_fetch_assoc($user_query);
                    $user_id = intval($user['id']);

                    // ------------------------------------------------------
                    // 1) CHECK PENDING BORROW FIRST
                    // ------------------------------------------------------
                    $pending_query = mysqli_query($conn, "
                        SELECT br.id, br.book_id, br.user_id, b.title
                        FROM tbl_borrowed_records br
                        JOIN tbl_books b ON br.book_id = b.id
                        WHERE br.user_id = '$user_id'
                          AND br.book_id = '$book_id'
                          AND br.status = 'pending'
                        ORDER BY br.id ASC
                        LIMIT 1
                    ");

                    if ($pending_query && mysqli_num_rows($pending_query) > 0) {
                        $pending_record = mysqli_fetch_assoc($pending_query);
                        $record_id = intval($pending_record['id']);

                        // Check available book stock
                        $book_query = mysqli_query($conn, "
                            SELECT id, title, quantity, borrowed
                            FROM tbl_books
                            WHERE id = '$book_id'
                            LIMIT 1
                        ");

                        if (!$book_query || mysqli_num_rows($book_query) <= 0) {
                            throw new Exception("Book not found.");
                        }

                        $book = mysqli_fetch_assoc($book_query);
                        $available = intval($book['quantity']);

                        if ($available <= 0) {
                            throw new Exception("No available copies left for this book.");
                        }

                        $approved_by = isset($_SESSION['student_id']) ? intval($_SESSION['student_id']) : "NULL";

                        $update_record = mysqli_query($conn, "
                            UPDATE tbl_borrowed_records
                            SET status = 'borrowed',
                                approved_at = NOW(),
                                approved_by = " . ($approved_by !== "NULL" ? $approved_by : "NULL") . "
                            WHERE id = '$record_id'
                        ");

                        if (!$update_record) {
                            throw new Exception("Failed to update borrow record.");
                        }

                        $update_book = mysqli_query($conn, "
                            UPDATE tbl_books
                            SET quantity = quantity - 1,
                                borrowed = borrowed + 1
                            WHERE id = '$book_id' AND quantity > 0
                        ");

                        if (!$update_book || mysqli_affected_rows($conn) <= 0) {
                            throw new Exception("Failed to update book stock.");
                        }

                        mysqli_commit($conn);

                        unset($_SESSION['smart_scan_lrn']);
                        $current_scan_lrn = "";

                        echo "<script>alert('Borrow approved successfully via Smart Scan.'); window.location='borrow_approval.php';</script>";
                        exit;
                    }

                    // ------------------------------------------------------
                    // 2) IF NO PENDING, CHECK ACTIVE BORROW FOR RETURN
                    // ------------------------------------------------------
                    $return_query = mysqli_query($conn, "
                        SELECT br.id, br.book_id, br.user_id, b.title
                        FROM tbl_borrowed_records br
                        JOIN tbl_books b ON br.book_id = b.id
                        WHERE br.user_id = '$user_id'
                          AND br.book_id = '$book_id'
                          AND br.status = 'borrowed'
                          AND (br.return_date IS NULL OR br.return_date = '0000-00-00 00:00:00')
                        ORDER BY br.id DESC
                        LIMIT 1
                    ");

                    if ($return_query && mysqli_num_rows($return_query) > 0) {
                        $return_record = mysqli_fetch_assoc($return_query);
                        $borrow_id = intval($return_record['id']);

                        $update_return_record = mysqli_query($conn, "
                            UPDATE tbl_borrowed_records
                            SET return_date = NOW(),
                                status = 'returned'
                            WHERE id = '$borrow_id'
                        ");

                        if (!$update_return_record) {
                            throw new Exception("Failed to update return record.");
                        }

                        $update_return_book = mysqli_query($conn, "
                            UPDATE tbl_books
                            SET quantity = quantity + 1,
                                borrowed = IF(borrowed > 0, borrowed - 1, 0)
                            WHERE id = '$book_id'
                        ");

                        if (!$update_return_book) {
                            throw new Exception("Failed to restore book stock.");
                        }

                        mysqli_commit($conn);

                        unset($_SESSION['smart_scan_lrn']);
                        $current_scan_lrn = "";

                        echo "<script>alert('Book returned successfully via Smart Scan.'); window.location='borrow_approval.php';</script>";
                        exit;
                    }

                    // ------------------------------------------------------
                    // 3) NO MATCH FOUND
                    // ------------------------------------------------------
                    mysqli_rollback($conn);
                    $smart_message = "No valid pending borrow or active borrowed record found for this student and book.";
                    $smart_message_type = "error";

                } catch (Exception $e) {
                    mysqli_rollback($conn);
                    $smart_message = $e->getMessage();
                    $smart_message_type = "error";
                }
            }
        }
    }
}

/*
|--------------------------------------------------------------------------
| BUTTON ACTIONS LOGIC
|--------------------------------------------------------------------------
| This handles old Approve / Reject / Return buttons only
*/
if (isset($_POST['action']) && isset($_POST['borrow_id'])) {
    $borrow_id = intval($_POST['borrow_id']);
    $action = $_POST['action'];

    // Get the book_id from the borrowed record
    $borrow_query = mysqli_query($conn, "SELECT book_id, status FROM tbl_borrowed_records WHERE id = $borrow_id LIMIT 1");

    if ($borrow_query && mysqli_num_rows($borrow_query) > 0) {
        $borrow_record = mysqli_fetch_assoc($borrow_query);
        $book_id = $borrow_record['book_id'];

        if ($action === 'approve') {
            // Check available stock first
            $book_query = mysqli_query($conn, "SELECT quantity, borrowed FROM tbl_books WHERE id = $book_id LIMIT 1");

            if ($book_query && mysqli_num_rows($book_query) > 0) {
                $book = mysqli_fetch_assoc($book_query);
                $available = $book['quantity'];

                if ($available > 0) {
                    $approved_by = isset($_SESSION['student_id']) ? intval($_SESSION['student_id']) : "NULL";

                    mysqli_query($conn, "
                        UPDATE tbl_borrowed_records
                        SET status = 'borrowed',
                            approved_at = NOW(),
                            approved_by = " . ($approved_by !== "NULL" ? $approved_by : "NULL") . "
                        WHERE id = $borrow_id
                    ");

                    mysqli_query($conn, "
                        UPDATE tbl_books
                        SET quantity = quantity - 1,
                            borrowed = borrowed + 1
                        WHERE id = $book_id AND quantity > 0
                    ");
                } else {
                    echo "<script>alert('No available copies left for this book.'); window.location='borrow_approval.php';</script>";
                    exit;
                }
            }
        } elseif ($action === 'reject') {
            mysqli_query($conn, "UPDATE tbl_borrowed_records SET status = 'rejected' WHERE id = $borrow_id");
        } elseif ($action === 'return') {
            mysqli_query($conn, "UPDATE tbl_borrowed_records SET return_date = NOW(), status = 'returned' WHERE id = $borrow_id");

            mysqli_query($conn, "
                UPDATE tbl_books
                SET quantity = quantity + 1,
                    borrowed = IF(borrowed > 0, borrowed - 1, 0)
                WHERE id = $book_id
            ");
        }
    }

    header("Location: borrow_approval.php");
    exit;
}

// Pagination Setup
$records_per_page = 20;
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
if ($page < 1) {
    $page = 1;
}
$offset = ($page - 1) * $records_per_page;

// Fetch all requests for display
$pending_res = mysqli_query($conn, "
    SELECT r.id, r.book_id, u.username, u.full_name, u.lrn, b.title, b.author, r.borrow_date, r.return_date, r.status
    FROM tbl_borrowed_records r
    JOIN tbl_books b ON b.id = r.book_id
    JOIN tbl_users u ON u.id = r.user_id
    WHERE r.status IN ('pending', 'borrowed', 'rejected', 'returned')
    ORDER BY r.borrow_date DESC
    LIMIT $records_per_page OFFSET $offset
");

// Get total requests
$total_requests_res = mysqli_query($conn, "
    SELECT COUNT(*) AS total
    FROM tbl_borrowed_records
    WHERE status IN ('pending', 'borrowed', 'rejected', 'returned')
");
$total_requests = mysqli_fetch_assoc($total_requests_res)['total'];
$total_pages = ceil($total_requests / $records_per_page);
?>

<!doctype html>
<html>
<head>
    <meta charset="utf-8">
    <title>Borrow Approval - Librarian Dashboard</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            background-color: #f4f4f9;
            margin: 0;
            padding: 0;
            color: #333;
        }

        header {
            background: linear-gradient(180deg, #6f3b3b, #b5651d);
            color: white;
            padding: 15px 20px;
            text-align: center;
            position: relative;
        }

        h1 {
            margin: 0;
            font-size: 24px;
        }

        .back-btn {
            position: absolute;
            top: 15px;
            right: 20px;
            padding: 8px 16px;
            background-color: #8b4b2b;
            color: white;
            border: none;
            border-radius: 5px;
            cursor: pointer;
            text-decoration: none;
            transition: background-color 0.3s ease;
        }

        .back-btn:hover {
            background-color: #5e2d21;
        }

        .container {
            display: flex;
            padding: 20px;
            justify-content: space-between;
            flex-wrap: wrap;
        }

        .main {
            flex: 1;
            background: white;
            padding: 20px;
            box-shadow: 0 4px 8px rgba(0, 0, 0, 0.1);
            border-radius: 8px;
            margin-right: 20px;
        }

        .btn {
            padding: 8px 16px;
            color: white;
            background-color: #b5651d;
            border: none;
            border-radius: 5px;
            cursor: pointer;
            text-decoration: none;
            transition: background-color 0.3s ease;
        }

        .btn:hover {
            background-color: #8b4b2b;
        }

        .btn:disabled,
        .disabled {
            background-color: #ccc;
            cursor: not-allowed;
            pointer-events: none;
        }

        .btn-danger {
            background-color: #e74c3c;
        }

        .btn-danger:hover {
            background-color: #c0392b;
        }

        .smart-scan-box {
            background: #eef6ff;
            border: 1px solid #b9d7ff;
            border-radius: 10px;
            padding: 16px;
            margin-bottom: 20px;
        }

        .smart-scan-title {
            margin: 0 0 10px 0;
            color: #184e9e;
        }

        .smart-status {
            background: #ffffff;
            border: 1px solid #d7e6ff;
            border-radius: 8px;
            padding: 10px 12px;
            margin-bottom: 12px;
        }

        .smart-ready {
            color: #1a7f37;
            font-weight: bold;
        }

        .smart-waiting {
            color: #b26a00;
            font-weight: bold;
        }

        .smart-message {
            padding: 10px 12px;
            border-radius: 8px;
            margin-bottom: 12px;
            font-size: 14px;
        }

        .smart-message.success {
            background: #e8f7ee;
            color: #146c43;
            border: 1px solid #b7e4c7;
        }

        .smart-message.error {
            background: #fdeaea;
            color: #b42318;
            border: 1px solid #f5c2c7;
        }

        .scan-row {
            display: flex;
            gap: 10px;
            align-items: center;
            flex-wrap: wrap;
        }

        .scan-row input {
            padding: 10px;
            min-width: 240px;
            border: 1px solid #ccc;
            border-radius: 5px;
            flex: 1;
        }

        .scan-note {
            font-size: 13px;
            color: #666;
            margin-top: 8px;
        }

        .reset-btn {
            background: #dc3545;
        }

        .reset-btn:hover {
            background: #b02a37;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 20px;
        }

        th, td {
            padding: 12px;
            text-align: left;
            border: 1px solid #ddd;
        }

        th {
            background-color: #ffcc66;
            color: #333;
        }

        td {
            background-color: #fff;
        }

        .status-text {
            font-size: 14px;
            color: #888;
        }

        .pagination {
            margin-top: 20px;
            text-align: center;
        }

        .pagination a {
            padding: 8px 16px;
            background-color: #b5651d;
            color: white;
            text-decoration: none;
            border-radius: 5px;
            margin: 0 5px;
        }

        .pagination a:hover {
            background-color: #8b4b2b;
        }

        .badge {
            display: inline-block;
            padding: 5px 10px;
            border-radius: 12px;
            font-size: 12px;
            font-weight: bold;
        }

        .badge-pending {
            background: #fff3cd;
            color: #856404;
        }

        .badge-borrowed {
            background: #d4edda;
            color: #155724;
        }

        .badge-rejected {
            background: #f8d7da;
            color: #721c24;
        }

        .badge-returned {
            background: #d1ecf1;
            color: #0c5460;
        }

        @media (max-width: 768px) {
            .container {
                padding: 12px;
            }

            .main {
                margin-right: 0;
                padding: 15px;
            }

            .scan-row {
                flex-direction: column;
                align-items: stretch;
            }

            .scan-row input,
            .scan-row button {
                width: 100%;
                min-width: unset;
            }

            table {
                display: block;
                overflow-x: auto;
                white-space: nowrap;
            }
        }
    </style>
</head>
<body>
    <header>
        <h1>Golden Minds E-Library - Borrow Approval</h1>
        <a href="dashboard.php" class="back-btn">Back to Dashboard</a>
    </header>

    <div class="container">
        <div class="main">
            <h2>Borrow Requests</h2>

            <div class="smart-scan-box">
                <h3 class="smart-scan-title">Smart Single Scanner (Backup)</h3>

                <div class="smart-status">
                    <strong>Current Student Scan:</strong>
                    <?php if (!empty($current_scan_lrn)): ?>
                        <span class="smart-ready"><?= htmlspecialchars($current_scan_lrn) ?></span>
                    <?php else: ?>
                        <span class="smart-waiting">Waiting for Student QR...</span>
                    <?php endif; ?>
                </div>

                <?php if (!empty($smart_message)): ?>
                    <div class="smart-message <?= htmlspecialchars($smart_message_type) ?>">
                        <?= htmlspecialchars($smart_message) ?>
                    </div>
                <?php endif; ?>

                <form method="POST" action="borrow_approval.php" id="smart-scan-form">
                    <div class="scan-row">
                        <input
                            type="text"
                            name="smart_scan_value"
                            id="smart_scan_value"
                            placeholder="<?= empty($current_scan_lrn) ? 'Scan Student QR / Enter LRN' : 'Scan Book QR / Enter Book ID' ?>"
                            autocomplete="off"
                            autofocus
                            required
                        >
                        <button type="submit" name="smart_scan_submit" id="smart_scan_submit_btn" class="btn">Scan</button>
                    </div>
                    <div class="scan-note">
                        Scan Student QR first, then scan Book QR. The system will auto-approve pending borrow or auto-return active borrowed books.
                    </div>
                </form>

                <form method="POST" action="borrow_approval.php" style="margin-top:10px;">
                    <button type="submit" name="reset_smart_scan" class="btn reset-btn">Reset Scan</button>
                </form>
            </div>

            <table>
                <thead>
                    <tr>
                        <th>Student Username</th>
                        <th>Full Name</th>
                        <th>LRN</th>
                        <th>Book Title</th>
                        <th>Book ID</th>
                        <th>Author</th>
                        <th>Borrow Date</th>
                        <th>Status</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($pending_res && mysqli_num_rows($pending_res) > 0): ?>
                        <?php while ($request = mysqli_fetch_assoc($pending_res)): ?>
                        <tr>
                            <td><?= htmlspecialchars($request['username']) ?></td>
                            <td><?= htmlspecialchars($request['full_name']) ?></td>
                            <td><?= htmlspecialchars($request['lrn']) ?></td>
                            <td><?= htmlspecialchars($request['title']) ?></td>
                            <td><?= htmlspecialchars($request['book_id']) ?></td>
                            <td><?= htmlspecialchars($request['author']) ?></td>
                            <td><?= date('M d, Y', strtotime($request['borrow_date'])) ?></td>
                            <td>
                                <?php if ($request['status'] === 'pending'): ?>
                                    <span class="badge badge-pending">Pending</span>
                                <?php elseif ($request['status'] === 'borrowed'): ?>
                                    <span class="badge badge-borrowed">Borrowed</span>
                                <?php elseif ($request['status'] === 'rejected'): ?>
                                    <span class="badge badge-rejected">Rejected</span>
                                <?php elseif ($request['status'] === 'returned'): ?>
                                    <span class="badge badge-returned">Returned</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($request['status'] === 'pending'): ?>
                                    <form method="POST" action="borrow_approval.php" style="display:inline;">
                                        <input type="hidden" name="borrow_id" value="<?= $request['id'] ?>">
                                        <button type="submit" name="action" value="approve" class="btn">Approve</button>
                                    </form>
                                    <form method="POST" action="borrow_approval.php" style="display:inline;">
                                        <input type="hidden" name="borrow_id" value="<?= $request['id'] ?>">
                                        <button type="submit" name="action" value="reject" class="btn btn-danger">Reject</button>
                                    </form>
                                <?php elseif ($request['status'] === 'borrowed' && !$request['return_date']): ?>
                                    <form method="POST" action="borrow_approval.php" style="display:inline;">
                                        <input type="hidden" name="borrow_id" value="<?= $request['id'] ?>">
                                        <button type="submit" name="action" value="return" class="btn">Return</button>
                                    </form>
                                <?php else: ?>
                                    <span class="status-text">Completed</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endwhile; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="9" style="text-align:center;">No borrow requests found.</td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>

            <div class="pagination">
                <?php if ($total_pages > 1): ?>
                    <?php if ($page > 1): ?>
                        <a href="borrow_approval.php?page=1">First</a>
                        <a href="borrow_approval.php?page=<?= $page - 1 ?>">Previous</a>
                    <?php else: ?>
                        <a class="disabled">First</a>
                        <a class="disabled">Previous</a>
                    <?php endif; ?>

                    <span style="margin: 0 10px; font-weight: bold;">
                        Page <?= $page ?> of <?= $total_pages ?>
                    </span>

                    <?php if ($page < $total_pages): ?>
                        <a href="borrow_approval.php?page=<?= $page + 1 ?>">Next</a>
                        <a href="borrow_approval.php?page=<?= $total_pages ?>">Last</a>
                    <?php else: ?>
                        <a class="disabled">Next</a>
                        <a class="disabled">Last</a>
                    <?php endif; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <script>
    document.addEventListener("DOMContentLoaded", function () {
        const smartInput = document.getElementById("smart_scan_value");
        const smartForm = document.getElementById("smart-scan-form");
        const smartBtn = document.getElementById("smart_scan_submit_btn");

        if (smartInput) {
            smartInput.focus();

            smartInput.addEventListener("keydown", function (e) {
                if (e.key === "Enter") {
                    e.preventDefault();

                    if (smartInput.value.trim() !== "") {
                        smartForm.requestSubmit(smartBtn);
                    }
                }
            });
        }

        document.addEventListener("click", function () {
            if (smartInput) {
                smartInput.focus();
            }
        });
    });
    </script>
</body>
</html>