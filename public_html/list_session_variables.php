<?php
// Start or resume the session
session_start();

// Get a list of all session variables
$sessionVariables = $_SESSION;

// Print the list of session variables
echo "List of Session Variables:<br>";
foreach ($sessionVariables as $key => $value) {
    echo "$key: $value<br>";
}
?>