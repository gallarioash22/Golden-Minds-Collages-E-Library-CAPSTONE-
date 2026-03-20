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
$books_per_page = 10;
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
if ($page < 1) {
    $page = 1;
}

// Search functionality
$search_query = '';
if (isset($_GET['search'])) {
    $search_query = trim($_GET['search']);
}
$safe_search = mysqli_real_escape_string($conn, $search_query);

// Load student's active borrow/request records
$student_active_books = [];

$active_books_res = mysqli_query($conn, "
    SELECT book_id, status
    FROM tbl_borrowed_records
    WHERE user_id = '$user_id'
      AND status IN ('pending', 'borrowed')
");

if ($active_books_res) {
    while ($row = mysqli_fetch_assoc($active_books_res)) {
        $student_active_books[(int)$row['book_id']] = $row['status'];
    }
}

// Handle borrow action
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && isset($_POST['book_id'])) {
    $book_id = (int)$_POST['book_id'];
    $action = $_POST['action'];

    if ($action === 'borrow') {
        // Check if the student already has an active request/borrow for this same book
        $existing_check = mysqli_query($conn, "
            SELECT id, status
            FROM tbl_borrowed_records
            WHERE user_id = '$user_id'
              AND book_id = '$book_id'
              AND status IN ('pending', 'borrowed')
            LIMIT 1
        ");

        if ($existing_check && mysqli_num_rows($existing_check) > 0) {
            $existing = mysqli_fetch_assoc($existing_check);

            if ($existing['status'] === 'pending') {
                echo "<script>
                    alert('You already have a pending request for this book. Please wait for librarian approval.');
                    window.location='borrow_books.php';
                </script>";
                exit;
            }

            if ($existing['status'] === 'borrowed') {
                echo "<script>
                    alert('You already borrowed this book and have not returned it yet.');
                    window.location='borrow_books.php';
                </script>";
                exit;
            }
        }

        // Check if the book is available
        $book_res = mysqli_query($conn, "
            SELECT id, title, quantity, status
            FROM tbl_books
            WHERE id = '$book_id'
            LIMIT 1
        ");
        $book = $book_res ? mysqli_fetch_assoc($book_res) : null;

        if ($book && (int)$book['quantity'] > 0 && $book['status'] === 'active') {
            $insert_res = mysqli_query($conn, "
                INSERT INTO tbl_borrowed_records (user_id, book_id, borrow_date, status)
                VALUES ('$user_id', '$book_id', NOW(), 'pending')
            ");

            if ($insert_res) {
                echo "<script>
                    alert('Your book request was added successfully! Please go to the librarian for approval/get your book.');
                    window.location='borrow_books.php';
                </script>";
                exit;
            } else {
                echo "<script>
                    alert('Failed to submit borrow request.');
                    window.location='borrow_books.php';
                </script>";
                exit;
            }
        } else {
            echo "<script>
                alert('This book is not available for borrowing right now.');
                window.location='borrow_books.php';
            </script>";
            exit;
        }
    }
}

// Get the total number of books for pagination FIRST
$total_books_res = mysqli_query($conn, "
    SELECT COUNT(*) AS total
    FROM tbl_books
    WHERE status = 'active'
      AND (
            title LIKE '%$safe_search%'
            OR author LIKE '%$safe_search%'
            OR category LIKE '%$safe_search%'
            OR publisher LIKE '%$safe_search%'
            OR isbn LIKE '%$safe_search%'
            OR shelf_location LIKE '%$safe_search%'
          )
");

$total_books = 0;
if ($total_books_res && mysqli_num_rows($total_books_res) > 0) {
    $total_row = mysqli_fetch_assoc($total_books_res);
    $total_books = (int)$total_row['total'];
}

$total_pages = max(1, ceil($total_books / $books_per_page));

if ($page > $total_pages) {
    $page = $total_pages;
}

$offset = ($page - 1) * $books_per_page;

// Fetch available books with pagination and search
$books_res = mysqli_query($conn, "
    SELECT 
        id,
        title,
        author,
        category,
        publisher,
        publication_year,
        isbn,
        description,
        shelf_location,
        book_cover,
        qr_code,
        qr_image,
        quantity,
        borrowed,
        status,
        created_at,
        quantity AS available
    FROM tbl_books
    WHERE status = 'active'
      AND (
            title LIKE '%$safe_search%'
            OR author LIKE '%$safe_search%'
            OR category LIKE '%$safe_search%'
            OR publisher LIKE '%$safe_search%'
            OR isbn LIKE '%$safe_search%'
            OR shelf_location LIKE '%$safe_search%'
          )
    ORDER BY title ASC
    LIMIT $books_per_page OFFSET $offset
");

// Fetch borrowed records of this user
$borrowed_res = mysqli_query($conn, "
    SELECT b.title, b.author, r.borrow_date, r.return_date, r.status
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
    .table-wrap { width: 100%; overflow-x: auto; margin-top: 12px; }
    table { width: 100%; min-width: 1200px; border-collapse: collapse; }
    th, td { padding: 10px; border: 1px solid #eee; text-align: left; vertical-align: top; }
    th { background: var(--accent); color: #333; }

    .search-row {
        display: flex;
        gap: 10px;
        align-items: center;
        flex-wrap: wrap;
        margin-bottom: 12px;
    }

    .search-input {
        flex: 1;
        min-width: 220px;
        padding: 10px;
        border-radius: 6px;
        border: 1px solid #ddd;
    }

    .action-form { margin: 0; }

    .borrow-list {
        list-style: none;
        padding-left: 0;
        margin: 0;
    }

    .borrow-list li {
        background: #fff8e7;
        padding: 10px;
        border-radius: 8px;
        margin-bottom: 10px;
    }

    .book-cover {
        width: 70px;
        height: 90px;
        object-fit: cover;
        border-radius: 6px;
        border: 1px solid #ddd;
        background: #fafafa;
    }

    .no-cover {
        width: 70px;
        height: 90px;
        border-radius: 6px;
        border: 1px solid #ddd;
        background: #f5f5f5;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 12px;
        color: #777;
        text-align: center;
        padding: 6px;
    }

    .desc-box {
        max-width: 260px;
        white-space: normal;
        line-height: 1.4;
        color: #444;
    }

    .status-pill {
        display: inline-block;
        padding: 5px 10px;
        border-radius: 999px;
        font-size: 12px;
        font-weight: bold;
        text-transform: capitalize;
    }

    .status-active {
        background: #d4edda;
        color: #155724;
    }

    .status-unavailable {
        background: #f8d7da;
        color: #721c24;
    }

    .muted {
        color: #777;
        font-size: 13px;
    }

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

    .empty-row {
        text-align: center;
        color: #777;
        padding: 18px;
    }

    @media (max-width: 768px) {
        .search-row .btn-primary {
            width: 100%;
        }

        .search-input {
            width: 100%;
            min-width: 0;
        }
    }
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

        <form method="GET" action="borrow_books.php" class="search-row">
            <input
                class="search-input"
                type="text"
                name="search"
                placeholder="Search by title, author, category, publisher, ISBN, or shelf location..."
                value="<?= htmlspecialchars($search_query) ?>"
            >
            <button type="submit" class="btn-primary">Search</button>
        </form>

        <div class="table-wrap">
            <table>
                <thead>
                    <tr>
                        <th>Cover</th>
                        <th>Title</th>
                        <th>Author</th>
                        <th>Category</th>
                        <th>Publisher</th>
                        <th>Year</th>
                        <th>ISBN</th>
                        <th>Description</th>
                        <th>Shelf</th>
                        <th>Available</th>
                        <th>Borrowed</th>
                        <th>Status</th>
                        <th>Action</th>
                    </tr>
                </thead>

                <tbody>
                    <?php if ($books_res && mysqli_num_rows($books_res) > 0): ?>
                        <?php while ($book = mysqli_fetch_assoc($books_res)): ?>
                            <tr>
                                <td>
                                    <?php if (!empty($book['book_cover'])): ?>
                                        <img src="<?= htmlspecialchars($book['book_cover']) ?>" alt="Book Cover" class="book-cover">
                                    <?php else: ?>
                                        <div class="no-cover">No Cover</div>
                                    <?php endif; ?>
                                </td>

                                <td><?= htmlspecialchars($book['title']) ?></td>
                                <td><?= htmlspecialchars($book['author']) ?></td>
                                <td><?= htmlspecialchars($book['category'] ?? '') ?></td>
                                <td><?= htmlspecialchars($book['publisher'] ?? '') ?></td>
                                <td><?= htmlspecialchars($book['publication_year'] ?? '') ?></td>
                                <td><?= htmlspecialchars($book['isbn'] ?? '') ?></td>

                                <td>
                                    <div class="desc-box">
                                        <?= !empty($book['description']) ? nl2br(htmlspecialchars($book['description'])) : '<span class="muted">No description</span>' ?>
                                    </div>
                                </td>

                                <td><?= htmlspecialchars($book['shelf_location'] ?? '') ?></td>
                                <td><?= (int)$book['available'] ?></td>
                                <td><?= (int)$book['borrowed'] ?></td>

                                <td>
                                    <span class="status-pill <?= $book['status'] === 'active' ? 'status-active' : 'status-unavailable' ?>">
                                        <?= htmlspecialchars($book['status']) ?>
                                    </span>
                                </td>

                                <td>
                                    <?php
                                    $current_book_id = (int)$book['id'];
                                    $active_status = $student_active_books[$current_book_id] ?? null;
                                    ?>
                                
                                    <?php if ($active_status === 'pending'): ?>
                                        <span class="muted" style="font-weight:bold; color:#b26a00;">Already Requested</span>
                                    
                                    <?php elseif ($active_status === 'borrowed'): ?>
                                        <span class="muted" style="font-weight:bold; color:#155724;">Already Borrowed</span>
                                    
                                    <?php elseif ((int)$book['available'] > 0 && $book['status'] === 'active'): ?>
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
                    <?php else: ?>
                        <tr>
                            <td colspan="14" class="empty-row">No books found.</td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

<div class="pagination">
    <?php if ($total_pages > 1): ?>

        <?php if ($page > 1): ?>
            <a href="borrow_books.php?page=1&search=<?= urlencode($search_query) ?>">First</a>
            <a href="borrow_books.php?page=<?= $page - 1 ?>&search=<?= urlencode($search_query) ?>">Previous</a>
        <?php else: ?>
            <a class="disabled">First</a>
            <a class="disabled">Previous</a>
        <?php endif; ?>

        <span class="page-info">Page <?= $page ?> of <?= $total_pages ?></span>

        <?php if ($page < $total_pages): ?>
            <a href="borrow_books.php?page=<?= $page + 1 ?>&search=<?= urlencode($search_query) ?>">Next</a>
            <a href="borrow_books.php?page=<?= $total_pages ?>&search=<?= urlencode($search_query) ?>">Last</a>
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