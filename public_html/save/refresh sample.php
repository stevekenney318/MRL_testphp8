<?php 
  
// Demonstrate the use of header() function 
// to refresh the current page 
   
echo "Welcome to index page</br>"; 
echo "Page will refresh in every 5 seconds</br></br>"; 
    
// The function will refresh the page  
// in every 3 second 

echo("<meta http-equiv='refresh' content='5'>");

    
echo date('H:i:s Y-m-d'); 
  
exit; 
?> 
