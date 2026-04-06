<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
$_GET['page_id'] = '1';
$_GET['user_id'] = '1';
require __DIR__ . '/actions/get_posts_with_comments.php';
