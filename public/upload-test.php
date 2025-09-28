<?php
// public/upload-test.php
echo "<h1>PHP Upload Configuration Test</h1>";

// Check PHP configuration
$configs = [
    'upload_max_filesize' => ini_get('upload_max_filesize'),
    'post_max_size' => ini_get('post_max_size'),
    'memory_limit' => ini_get('memory_limit'),
    'max_execution_time' => ini_get('max_execution_time'),
    'max_input_time' => ini_get('max_input_time'),
    'max_file_uploads' => ini_get('max_file_uploads'),
    'file_uploads' => ini_get('file_uploads'),
];

echo "<h2>Current PHP Configuration:</h2>";
echo "<table border='1' cellpadding='5'>";
foreach ($configs as $key => $value) {
    echo "<tr><td><strong>$key</strong></td><td>$value</td></tr>";
}
echo "</table>";

// Check directory permissions
$uploadDir = __DIR__ . '/uploads/pdf';
echo "<h2>Directory Check:</h2>";
echo "Upload directory: $uploadDir<br>";
echo "Exists: " . (is_dir($uploadDir) ? 'Yes' : 'No') . "<br>";
echo "Writable: " . (is_writable($uploadDir) ? 'Yes' : 'No') . "<br>";

// Temporary directory
$tmpDir = ini_get('upload_tmp_dir') ?: sys_get_temp_dir();
echo "Temporary directory: $tmpDir<br>";
echo "Tmp dir writable: " . (is_writable($tmpDir) ? 'Yes' : 'No') . "<br>";

// Test form
echo "<h2>Test Upload Form:</h2>";
echo '<form action="" method="post" enctype="multipart/form-data">';
echo '<input type="file" name="test_file">';
echo '<input type="submit" value="Test Upload">';
echo '</form>';

if ($_FILES && isset($_FILES['test_file'])) {
    echo "<h2>Upload Test Results:</h2>";
    var_dump($_FILES['test_file']);
    
    if ($_FILES['test_file']['error'] === UPLOAD_ERR_OK) {
        echo "<p style='color: green;'>Upload successful!</p>";
    } else {
        $errorMessages = [
            UPLOAD_ERR_INI_SIZE => 'File too large (upload_max_filesize)',
            UPLOAD_ERR_FORM_SIZE => 'File too large (HTML form limit)',
            UPLOAD_ERR_PARTIAL => 'Partial upload',
            UPLOAD_ERR_NO_FILE => 'No file',
            UPLOAD_ERR_NO_TMP_DIR => 'No temp directory',
            UPLOAD_ERR_CANT_WRITE => 'Cannot write to disk',
            UPLOAD_ERR_EXTENSION => 'PHP extension error',
        ];
        echo "<p style='color: red;'>Upload failed: " . ($errorMessages[$_FILES['test_file']['error']] ?? 'Unknown error') . "</p>";
    }
}