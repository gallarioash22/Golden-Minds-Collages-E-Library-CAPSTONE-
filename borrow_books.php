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

$user_id = $_SESSION['student_id'];

// Pagination Setup
$books_per_page = 10; // Number of books to display per page
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$offset = ($page - 1) * $books_per_page;

// Search functionality
$search_query = '';
if (isset($_GET['search'])) {
    $search_query = mysqli_real_escape_string($conn, $_GET['search']);
}

// handle borrow action
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && isset($_POST['book_id'])) {
    $book_id = (int)$_POST['book_id'];
    $action = $_POST['action'];

    if ($action === 'borrow') {
        // Borrow book: now only insert into tbl_borrowed_records
        $book = mysqli_fetch_assoc(mysqli_query($conn, "SELECT * FROM tbl_books WHERE id='$book_id'"));

        if ($book && $book['quantity'] > 0) {
            // Insert borrow record into tbl_borrowed_records (the quantity reduction is handled in borrow_approval.php)
            mysqli_query($conn, "INSERT INTO tbl_borrowed_records (user_id, book_id, borrow_date) VALUES ('$user_id', '$book_id', NOW())");
        }
    }
    echo "<script>
                alert('Your Book is Successfully Added 🛒! You may go the librarian to approve/get your book 📚!');
                window.location='borrow_books.php';
            </script>";
    exit;
}

// Fetch available books with pagination and search
$books_res = mysqli_query($conn, "
    SELECT id, title, author, quantity, borrowed, (quantity) AS available
    FROM tbl_books
    WHERE title LIKE '%$search_query%' OR author LIKE '%$search_query%'
    ORDER BY title ASC
    LIMIT $books_per_page OFFSET $offset
");

// Get the total number of books for pagination
$total_books_res = mysqli_query($conn, "
    SELECT COUNT(*) as total FROM tbl_books
    WHERE title LIKE '%$search_query%' OR author LIKE '%$search_query%'
");
$total_books = mysqli_fetch_assoc($total_books_res)['total'];
$total_pages = ceil($total_books / $books_per_page);

// Fetch borrowed records of this user
$borrowed_res = mysqli_query($conn, "
    SELECT b.title, b.author, r.borrow_date, r.return_date
    FROM tbl_borrowed_records r
    JOIN tbl_books b ON b.id = r.book_id
    WHERE r.user_id = '{$user_id}'
    ORDER BY r.borrow_date DESC
    LIMIT 5
");

?>

<!doctype html>
<html>
<head>
    <meta charset="utf-8">
    <title>Borrow Books — Golden Minds E-Library</title>

    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="stylesheet" href="assets/css/style.css">

    <style>
    /* Page-only helpers (safe even with your style.css) */
    .table-wrap { width: 100%; overflow-x: auto; margin-top: 12px; }
    table { width: 100%; min-width: 520px; border-collapse: collapse; }
    th, td { padding: 10px; border: 1px solid #eee; text-align: left; }
    th { background: var(--accent); color: #333; }

    .search-row { display: flex; gap: 10px; align-items: center; flex-wrap: wrap; }
    .search-input { flex: 1; min-width: 220px; padding: 10px; border-radius: 6px; border: 1px solid #ddd; }

    /* Make the small form inside table not look cramped */
    .action-form { margin: 0; }

    /* On very small screens, make the search button full width */
    @media (max-width: 480px) {
    .search-row .btn { width: 100%; }
    .search-input { width: 100%; min-width: 0; }
    }

    /* Optional: borrow list cards a bit cleaner */
    .borrow-list { list-style: none; padding-left: 0; margin: 0; }
    .borrow-list li { background: #fff8e7; padding: 10px; border-radius: 8px; margin-bottom: 10px; }
    </style>
</head>

<body>

  <header class="topbar">
    <div class="topbar-left">
    <h1 class="topbar-title">Golden Minds E-Library</h1>
    </div>
    <div class="topbar-right">
    <a href="dashboard.php" class="logout-btn">Back to Dashboard</a>
    </div>
  </header>

  <div class="wrap">

    <section class="main">
    <h2>Available Books</h2>

    <!-- Search Bar -->
    <form method="GET" action="borrow_books.php" class="search-row">
        <input class="search-input" type="text" name="search" placeholder="Search books by title or author..." value="<?= htmlspecialchars($search_query) ?>">
        <button type="submit" class="btn-primary">Search</button>
    </form>

    <div class="table-wrap">
        <table>
        <thead>
            <tr>
            <th>Title</th>
            <th>Author</th>
            <th>Available</th>
            <th>Action</th>
            </tr>
        </thead>

        <tbody>
            <?php while ($book = mysqli_fetch_assoc($books_res)): ?>
            <tr>
                <td><?= htmlspecialchars($book['title']) ?></td>
                <td><?= htmlspecialchars($book['author']) ?></td>
                <td><?= (int)$book['available'] ?></td>
                <td>
                <?php if ((int)$book['available'] > 0): ?>
                    <form method="POST" action="borrow_books.php" class="action-form">
                    <input type="hidden" name="book_id" value="<?= (int)$book['id'] ?>">
                    <button type="submit" name="action" value="borrow" class="btn-primary">Borrow</button>
                    </form>
                <?php else: ?>
                    <span class="muted">Not Available</span>
                <?php endif; ?>
                </td>
            </tr>
            <?php endwhile; ?>
        </tbody>
        </table>
    </div>

    <!-- Pagination -->
    <div class="pagination">
        <?php if ($page > 1): ?>
        <a href="borrow_books.php?page=<?= $page - 1 ?>&search=<?= urlencode($search_query) ?>" class="page-link">Previous</a>
        <?php endif; ?>

        <?php if ($page < $total_pages): ?>
        <a href="borrow_books.php?page=<?= $page + 1 ?>&search=<?= urlencode($search_query) ?>" class="page-link">Next</a>
        <?php endif; ?>
    </div>

    </section>

    <aside class="sidebar">
    <h3>My Borrowed Books</h3>

    <ul class="borrow-list">
        <?php while ($borrowed = mysqli_fetch_assoc($borrowed_res)): ?>
        <li>
            <strong><?= htmlspecialchars($borrowed['title']) ?></strong>
            by <?= htmlspecialchars($borrowed['author']) ?><br>
            Borrowed: <?= date('M d, Y', strtotime($borrowed['borrow_date'])) ?><br>
            Returned: <?= $borrowed['return_date'] ? 'Yes' : 'No' ?>
        </li>
        <?php endwhile; ?>
    </ul>
    </aside>

</div>

</body>
</html>