<?php
session_start();
include("db_connect.php");
include("moderation_helper.php");

if (!isset($_SESSION['student_id'])) {
    header("Location: login.php");
    exit;
}

$user_id = (int) $_SESSION['student_id'];
$user_role = $_SESSION['student_role'] ?? '';

// Only students can post
if ($user_role !== 'student') {
    echo "<script>alert('Only students can post quotes or feedback.'); window.location='quotes_feedback.php';</script>";
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_post'])) {
    $type = trim($_POST['type'] ?? '');
    $content = trim($_POST['content'] ?? '');
    $is_anonymous = isset($_POST['is_anonymous']) ? 1 : 0;

    $allowedTypes = ['quote', 'comment'];

    if ($content === '' || !in_array($type, $allowedTypes)) {
        $_SESSION['post_message'] = "Please complete the form correctly.";
        $_SESSION['post_message_type'] = "error";
        header("Location: quotes_feedback.php");
        exit;
    }

    if (mb_strlen($content) > 300) {
        $_SESSION['post_message'] = "Post must not exceed 300 characters.";
        $_SESSION['post_message_type'] = "error";
        header("Location: quotes_feedback.php");
        exit;
    }

    $moderation_result = 'clean';
    $status = 'approved';
    $final_content = $content;

    if (containsBadWords($content)) {
        $final_content = filterBadWords($content);
        $moderation_result = 'filtered';
    }

    $type = mysqli_real_escape_string($conn, $type);
    $final_content = mysqli_real_escape_string($conn, $final_content);
    $moderation_result = mysqli_real_escape_string($conn, $moderation_result);
    $status = mysqli_real_escape_string($conn, $status);

    $sql = "INSERT INTO tbl_quotes (user_id, type, is_anonymous, quote, moderation_result, status, post_date, likes)
            VALUES ('$user_id', '$type', '$is_anonymous', '$final_content', '$moderation_result', '$status', NOW(), 0)";

    if (mysqli_query($conn, $sql)) {
        if ($moderation_result === 'filtered') {
            $_SESSION['post_message'] = "Post submitted successfully. Inappropriate words were automatically filtered.";
            $_SESSION['post_message_type'] = "warning";
        } else {
            $_SESSION['post_message'] = "Post submitted successfully.";
            $_SESSION['post_message_type'] = "success";
        }
    } else {
        $_SESSION['post_message'] = "Failed to submit post: " . mysqli_error($conn);
        $_SESSION['post_message_type'] = "error";
    }

    header("Location: quotes_feedback.php");
    exit;
}

header("Location: quotes_feedback.php");
exit;
?>