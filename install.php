<?php

declare(strict_types=1);

/**
 * Self-installer for cPanel users without CLI/SSH/Composer.
 * Runs once, downloads PHPMailer, builds vendor/autoload.php, then deletes itself.
 */

ini_set('display_errors', '1');
error_reporting(E_ALL);

$dir = __DIR__;
$zipUrl = 'https://github.com/PHPMailer/PHPMailer/archive/refs/tags/v6.9.1.zip';
$zipFile = $dir . '/phpmailer.zip';

header('Content-Type: text/plain; charset=utf-8');
echo "Intent Mail - Automatic Dependency Installer\n";
echo "=============================================\n\n";

if (file_exists($dir . '/vendor/autoload.php')) {
    echo "✓ vendor/autoload.php already exists!\n";
    @unlink(__FILE__);
    exit(0);
}

echo "1. Downloading PHPMailer package...\n";
$ch = curl_init($zipUrl);
$fp = fopen($zipFile, 'w+');
curl_setopt($ch, CURLOPT_TIMEOUT, 60);
curl_setopt($ch, CURLOPT_FILE, $fp);
curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
curl_exec($ch);
curl_close($ch);
fclose($fp);

if (!file_exists($zipFile) || filesize($zipFile) < 1000) {
    die("Error: Could not download PHPMailer archive.\n");
}

echo "2. Extracting package...\n";
$zip = new ZipArchive();
if ($zip->open($zipFile) === true) {
    if (!is_dir($dir . '/vendor/phpmailer')) {
        mkdir($dir . '/vendor/phpmailer', 0755, true);
    }
    $zip->extractTo($dir . '/vendor/phpmailer');
    $zip->close();
    @unlink($zipFile);
} else {
    die("Error: Failed to open zip archive.\n");
}

$extractedDir = glob($dir . '/vendor/phpmailer/PHPMailer*')[0] ?? null;
if ($extractedDir && $extractedDir !== $dir . '/vendor/phpmailer/phpmailer') {
    @rename($extractedDir, $dir . '/vendor/phpmailer/phpmailer');
}

echo "3. Creating PSR-4 autoloader...\n";
$autoloader = <<<'AUTOLOAD'
<?php
spl_autoload_register(function ($class) {
    $prefixes = [
        'IntentMail\\' => __DIR__ . '/../src/',
        'PHPMailer\\PHPMailer\\' => __DIR__ . '/phpmailer/phpmailer/src/'
    ];
    foreach ($prefixes as $prefix => $baseDir) {
        $len = strlen($prefix);
        if (strncmp($prefix, $class, $len) !== 0) {
            continue;
        }
        $relativeClass = substr($class, $len);
        $file = $baseDir . str_replace('\\', '/', $relativeClass) . '.php';
        if (file_exists($file)) {
            require $file;
            return;
        }
    }
});
AUTOLOAD;

file_put_contents($dir . '/vendor/autoload.php', $autoloader);

echo "\n✓ Installation successful!\n";
echo "✓ Deleted install.php for security.\n";
@unlink(__FILE__);

