<?php
declare(strict_types=1);

$root = dirname(__DIR__);
define('PUBLIC_PATH', $root . '/public');
define('VIEWS_BASE', PUBLIC_PATH . '/views/themes');
define('DEFAULT_THEME_FOLDER', 'default');
$suffix = (string)getmypid();
$linkedFolder = 'widget-linked-' . $suffix;
$linkedRoot = VIEWS_BASE . '/' . $linkedFolder;
$outsideRoot = sys_get_temp_dir() . '/jyavani-widget-' . $suffix;
$failures = [];
$check = static function (bool $condition, string $message) use (&$failures): void {
    echo ($condition ? 'PASS' : 'FAIL') . ' ' . $message . PHP_EOL;
    if (!$condition) $failures[] = $message;
};
$remove = static function (string $path) use (&$remove): void {
    if (is_link($path) || is_file($path)) {
        @unlink($path);
        return;
    }
    if (!is_dir($path)) return;
    foreach (scandir($path) ?: [] as $entry) {
        if ($entry !== '.' && $entry !== '..') $remove($path . DIRECTORY_SEPARATOR . $entry);
    }
    @rmdir($path);
};

try {
    mkdir($outsideRoot . '/widget', 0700, true);
    file_put_contents($outsideRoot . '/widget/contract.php', '<?php echo "outside";');
    if (!function_exists('symlink') || !@symlink($outsideRoot, $linkedRoot)) {
        echo "SKIP widget symlink contract (symlink unavailable)\n";
    } else {
        function get_active_theme_folder(): string { return 'widget-linked-' . getmypid(); }
        require_once $root . '/cfg/helpers/widget_helper.php';
        $check(widget_find_view('contract') === null, 'widget resolver rejects a symlinked theme directory');
    }
} finally {
    $remove($linkedRoot);
    $remove($outsideRoot);
}

if ($failures !== []) exit(1);
echo "RESULT: ALL PASS\n";
