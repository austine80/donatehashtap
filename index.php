<?php
file_put_contents('debug.log', print_r($_POST, true), FILE_APPEND);
echo "Safaricom STK Push Backend is running!";
?>
