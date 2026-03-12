<?php
session_start();
include "db_connect.php";

// Admin only
if (!isset($_SESSION['student_role']) || $_SESSION['student_role'] !== 'admin') {
    header("Location: login.php");
    exit;
}

$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) {
    die("Invalid book ID");
}

// Fetch book
$stmt = mysqli_prepare($conn, "SELECT * FROM tbl_books WHERE id = ? LIMIT 1");
mysqli_stmt_bind_param($stmt, "i", $id);
mysqli_stmt_execute($stmt);
$res = mysqli_stmt_get_result($stmt);
$book = mysqli_fetch_assoc($res);

if (!$book) {
    die("Book not found");
}

$msg = "";
$msgType = "";

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $title            = trim($_POST['title'] ?? '');
    $author           = trim($_POST['author'] ?? '');
    $category         = trim($_POST['category'] ?? '');
    $publisher        = trim($_POST['publisher'] ?? '');
    $publication_year = trim($_POST['publication_year'] ?? '');
    $isbn             = trim($_POST['isbn'] ?? '');
    $description      = trim($_POST['description'] ?? '');
    $shelf_location   = trim($_POST['shelf_location'] ?? '');
    $qr_code          = trim($_POST['qr_code'] ?? '');
    $change_qty       = (int)($_POST['change_quantity'] ?? 0);
    $action           = $_POST['action'] ?? 'add';

    $current_qty = (int)$book['quantity'];
    $borrowed    = (int)$book['borrowed'];
    $book_cover  = $book['book_cover'];

    if ($title === '' || $author === '') {
        $msg = "Title and author are required.";
        $msgType = "error";
    } elseif ($change_qty < 0) {
        $msg = "Change quantity cannot be negative.";
        $msgType = "error";
    } elseif ($publication_year !== '' && !preg_match('/^\d{4}$/', $publication_year)) {
        $msg = "Publication year must be 4 digits only.";
        $msgType = "error";
    } else {
        if ($action === 'add') {
            $new_qty = $current_qty + $change_qty;
        } else {
            $new_qty = $current_qty - $change_qty;
        }

        if ($new_qty < $borrowed) {
            $msg = "Cannot set quantity lower than borrowed copies ({$borrowed}).";
            $msgType = "error";
        } else {
            // Optional cover upload
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
                        $msg = "Failed to upload new book cover.";
                        $msgType = "error";
                    }
                }
            }

            if ($msgType !== "error") {
                $available = $new_qty - $borrowed;
                $status = $available > 0 ? 'active' : 'unavailable';

                $update = mysqli_prepare($conn, "UPDATE tbl_books 
                    SET title=?, author=?, category=?, publisher=?, publication_year=?, isbn=?, description=?, shelf_location=?, book_cover=?, qr_code=?, quantity=?, status=?
                    WHERE id=?");

                mysqli_stmt_bind_param(
                    $update,
                    "ssssssssssisi",
                    $title,
                    $author,
                    $category,
                    $publisher,
                    $publication_year,
                    $isbn,
                    $description,
                    $shelf_location,
                    $book_cover,
                    $qr_code,
                    $new_qty,
                    $status,
                    $id
                );

                if (mysqli_stmt_execute($update)) {
                    header("Location: main_add_book.php?msg=" . urlencode("Book updated successfully!"));
                    exit;
                } else {
                    $msg = "Error updating book: " . mysqli_error($conn);
                    $msgType = "error";
                }
            }
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Edit Book</title>
<style>
body {
    font-family: Arial, sans-serif;
    background: #fff8e7;
    color: #3b3b3b;
    padding: 20px;
}

.container {
    max-width: 800px;
    margin: auto;
    background: #ffffff;
    padding: 25px;  
    border-radius: 12px;
    border: 1px solid #ddd2bc;
    box-shadow: 0 4px 10px rgba(0,0,0,0.15);
}

.form-grid {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 15px;
}

.full {
    grid-column: 1 / -1;
}

label {
    display: block;
    margin-bottom: 6px;
    font-weight: bold;
    color: #4a4035;
}

input, textarea, select, button {
    width: 100%;
    padding: 10px;
    border-radius: 8px;
    border: 1px solid #cdbb98;
    box-sizing: border-box;
}

input, textarea, select {
    background: #ffffff;
    color: #333;
}

textarea {
    min-height: 100px;
}

input[readonly] {
    background: #f7ac4a;
    color: #7a6e5c;
}

button {
    background: #b9652a;
    color: white;
    cursor: pointer;
    border: none;
}

button:hover {
    background: #a55522;
}

.message {
    padding: 12px;
    border-radius: 8px;
    margin-bottom: 15px;
    font-weight: bold;
}

.success {
    background: #e8f5e9;
    color: #2e7d32;
    border: 1px solid #b7dfba;
}

.error {
    background: #fdecea;
    color: #c62828;
    border: 1px solid #efb7b2;
}

.back {
    margin-top: 15px;
}

.back a {
    color: #b9652a;
    text-decoration: none;
    font-weight: bold;
}

.back a:hover {
    text-decoration: underline;
}

@media (max-width: 768px) {
    .form-grid {
        grid-template-columns: 1fr;
    }
}
</style>

<script>
function updateTotal() {
    let current = parseInt(document.getElementById('current_qty').value) || 0;
    let borrowed = parseInt(document.getElementById('borrowed_qty').value) || 0;
    let change = parseInt(document.getElementById('change_qty').value) || 0;
    let action = document.getElementById('action').value;
    let total = action === 'add' ? current + change : current - change;
    if (total < 0) total = 0;

    document.getElementById('total_qty').value = total;

    let available = total - borrowed;
    if (available < 0) available = 0;
    document.getElementById('available_qty').value = available;
}
</script>
</head>
<body>

<div class="container">
    <h2>Edit Book</h2>

    <?php if ($msg): ?>
        <div class="message <?= $msgType === 'error' ? 'error' : 'success' ?>">
            <?= htmlspecialchars($msg) ?>
        </div>
    <?php endif; ?>

    <form method="POST" enctype="multipart/form-data">
        <div class="form-grid">
            <div>
                <label>Title</label>
                <input type="text" name="title" value="<?= htmlspecialchars($book['title']) ?>" required>
            </div>

            <div>
                <label>Author</label>
                <input type="text" name="author" value="<?= htmlspecialchars($book['author']) ?>" required>
            </div>

            <div>
                <label>Category</label>
                <input type="text" name="category" value="<?= htmlspecialchars($book['category']) ?>">
            </div>

            <div>
                <label>Publisher</label>
                <input type="text" name="publisher" value="<?= htmlspecialchars($book['publisher']) ?>">
            </div>

            <div>
                <label>Publication Year</label>
                <input type="text" name="publication_year" maxlength="4" value="<?= htmlspecialchars($book['publication_year']) ?>">
            </div>

            <div>
                <label>ISBN</label>
                <input type="text" name="isbn" value="<?= htmlspecialchars($book['isbn']) ?>">
            </div>

            <div>
                <label>Shelf Location</label>
                <input type="text" name="shelf_location" value="<?= htmlspecialchars($book['shelf_location']) ?>">
            </div>

            <div>
                <label>QR Code Value</label>
                <input type="text" name="qr_code" value="<?= htmlspecialchars($book['qr_code']) ?>">
            </div>

            <div class="full">
                <label>Description</label>
                <textarea name="description"><?= htmlspecialchars($book['description']) ?></textarea>
            </div>

            <div>
                <label>Current Stock</label>
                <input type="number" id="current_qty" value="<?= (int)$book['quantity'] ?>" readonly>
            </div>

            <div>
                <label>Borrowed</label>
                <input type="number" id="borrowed_qty" value="<?= (int)$book['borrowed'] ?>" readonly>
            </div>

            <div>
                <label>Change Quantity</label>
                <input type="number" id="change_qty" name="change_quantity" min="0" value="0" oninput="updateTotal()" required>
            </div>

            <div>
                <label>Action</label>
                <select id="action" name="action" onchange="updateTotal()">
                    <option value="add">Add Stock</option>
                    <option value="subtract">Subtract Stock</option>
                </select>
            </div>

            <div>
                <label>Total Stock After Save</label>
                <input type="number" id="total_qty" value="<?= (int)$book['quantity'] ?>" readonly>
            </div>

            <div>
                <label>Available After Save</label>
                <input type="number" id="available_qty" value="<?= max(0, (int)$book['quantity'] - (int)$book['borrowed']) ?>" readonly>
            </div>

            <div class="full">
                <label>Replace Book Cover</label>
                <input type="file" name="book_cover" accept=".jpg,.jpeg,.png,.webp">
            </div>
        </div>

        <button type="submit">Save Changes</button>
    </form>

    <div class="back">
        <a href="main_add_book.php">← Back</a>
    </div>
</div>

<script>
updateTotal();
</script>

</body>
</html>