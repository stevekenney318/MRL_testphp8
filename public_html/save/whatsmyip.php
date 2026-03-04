<?php
// whatsmyip.php

// ******************************************************************
// get ip and store it as $ip
// https://www.w3resource.com/php-exercises/php-basic-exercise-5.php
//
//whether ip is from share internet
if (!empty($_SERVER['HTTP_CLIENT_IP']))   
  {
    $ip = $_SERVER['HTTP_CLIENT_IP'];
  }
//whether ip is from proxy
elseif (!empty($_SERVER['HTTP_X_FORWARDED_FOR']))  
  {
    $ip = $_SERVER['HTTP_X_FORWARDED_FOR'];
  }
//whether ip is from remote address
else
  {
    $ip = $_SERVER['REMOTE_ADDR'];
  }
echo $ip;
// ******************************************************************