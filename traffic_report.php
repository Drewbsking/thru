<?php
// Database connection details
$servername = "localhost";
$username = "rcocwiki_thru";
$password = "Password#110";
$dbname = "rcocwiki_thru";

// Create a connection
$conn = new mysqli($servername, $username, $password, $dbname);

// Check connection
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

// Define SQL queries for each site
$query_site1 = "SELECT plate, time, location, vehicle_type, vehicle_color, in_out FROM site1_vehicle_data";
$query_site2 = "SELECT plate, time, location, vehicle_type, vehicle_color, in_out FROM site2_vehicle_data";
$query_site3 = "SELECT plate, time, location, vehicle_type, vehicle_color, in_out FROM site3_vehicle_data";

// Run the queries and fetch the data
$site1_result = $conn->query($query_site1);
$site2_result = $conn->query($query_site2);
$site3_result = $conn->query($query_site3);

// Store the data in arrays
$site1_data = array();
$site2_data = array();
$site3_data = array();

while ($row = $site1_result->fetch_assoc()) {
    $site1_data[] = $row;
}

while ($row = $site2_result->fetch_assoc()) {
    $site2_data[] = $row;
}

while ($row = $site3_result->fetch_assoc()) {
    $site3_data[] = $row;
}

// Helper function to identify cut-throughs based on the first 2 characters of the plate
function identify_cut_throughs($site_in, $site_out, $site_in_name, $site_out_name) {
    $cut_throughs = array();

    foreach ($site_in as $in_vehicle) {
        if ($in_vehicle['in_out'] == 'In') {
            foreach ($site_out as $out_vehicle) {
                if ($out_vehicle['in_out'] == 'Out' &&
                    substr($in_vehicle['plate'], 0, 2) == substr($out_vehicle['plate'], 0, 2) && // Use first 2 chars of plate
                    $in_vehicle['vehicle_type'] == $out_vehicle['vehicle_type'] &&
                    $in_vehicle['vehicle_color'] == $out_vehicle['vehicle_color']) {

                    // Calculate the time difference in minutes
                    $time_in = strtotime($in_vehicle['time']);
                    $time_out = strtotime($out_vehicle['time']);
                    $time_diff_minutes = ($time_out - $time_in) / 60;

                    // Check if the time difference is within the valid range
                    if ($time_diff_minutes > 0 && $time_diff_minutes <= 5) {
                        // Calculate average speed based on known distances
                        $distances = array(
                            'Site 1 to Site 2' => 0.4,
                            'Site 2 to Site 1' => 0.4,
                            'Site 2 to Site 3' => 0.4,
                            'Site 3 to Site 2' => 0.4,
                            'Site 1 to Site 3' => 0.6,
                            'Site 3 to Site 1' => 0.6
                        );
                        $movement_key = $site_in_name . " to " . $site_out_name;
                        $distance = isset($distances[$movement_key]) ? $distances[$movement_key] : 0;
                        $average_speed = $distance / ($time_diff_minutes / 60); // Speed = Distance / Time

                        $cut_throughs[] = array(
                            'plate' => $in_vehicle['plate'],
                            'vehicle_type' => $in_vehicle['vehicle_type'],
                            'vehicle_color' => $in_vehicle['vehicle_color'],
                            'time_in' => $in_vehicle['time'],
                            'time_out' => $out_vehicle['time'],
                            'time_diff_minutes' => $time_diff_minutes,
                            'movement' => $movement_key,
                            'average_speed' => round($average_speed, 2)
                        );
                    }
                }
            }
        }
    }

    return $cut_throughs;
}

// Identify cut-throughs for each combination of sites using the first two characters of plates
$cut_throughs_1_to_2 = identify_cut_throughs($site1_data, $site2_data, 'Site 1', 'Site 2');
$cut_throughs_1_to_3 = identify_cut_throughs($site1_data, $site3_data, 'Site 1', 'Site 3');
$cut_throughs_2_to_1 = identify_cut_throughs($site2_data, $site1_data, 'Site 2', 'Site 1');
$cut_throughs_2_to_3 = identify_cut_throughs($site2_data, $site3_data, 'Site 2', 'Site 3');
$cut_throughs_3_to_1 = identify_cut_throughs($site3_data, $site1_data, 'Site 3', 'Site 1');
$cut_throughs_3_to_2 = identify_cut_throughs($site3_data, $site2_data, 'Site 3', 'Site 2');

// Combine all cut-throughs
$all_cut_throughs = array_merge($cut_throughs_1_to_2, $cut_throughs_1_to_3, $cut_throughs_2_to_1, $cut_throughs_2_to_3, $cut_throughs_3_to_1, $cut_throughs_3_to_2);

// Display the cut-through results in an HTML table
echo "<h1>Cut-Through Summary with Average Speed</h1>";
echo "<table border='1'>";
echo "<tr><th>Plate</th><th>Vehicle Type</th><th>Vehicle Color</th><th>Time In</th><th>Time Out</th><th>Time Difference (min)</th><th>Movement</th><th>Average Speed (MPH)</th></tr>";

foreach ($all_cut_throughs as $cut_through) {
    echo "<tr>";
    echo "<td>" . $cut_through['plate'] . "</td>";
    echo "<td>" . $cut_through['vehicle_type'] . "</td>";
    echo "<td>" . $cut_through['vehicle_color'] . "</td>";
    echo "<td>" . $cut_through['time_in'] . "</td>";
    echo "<td>" . $cut_through['time_out'] . "</td>";
    echo "<td>" . round($cut_through['time_diff_minutes'], 2) . "</td>";
    echo "<td>" . $cut_through['movement'] . "</td>";
    echo "<td>" . $cut_through['average_speed'] . "</td>";
    echo "</tr>";
}

echo "</table>";

// Close the connection
$conn->close();
?>
