<?php
session_start(); // Start the session

// Disable caching (important for login pages)
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Pragma: no-cache");
header("Expires: 0");

// Set unique session token for each tab (this ensures session isolation across tabs)
if (!isset($_SESSION['session_token'])) {
    $_SESSION['session_token'] = bin2hex(random_bytes(16)); // Unique token for each tab
    session_name($_SESSION['session_token']); // Use the session token to isolate sessions
    session_regenerate_id(true); // Regenerate session ID to make it unique for this tab
}

include("db_connect.php"); // Include the database connection

// Clear session if not logging in
if (!isset($_POST['login'])) {
    session_unset(); // Clears all session variables
    session_destroy(); // Destroys the session completely
}

// When the user submits the login form
if (isset($_POST['login'])) {
    // Sanitize user input
    $username_input = mysqli_real_escape_string($conn, trim($_POST['username']));
    $password_input = trim($_POST['password']);

    // Fetch the user from the database
    $sql = "SELECT id, password, role, account_status, user_session_id FROM tbl_users WHERE username = ? LIMIT 1";
    $stmt = mysqli_prepare($conn, $sql);
    mysqli_stmt_bind_param($stmt, "s", $username_input);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);

    if ($user = mysqli_fetch_assoc($result)) {
        // Verify password
        if (!password_verify($password_input, $user['password'])) {
            echo "<script>alert('❌ Incorrect username or password'); window.location='login.php';</script>";
            exit;
        }

              // ✅ Block login if account is not active
        $acctStatus = strtolower(trim($user['account_status'] ?? 'active'));

        if ($acctStatus === 'deactivated') {
    echo "<script>
        alert('🚫 Your account has been DEACTIVATED. Please contact the librarian/admin.');
        window.location='login.php';
    </script>";
    exit;
        }

        if ($acctStatus === 'maintenance') {
    echo "<script>
        alert('🛠️ Your account is currently under MAINTENANCE. Please try again later.');
        window.location='login.php';
    </script>";
    exit;
        }

        // Generate a new unique session ID for this login (per tab)
        $new_session_id = bin2hex(random_bytes(32)); // Generate a new session ID

        // Update the session ID in the database for this user
        mysqli_query($conn, "UPDATE tbl_users SET user_session_id = '$new_session_id' WHERE id = '{$user['id']}'");

        // Set session variables on successful login
        $_SESSION['student_id'] = $user['id'];
        $_SESSION['student_role'] = $user['role']; // Dynamically store role
        $_SESSION['user_session_id'] = $new_session_id; // Store the session ID in the session

        // Regenerate session ID to avoid session fixation
        session_regenerate_id(true); // Regenerates session ID after login

        // Delay redirection to allow alert to show
        echo "<script>alert('✔️ Successful login!'); window.location='";
        
        // Redirect based on user role
        if ($_SESSION['student_role'] === 'user') {
            echo "dashboard.php";
        } else {
            echo "dashboard.php";
        }
        echo "';</script>";
        exit;
    } else {
        echo "<script>alert('❌ Incorrect username or password'); window.location='login.php';</script>";
        exit;
    }
}
?>
<html>
<head>
<title>Login</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <link rel="stylesheet" href="assets/css/style.css">
<style>

body {
    background: #f3e9c7;
    font-family: Arial;
    margin: 0;
    padding: 24px 0;
}

.container {
    width: 92%;
    max-width: 380px;   /* instead of fixed 350px */
    background: white;
    padding: 20px;
    margin: 40px auto;  /* instead of 100px (too tall on small phones) */
    border-radius: 10px;
    box-shadow: 0 0 30px rgba(136, 84, 12, 0.7),
            0 0 30px rgba(144, 84, 15, 0.4);
}

input, select {
    width: 100%;
    padding: 10px;
    margin: 8px 0;
}

button {
    width: 100%;
    padding: 10px;
    background: #bb7310ff;
    color: white;
    border: none;
    border-radius: 6px;
}

button:hover { background: #905606ff; }

a { text-decoration: none; color: #bb7310ff; }
a:hover { color: #905606ff; }

.reg {
    text-align: center;
    margin-top: 16px;
    display: block;
}

    .reg:hover {
    color: #905606ff;
}

</style>
</head>
<body>

<div class="container">
    <h2>Login</h2>
    <form method="POST">
        <label>Username</label>
        <input type="text" name="username" required placeholder="Enter your username">

        <label>Password</label>
<div style="position:relative;">
    <input type="password" id="password" name="password" required placeholder="Enter your password">
    <span onclick="togglePassword()" 
          style="position:absolute; right:10px; top:12px; cursor:pointer; font-size:13px; color:#bb7310ff;">
          Show
    </span>
</div>
        <button type="submit" name="login">Login</button>
        <div class='reg'>
            <a href="signup.php">Register</a>
        </div>
    </form>
</div>

<script>
function togglePassword() {
    const pass = document.getElementById("password");
    const btn = event.target;

    if (pass.type === "password") {
        pass.type = "text";
        btn.textContent = "Hide";
    } else {
        pass.type = "password";
        btn.textContent = "Show";
    }
}
</script>
</body>
</html>

