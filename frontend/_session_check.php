<?php
session_start();
echo 'SID: ' . session_id() . '<br>';
echo 'username: ' . ($_SESSION['username'] ?? 'NULL');
