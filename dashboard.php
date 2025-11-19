<?php
// Database connection
$servername = "localhost";
$username = "rcocwiki_thru";
$password = "Password#110";
$dbname = "rcocwiki_thru";

// Create connection
$conn = new mysqli($servername, $username, $password, $dbname);

// Check connection
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

// Query to fetch relevant data from the database
$sql = "SELECT vehicle_type, vehicle_color, time, location, in_out
        FROM traffic_data
        ORDER BY time ASC";

$result = $conn->query($sql);

// Store the data in an associative array
$data = array();
while($row = $result->fetch_assoc()) {
    $data[] = $row;
}

// Function to identify cut-throughs based on vehicle type and color
function identify_cut_throughs($data) {
    $entries = array();
    $cut_throughs = array();

    foreach ($data as $record) {
        $key = $record['vehicle_type'] . "_" . $record['vehicle_color'];

        if ($record['in_out'] == 'In') {
            // Store the entry data for the vehicle type and color
            $entries[$key] = $record;
        } elseif ($record['in_out'] == 'Out' && isset($entries[$key])) {
            // Calculate time difference in minutes
            $time_in = new DateTime($entries[$key]['time']);
            $time_out = new DateTime($record['time']);
            $interval = $time_out->diff($time_in);
            $time_diff = ($interval->h * 60) + $interval->i; // Convert to minutes

            // Check if time difference is within 2 minutes
            if ($time_diff > 0 && $time_diff <= 2) {
                $cut_throughs[] = array(
                    'from' => $entries[$key]['location'],
                    'to' => $record['location'],
                    'vehicle_type' => $record['vehicle_type'],
                    'vehicle_color' => $record['vehicle_color'],
                    'time_in' => $entries[$key]['time'],
                    'time_out' => $record['time'],
                    'time_diff' => $time_diff
                );
            }
            unset($entries[$key]); // Remove the processed entry
        }
    }
    return $cut_throughs;
}

// Get the cut-through analysis
$cut_throughs = identify_cut_throughs($data);

// Display the cut-through results in a table
echo "<table border='1'>
<tr>
<th>From</th>
<th>To</th>
<th>Vehicle Type</th>
<th>Vehicle Color</th>
<th>Time In</th>
<th>Time Out</th>
<th>Time Difference (min)</th>
</tr>";

foreach ($cut_throughs as $cut) {
    echo "<tr>
    <td>{$cut['from']}</td>
    <td>{$cut['to']}</td>
    <td>{$cut['vehicle_type']}</td>
    <td>{$cut['vehicle_color']}</td>
    <td>{$cut['time_in']}</td>
    <td>{$cut['time_out']}</td>
    <td>{$cut['time_diff']}</td>
    </tr>";
}
echo "</table>";

// Close the connection
$conn->close();
?>
