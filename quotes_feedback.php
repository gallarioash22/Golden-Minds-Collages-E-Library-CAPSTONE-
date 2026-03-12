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

// Fetch logged user
$user_q = mysqli_query($conn, "SELECT * FROM tbl_users WHERE id = '$user_id' LIMIT 1");
$user = mysqli_fetch_assoc($user_q);

if (!$user) {
    session_destroy();
    echo "<script>alert('Session invalid. Please login again.'); window.location='login.php';</script>";
    exit;
}

// Fetch approved posts
$posts_res = mysqli_query($conn, "
    SELECT q.*, u.full_name
    FROM tbl_quotes q
    JOIN tbl_users u ON q.user_id = u.id
    WHERE q.status = 'approved'
    ORDER BY q.post_date DESC
");
?>
<!doctype html>
<html>
<head>
  <meta charset="utf-8">
  <title>Quotes & Feedback — Golden Minds E-Library</title>
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <link rel="stylesheet" href="assets/css/style.css">
  <style>
    .post-form select,
    .post-form textarea {
      width: 100%;
      padding: 10px;
      margin-top: 10px;
      border-radius: 6px;
      border: 1px solid #ddd;
      font-family: Arial, sans-serif;
    }

    .post-form textarea {
      resize: vertical;
      min-height: 110px;
    }

    .check-row {
      margin-top: 12px;
      display: flex;
      align-items: center;
      gap: 8px;
      flex-wrap: wrap;
    }

    .message-box {
      padding: 12px;
      border-radius: 8px;
      margin-bottom: 16px;
      font-size: 14px;
    }

    .message-success {
      background: #e7f7ed;
      color: #1f6b3b;
      border: 1px solid #b8e0c8;
    }

    .message-warning {
      background: #fff4db;
      color: #8a5a00;
      border: 1px solid #f2d28b;
    }

    .message-error {
      background: #fde8e8;
      color: #9b1c1c;
      border: 1px solid #f5b5b5;
    }

    .post-badge {
      display: inline-block;
      margin-left: 8px;
      padding: 3px 8px;
      border-radius: 20px;
      font-size: 12px;
      background: #ffecbf;
      color: #6f3b3b;
      font-weight: bold;
    }

    .post-type-comment {
      background: #e8f1ff;
      color: #244c8a;
    }

    .post-meta-line {
      margin-top: 10px;
      font-size: 14px;
      color: #888;
      display: flex;
      gap: 10px;
      flex-wrap: wrap;
      align-items: center;
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
    <h3>Quotes & Feedback</h3>
    <ul class="sidebar-list">
      <li><a href="dashboard.php">Dashboard</a></li>
      <li><a href="profile.php">Profile</a></li>

      <?php if ($user_role === 'student'): ?>
        <li><a href="borrow_books.php">Borrow Books</a></li>
        <li><a href="history.php">Borrow History</a></li>
      <?php endif; ?>

      <?php if ($user_role === 'admin'): ?>
        <li><a href="main_add_book.php">Add Book</a></li>
        <li><a href="manage_roles.php">User Management</a></li>
        <li><a href="quotes_feedback.php">View Quotes & Feedback</a></li>
        <li><a href="manage_quotes.php">Manage Posts</a></li>
      <?php elseif ($user_role === 'librarian'): ?>
        <li><a href="borrow_approval.php">Borrow Approval</a></li>
        <li><a href="manage_roles.php">User Management</a></li>
        <li><a href="quotes_feedback.php">View Quotes & Feedback</a></li>
        <li><a href="manage_quotes.php">Manage Posts</a></li>
      <?php endif; ?>
    </ul>
  </aside>

  <section class="main">
    <a href="dashboard.php" class="back-link">← Back to Dashboard</a>

    <h3>Quotes & Feedback Feed</h3>

    <?php if (isset($_SESSION['post_message'])): ?>
      <?php
        $msgType = $_SESSION['post_message_type'] ?? 'success';
        $msgClass = 'message-success';

        if ($msgType === 'warning') {
            $msgClass = 'message-warning';
        } elseif ($msgType === 'error') {
            $msgClass = 'message-error';
        }
      ?>
      <div class="message-box <?php echo $msgClass; ?>">
        <?php echo htmlspecialchars($_SESSION['post_message']); ?>
      </div>
      <?php unset($_SESSION['post_message'], $_SESSION['post_message_type']); ?>
    <?php endif; ?>

    <?php if ($user_role === 'student'): ?>
      <form method="POST" action="submit_quote.php" class="post-form">
        <label for="type"><strong>Post Type</strong></label>
        <select name="type" id="type" required>
          <option value="">-- Select Type --</option>
          <option value="quote">Inspirational Quote</option>
          <option value="comment">Feedback / Comment</option>
        </select>

        <label for="content"><strong>Content</strong></label>
        <textarea
          name="content"
          id="content"
          maxlength="300"
          placeholder="Write your quote or feedback here..."
          required
        ></textarea>

        <div class="check-row">
          <input type="checkbox" name="is_anonymous" id="is_anonymous" value="1">
          <label for="is_anonymous">Post as Anonymous</label>
        </div>

        <button type="submit" name="submit_post" class="btn-primary">Submit Post</button>
      </form>

      <hr style="margin:20px 0;">
    <?php else: ?>
      <div class="message-box message-warning">
        You can view posts here. Only students are allowed to submit quotes or feedback.
      </div>
    <?php endif; ?>

    <div class="quotes-feed">
      <?php if ($posts_res && mysqli_num_rows($posts_res) > 0): ?>
        <?php while ($post = mysqli_fetch_assoc($posts_res)): ?>
          <div class="quote-card">
            <div class="quote-text"><?php echo nl2br(htmlspecialchars($post['quote'])); ?></div>

            <div class="post-meta-line">
              <span class="quote-author">
                <?php echo $post['is_anonymous'] ? 'Anonymous' : htmlspecialchars($post['full_name']); ?>
              </span>

              <span class="post-badge <?php echo ($post['type'] === 'comment') ? 'post-type-comment' : ''; ?>">
                <?php echo ucfirst(htmlspecialchars($post['type'])); ?>
              </span>

              <span>Posted on: <?php echo date('M d, Y h:i A', strtotime($post['post_date'])); ?></span>
              <span>Likes: <?php echo (int)$post['likes']; ?></span>
            </div>
          </div>
        <?php endwhile; ?>
      <?php else: ?>
        <p>No posts yet.</p>
      <?php endif; ?>
    </div>
  </section>
</div>

</body>
</html>