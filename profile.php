<?php
session_start();
include("db_connect.php");

// IMAGE COMPRESSION FUNCTION
function compress_any_image_to_jpg($tmpPath, $destPath, $maxWidth = 800, $quality = 70) {
    // Block non-images (videos will fail here)
    $info = @getimagesize($tmpPath);
    if ($info === false) return false;

    // Load image data (supports many formats)
    $data = @file_get_contents($tmpPath);
    if ($data === false) return false;

    $src = @imagecreatefromstring($data);
    if (!$src) return false;

    $w = imagesx($src);
    $h = imagesy($src);

    // Resize only if too large
    if ($w > $maxWidth) {
        $newW = $maxWidth;
        $newH = (int) round(($h / $w) * $newW);
    } else {
        $newW = $w;
        $newH = $h;
    }

    $dst = imagecreatetruecolor($newW, $newH);
    imagecopyresampled($dst, $src, 0, 0, 0, 0, $newW, $newH, $w, $h);

    // Save compressed JPEG
    $ok = imagejpeg($dst, $destPath, $quality);

    imagedestroy($src);
    imagedestroy($dst);

    return $ok;
}

// LOGIN CHECK
if (!isset($_SESSION['student_id'])) {
    header("Location: login.php");
    exit;
}

$user_id = $_SESSION['student_id'];

// Fetch User
$user_q = mysqli_query($conn, "SELECT * FROM tbl_users WHERE id='$user_id' LIMIT 1");
$user = mysqli_fetch_assoc($user_q);

if (!$user) {
    die("User not found");
}

//HANDLE FORM SUBMISSIONS
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

   /* ===== PROFILE PICTURE UPDATE ===== */
if (isset($_FILES['profile_pic']) && !empty($_FILES['profile_pic']['name'])) {

    if ($_FILES['profile_pic']['error'] !== UPLOAD_ERR_OK) {
        echo "Upload failed. Error code: " . $_FILES['profile_pic']['error'];
    } else {

        $tmp = $_FILES['profile_pic']['tmp_name'];

        // Block videos & non-image files
        if (@getimagesize($tmp) === false) {
            echo "Only image files are allowed.";
        } else {

            $updir = __DIR__ . '/uploads/profile/';
            if (!is_dir($updir)) mkdir($updir, 0777, true);

            // Always save as JPG (smaller + faster)
            $fname = time() . "_user{$user_id}.jpg";
            $target = $updir . $fname;

            // Compress (HD -> smaller)
            $maxWidth = 800; // 600–1000 is good
            $quality  = 70;  // 60–80 good balance

            if (compress_any_image_to_jpg($tmp, $target, $maxWidth, $quality)) {
                $rel = 'uploads/profile/' . $fname;
                mysqli_query($conn, "UPDATE tbl_users SET profile_pic='" . mysqli_real_escape_string($conn, $rel) . "' WHERE id='$user_id'");
            } else {
                // Fallback: if compression fails, upload original file
                $origName = time() . '_' . basename($_FILES['profile_pic']['name']);
                $fallbackTarget = $updir . $origName;

                if (move_uploaded_file($tmp, $fallbackTarget)) {
                    $rel = 'uploads/profile/' . $origName;
                    mysqli_query($conn, "UPDATE tbl_users SET profile_pic='" . mysqli_real_escape_string($conn, $rel) . "' WHERE id='$user_id'");
                } else {
                    echo "Failed to upload image.";
                }
            }
        }
    }
}


    // Pass Update
    if (isset($_POST['update_password'])) {
        $current = $_POST['current_password'] ?? '';
        $newpw = $_POST['new_password'] ?? '';

        if (password_verify($current, $user['password']) && strlen($newpw) >= 6) {
            $newhash = password_hash($newpw, PASSWORD_DEFAULT);
            mysqli_query($conn, "UPDATE tbl_users SET password='$newhash' WHERE id='$user_id'");
            echo "Password updated.";
        } else {
            echo "Incorrect current password or weak new password.";
        }
    }

    // BIO UPDATE 
    if (isset($_POST['update_bio'])) {
        $bio = mysqli_real_escape_string($conn, $_POST['bio'] ?? '');
        mysqli_query($conn, "UPDATE tbl_users SET bio='$bio' WHERE id='$user_id'");
    }

    // REFRESH USER 
    $user_q = mysqli_query($conn, "SELECT * FROM tbl_users WHERE id='$user_id' LIMIT 1");
    $user = mysqli_fetch_assoc($user_q);
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Profile — Golden Minds E-Library</title>

    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <link rel="stylesheet" href="assets/css/style.css">

    <style>
        /* Styling for the profile page */
        :root { 
            --brown: #b5651d; 
            --light: #fff8e7; 
            --accent: #ffcc66; 
            --dark: #6f3b3b;
        }

        body {
            font-family: Arial, sans-serif;
            margin: 0;
            background: var(--light);
            color: #333;
        }

        header {
            background: var(--dark);
            color: white;
            padding: 15px 20px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
        }

        header h1 {
            margin: 0;
            font-size: 24px;
        }

        .wrap {
            display: flex;
            gap: 20px;
            padding: 40px;
            flex-wrap: wrap;
        }

        .sidebar {
            width: 320px;
            background: #fff;
            padding: 20px;
            border-radius: 8px;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
        }

        .main {
            flex: 1;
            background: #fff;
            padding: 20px;
            border-radius: 8px;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
        }

        .btn {
            padding: 8px 16px;
            background: var(--brown);
            color: white;
            border-radius: 8px;
            text-decoration: none;
            font-size: 16px;
            transition: background-color 0.3s ease;
        }

        .btn:hover {
            background-color: #8b4b2b;
        }

        .profile-header {
            display: flex;
            align-items: center;
            gap: 20px;
            margin-bottom: 30px;
        }

        .profile-pic {
            width: 120px;
            height: 120px;
            border-radius: 50%;
            object-fit: cover;
            border: 5px solid white;
            box-shadow: 0 4px 8px rgba(0, 0, 0, 0.2);
        }

        .profile-info {
            font-size: 16px;
        }

        .profile-info strong {
            font-size: 18px;
            color: var(--brown);
        }

        .profile-info .muted {
            font-size: 14px;
            color: #888;
        }

        .form-group {
            margin-bottom: 20px;
        }

        .form-group label {
            font-weight: bold;
            color: var(--brown);
        }

        .form-group input[type="password"],
        .form-group input[type="text"],
        .form-group input[type="file"],
        .form-group textarea {
        width: 100%;
        padding: 12px;
        margin-top: 6px;
        border-radius: 6px;
        border: 1px solid #ddd;
        font-size: 14px;
        background-color: #fff;
        box-sizing: border-box;
}

        .form-group button {
            padding: 12px 20px;
            background-color: var(--brown);
            color: white;
            border: none;
            border-radius: 6px;
            cursor: pointer;
            transition: background-color 0.3s ease;
        }

        .form-group button:hover {
            background-color: #8b4b2b;
        }

        .form-group input[type="file"] {
            cursor: pointer;
        }

        .bio {
            padding: 10px;
            background-color: #f5f5f5;
            border-radius: 8px;
            margin-bottom: 20px;
            font-size: 14px;
        }

        @media (max-width: 900px) {
    .wrap { flex-direction: column; padding: 12px; }
    .sidebar { width: 100%; }
    .main { width: 100%; }
    .profile-header { flex-direction: column; align-items: flex-start; }
    }
    </style>
</head>
<body>

<header>
    <h1>Golden Minds E-Library</h1>
    <a href="dashboard.php" class="btn">Back to Dashboard</a>
</header>

<div class="wrap">
    <section class="sidebar">
        <div class="profile-header">
            <img src="<?= htmlspecialchars($user['profile_pic'] ?: 'uploads/profile/default.png'); ?>" alt="Profile Picture" class="profile-pic">
            <div class="profile-info">
                <strong><?= htmlspecialchars($user['full_name']); ?></strong>
                <div class="muted"><?= htmlspecialchars($user['section']); ?> — <?= htmlspecialchars($user['strand']); ?></div>
            </div>
        </div>
        
        <div class="bio">
            <strong>Bio:</strong>
            <p><?= htmlspecialchars($user['bio'] ?: 'No bio available.'); ?></p>
        </div>
    </section>

    <section class="main">
        <h2>Update Your Profile</h2>
        
        <!-- Update Password Section -->
        <form method="POST">
           <div class="form-group">
    <label for="current_password">Current Password</label>
    <div style="position:relative;">
        <input type="password" name="current_password" id="current_password" placeholder="Enter current password" required>
        <span onclick="togglePassword('current_password', this)"
              style="position:absolute; right:10px; top:12px; cursor:pointer; font-size:13px; color:#b5651d;">
              Show
        </span>
    </div>
</div>

<div class="form-group">
    <label for="new_password">New Password</label>
    <div style="position:relative;">
        <input type="password" name="new_password" id="new_password" placeholder="Enter new password (6-50 chars)" required>
        <span onclick="togglePassword('new_password', this)"
              style="position:absolute; right:10px; top:12px; cursor:pointer; font-size:13px; color:#b5651d;">
              Show
        </span>
    </div>
</div>

            <div class="form-group">
                <button type="submit" name="update_password">Update Password</button>
            </div>
        </form>
        
        <!-- Update Profile Picture Section -->
        <form method="POST" enctype="multipart/form-data">
            <div class="form-group">
                <label for="profile_pic">Profile Picture</label>
                <input type="file" name="profile_pic" id="profile_pic" accept="image/*">
            </div>

            <div class="form-group">
                <button type="submit" name="update_profile">Update Profile Picture</button>
            </div>
        </form>
        
        <!-- Update Bio Section -->
        <form method="POST">
            <div class="form-group">
                <label for="bio">Bio</label>
                <textarea name="bio" id="bio" rows="4" placeholder="Enter your bio..."></textarea>
            </div>

            <div class="form-group">
                <button type="submit" name="update_bio">Update Bio</button>
            </div>
        </form>
    </section>
</div>

<script>
function togglePassword(inputId, el) {
    const input = document.getElementById(inputId);
    if (!input) return;

    if (input.type === "password") {
        input.type = "text";
        el.textContent = "Hide";
    } else {
        input.type = "password";
        el.textContent = "Show";
    }
}
</script>
</body>
</html>
