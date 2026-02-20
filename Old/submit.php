<?php
ini_set('display_errors', 1);  // Enable error reporting for debugging
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// Database connection details
$servername = "localhost";  // Use "localhost" since it's on the same server
$username = "rcocwiki_thru";   // Replace with your MySQL username
$password = "Password#110";    // Replace with your MySQL password
$dbname = "rcocwiki_thru";     // Your database name

// Create connection
$conn = new mysqli($servername, $username, $password, $dbname);

// Check connection
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

// Process POST request data
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $plate = $_POST['plate'];
    $location = $_POST['location'];
    $vehicle_type = $_POST['vehicle_type'];
    $vehicle_color = $_POST['vehicle_color'];
    $in_out = $_POST['in_out']; // Retrieve the 'In/Out' field
    $comments = $_POST['comments'];

    // Determine the correct table based on the location parameter
    $table = "";
    if ($location == "Site 1") {
        $table = "site1_vehicle_data";
    } elseif ($location == "Site 2") {
        $table = "site2_vehicle_data";
    } elseif ($location == "Site 3") {
        $table = "site3_vehicle_data";
    }

    // Insert data into the appropriate table
    if ($table != "") {
        $sql = "INSERT INTO $table (plate, location, vehicle_type, vehicle_color, in_out, comments)
                VALUES ('$plate', '$location', '$vehicle_type', '$vehicle_color', '$in_out', '$comments')";

        if ($conn->query($sql) === TRUE) {
            echo "New record created successfully";
        } else {
            echo "Error: " . $conn->error;
        }
    } else {
        echo "Invalid location parameter!";
    }
}

// Close the database connection
$conn->close();
?>
