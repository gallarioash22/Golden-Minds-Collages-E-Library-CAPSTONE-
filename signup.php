<?php
// signup.php
error_reporting(E_ALL);
ini_set('display_errors', 1);

include("db_connect.php");

$message = "";

$old = $_POST ?? [];
function old($key){
  global $old;
  return htmlspecialchars($old[$key] ?? '', ENT_QUOTES, 'UTF-8');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['signup'])) {
    // sanitize
    $full_name = mysqli_real_escape_string($conn, trim($_POST['full_name']));
    $gender = mysqli_real_escape_string($conn, trim($_POST['gender'] ?? ''));
    $birthday  = $_POST['birthday'];
    $section   = mysqli_real_escape_string($conn, trim($_POST['section']));
    $strand    = mysqli_real_escape_string($conn, trim($_POST['strand']));
    $contact   = mysqli_real_escape_string($conn, trim($_POST['contact']));
    $address   = mysqli_real_escape_string($conn, trim($_POST['address']));
    $username  = mysqli_real_escape_string($conn, trim($_POST['username']));
    $lrn  = mysqli_real_escape_string($conn, trim($_POST['lrn']));
    $password  = trim($_POST['password']);

    // simple validations
    if (
  $full_name === '' || $gender === '' || $birthday === '' ||
  $section === '' || $strand === '' || $contact === '' ||
  $address === '' || $username === '' || $lrn === '' || $password === ''
  ) {
  $message = "Please fill out all fields.";
  }
  elseif (strlen($password) < 6) {
  $message = "Password must be at least 6 characters.";
  }
  elseif (!preg_match('/^[0-9]{12}$/', $lrn)) {
  $message = "LRN must be exactly 12 digits.";
  }
  elseif (!preg_match('/^[0-9]{10,11}$/', $contact)) {
    $message = "Contact number must be 10-11 digits.";
  } 

    else {
        // check username uniqueness
        $chk = mysqli_query($conn, "SELECT id FROM tbl_users WHERE username='$username' LIMIT 1");
        if (mysqli_num_rows($chk) > 0) {
            $message = "Username already taken. Choose another.";
        } else {
            // hash password before saving
           $hash = password_hash($password, PASSWORD_DEFAULT);

          $user_session_id = bin2hex(random_bytes(32));

          $sql = "INSERT INTO tbl_users
          (full_name, gender, birthday, section, strand, contact, address, username, lrn, password, role, profile_pic, user_session_id)
          VALUES
          ('$full_name', '$gender', '$birthday', '$section', '$strand', '$contact', '$address', '$username', '$lrn', '$hash', 'user', 'uploads/profile/default.png', '$user_session_id')";
            if (mysqli_query($conn, $sql)) {
                echo "<script>alert('Account created successfully!'); window.location='login.php';</script>";
                exit;
            } else {
                $message = "Failed to create account: " . mysqli_error($conn);
            }
        }
    }
}
?>
<!doctype html>
<html>
<head>
  <meta charset="utf-8">
  <title>Sign Up — Golden Minds E-Library</title>

  <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <link rel="stylesheet" href="assets/css/style.css">

  <style>
body{
  font-family: Arial;
  background:#f3e9c7;
  margin:0;
  padding:24px 0;
  display:flex;
  justify-content:center;
}

.box{
  width: 92%;
  max-width: 460px; /* instead of fixed 420px */
  background:#fff;
  padding:22px;
  border-radius:10px;
  box-shadow: 0 0 30px rgba(136, 84, 12, 0.7),
              0 0 30px rgba(144, 84, 15, 0.4);
}

    h2{color:#b5651d;
      text-align:center;
      margin:0 0 12px}

    input, select, textarea{width:100%;
      padding:10px;
      margin:8px 0;
      border:1px solid #955405ff;
      border-radius:6px;
      box-sizing:border-box}

    .gender{display:flex;
      gap:10px;
      align-items:center}

    button{width:100%;
      padding:12px;
      background:#b5651d;
      color:#fff;
      border:none;
      border-radius:6px;
      cursor:pointer;
      font-weight:600}

    .small{font-size:13px;color:#666;text-align:center;margin-top:8px;
}
    .msg{color:#c0392b;margin-bottom:8px;text-align:center}

    .log{

     text-decoration: none;
     color: #bb7310ff;
    }

    .log:hover {
  color: #905606ff;
}

   .sup {
    width: 100%;
    padding: 10px;
    background: #bb7310ff;
    color: white;
    border: none;
}

.sup:hover {
  background: #905606ff;
}

.back {
  text-align: center;
  margin-top: 12px;
}

  </style>
</head>
<body>
  <div class="box">
    <h2>Create Account</h2>
    <?php if(!empty($message)) echo "<div class='msg'>".htmlspecialchars($message)."</div>"; ?>
    <form method="post" novalidate>
      <input type="text" name="full_name" placeholder="Full Name" value="<?= old('full_name') ?>" required>

      <div class="gender">
        <label><input type="radio" name="gender" value="Male" <?= (old('gender')==='Male')?'checked':'' ?> required> Male</label>
        <label><input type="radio" name="gender" value="Female" <?= (old('gender')==='Female')?'checked':'' ?> required> Female</label>
      </div>

      <input type="date" name="birthday" value="<?= old('birthday') ?>" required>

      <input type="text" name="section" placeholder="Section (e.g. 12-A)" value="<?= old('section') ?>" required>

      <select name="strand" required>
      <option value="">-- Select Strand --</option>
      <option value="ABM" <?= (old('strand')==='ABM')?'selected':'' ?>>ABM</option>
      <option value="TVL-ICT" <?= (old('strand')==='TVL-ICT')?'selected':'' ?>>TVL-ICT</option>
      <option value="TVL-HE" <?= (old('strand')==='TVL-HE')?'selected':'' ?>>TVL-HE</option>
      <option value="STEM" <?= (old('strand')==='STEM')?'selected':'' ?>>STEM</option>
      <option value="GAS" <?= (old('strand')==='GAS')?'selected':'' ?>>GAS</option>
      <option value="HUMSS" <?= (old('strand')==='HUMSS')?'selected':'' ?>>HUMSS</option>
      </select>

      <input type="text" name="contact" placeholder="Contact Number" maxlength="11" pattern="[0-9]{10,11}" inputmode="numeric" oninput="this.value=this.value.replace(/[^0-9]/g,'')" value="<?= old('contact') ?>" required>
      <textarea name="address" placeholder="Address" rows="3" required><?= old('address') ?></textarea>

      <input type="text" name="username" placeholder="Username" value="<?= old('username') ?>" required>

      <input type="text" name="lrn" placeholder="LRN" maxlength="12" inputmode="numeric" oninput="this.value=this.value.replace(/[^0-9]/g,'')" value="<?= old('lrn') ?>" required>
      
      <div style="position:relative;">
    <input type="password" id="password" name="password" placeholder="Password (min 6 chars)" required>
    <span id="toggleText" onclick="togglePassword()" 
      style="position:absolute; right:10px; top:12px; cursor:pointer; font-size:13px; color:#bb7310ff;">
      Show
    </span>
</div>

      <button class='sup' type="submit" name="signup">Sign Up</button>
      <div class="back">
  <a href="login.php" class="log">Back</a>
</div>

   
    </form>
  </div>

  <script>
function togglePassword() {
    const pass = document.getElementById("password");
    const text = document.getElementById("toggleText");

    if (pass.type === "password") {
        pass.type = "text";
        text.innerText = "Hide";
    } else {
        pass.type = "password";
        text.innerText = "Show";
    }
}
</script>
</body>
</html>
