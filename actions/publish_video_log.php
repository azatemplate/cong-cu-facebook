<?php
// Tạm thời log request lại:
$log = "POST DATA:\n" . print_r($_POST, true) . "\nFILES:\n" . print_r($_FILES, true);
file_put_contents("d:/pagespeed/hi/facebook/test_payload.log", $log, FILE_APPEND);
