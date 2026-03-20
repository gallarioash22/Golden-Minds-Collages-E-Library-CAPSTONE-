<?php
session_start();
include("db_connect.php");

// Admin only
if (!isset($_SESSION['student_id']) || $_SESSION['student_role'] !== 'admin') {
    header("Location: login.php");
    exit;
}

// Make sure folder exists
$qrFolder = "uploads/qr_codes/";
if (!is_dir($qrFolder)) {
    mkdir($qrFolder, 0777, true);
}

// Select books without QR yet
$sql = "SELECT id, title FROM tbl_books WHERE qr_code IS NULL OR qr_code = ''";
$result = mysqli_query($conn, $sql);

$successCount = 0;
$errorCount = 0;

while ($row = mysqli_fetch_assoc($result)) {
    $bookId = (int)$row['id'];
    $bookTitle = $row['title'];

    $qrText = "BOOK-" . $bookId;
    $fileName = "book_" . $bookId . ".png";
    $filePath = $qrFolder . $fileName;

    // =========================================================
    // PUT YOUR EXISTING QR IMAGE GENERATION CODE HERE
    // Example:
    // QRcode::png($qrText, $filePath, QR_ECLEVEL_L, 4);
    // =========================================================

    // Update database only if QR image was created
    if (file_exists($filePath)) {
        $qrTextEscaped = mysqli_real_escape_string($conn, $qrText);
        $filePathEscaped = mysqli_real_escape_string($conn, $filePath);

        $updateSql = "
            UPDATE tbl_books 
            SET qr_code = '$qrTextEscaped',
                qr_image = '$filePathEscaped'
            WHERE id = $bookId
        ";

        if (mysqli_query($conn, $updateSql)) {
            $successCount++;
        } else {
            $errorCount++;
        }
    } else {
        $errorCount++;
    }
}
?>

<!DOCTYPE html>
<html>
<head>
    <title>Generate Existing Book QR Codes</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            padding: 30px;
            background: #f5f5f5;
        }
        .box {
            background: white;
            padding: 20px;
            border-radius: 10px;
            max-width: 600px;
            margin: auto;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }
        h2 {
            margin-bottom: 15px;
        }
        p {
            font-size: 16px;
        }
        a {
            display: inline-block;
            margin-top: 15px;
            text-decoration: none;
            background: #007bff;
            color: white;
            padding: 10px 14px;
            border-radius: 6px;
        }
    </style>
</head>
<body>
    <div class="box">
        <h2>QR Generation Complete</h2>
        <p><strong>Success:</strong> <?php echo $successCount; ?></p>
        <p><strong>Errors:</strong> <?php echo $errorCount; ?></p>
        <a href="main_add_book.php">Back to Main Add Book</a>
    </div>
</body>
</html>