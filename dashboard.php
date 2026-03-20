<?php
session_start();
include("db_connect.php");

// Handle logout
if (isset($_GET['logout'])) {
    // Clear all session data
    $_SESSION = [];
    session_destroy(); // Destroy the session
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

// Check if the user is logged in, otherwise redirect to login
if (!isset($_SESSION['student_id'])) {
    header("Location: login.php");
    exit;
}

$user_role = $_SESSION['student_role']; // 'user', 'admin', or 'librarian'
$user_id = $_SESSION['student_id'];

// Fetch logged user info
$user_q = mysqli_query($conn, "SELECT * FROM tbl_users WHERE id = '{$user_id}' LIMIT 1");
$user = mysqli_fetch_assoc($user_q);

// ✅ Safety: if user no longer exists
if (!$user) {
    session_destroy();
    echo "<script>alert('Session invalid. Please login again.'); window.location='login.php';</script>";
    exit;
}

// ✅ Block access if status is not active
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

// ✅ Optional but STRONGLY recommended: enforce your per-tab session id
// (prevents using old session after new login)
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

// handle quote posting


// handle like and delete actions
if (isset($_GET['action']) && isset($_GET['quote_id'])) {
    $quote_id = (int) $_GET['quote_id'];
    $action = $_GET['action'];

    if ($action === 'like') {

        $check = mysqli_query($conn, "
            SELECT id FROM tbl_quote_likes 
            WHERE quote_id = '$quote_id' AND user_id = '$user_id'
        ");

        if (mysqli_num_rows($check) == 0) {

            mysqli_query($conn, "
                INSERT INTO tbl_quote_likes (quote_id, user_id)
                VALUES ('$quote_id', '$user_id')
            ");

            mysqli_query($conn, "
                UPDATE tbl_quotes 
                SET likes = likes + 1 
                WHERE id = '$quote_id'
            ");

        } else {
            echo "<script>alert('You already liked this quote!');</script>";
        }
    }
}

// Pagination for quotes feed
$limit = 10;
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;

if ($page < 1) {
    $page = 1;
}

$quotes_count_res = mysqli_query($conn, "
    SELECT COUNT(*) AS total_quotes 
    FROM tbl_quotes
    WHERE status = 'approved'
");

$total_quotes = 0;
if ($quotes_count_res && mysqli_num_rows($quotes_count_res) > 0) {
    $count_row = mysqli_fetch_assoc($quotes_count_res);
    $total_quotes = (int)$count_row['total_quotes'];
}

$total_pages = max(1, ceil($total_quotes / $limit));

if ($page > $total_pages) {
    $page = $total_pages;
}

$offset = ($page - 1) * $limit;

$quotes_res = mysqli_query($conn, "
    SELECT q.id, q.quote, q.post_date, q.likes, u.full_name 
    FROM tbl_quotes q 
    JOIN tbl_users u ON q.user_id = u.id
    WHERE q.status = 'approved'
    ORDER BY q.post_date DESC
    LIMIT $limit OFFSET $offset
");

// Fetch borrowed records
$borrowed_res = mysqli_query($conn, "
    SELECT r.id as rec_id, r.borrow_date, r.return_date, b.* 
    FROM tbl_borrowed_records r 
    JOIN tbl_books b ON b.id = r.book_id
    WHERE r.user_id = '{$user_id}'
    ORDER BY r.borrow_date DESC
    LIMIT 5"
);

?>
<!doctype html>
<html>
<head>
  <meta charset="utf-8">
  <title>Dashboard — Golden Minds E-Library</title>

  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <link rel="stylesheet" href="assets/css/style.css">

  <style>
    .pagination {
    margin-top: 20px;
    text-align: center;
}

.pagination a,
.pagination span.page-info {
    display: inline-block;
    margin: 0 5px;
    padding: 8px 16px;
    border-radius: 5px;
    text-decoration: none;
    font-weight: bold;
}

.pagination a {
    background-color: #b5651d;
    color: white;
    transition: background-color 0.3s ease;
}

.pagination a:hover {
    background-color: #8b4b2b;
}

.pagination .disabled {
    background-color: #ccc;
    color: white;
    cursor: not-allowed;
    pointer-events: none;
}

.pagination .page-info {
    background: transparent;
    color: #333;
    padding: 8px 10px;
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
      src="<?php echo htmlspecialchars($user['profile_pic'] ?: 'uploads/profile/default.png'); ?>"
      alt="Profile"
      class="profile-pic"
    >

    <a href="/program/dashboard.php?logout=1" class="logout-btn">Logout</a>
  </div>
</header>

<div class="wrap">
  <aside class="sidebar">
  <h3>Navigation</h3>
  <ul class="sidebar-list">
    <li><a href="profile.php">Profile</a></li>

    <?php if ($user_role === 'student'): ?>
      <li><a href="borrow_books.php">Borrow Books</a></li>
      <li><a href="history.php">Borrow History</a></li>
      <li><a href="quotes_feedback.php">Quotes & Feedback</a></li>
    <?php endif; ?>

    <?php if ($user_role === 'admin'): ?>
      <li><a href="main_add_book.php">Add Book</a></li>
      <li><a href="admin_statistics.php">Dashboard Statistics</a></li>
      <li><a href="manage_roles.php">User Management</a></li>
      <li><a href="quotes_feedback.php">View Quotes & Feedback</a></li>
      <li><a href="manage_quotes.php">Manage Quotes & Feedback</a></li>
    <?php elseif ($user_role === 'librarian'): ?>
      <li><a href="borrow_approval.php">Borrow Approval</a></li>
      <li><a href="admin_statistics.php">Dashboard Statistics</a></li>
      <li><a href="manage_roles.php">User Management</a></li>
      <li><a href="quotes_feedback.php">View Quotes & Feedback</a></li>
      <li><a href="manage_quotes.php">Manage Quotes & Feedback</a></li>
    <?php endif; ?>
  </ul>
</aside>

  <section class="main">
    <h3>Your Daily Quotes</h3>

    

<div class="quotes-feed">
  <?php while ($quote = mysqli_fetch_assoc($quotes_res)): ?>
    <div class="quote-card">
      <div class="quote-text"><?= htmlspecialchars($quote['quote']) ?></div>
      <div class="quote-info">
        <span class="quote-author"><?= htmlspecialchars($quote['full_name']) ?></span>
        <span>— Posted on: <?= date('M d, Y', strtotime($quote['post_date'])) ?></span>
        <span>— Likes: <?= (int)$quote['likes'] ?></span>

        <div style="margin-top:8px;">
          <a href="dashboard.php?action=like&quote_id=<?= $quote['id'] ?>" 
            style="text-decoration:none; font-weight:bold; color:#b5651d;">
            👍 Like
          </a>
        </div>
      </div>
    </div>
  <?php endwhile; ?>
</div>

    <div class="pagination">
    <?php if ($total_pages > 1): ?>

        <?php if ($page > 1): ?>
            <a href="dashboard.php?page=1">First</a>
            <a href="dashboard.php?page=<?= $page - 1 ?>">Previous</a>
        <?php else: ?>
            <a class="disabled">First</a>
            <a class="disabled">Previous</a>
        <?php endif; ?>

        <span class="page-info">Page <?= $page ?> of <?= $total_pages ?></span>

        <?php if ($page < $total_pages): ?>
            <a href="dashboard.php?page=<?= $page + 1 ?>">Next</a>
            <a href="dashboard.php?page=<?= $total_pages ?>">Last</a>
        <?php else: ?>
            <a class="disabled">Next</a>
            <a class="disabled">Last</a>
        <?php endif; ?>

    <?php endif; ?>
</div>
  </section>
</div>

</body>
</html>