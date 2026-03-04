<?php

// **********************************************************************************
// check if current date is between 2 dates
   
$currentDate = date('Y-m-d');
$currentDate = date('Y-m-d', strtotime($currentDate));
   
$startDate = date('Y-m-d', strtotime("01/01/2020"));
$endDate = date('Y-m-d', strtotime("09/05/2020"));
   
if (($currentDate >= $startDate) && ($currentDate <= $endDate)){
    echo "Current date is before lock date, show submission form.";

}else{
    echo "Current date is NOT before lock date, Do NOT show submission form.";  
}

// **********************************************************************************

?>