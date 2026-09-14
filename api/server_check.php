<?php
header('Content-Type: text/plain');

echo "=== Server Environment Check ===\n\n";

// OS Info
echo "OS: " . PHP_OS . "\n";
echo "PHP Version: " . PHP_VERSION . "\n";
echo "Server Software: " . ($_SERVER['SERVER_SOFTWARE'] ?? 'N/A') . "\n";
echo "User: " . (function_exists('posix_getpwuid') ? posix_getpwuid(posix_geteuid())['name'] : (getenv('USER') ?: 'unknown')) . "\n";
echo "\n";

// Disabled functions
$disabled = ini_get('disable_functions');
echo "Disabled Functions: " . ($disabled ?: '(none)') . "\n\n";

// Key functions availability
$funcs = ['exec', 'shell_exec', 'proc_open', 'proc_close', 'proc_terminate', 'proc_get_status', 'popen', 'passthru', 'system', 'pcntl_fork', 'pcntl_exec'];
echo "=== Function Availability ===\n";
foreach ($funcs as $f) {
    echo "$f: " . (function_exists($f) ? "✓ available" : "✗ disabled") . "\n";
}
echo "\n";

// Check installed compilers/interpreters
echo "=== Installed Languages ===\n";
$commands = [
    'python3 --version 2>&1',
    'python --version 2>&1',
    'gcc --version 2>&1 | head -1',
    'g++ --version 2>&1 | head -1',
    'node --version 2>&1',
    'java -version 2>&1 | head -1',
    'javac -version 2>&1',
    'which timeout 2>&1',
    'which ulimit 2>&1',
    'docker --version 2>&1',
    'firejail --version 2>&1',
    'nsjail --version 2>&1',
];

foreach ($commands as $cmd) {
    $label = explode(' ', $cmd)[0];
    if (function_exists('exec')) {
        $output = [];
        @exec($cmd, $output, $returnCode);
        echo "$label: " . ($returnCode === 0 ? implode(' ', $output) : "not found (exit code: $returnCode)") . "\n";
    } else {
        echo "$label: (exec disabled)\n";
    }
}
echo "\n";

// Check writable temp directory
echo "=== Filesystem ===\n";
$tmpDir = sys_get_temp_dir();
echo "Temp dir: $tmpDir\n";
echo "Temp writable: " . (is_writable($tmpDir) ? "yes" : "no") . "\n";

// Check if we can create and run files
if (function_exists('proc_open')) {
    $testFile = tempnam($tmpDir, 'cq_test_');
    file_put_contents($testFile, '#!/usr/bin/env python3\nprint("sandbox test ok")');
    chmod($testFile, 0755);
    
    $desc = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w']
    ];
    
    $proc = proc_open("python3 $testFile", $desc, $pipes, $tmpDir);
    if (is_resource($proc)) {
        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exitCode = proc_close($proc);
        echo "proc_open test: stdout='$stdout', stderr='$stderr', exit=$exitCode\n";
    } else {
        echo "proc_open test: failed to open process\n";
    }
    unlink($testFile);
} else {
    echo "proc_open: not available\n";
}

// Memory limit
echo "\nPHP memory_limit: " . ini_get('memory_limit') . "\n";
echo "PHP max_execution_time: " . ini_get('max_execution_time') . "\n";

// Check cgroup / resource limits
if (function_exists('exec')) {
    echo "\n=== Resource Limit Tools ===\n";
    $output = [];
    @exec('cat /proc/version 2>&1', $output);
    echo "Kernel: " . implode('', $output) . "\n";
    
    $output = [];
    @exec('ulimit -a 2>&1', $output);
    echo "ulimit:\n" . implode("\n", $output) . "\n";
}
?>
