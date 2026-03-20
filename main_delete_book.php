<?php
session_start();
include "db_connect.php";

// Admin only
if (!isset($_SESSION['student_role']) || $_SESSION['student_role'] !== 'admin') {
    header("Location: login.php");
    exit;
}

$id = (int)($_GET['id'] ?? 0);
$page = (int)($_GET['page'] ?? 1);
$search = trim($_GET['search'] ?? '');

if ($page < 1) {
    $page = 1;
}

$backUrl = "main_add_book.php?page=" . $page . "&search=" . urlencode($search);

if ($id <= 0) {
    header("Location: " . $backUrl . "&type=error&msg=" . urlencode("Invalid book ID."));
    exit;
}

// Optional: fetch cover so we can delete file too
$stmt = mysqli_prepare($conn, "SELECT book_cover FROM tbl_books WHERE id = ? LIMIT 1");
mysqli_stmt_bind_param($stmt, "i", $id);
mysqli_stmt_execute($stmt);
$res = mysqli_stmt_get_result($stmt);
$book = mysqli_fetch_assoc($res);

if (!$book) {
    header("Location: " . $backUrl . "&type=error&msg=" . urlencode("Book not found."));
    exit;
}

// Optional protection: prevent deletion if borrowed > 0
$checkBorrowed = mysqli_prepare($conn, "SELECT borrowed FROM tbl_books WHERE id = ? LIMIT 1");
mysqli_stmt_bind_param($checkBorrowed, "i", $id);
mysqli_stmt_execute($checkBorrowed);
$borrowedRes = mysqli_stmt_get_result($checkBorrowed);
$borrowedRow = mysqli_fetch_assoc($borrowedRes);

if ($borrowedRow && (int)$borrowedRow['borrowed'] > 0) {
    header("Location: " . $backUrl . "&type=error&msg=" . urlencode("Cannot delete a book that still has borrowed copies."));
    exit;
}

$delete = mysqli_prepare($conn, "DELETE FROM tbl_books WHERE id = ?");
mysqli_stmt_bind_param($delete, "i", $id);

if (mysqli_stmt_execute($delete)) {
    if (!empty($book['book_cover']) && file_exists($book['book_cover'])) {
        @unlink($book['book_cover']);
    }

    header("Location: " . $backUrl . "&type=success&msg=" . urlencode("Book deleted successfully."));
    exit;
} else {
    header("Location: " . $backUrl . "&type=error&msg=" . urlencode("Error deleting book."));
    exit;
}
?>