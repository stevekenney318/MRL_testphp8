<?php
// Get the data from the POST request
$data = $_POST['csv_data'];

// Set the headers to force a download of the CSV file
header('Content-Type: application/csv');
header('Content-Disposition: attachment; filename="driver_data.csv"');

// Output the data to the response body
echo $data;
?>
