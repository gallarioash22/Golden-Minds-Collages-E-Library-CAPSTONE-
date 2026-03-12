<?php
session_start();
include("db_connect.php");

header("Cache-Control: no-cache, no-store, must-revalidate");
header("Pragma: no-cache");
header("Expires: 0");

// Check student session
if (!isset($_SESSION['student_id']) || $_SESSION['student_role'] !== 'student') {
    header("Location: login.php");
    exit;
}

$user_id = (int)$_SESSION['student_id'];

// --- Status Tabs Setup (Shopee-like) ---
$allowed_statuses = ['pending', 'borrowed', 'returned', 'rejected'];
$status = isset($_GET['status']) ? strtolower(trim($_GET['status'])) : 'pending';
if (!in_array($status, $allowed_statuses, true)) $status = 'pending';

// Pagination Setup (per status)
$history_per_page = 10;
$page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$offset = ($page - 1) * $history_per_page;

// Count totals for each status (for badge numbers)
$counts = [];
$count_sql = "
    SELECT LOWER(r.status) AS st, COUNT(*) AS total
    FROM tbl_borrowed_records r
    WHERE r.user_id = $user_id
    GROUP BY LOWER(r.status)
";
$count_res = mysqli_query($conn, $count_sql);
while ($row = mysqli_fetch_assoc($count_res)) {
    $counts[$row['st']] = (int)$row['total'];
}
foreach ($allowed_statuses as $st) {
    if (!isset($counts[$st])) $counts[$st] = 0;
}

// Fetch borrow history filtered by selected status
$history_sql = "
    SELECT b.title, b.author, r.borrow_date, r.return_date, r.status
    FROM tbl_borrowed_records r
    JOIN tbl_books b ON b.id = r.book_id
    WHERE r.user_id = $user_id
      AND LOWER(r.status) = '".mysqli_real_escape_string($conn, $status)."'
    ORDER BY r.borrow_date DESC
    LIMIT $history_per_page OFFSET $offset
";
$history_res = mysqli_query($conn, $history_sql);

// Total pages for selected status
$total_history = $counts[$status];
$total_pages = max(1, (int)ceil($total_history / $history_per_page));

// Helper: status label
function status_label($st) {
    return ucfirst($st);
}

// Helper: format date safely
function fmt_date($dateStr) {
    if (!$dateStr) return '—';
    return date('M d, Y', strtotime($dateStr));
}
?>

<!doctype html>
<html>
<head>
    <meta charset="utf-8">
    <title>Borrow History — Golden Minds E-Library</title>

    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <link rel="stylesheet" href="assets/css/style.css">

    <style>
        :root { --brown:#b5651d; --light:#fff8e7; --accent:#ffcc66; --dark:#333; --muted:#777; }
        body { font-family: Arial, sans-serif; margin: 0; background: var(--light); }

        header { background: linear-gradient(180deg, #6f3b3b, var(--brown)); color: white; padding: 12px 20px;
                 display: flex; align-items: center; justify-content: space-between; }

        h1 { margin: 0; font-size: 20px; }
        .wrap { padding: 20px; }

        .main { background: #fff; padding: 18px; border-radius: 10px; box-shadow: 0 6px 18px rgba(0,0,0,0.06); }

        .btn { display: inline-block; padding: 8px 12px; background: var(--brown); color: white; border-radius: 8px; text-decoration: none; }
        .btn:hover { background: #8b4b2b; }

        /* Shopee-like tabs */
        .tabs { display:flex; gap:10px; flex-wrap:wrap; margin: 10px 0 16px; }
        .tab {
            flex: 1;
            min-width: 160px;
            display:flex; align-items:center; justify-content:space-between;
            padding: 12px 14px;
            border-radius: 12px;
            background: #fff8e7;
            border: 1px solid #f2e6cf;
            text-decoration:none;
            color: var(--dark);
            transition: .15s ease;
        }
        .tab:hover { transform: translateY(-1px); box-shadow: 0 6px 14px rgba(0,0,0,0.06); }

        .tab.active {
            background: #fff3d1;
            border-color: var(--accent);
            box-shadow: 0 6px 14px rgba(0,0,0,0.06);
        }

        .tab-left { display:flex; align-items:center; gap:10px; }
        .icon {
            width: 34px; height: 34px;
            border-radius: 10px;
            display:flex; align-items:center; justify-content:center;
            background: white;
            border: 1px solid #f0e2c6;
            font-size: 18px;
        }
        .tab-title { font-weight: 700; font-size: 14px; }
        .tab-sub { font-size: 12px; color: var(--muted); margin-top: 2px; }

        .badge {
            min-width: 32px;
            text-align:center;
            padding: 6px 10px;
            border-radius: 999px;
            background: var(--brown);
            color: white;
            font-weight: 700;
            font-size: 12px;
        }

        /* Card list (Shopee-ish) */
        .cards { display:flex; flex-direction:column; gap:12px; margin-top: 10px; }
        .card {
            border: 1px solid #f2e6cf;
            border-radius: 12px;
            padding: 14px;
            background: #fff;
        }
        .card-top { display:flex; justify-content:space-between; gap:10px; align-items:flex-start; }
        .card h3 { margin: 0; font-size: 16px; }
        .meta { margin-top: 6px; color: var(--muted); font-size: 13px; display:flex; gap:14px; flex-wrap:wrap; }
        .pill {
            display:inline-block;
            padding: 6px 10px;
            border-radius: 999px;
            font-weight: 700;
            font-size: 12px;
            background: #fff8e7;
            border: 1px solid #f2e6cf;
            color: #6f3b3b;
            text-transform: capitalize;
        }
        .empty { padding: 18px; background:#fff8e7; border:1px dashed #e8d8b8; border-radius:12px; color: var(--muted); }

        /* Pagination */
        .pagination { margin-top: 16px; display:flex; gap:10px; align-items:center; }
        .page-info { color: var(--muted); font-size: 13px; }
    </style>
</head>
<body>
<header>
    <h1>Golden Minds E-Library</h1>
    <a href="dashboard.php" class="btn">Back to Dashboard</a>
</header>

<div class="wrap">
    <section class="main">
        <h2 style="margin-top:0;">Borrow History</h2>

        <!-- Tabs -->
        <div class="tabs">
            <a class="tab <?= $status==='pending'?'active':'' ?>" href="history.php?status=pending&page=1">
                <div class="tab-left">
                    <div class="icon">🕒</div>
                    <div>
                        <div class="tab-title">Pending</div>
                        <div class="tab-sub">Waiting approval</div>
                    </div>
                </div>
                <div class="badge"><?= $counts['pending'] ?></div>
            </a>

            <a class="tab <?= $status==='borrowed'?'active':'' ?>" href="history.php?status=borrowed&page=1">
                <div class="tab-left">
                    <div class="icon">📚</div>
                    <div>
                        <div class="tab-title">Borrowed</div>
                        <div class="tab-sub">Currently borrowed</div>
                    </div>
                </div>
                <div class="badge"><?= $counts['borrowed'] ?></div>
            </a>

            <a class="tab <?= $status==='returned'?'active':'' ?>" href="history.php?status=returned&page=1">
                <div class="tab-left">
                    <div class="icon">✅</div>
                    <div>
                        <div class="tab-title">Returned</div>
                        <div class="tab-sub">Completed</div>
                    </div>
                </div>
                <div class="badge"><?= $counts['returned'] ?></div>
            </a>

            <a class="tab <?= $status==='rejected'?'active':'' ?>" href="history.php?status=rejected&page=1">
                <div class="tab-left">
                    <div class="icon">❌</div>
                    <div>
                        <div class="tab-title">Rejected</div>
                        <div class="tab-sub">Not approved</div>
                    </div>
                </div>
                <div class="badge"><?= $counts['rejected'] ?></div>
            </a>
        </div>

        <!-- Content -->
        <div class="cards">
            <?php if (mysqli_num_rows($history_res) === 0): ?>
                <div class="empty">
                    No records found under <b><?= htmlspecialchars(status_label($status)) ?></b>.
                </div>
            <?php else: ?>
                <?php while ($history = mysqli_fetch_assoc($history_res)): ?>
                    <div class="card">
                        <div class="card-top">
                            <div>
                                <h3><?= htmlspecialchars($history['title']) ?></h3>
                                <div class="meta">
                                    <span><b>Author:</b> <?= htmlspecialchars($history['author']) ?></span>
                                    <span><b>Borrowed:</b> <?= fmt_date($history['borrow_date']) ?></span>
                                    <span><b>Returned:</b> <?= $history['return_date'] ? fmt_date($history['return_date']) : 'No' ?></span>
                                </div>
                            </div>
                            <span class="pill"><?= htmlspecialchars(strtolower($history['status'])) ?></span>
                        </div>
                    </div>
                <?php endwhile; ?>
            <?php endif; ?>
        </div>

        <!-- Pagination -->
        <div class="pagination">
            <?php if ($page > 1): ?>
                <a class="btn" href="history.php?status=<?= urlencode($status) ?>&page=<?= $page - 1 ?>">Previous</a>
            <?php endif; ?>

            <span class="page-info">Page <?= $page ?> of <?= $total_pages ?></span>

            <?php if ($page < $total_pages): ?>
                <a class="btn" href="history.php?status=<?= urlencode($status) ?>&page=<?= $page + 1 ?>">Next</a>
            <?php endif; ?>
        </div>
    </section>
</div>
</body>
</html>