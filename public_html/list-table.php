<?Php
include "config.php"; // Database connection using PDO

//$sql="SELECT name,id FROM student"; 

//$sql="SELECT name,id FROM student order by name";

$sql="SELECT name,id FROM student WHERE class='four' order by name "; 

/* You can add order by clause to the sql statement if the names are to be displayed in alphabetical order */

echo "<select name=student value=''>Student Name</option>"; // list box select command

 
 foreach ($dbo->query($sql) as $row){//Array or records stored in $row

if($row[id]==10){

echo "<option value=$row[id] selected>$row[name]</option>"; 

}else{

echo "<option value=$row[id]>$row[name]</option>"; 

}

}

 echo "</select>";// Closing of list box
?>