# NOTES

## LOGGER

How to use logger.

```php
$logger = new \Bema\Bema_CRM_Logger('Korede');

echo "<h2>Testing Bema_CRM_Logger Class</h2>";

// Test different log levels
echo "<h3>Writing Log Messages...</h3>";
$logger->info("This is an informational message.");
$logger->warning("A warning occurred while processing a user request.", ['user_id' => 123, 'ip_address' => '192.168.1.1']);
$logger->error("An error occurred during a database query.", ['query' => 'SELECT * FROM non_existent_table']);
$logger->debug("Debugging a variable's value: ", ['variable' => 'value', 'type' => 'string']);
$logger->emergency("System is offline! Immediate action required.");

echo "Log messages have been written to the file.<br><br>";

// Test getting logs
echo "<h3>Retrieving Logs...</h3>";
$logs = $logger->get_logs();
if ($logs) {
    echo "<pre>" . htmlspecialchars($logs) . "</pre>";
} else {
    echo "No logs found.<br>";
}

echo "<hr>";

// Test log rotation
echo "<h3>Testing Log Rotation (Simulated)...</h3>";
// To test log rotation, you can simulate a large file by adding many lines.
echo "To test log rotation, you would need to write a large amount of data to the log file. <br>";
echo "The `rotate_file_if_needed()` method will automatically rename the file once it exceeds the configured size (5MB by default).<br><br>";

// Test clearing logs
echo "<h3>Clearing Logs...</h3>";
if ($logger->clear_logs()) {
    echo "Logs have been cleared.<br>";
} else {
    echo "Failed to clear logs.<br>";
}

echo "<hr>";

// Test getting logs again after clearing
echo "<h3>Retrieving Logs After Clearing...</h3>";
$logs_after_clear = $logger->get_logs();
if ($logs_after_clear) {
    echo "<pre>" . htmlspecialchars($logs_after_clear) . "</pre>";
} else {
    echo "No logs found (as expected).<br>";
}

echo "<hr>";

// Test deleting logs (This will delete the directory and all files)
echo "<h3>Deleting All Logs and Directory...</h3>";
if ($logger->delete_logs()) {
    echo "Log directory and all files have been deleted.<br>";
} else {
    echo "Failed to delete logs.<br>";
}
echo "<br><b>Note:</b> You must manually check the `wp-content/uploads/logs/my-plugin` directory to confirm it no longer exists after this test.";
```