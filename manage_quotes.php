<?php
session_start();
include("db_connect.php");

// Check login
if (!isset($_SESSION['student_id'])) {
    header("Location: login.php");
    exit;
}

$user_id = (int) $_SESSION['student_id'];
$user_role = $_SESSION['student_role'] ?? '';

// Only admin and librarian can access
if ($user_role !== 'admin' && $user_role !== 'librarian') {
    echo "<script>alert('Access denied.'); window.location='dashboard.php';</script>";
    exit;
}

// Fetch logged user
$user_q = mysqli_query($conn, "SELECT * FROM tbl_users WHERE id = '$user_id' LIMIT 1");
$user = mysqli_fetch_assoc($user_q);

if (!$user) {
    session_destroy();
    echo "<script>alert('Session invalid. Please login again.'); window.location='login.php';</script>";
    exit;
}

// Handle actions
if (isset($_GET['action']) && isset($_GET['id'])) {
    $post_id = (int) $_GET['id'];
    $action = $_GET['action'];

    if ($action === 'approve') {
        mysqli_query($conn, "UPDATE tbl_quotes SET status = 'approved' WHERE id = '$post_id'");
        echo "<script>alert('Post approved successfully.'); window.location='manage_quotes.php';</script>";
        exit;
    }

    if ($action === 'reject') {
        mysqli_query($conn, "UPDATE tbl_quotes SET status = 'rejected' WHERE id = '$post_id'");
        echo "<script>alert('Post rejected successfully.'); window.location='manage_quotes.php';</script>";
        exit;
    }

    if ($action === 'flag') {
        mysqli_query($conn, "UPDATE tbl_quotes SET status = 'flagged' WHERE id = '$post_id'");
        echo "<script>alert('Post flagged successfully.'); window.location='manage_quotes.php';</script>";
        exit;
    }

    if ($action === 'delete') {
        mysqli_query($conn, "DELETE FROM tbl_quotes WHERE id = '$post_id'");
        echo "<script>alert('Post deleted successfully.'); window.location='manage_quotes.php';</script>";
        exit;
    }
}

// Filter
$filter_status = $_GET['status'] ?? 'all';

$where = "";
if ($filter_status === 'approved') {
    $where = "WHERE q.status = 'approved'";
} elseif ($filter_status === 'flagged') {
    $where = "WHERE q.status = 'flagged'";
} elseif ($filter_status === 'rejected') {
    $where = "WHERE q.status = 'rejected'";
} elseif ($filter_status === 'filtered') {
    $where = "WHERE q.moderation_result = 'filtered'";
}

// Pagination setup
$records_per_page = 15;
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;

if ($page < 1) {
    $page = 1;
}

// Count total posts using same filter
$count_sql = "
    SELECT COUNT(*) AS total
    FROM tbl_quotes q
    LEFT JOIN tbl_users u ON q.user_id = u.id
    $where
";
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

// Fetch posts with pagination
$sql = "
    SELECT q.*, u.full_name
    FROM tbl_quotes q
    LEFT JOIN tbl_users u ON q.user_id = u.id
    $where
    ORDER BY q.post_date DESC
    LIMIT $records_per_page OFFSET $offset
";

$result = mysqli_query($conn, $sql);
?>
<!doctype html>
<html>
<head>
  <meta charset="utf-8">
  <title>Manage Quotes & Feedback — Golden Minds E-Library</title>
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <link rel="stylesheet" href="assets/css/style.css">
  <style>
    .table-wrap {
      overflow-x: auto;
      margin-top: 20px;
    }

    .manage-table {
      width: 100%;
      border-collapse: collapse;
      min-width: 1100px;
    }

    .manage-table th,
    .manage-table td {
      border: 1px solid #ddd;
      padding: 10px;
      text-align: left;
      vertical-align: top;
      font-size: 14px;
    }

    .manage-table th {
      background: #f3e7d8;
      color: #6f3b3b;
    }

    .badge {
      display: inline-block;
      padding: 4px 8px;
      border-radius: 20px;
      font-size: 12px;
      font-weight: bold;
      margin: 2px 0;
    }

    .badge-quote {
      background: #ffecbf;
      color: #6f3b3b;
    }

    .badge-comment {
      background: #e8f1ff;
      color: #244c8a;
    }

    .badge-approved {
      background: #e7f7ed;
      color: #1f6b3b;
    }

    .badge-flagged {
      background: #fff4db;
      color: #8a5a00;
    }

    .badge-rejected {
      background: #fde8e8;
      color: #9b1c1c;
    }

    .badge-clean {
      background: #eef7ee;
      color: #2d6a2d;
    }

    .badge-filtered {
      background: #fff0d9;
      color: #9a5b00;
    }

    .badge-blocked {
      background: #fde8e8;
      color: #9b1c1c;
    }

    .action-btn {
      display: inline-block;
      margin: 3px 3px 3px 0;
      padding: 6px 10px;
      border-radius: 6px;
      text-decoration: none;
      color: #fff;
      font-size: 13px;
    }

    .btn-approve { background: #2e8b57; }
    .btn-flag { background: #c58a00; }
    .btn-reject { background: #b22222; }
    .btn-delete { background: #444; }

    .action-btn:hover {
      opacity: 0.9;
    }

    .filter-bar {
      display: flex;
      gap: 10px;
      flex-wrap: wrap;
      margin-top: 10px;
      margin-bottom: 10px;
    }

    .filter-link {
      text-decoration: none;
      padding: 8px 12px;
      border-radius: 6px;
      background: var(--accent);
      color: #000;
      font-size: 14px;
    }

    .filter-link:hover {
      opacity: 0.9;
    }

    .content-cell {
      max-width: 280px;
      white-space: pre-wrap;
      word-break: break-word;
    }

    .back-link {
      display: inline-block;
      margin-bottom: 15px;
      text-decoration: none;
      color: var(--brown);
      font-weight: bold;
    }

    .back-link:hover {
      text-decoration: underline;
    }

    .pagination {
      margin-top: 20px;
      text-align: center;
    }

    .pagination .btn,
    .pagination .page-info {
      display: inline-block;
      margin: 0 5px;
      padding: 8px 16px;
      border-radius: 5px;
      text-decoration: none;
      font-weight: bold;
    }

    .pagination .btn {
      background-color: #b5651d;
      color: white;
      transition: background-color 0.3s ease;
    }

    .pagination .btn:hover {
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

    <a href="dashboard.php" class="logout-btn">Back to Dashboard</a>
  </div>
</header>

<div class="wrap">
  <aside class="sidebar">
    <h3>Moderation Panel</h3>
    <ul class="sidebar-list">
      <li><a href="dashboard.php">Dashboard</a></li>

      <?php if ($user_role === 'admin'): ?>
        <li><a href="main_add_book.php">Add Book</a></li>
        <li><a href="manage_roles.php">User Management</a></li>
      <?php elseif ($user_role === 'librarian'): ?>
        <li><a href="borrow_approval.php">Borrow Approval</a></li>
        <li><a href="manage_roles.php">User Management</a></li>
      <?php endif; ?>

      <li><a href="quotes_feedback.php">View Quotes & Feedback</a></li>
      <li><a href="manage_quotes.php">Manage Posts</a></li>
    </ul>
  </aside>

  <section class="main">
    <a href="dashboard.php" class="back-link">← Back to Dashboard</a>

    <h3>Manage Quotes & Feedback</h3>
    <p>Use this page to approve, flag, reject, or delete student posts.</p>

    <div class="filter-bar">
      <a href="manage_quotes.php?status=all" class="filter-link">All</a>
      <a href="manage_quotes.php?status=approved" class="filter-link">Approved</a>
      <a href="manage_quotes.php?status=flagged" class="filter-link">Flagged</a>
      <a href="manage_quotes.php?status=rejected" class="filter-link">Rejected</a>
      <a href="manage_quotes.php?status=filtered" class="filter-link">Filtered Words</a>
    </div>

    <div class="table-wrap">
      <table class="manage-table">
        <thead>
          <tr>
            <th>ID</th>
            <th>Author</th>
            <th>Type</th>
            <th>Content</th>
            <th>Anonymous</th>
            <th>Moderation</th>
            <th>Status</th>
            <th>Likes</th>
            <th>Date Posted</th>
            <th>Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php if ($result && mysqli_num_rows($result) > 0): ?>
            <?php while ($row = mysqli_fetch_assoc($result)): ?>
              <tr>
                <td><?php echo (int)$row['id']; ?></td>
                <td>
                  <?php
                    if ((int)$row['is_anonymous'] === 1) {
                        echo "Anonymous";
                    } else {
                        echo htmlspecialchars($row['full_name'] ?? 'Unknown User');
                    }
                  ?>
                </td>
                <td>
                  <span class="badge <?php echo ($row['type'] === 'comment') ? 'badge-comment' : 'badge-quote'; ?>">
                    <?php echo ucfirst(htmlspecialchars($row['type'] ?? 'quote')); ?>
                  </span>
                </td>
                <td class="content-cell"><?php echo nl2br(htmlspecialchars($row['quote'])); ?></td>
                <td><?php echo ((int)$row['is_anonymous'] === 1) ? 'Yes' : 'No'; ?></td>
                <td>
                  <span class="badge
                    <?php
                      if (($row['moderation_result'] ?? 'clean') === 'filtered') {
                          echo 'badge-filtered';
                      } elseif (($row['moderation_result'] ?? 'clean') === 'blocked') {
                          echo 'badge-blocked';
                      } else {
                          echo 'badge-clean';
                      }
                    ?>">
                    <?php echo ucfirst(htmlspecialchars($row['moderation_result'] ?? 'clean')); ?>
                  </span>
                </td>
                <td>
                  <span class="badge
                    <?php
                      if (($row['status'] ?? 'approved') === 'flagged') {
                          echo 'badge-flagged';
                      } elseif (($row['status'] ?? 'approved') === 'rejected') {
                          echo 'badge-rejected';
                      } else {
                          echo 'badge-approved';
                      }
                    ?>">
                    <?php echo ucfirst(htmlspecialchars($row['status'] ?? 'approved')); ?>
                  </span>
                </td>
                <td><?php echo (int)$row['likes']; ?></td>
                <td><?php echo date('M d, Y h:i A', strtotime($row['post_date'])); ?></td>
                <td>
                  <a class="action-btn btn-approve" href="manage_quotes.php?action=approve&id=<?php echo (int)$row['id']; ?>" onclick="return confirm('Approve this post?')">Approve</a>
                  <a class="action-btn btn-flag" href="manage_quotes.php?action=flag&id=<?php echo (int)$row['id']; ?>" onclick="return confirm('Flag this post?')">Flag</a>
                  <a class="action-btn btn-reject" href="manage_quotes.php?action=reject&id=<?php echo (int)$row['id']; ?>" onclick="return confirm('Reject this post?')">Reject</a>
                  <a class="action-btn btn-delete" href="manage_quotes.php?action=delete&id=<?php echo (int)$row['id']; ?>" onclick="return confirm('Delete this post permanently?')">Delete</a>
                </td>
              </tr>
            <?php endwhile; ?>
          <?php else: ?>
            <tr>
              <td colspan="10" style="text-align:center; padding:20px;">No posts found.</td>
            </tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>

    <div class="pagination">
      <?php if ($total_pages > 1): ?>
        <?php $query_base = 'status=' . urlencode($filter_status); ?>

        <?php if ($page > 1): ?>
          <a href="manage_quotes.php?<?= $query_base ?>&page=1" class="btn">First</a>
          <a href="manage_quotes.php?<?= $query_base ?>&page=<?= $page - 1 ?>" class="btn">Previous</a>
        <?php else: ?>
          <span class="btn disabled">First</span>
          <span class="btn disabled">Previous</span>
        <?php endif; ?>

        <span class="page-info">Page <?= $page ?> of <?= $total_pages ?></span>

        <?php if ($page < $total_pages): ?>
          <a href="manage_quotes.php?<?= $query_base ?>&page=<?= $page + 1 ?>" class="btn">Next</a>
          <a href="manage_quotes.php?<?= $query_base ?>&page=<?= $total_pages ?>" class="btn">Last</a>
        <?php else: ?>
          <span class="btn disabled">Next</span>
          <span class="btn disabled">Last</span>
        <?php endif; ?>
      <?php endif; ?>
    </div>
  </section>
</div>

</body>
</html>