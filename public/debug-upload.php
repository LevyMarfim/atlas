<?php
// public/debug-upload.php
echo "<h1>PHP Upload Configuration</h1>";
echo "upload_max_filesize: " . ini_get('upload_max_filesize') . "<br>";
echo "post_max_size: " . ini_get('post_max_size') . "<br>";
echo "memory_limit: " . ini_get('memory_limit') . "<br>";
echo "max_execution_time: " . ini_get('max_execution_time') . "<br>";
echo "max_input_time: " . ini_get('max_input_time') . "<br>";
echo "max_file_uploads: " . ini_get('max_file_uploads') . "<br>";

echo "<h2>Testing Directory</h2>";
$dir = __DIR__ . '/uploads/pdf';
echo "Directory: $dir<br>";
echo "Exists: " . (is_dir($dir) ? 'Yes' : 'No') . "<br>";
echo "Writable: " . (is_writable($dir) ? 'Yes' : 'No') . "<br>";

if (!is_dir($dir)) {
    mkdir($dir, 0755, true);
    echo "Created directory<br>";
}