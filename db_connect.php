    <?php
    // db_connect.php
    $DB_HOST = '127.0.0.1';  // Ensure this is correct
    $DB_USER = 'root';        // Your MySQL username
    $DB_PASS = '';            // Your MySQL password (empty by default for XAMPP)
    $DB_NAME = 'db_capstone'; // Your database name

    // Create connection
    $conn = mysqli_connect($DB_HOST, $DB_USER, $DB_PASS, $DB_NAME);

    // Check connection
    if (!$conn) {
        die("Connection failed: " . mysqli_connect_error());
    }

    // Ensure UTF-8 charset
    mysqli_set_charset($conn, 'utf8mb4');
    ?>
