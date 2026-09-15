<?php
// media.php - Public media endpoint for social platforms (Buffer, Pinterest, TikTok)
if (empty($_GET['file'])) {
    http_response_code(400);
    exit('File parameter missing');
}

$file = basename($_GET['file']);
$upload_dir = __DIR__ . '/uploads/';
$file_path = $upload_dir . $file;

if (!file_exists($file_path)) {
    http_response_code(404);
    exit('Media file not found');
}

$ext = strtolower(pathinfo($file_path, PATHINFO_EXTENSION));
$mime_types = [
    'jpg'  => 'image/jpeg',
    'jpeg' => 'image/jpeg',
    'png'  => 'image/png',
    'gif'  => 'image/gif',
    'webp' => 'image/webp',
    'mp4'  => 'video/mp4',
    'mov'  => 'video/quicktime',
    'webm' => 'video/webm'
];

$content_type = $mime_types[$ext] ?? 'application/octet-stream';

// Set public headers for social media crawlers
header('Content-Type: ' . $content_type);
header('Content-Length: ' . filesize($file_path));
header('Access-Control-Allow-Origin: *');
header('Cache-Control: public, max-age=31536000');
header('Pragma: public');

readfile($file_path);
exit;
