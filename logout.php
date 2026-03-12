<?php
session_start(); // Start the session

// Get the current session ID
$current_session_id = $_SESSION['user_session_id'] ?? '';

// If the user is logged in, clear the session for the current tab
if ($current_session_id) {
    // Destroy the session for the current tab only
    $_SESSION = [];  // Unset all session variables
    session_destroy();  // Destroy the session

    // Optionally, clear the session cookie as well
    if (ini_get("session.use_cookies")) {
        $params = session_get_cookie_params();
        setcookie(
            session_name(),
            '',
            time() - 42000, // Expire the cookie
            $params["path"],
            $params["domain"],
            $params["secure"],
            $params["httponly"]
        );
    }

    // Update the database to remove the session ID for the user in case of logout
    include("db_connect.php"); // Include the database connection
    mysqli_query($conn, "UPDATE tbl_users SET user_session_id = NULL WHERE user_session_id = '$current_session_id'");

    // Redirect to the login page after logout
    header("Location: login.php");
    exit;
} else {
    header("Location: login.php");  // If no session, redirect to login
    exit;
}
?>