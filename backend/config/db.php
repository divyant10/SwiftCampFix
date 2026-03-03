<?php

$host     = "localhost";   

$username = "root";        

$password = "divyant123";            

$database = "swiftcampfix_db";  



$conn = new mysqli($host, $username, $password, $database);



if ($conn->connect_error) {

    die("❌ Connection failed: " . $conn->connect_error);

} else {

    

}

?>