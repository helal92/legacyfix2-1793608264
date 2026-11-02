<?php
$out = [];
$out[] = '=== LEGACY FIX2 ' . date('c') . ' ===';
$out[] = 'uid=' . posix_geteuid() . ' user=' . (function_exists('posix_getpwuid') ? posix_getpwuid(posix_geteuid())['name'] : '?');

$index = '/var/www/vhosts/bahman.ir/httpdocs/index.php';
$out[] = 'exists=' . (file_exists($index) ? 'yes' : 'no');
$out[] = 'readable=' . (is_readable($index) ? 'yes' : 'no');
$out[] = 'writable=' . (is_writable($index) ? 'yes' : 'no');
$out[] = 'dir_writable=' . (is_writable(dirname($index)) ? 'yes' : 'no');
if (file_exists($index)) {
    $st = stat($index);
    $out[] = sprintf('perm=%o owner=%d group=%d size=%d', $st['mode'] & 0777, $st['uid'], $st['gid'], $st['size']);
    $out[] = 'uname=' . (function_exists('posix_getpwuid') ? posix_getpwuid($st['uid'])['name'] : $st['uid']);
}

// Try to make writable
@chmod($index, 0664);
@shell_exec('chmod u+w ' . escapeshellarg($index) . ' 2>&1');
clearstatcache(true, $index);
$out[] = 'after chmod writable=' . (is_writable($index) ? 'yes' : 'no');

$raw = @file_get_contents($index);
if ($raw === false) {
    $out[] = 'cannot read index';
    echo implode("\n", $out) . "\n";
    exit(1);
}

// Extract legacy block lines
$lines = explode("\n", $raw);
foreach ($lines as $i => $line) {
    if (stripos($line, 'legacy') !== false || (stripos($line, 'TEMPLATE_NAME') !== false && $i > 120 && $i < 150)) {
        $out[] = 'L' . ($i + 1) . ': ' . rtrim($line);
    }
}

if (preg_match('/legacyApplyFn\s*=\s*__NAMESPACE__/', $raw)) {
    $out[] = 'ALREADY FIXED';
} else {
    $count = 0;
    $raw2 = preg_replace(
        '/\$legacyApplyFn\s*=\s*TEMPLATE_NAME\s*\.\s*[\'"]_apply_legacy_redirects[\'"]\s*;/',
        "\$legacyApplyFn = __NAMESPACE__ . '\\\\' . TEMPLATE_NAME . '_apply_legacy_redirects';",
        $raw,
        1,
        $count
    );
    $out[] = 'preg_replace count=' . $count;
    if ($count < 1) {
        $out[] = 'pattern not found; dumping nearby:';
        foreach ($lines as $i => $line) {
            if ($i >= 125 && $i <= 145) {
                $out[] = 'L' . ($i + 1) . ': ' . rtrim($line);
            }
        }
    } else {
        $backup = $index . '.bak-legacy-' . date('YmdHis');
        $bk = @copy($index, $backup);
        $out[] = 'backup=' . ($bk ? $backup : 'FAILED');
        $ok = @file_put_contents($index, $raw2);
        if ($ok === false) {
            // try via temp + rename
            $tmp = $index . '.tmpfix';
            if (@file_put_contents($tmp, $raw2) !== false) {
                $out[] = 'wrote tmp';
                if (@rename($tmp, $index)) {
                    $out[] = 'renamed ok';
                    $ok = true;
                } else {
                    // try shell mv as last resort
                    $mv = shell_exec('cp ' . escapeshellarg($tmp) . ' ' . escapeshellarg($index) . ' 2>&1; echo EXIT:$?');
                    $out[] = 'shell cp: ' . trim((string)$mv);
                    $ok = strpos((string)$mv, 'EXIT:0') !== false;
                }
            } else {
                $out[] = 'tmp write failed';
                // try writing via tee
                $b64 = base64_encode($raw2);
                $cmd = 'echo ' . escapeshellarg($b64) . ' | base64 -d > ' . escapeshellarg($index) . ' 2>&1; echo EXIT:$?';
                $mv = shell_exec($cmd);
                $out[] = 'tee/base64: ' . trim((string)$mv);
                $ok = strpos((string)$mv, 'EXIT:0') !== false;
            }
        } else {
            $out[] = 'file_put_contents bytes=' . $ok;
        }
        clearstatcache(true, $index);
        $verify = file_get_contents($index);
        if (preg_match('/\$legacyApplyFn\s*=\s*.+/', $verify, $mm)) {
            $out[] = 'AFTER: ' . trim($mm[0]);
        }
        $out[] = 'fixed=' . (preg_match('/legacyApplyFn\s*=\s*__NAMESPACE__/', $verify) ? 'yes' : 'no');
    }
}

$out[] = '';
$out[] = '=== RETEST ===';
foreach (['/product/231', '/product/231/FORCE-F38B', '/product/716'] as $path) {
    $cmd = 'curl -skI --connect-timeout 5 --max-time 15 -H "Host: bahman.ir" "https://172.20.14.76' . $path . '" 2>&1 | head -15';
    $out[] = $path;
    $out[] = trim((string) shell_exec($cmd));
    $out[] = '---';
}

$text = implode("\n", $out) . "\n";
@file_put_contents('/tmp/legacyfix2_report.txt', $text);
echo $text;
