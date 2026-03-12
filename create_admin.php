<?php
// Password to be hashed
$password = "libra123";

// Hash the password using PASSWORD_DEFAULT (which uses bcrypt)
$hashed_password = password_hash($password, PASSWORD_DEFAULT);

// Display the hashed password
echo "Hashed password: " . $hashed_password;
?>
