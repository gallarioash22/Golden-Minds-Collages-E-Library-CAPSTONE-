<?php
session_start();
include "db_connect.php";

// Admin only
if (!isset($_SESSION['student_role']) || $_SESSION['student_role'] !== 'admin') {
    header("Location: login.php");
    exit;
}

header("Cache-Control: no-cache, no-store, must-revalidate");
header("Pragma: no-cache");
header("Expires: 0");

$msg = "";
$msgType = "";
$latestQrCode = "";
$latestBookTitle = "";
$latestShelfLocation = "";
$autoPrint = false;

if (isset($_GET['msg']) && $_GET['msg'] !== '') {
    $msg = $_GET['msg'];
    $msgType = $_GET['type'] ?? 'success';
}

if (isset($_GET['qr']) && $_GET['qr'] !== '') {
    $latestQrCode = trim($_GET['qr']);
}

if (isset($_GET['title']) && $_GET['title'] !== '') {
    $latestBookTitle = trim($_GET['title']);
}

if (isset($_GET['shelf']) && $_GET['shelf'] !== '') {
    $latestShelfLocation = trim($_GET['shelf']);
}

if (isset($_GET['autoprint']) && $_GET['autoprint'] == '1') {
    $autoPrint = true;
}

// Handle add book
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_book'])) {
    $title            = trim($_POST['title'] ?? '');
    $author           = trim($_POST['author'] ?? '');
    $category         = trim($_POST['category'] ?? '');
    $publisher        = trim($_POST['publisher'] ?? '');
    $publication_year = trim($_POST['publication_year'] ?? '');
    $isbn             = trim($_POST['isbn'] ?? '');
    $description      = trim($_POST['description'] ?? '');
    $shelf_location   = trim($_POST['shelf_location'] ?? '');
    $quantity         = (int)($_POST['quantity'] ?? 0);
    $borrowed         = 0;

    if ($title === '' || $author === '') {
        $msg = "Title and author are required.";
        $msgType = "error";
    } elseif ($quantity < 1) {
        $msg = "Quantity must be at least 1.";
        $msgType = "error";
    } elseif ($publication_year !== '' && !preg_match('/^\d{4}$/', $publication_year)) {
        $msg = "Publication year must be 4 digits only.";
        $msgType = "error";
    } else {
        $status = ($quantity - $borrowed) > 0 ? 'active' : 'unavailable';
        $book_cover = null;

        if (isset($_FILES['book_cover']) && $_FILES['book_cover']['error'] === 0) {
            $allowedExt = ['jpg', 'jpeg', 'png', 'webp'];
            $fileName = $_FILES['book_cover']['name'];
            $fileTmp  = $_FILES['book_cover']['tmp_name'];
            $fileSize = $_FILES['book_cover']['size'];
            $fileExt  = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));

            if (!in_array($fileExt, $allowedExt)) {
                $msg = "Book cover must be JPG, JPEG, PNG, or WEBP only.";
                $msgType = "error";
            } elseif ($fileSize > 2 * 1024 * 1024) {
                $msg = "Book cover must not exceed 2MB.";
                $msgType = "error";
            } else {
                $uploadDir = "uploads/book_covers/";
                if (!is_dir($uploadDir)) {
                    mkdir($uploadDir, 0777, true);
                }

                $newFileName = "book_" . time() . "_" . rand(1000, 9999) . "." . $fileExt;
                $destination = $uploadDir . $newFileName;

                if (move_uploaded_file($fileTmp, $destination)) {
                    $book_cover = $destination;
                } else {
                    $msg = "Failed to upload book cover.";
                    $msgType = "error";
                }
            }
        }

        if ($msgType !== "error") {
            $check = mysqli_prepare($conn, "SELECT id FROM tbl_books WHERE title = ? AND author = ? LIMIT 1");
            mysqli_stmt_bind_param($check, "ss", $title, $author);
            mysqli_stmt_execute($check);
            $checkRes = mysqli_stmt_get_result($check);

            if (mysqli_num_rows($checkRes) > 0) {
                $msg = "This book already exists.";
                $msgType = "error";
            } else {
                mysqli_begin_transaction($conn);

                try {
                    $stmt = mysqli_prepare($conn, "INSERT INTO tbl_books 
                        (title, author, category, publisher, publication_year, isbn, description, shelf_location, book_cover, qr_code, quantity, borrowed, status)
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, '', ?, ?, ?)");

                    if (!$stmt) {
                        throw new Exception("Failed to prepare insert statement: " . mysqli_error($conn));
                    }

                    mysqli_stmt_bind_param(
                        $stmt,
                        "sssssssssiis",
                        $title,
                        $author,
                        $category,
                        $publisher,
                        $publication_year,
                        $isbn,
                        $description,
                        $shelf_location,
                        $book_cover,
                        $quantity,
                        $borrowed,
                        $status
                    );

                    if (!mysqli_stmt_execute($stmt)) {
                        throw new Exception("Error adding book: " . mysqli_stmt_error($stmt));
                    }

                    $new_book_id = mysqli_insert_id($conn);

                    if ($new_book_id <= 0) {
                        throw new Exception("Failed to get new book ID.");
                    }

                    // Auto-generate QR code value
                    $qr_code = "QR-BOOK-" . str_pad($new_book_id, 3, "0", STR_PAD_LEFT);

                    // Save generated qr_code
                    $updateStmt = mysqli_prepare($conn, "UPDATE tbl_books SET qr_code = ? WHERE id = ?");
                    if (!$updateStmt) {
                        throw new Exception("Failed to prepare QR update statement: " . mysqli_error($conn));
                    }

                    mysqli_stmt_bind_param($updateStmt, "si", $qr_code, $new_book_id);

                    if (!mysqli_stmt_execute($updateStmt)) {
                        throw new Exception("Failed to save generated QR code.");
                    }

                    mysqli_commit($conn);

                    header("Location: main_add_book.php?type=success&msg=" . urlencode("New book added successfully!") . "&qr=" . urlencode($qr_code) . "&title=" . urlencode($title) . "&shelf=" . urlencode($shelf_location));
                    exit;
                } catch (Exception $e) {
                    mysqli_rollback($conn);

                    if (!empty($book_cover) && file_exists($book_cover)) {
                        unlink($book_cover);
                    }

                    $msg = $e->getMessage();
                    $msgType = "error";
                }
            }
        }
    }
}

// Fetch books with search + pagination
$limit = 20;
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;

if ($page < 1) {
    $page = 1;
}

$search = trim($_GET['search'] ?? '');
$whereSql = "";

if ($search !== '') {
    $searchEscaped = mysqli_real_escape_string($conn, $search);
    $whereSql = "WHERE title LIKE '%$searchEscaped%' 
                 OR author LIKE '%$searchEscaped%' 
                 OR category LIKE '%$searchEscaped%' 
                 OR qr_code LIKE '%$searchEscaped%' 
                 OR shelf_location LIKE '%$searchEscaped%'";
}

// Count total books
$countQuery = mysqli_query($conn, "SELECT COUNT(*) AS total FROM tbl_books $whereSql");

$totalBooks = 0;
if ($countQuery && mysqli_num_rows($countQuery) > 0) {
    $countRow = mysqli_fetch_assoc($countQuery);
    $totalBooks = (int)$countRow['total'];
}

$totalPages = max(1, ceil($totalBooks / $limit));

if ($page > $totalPages) {
    $page = $totalPages;
}

$offset = ($page - 1) * $limit;

// Fetch paginated books
$result = mysqli_query($conn, "
    SELECT * 
    FROM tbl_books 
    $whereSql 
    ORDER BY id DESC 
    LIMIT $limit OFFSET $offset
");
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Main Add Book</title>
    <style>
    * {
        box-sizing: border-box;
    }

    body {
        margin: 0;
        font-family: Arial, sans-serif;
        background: #fff8e7;
        color: #3b3b3b;
        min-height: 100vh;
        padding: 25px 15px;
    }

    .page-wrapper {
        max-width: 1350px;
        margin: 0 auto;
    }

    .main-card {
        background: #f4efe6;
        border: 1px solid #ddd2bc;
        border-radius: 14px;
        box-shadow: 0 4px 12px rgba(0, 0, 0, 0.12);
        overflow: hidden;
    }

    .header-section {
        background: linear-gradient(90deg, #8f4a1c, #c7742a);
        padding: 22px 25px;
        display: flex;
        justify-content: space-between;
        align-items: center;
        gap: 15px;
        flex-wrap: wrap;
    }

    .header-left h1 {
        margin: 0;
        font-size: 28px;
        color: #ffffff;
    }

    .header-left p {
        margin: 6px 0 0;
        color: #fff3e6;
        font-size: 14px;
    }

    .back-btn {
        display: inline-block;
        text-decoration: none;
        background: rgba(255,255,255,0.12);
        color: #ffffff;
        padding: 10px 16px;
        border-radius: 8px;
        font-weight: bold;
        transition: 0.2s ease;
        border: none;
    }

    .back-btn:hover {
        background: rgba(255,255,255,0.2);
    }

    .content {
        padding: 25px;
    }

    .section-title {
        margin: 0 0 18px;
        font-size: 20px;
        color: #3b3b3b;
        border-left: 5px solid #c7742a;
        padding-left: 12px;
    }

    .message {
        padding: 14px 16px;
        border-radius: 10px;
        margin-bottom: 20px;
        font-size: 14px;
        font-weight: bold;
    }

    .message.success {
        background: #e8f5e9;
        color: #2e7d32;
        border: 1px solid #b7dfba;
    }

    .message.error {
        background: #fdecea;
        color: #c62828;
        border: 1px solid #efb7b2;
    }

    .form-card {
        background: #f8f5ef;
        border: 1px solid #ddd2bc;
        border-radius: 12px;
        padding: 22px;
        margin-bottom: 28px;
    }

    .form-grid {
        display: grid;
        grid-template-columns: repeat(2, 1fr);
        gap: 18px;
    }

    .form-group.full {
        grid-column: 1 / -1;
    }

    .form-group label {
        display: block;
        margin-bottom: 8px;
        font-size: 14px;
        font-weight: bold;
        color: #4a4035;
    }

    .form-group input,
    .form-group textarea,
    .form-group select {
        width: 100%;
        padding: 12px 14px;
        border-radius: 8px;
        border: 1px solid #cdbb98;
        background: #ffffff;
        color: #333333;
        outline: none;
        transition: 0.2s ease;
    }

    .form-group input:focus,
    .form-group textarea:focus,
    .form-group select:focus {
        border-color: #c7742a;
        box-shadow: 0 0 0 3px rgba(199, 116, 42, 0.15);
    }

    .form-group textarea {
        resize: vertical;
        min-height: 110px;
    }

    .submit-btn {
        margin-top: 20px;
        width: 100%;
        padding: 14px;
        border: none;
        border-radius: 8px;
        background: #b9652a;
        color: #ffffff;
        font-size: 15px;
        font-weight: bold;
        cursor: pointer;
        transition: 0.2s ease;
    }

    .submit-btn:hover {
        background: #a55522;
    }

    .table-card {
        background: #f8f5ef;
        border: 1px solid #ddd2bc;
        border-radius: 12px;
        padding: 20px;
    }

    .search-form {
        margin-bottom: 18px;
        display: flex;
        gap: 10px;
        flex-wrap: wrap;
    }

    .search-input {
        flex: 1;
        min-width: 260px;
        padding: 12px 14px;
        border: 1px solid #cdbb98;
        border-radius: 8px;
        background: #fff;
    }

    .search-btn,
    .clear-btn {
        padding: 12px 18px;
        border: none;
        border-radius: 8px;
        font-weight: bold;
        cursor: pointer;
        text-decoration: none;
        display: inline-block;
    }

    .search-btn {
        background: #b9652a;
        color: #fff;
    }

    .search-btn:hover {
        background: #a55522;
    }

    .clear-btn {
        background: #777;
        color: #fff;
    }

    .clear-btn:hover {
        background: #666;
    }

    .table-wrap {
        overflow-x: auto;
        width: 100%;
    }

    table {
        width: 100%;
        min-width: 1200px;
        border-collapse: collapse;
    }

    thead th {
        background: #f0c060;
        color: #3b3b3b;
        font-size: 13px;
        padding: 12px 10px;
        text-align: center;
        border: 1px solid #d7c7a8;
        white-space: nowrap;
    }

    tbody td {
        padding: 12px 10px;
        text-align: center;
        border: 1px solid #e0d3ba;
        color: #3b3b3b;
        font-size: 14px;
        background: #fdfbf7;
    }

    tbody tr:hover td {
        background: #f8edd8;
    }

    .cover-img {
        width: 58px;
        height: 78px;
        object-fit: cover;
        border-radius: 6px;
        border: 1px solid #cdbb98;
    }

    .no-cover {
        color: #8a7d6b;
        font-size: 12px;
    }

    .status-active {
        color: #2e7d32;
        font-weight: bold;
    }

    .status-unavailable {
        color: #c62828;
        font-weight: bold;
    }

    .action-links a {
        text-decoration: none;
        font-weight: bold;
        margin: 0 4px;
    }

    .edit-link {
        color: #b9652a;
    }

    .delete-link {
        color: #c62828;
    }

    .edit-link:hover,
    .delete-link:hover {
        text-decoration: underline;
    }

    .empty-row {
        color: #8a7d6b;
        font-style: italic;
    }

    .qr-preview-box {
        max-width: 420px;
        margin: 0 auto 24px;
        text-align: center;
        padding: 18px;
    }

    .qr-preview-title {
        margin: 0 0 10px;
        font-size: 18px;
        color: #3b3b3b;
        font-weight: bold;
    }

    .qr-preview-subtext {
        margin: 4px 0;
        font-size: 13px;
        color: #5c5144;
        word-break: break-word;
    }

    .qr-preview-img {
        width: 150px;
        height: 150px;
        object-fit: contain;
        border: 1px solid #d8ccb8;
        padding: 6px;
        background: #fff;
        border-radius: 10px;
        margin: 12px auto;
        display: block;
    }

    .qr-action-row {
        display: flex;
        justify-content: center;
        gap: 10px;
        flex-wrap: wrap;
        margin-top: 12px;
    }

    .download-btn,
    .view-qr-btn,
    .print-btn {
        display: inline-block;
        text-decoration: none;
        background: #b9652a;
        color: #fff;
        padding: 8px 14px;
        border-radius: 8px;
        font-weight: bold;
        font-size: 12px;
        border: none;
        cursor: pointer;
    }

    .download-btn:hover,
    .view-qr-btn:hover,
    .print-btn:hover {
        background: #a55522;
    }

    .qr-cell {
        display: flex;
        flex-direction: column;
        gap: 8px;
        align-items: center;
    }

    .print-label-brand {
        font-size: 12px;
        color: #8f4a1c;
        font-weight: bold;
        margin-bottom: 6px;
    }

    .print-label-book {
        font-size: 18px;
        font-weight: bold;
        color: #2f2f2f;
        margin-bottom: 10px;
        word-break: break-word;
    }

    .print-label-code {
        font-size: 14px;
        font-weight: bold;
        color: #444;
        margin-top: 8px;
        letter-spacing: 0.5px;
        word-break: break-word;
    }

    .print-label-shelf {
        font-size: 13px;
        color: #5b4a39;
        font-weight: bold;
        margin-bottom: 10px;
        word-break: break-word;
    }

    @media (max-width: 900px) {
        .form-grid {
            grid-template-columns: 1fr;
        }

        .header-left h1 {
            font-size: 23px;
        }

        .content {
            padding: 18px;
        }

        .form-card,
        .table-card {
            padding: 15px;
        }
    }

    @media (max-width: 480px) {
        body {
            padding: 12px;
        }

        .header-section {
            padding: 18px;
        }

        .header-left h1 {
            font-size: 20px;
        }

        .back-btn {
            width: 100%;
            text-align: center;
        }
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

    @media print {
        @page {
            size: auto;
            margin: 10mm;
        }

        body * {
            visibility: hidden !important;
        }

        #printableQrLabel,
        #printableQrLabel * {
            visibility: visible !important;
        }

        #printableQrLabel {
            position: absolute;
            left: 50%;
            top: 20px;
            transform: translateX(-50%);
            width: 280px;
            max-width: 280px;
            margin: 0;
            padding: 18px 16px;
            background: #ffffff !important;
            border: 1px solid #000;
            border-radius: 10px;
            box-shadow: none !important;
        }

        .no-print,
        .qr-action-row,
        .back-btn,
        .submit-btn,
        .table-card,
        .header-section,
        .message,
        .form-card:not(#printableQrLabel) {
            display: none !important;
        }

        .qr-preview-title {
            font-size: 18px;
            margin-bottom: 8px;
            color: #000 !important;
        }

        .print-label-brand {
            font-size: 12px;
            color: #000 !important;
            margin-bottom: 6px;
        }

        .print-label-book {
            font-size: 18px;
            color: #000 !important;
            margin-bottom: 10px;
        }

        .qr-preview-img {
            width: 180px;
            height: 180px;
            margin: 10px auto;
            border: 1px solid #000;
            padding: 4px;
            background: #fff !important;
        }

        .print-label-code {
            font-size: 13px;
            color: #000 !important;
            margin-top: 8px;
        }

        .print-label-shelf {
            font-size: 13px;
            color: #5b4a39;
            font-weight: bold;
            margin-bottom: 10px;
            word-break: break-word;
        }
    }
    </style>
</head>
<body>

<div class="page-wrapper">
    <div class="main-card">
        <div class="header-section">
            <div class="header-left">
                <h1>Add New Book</h1>
                <p>Welcome, <?= htmlspecialchars($_SESSION['student_name'] ?? 'Admin') ?></p>
            </div>
            <a href="dashboard.php" class="back-btn">← Back to Dashboard</a>
        </div>

        <div class="content">
            <?php if ($msg): ?>
                <div class="message <?= htmlspecialchars($msgType) ?>">
                    <?= htmlspecialchars($msg) ?>
                </div>
            <?php endif; ?>

            <?php if (!empty($latestQrCode)): ?>
                <div class="form-card qr-preview-box" id="printableQrLabel">
                    <div class="qr-preview-title">Generated QR Code</div>

                    <div class="print-label-brand">Golden Minds E-Library</div>
                    <div class="print-label-book"><?= htmlspecialchars($latestBookTitle) ?></div>

                    <?php if (!empty($latestShelfLocation)): ?>
                        <div class="print-label-shelf">Shelf: <?= htmlspecialchars($latestShelfLocation) ?></div>
                    <?php endif; ?>

                    <img 
                        src="https://api.qrserver.com/v1/create-qr-code/?size=220x220&data=<?= urlencode($latestQrCode) ?>" 
                        alt="Generated QR Code"
                        class="qr-preview-img"
                    >

                    <div class="print-label-code"><?= htmlspecialchars($latestQrCode) ?></div>

                    <div class="qr-action-row no-print">
                        <a 
                            href="https://api.qrserver.com/v1/create-qr-code/?size=500x500&data=<?= urlencode($latestQrCode) ?>" 
                            target="_blank"
                            class="download-btn"
                        >
                            Download QR
                        </a>

                        <a 
                            href="javascript:void(0);" 
                            onclick="printQRCodeOnly()"
                            class="print-btn"
                        >
                            Print QR
                        </a>
                    </div>
                </div>
            <?php endif; ?>

            <div class="form-card">
                <h2 class="section-title">Book Information</h2>

                <form method="POST" enctype="multipart/form-data">
                    <div class="form-grid">
                        <div class="form-group">
                            <label>Book Title</label>
                            <input type="text" name="title" required>
                        </div>

                        <div class="form-group">
                            <label>Author</label>
                            <input type="text" name="author" required>
                        </div>

                        <div class="form-group">
                            <label>Category</label>
                            <input type="text" name="category" placeholder="e.g. Fiction, Science, Math">
                        </div>

                        <div class="form-group">
                            <label>Publisher</label>
                            <input type="text" name="publisher">
                        </div>

                        <div class="form-group">
                            <label>Publication Year</label>
                            <input type="text" name="publication_year" maxlength="4" placeholder="e.g. 2024">
                        </div>

                        <div class="form-group">
                            <label>ISBN</label>
                            <input type="text" name="isbn" placeholder="Optional ISBN">
                        </div>

                        <div class="form-group">
                            <label>Shelf Location</label>
                            <input type="text" name="shelf_location" placeholder="e.g. Shelf A-2">
                        </div>

                        <div class="form-group">
                            <label>Quantity</label>
                            <input type="number" name="quantity" min="1" required>
                        </div>

                        <div class="form-group">
                            <label>Book Cover</label>
                            <input type="file" name="book_cover" accept=".jpg,.jpeg,.png,.webp">
                        </div>

                        <div class="form-group full">
                            <label>Description</label>
                            <textarea name="description" placeholder="Enter book description..."></textarea>
                        </div>
                    </div>

                    <button type="submit" name="add_book" class="submit-btn">Add Book</button>
                </form>
            </div>

            <div class="table-card">
                <h2 class="section-title">Current Books</h2>

                <form method="GET" class="search-form">
                    <input 
                        type="text" 
                        name="search" 
                        class="search-input"
                        placeholder="Search by title, author, category, QR code, or shelf..."
                        value="<?= htmlspecialchars($search) ?>"
                    >

                    <button type="submit" class="search-btn">Search</button>

                    <?php if ($search !== ''): ?>
                        <a href="main_add_book.php" class="clear-btn">Clear</a>
                    <?php endif; ?>
                </form>

                <div class="table-wrap">
                    <table>
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>Cover</th>
                                <th>Title</th>
                                <th>Author</th>
                                <th>Category</th>
                                <th>Year</th>
                                <th>ISBN</th>
                                <th>Shelf</th>
                                <th>QR</th>
                                <th>Qty</th>
                                <th>Borrowed</th>
                                <th>Available</th>
                                <th>Status</th>
                                <th>Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if ($result && mysqli_num_rows($result) > 0): ?>
                                <?php while ($row = mysqli_fetch_assoc($result)): ?>
                                    <?php
                                        $available = (int)$row['quantity'] - (int)$row['borrowed'];
                                        if ($available < 0) $available = 0;
                                    ?>
                                    <tr>
                                        <td><?= (int)$row['id'] ?></td>
                                        <td>
                                            <?php if (!empty($row['book_cover'])): ?>
                                                <img src="<?= htmlspecialchars($row['book_cover']) ?>" alt="Book Cover" class="cover-img">
                                            <?php else: ?>
                                                <span class="no-cover">No Cover</span>
                                            <?php endif; ?>
                                        </td>
                                        <td><?= htmlspecialchars($row['title']) ?></td>
                                        <td><?= htmlspecialchars($row['author']) ?></td>
                                        <td><?= htmlspecialchars($row['category']) ?></td>
                                        <td><?= htmlspecialchars($row['publication_year']) ?></td>
                                        <td><?= htmlspecialchars($row['isbn']) ?></td>
                                        <td><?= htmlspecialchars($row['shelf_location']) ?></td>
                                        <td>
                                            <div class="qr-cell">
                                                <div><?= htmlspecialchars($row['qr_code']) ?></div>

                                                <?php if (!empty($row['qr_code'])): ?>
                                                    <a 
                                                        href="main_add_book.php?page=<?= $page ?>&search=<?= urlencode($search) ?>&qr=<?= urlencode($row['qr_code']) ?>&title=<?= urlencode($row['title']) ?>&shelf=<?= urlencode($row['shelf_location']) ?>" 
                                                        class="view-qr-btn"
                                                    >
                                                        View QR
                                                    </a>

                                                    <a 
                                                        href="https://api.qrserver.com/v1/create-qr-code/?size=500x500&data=<?= urlencode($row['qr_code']) ?>" 
                                                        target="_blank"
                                                        class="view-qr-btn"
                                                    >
                                                        Download
                                                    </a>

                                                    <a 
                                                        href="main_add_book.php?page=<?= $page ?>&search=<?= urlencode($search) ?>&qr=<?= urlencode($row['qr_code']) ?>&title=<?= urlencode($row['title']) ?>&shelf=<?= urlencode($row['shelf_location']) ?>&autoprint=1" 
                                                        class="view-qr-btn"
                                                    >
                                                        Print
                                                    </a>
                                                <?php else: ?>
                                                    <span class="no-cover">No QR</span>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                        <td><?= (int)$row['quantity'] ?></td>
                                        <td><?= (int)$row['borrowed'] ?></td>
                                        <td><?= $available ?></td>
                                        <td class="<?= $row['status'] === 'active' ? 'status-active' : 'status-unavailable' ?>">
                                            <?= htmlspecialchars($row['status']) ?>
                                        </td>
                                        <td class="action-links">
                                            <a href="main_edit_book.php?id=<?= (int)$row['id'] ?>&page=<?= $page ?>&search=<?= urlencode($search) ?>" class="edit-link">Edit</a> |
                                            <a href="main_delete_book.php?id=<?= (int)$row['id'] ?>&page=<?= $page ?>&search=<?= urlencode($search) ?>" class="delete-link" onclick="return confirm('Delete this book?')">Delete</a>
                                        </td>
                                    </tr>
                                <?php endwhile; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="14" class="empty-row">
                                        <?= ($search !== '') ? 'No books matched your search.' : 'No books found.' ?>
                                    </td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>

                    <div class="pagination">
    <?php if ($totalPages > 1): ?>
        <?php $queryBase = 'search=' . urlencode($search); ?>

        <?php if ($page > 1): ?>
            <a href="main_add_book.php?<?= $queryBase ?>&page=1">First</a>
            <a href="main_add_book.php?<?= $queryBase ?>&page=<?= $page - 1 ?>">Previous</a>
        <?php else: ?>
            <a class="disabled">First</a>
            <a class="disabled">Previous</a>
        <?php endif; ?>

        <span class="page-info">Page <?= $page ?> of <?= $totalPages ?></span>

        <?php if ($page < $totalPages): ?>
            <a href="main_add_book.php?<?= $queryBase ?>&page=<?= $page + 1 ?>">Next</a>
            <a href="main_add_book.php?<?= $queryBase ?>&page=<?= $totalPages ?>">Last</a>
        <?php else: ?>
            <a class="disabled">Next</a>
            <a class="disabled">Last</a>
        <?php endif; ?>
    <?php endif; ?>
</div>
                </div>
            </div>

        </div>
    </div>
</div>

<script>
function printQRCodeOnly() {
    window.print();
}

<?php if ($autoPrint && !empty($latestQrCode)): ?>
window.onload = function () {
    setTimeout(function () {
        printQRCodeOnly();
    }, 400);
};
<?php endif; ?>
</script>

</body>
</html> 