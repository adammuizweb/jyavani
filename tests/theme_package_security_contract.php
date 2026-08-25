<?php
declare(strict_types=1);

final class ThemePackageContractStatement extends PDOStatement
{
    private array $rows = [];

    public function __construct(private ThemePackageContractPdo $pdo, private string $query)
    {
    }

    public function execute(?array $params = null): bool
    {
        $this->rows = [];
        if (str_starts_with($this->query, 'SELECT is_system, is_active FROM themes')) {
            return true;
        }
        if (str_starts_with($this->query, 'INSERT INTO themes')) {
            if ($this->pdo->mutationTarget !== '') file_put_contents($this->pdo->mutationTarget . '/foreign.txt', 'changed');
            if ($this->pdo->failRegistration) throw new RuntimeException('contract registration failure');
            $this->pdo->registered = true;
        }
        return true;
    }

    public function fetch(int $mode = PDO::FETCH_DEFAULT, int $cursorOrientation = PDO::FETCH_ORI_NEXT, int $cursorOffset = 0): mixed
    {
        return array_shift($this->rows) ?: false;
    }
}

final class ThemePackageContractPdo extends PDO
{
    public bool $registered = false;
    public bool $failRegistration = false;
    public string $mutationTarget = '';
    private bool $transaction = false;
    private bool $registeredSnapshot = false;

    public function __construct()
    {
    }

    public function prepare(string $query, array $options = []): PDOStatement|false
    {
        return new ThemePackageContractStatement($this, $query);
    }

    public function beginTransaction(): bool
    {
        if ($this->transaction) return false;
        $this->transaction = true;
        $this->registeredSnapshot = $this->registered;
        return true;
    }

    public function inTransaction(): bool
    {
        return $this->transaction;
    }

    public function commit(): bool
    {
        if (!$this->transaction) return false;
        $this->transaction = false;
        return true;
    }

    public function rollBack(): bool
    {
        if (!$this->transaction) return false;
        $this->registered = $this->registeredSnapshot;
        $this->transaction = false;
        return true;
    }
}

$root = dirname(__DIR__);
$fixture = sys_get_temp_dir() . '/jy-theme-package-' . bin2hex(random_bytes(6));
mkdir($fixture . '/public/views/themes', 0770, true);
mkdir($fixture . '/backend/var', 0750, true);
define('PUBLIC_PATH', $fixture . '/public');
define('VIEWS_BASE', $fixture . '/public/views/themes');
define('BACKEND_PATH', $fixture . '/backend');
define('THEME_DEBUG', false);
require_once $root . '/cfg/helpers/theme_helper.php';

$failures = [];
$check = static function (bool $condition, string $message) use (&$failures): void {
    echo ($condition ? 'PASS' : 'FAIL') . ' ' . $message . PHP_EOL;
    if (!$condition) $failures[] = $message;
};

$makeZip = static function (string $path, string $entry, ?int $unixType = null): void {
    $zip = new ZipArchive();
    $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
    $zip->addFromString('safe/theme.json', json_encode(['folder' => 'safe', 'name' => 'safe', 'version' => '1.0.0']));
    $zip->addFromString($entry, 'payload');
    if ($unixType !== null) $zip->setExternalAttributesName($entry, ZipArchive::OPSYS_UNIX, ($unixType | 0777) << 16);
    $zip->close();
};

try {
    $traversal = $fixture . '/traversal.zip';
    $makeZip($traversal, 'safe/../escape.php');
    $traversalResult = install_theme_from_zip(null, $traversal, false, null, 'safe');
    $check(($traversalResult['success'] ?? false) === false, 'theme installer rejects traversal entries');

    $symlink = $fixture . '/symlink.zip';
    $makeZip($symlink, 'safe/link.php', 0120000);
    $symlinkResult = install_theme_from_zip(null, $symlink, false, null, 'safe');
    $check(($symlinkResult['success'] ?? false) === false, 'theme installer rejects symlink entries');

    $fifo = $fixture . '/fifo.zip';
    $makeZip($fifo, 'safe/pipe', 0010000);
    $fifoResult = install_theme_from_zip(null, $fifo, false, null, 'safe');
    $check(($fifoResult['success'] ?? false) === false, 'theme installer rejects special filesystem entries');

    $identity = $fixture . '/identity.zip';
    $makeZip($identity, 'safe/header.php');
    $identityResult = install_theme_from_zip(null, $identity, false, null, 'different');
    $check(($identityResult['success'] ?? false) === false, 'Store theme installation binds the requested package identity');

    $caseConflict = $fixture . '/case-conflict.zip';
    $zip = new ZipArchive();
    $zip->open($caseConflict, ZipArchive::CREATE | ZipArchive::OVERWRITE);
    $zip->addFromString('theme.json', json_encode(['folder' => 'Safe', 'name' => 'safe', 'version' => '1.0.0']));
    $zip->addFromString('header.php', '<?php echo "safe";');
    $zip->close();
    $caseResult = install_theme_from_zip(null, $caseConflict, false, null, 'safe');
    $check(($caseResult['success'] ?? false) === false && str_contains((string)$caseResult['message'], 'identity'), 'Store theme identity comparison is exact and never sanitizes conflicting case');

    $folderless = $fixture . '/folderless.zip';
    $zip = new ZipArchive();
    $zip->open($folderless, ZipArchive::CREATE | ZipArchive::OVERWRITE);
    $zip->addFromString('theme.json', json_encode(['name' => 'Store Theme', 'version' => '1.0.0']));
    $zip->addFromString('header.php', '<?php echo "safe";');
    $zip->close();
    $folderlessResult = install_theme_from_zip(new PDO('sqlite::memory:'), $folderless, false, null, 'store-theme');
    $check(($folderlessResult['success'] ?? false) === false && str_contains((string)$folderlessResult['message'], 'DB register failed')
        && !str_contains((string)$folderlessResult['message'], 'identity') && !file_exists(VIEWS_BASE . '/store-theme'),
        'folderless Store packages bind to the trusted requested folder and remove the exact fresh publication after registration failure');

    $manifestOnly = $fixture . '/manifest-only.zip';
    $zip = new ZipArchive();
    $zip->open($manifestOnly, ZipArchive::CREATE | ZipArchive::OVERWRITE);
    $zip->addFromString('theme.json', json_encode(['folder' => 'safe', 'name' => 'safe', 'version' => '1.0.0']));
    $zip->close();
    $manifestOnlyResult = install_theme_from_zip(null, $manifestOnly, false, null, 'safe');
    $check(($manifestOnlyResult['success'] ?? false) === false, 'theme installer rejects manifest-only packages');

    $missingManifest = $fixture . '/missing-manifest.zip';
    $zip = new ZipArchive();
    $zip->open($missingManifest, ZipArchive::CREATE | ZipArchive::OVERWRITE);
    $zip->addFromString('safe/header.php', '<?php echo "safe";');
    $zip->close();
    $missingManifestResult = install_theme_from_zip(null, $missingManifest, false, null, 'safe');
    $check(($missingManifestResult['success'] ?? false) === false, 'theme installer requires a valid manifest');

    $ratioBomb = $fixture . '/ratio.zip';
    $zip = new ZipArchive();
    $zip->open($ratioBomb, ZipArchive::CREATE | ZipArchive::OVERWRITE);
    $zip->addFromString('safe/theme.json', json_encode(['folder' => 'safe', 'name' => 'safe', 'version' => '1.0.0']));
    $zip->addFromString('safe/header.php', '<?php /*' . str_repeat('A', 2 * 1024 * 1024) . '*/');
    $zip->close();
    $ratioResult = install_theme_from_zip(null, $ratioBomb, false, null, 'safe');
    $check(($ratioResult['success'] ?? false) === false, 'theme installer rejects extreme compression ratios');

    $residualPackage = $fixture . '/residual-theme.zip';
    $zip = new ZipArchive();
    $zip->open($residualPackage, ZipArchive::CREATE | ZipArchive::OVERWRITE);
    $zip->addFromString('theme.json', json_encode(['folder' => 'residual-theme', 'name' => 'Residual', 'version' => '1.0.0']));
    $zip->addFromString('header.php', '<?php echo "safe";');
    $zip->close();
    $themeRecovery = package_unique_publication_recovery_path(VIEWS_BASE . '/residual-theme', 'old');
    mkdir($themeRecovery, 0755);
    file_put_contents($themeRecovery . '/preserved.marker', 'recovery');
    $themeEntriesBefore = scandir(VIEWS_BASE);
    $residualResult = install_theme_from_zip(new ThemePackageContractPdo(), $residualPackage, false, null, 'residual-theme');
    $check(!($residualResult['success'] ?? false)
        && str_contains((string)$residualResult['message'], 'Inspect and restore or archive')
        && scandir(VIEWS_BASE) === $themeEntriesBefore
        && !file_exists(VIEWS_BASE . '/residual-theme')
        && is_file($themeRecovery . '/preserved.marker'),
        'fresh theme publication detects residual recovery state under lock before staging, DB, or target mutation');
    package_remove_tree($themeRecovery);

    $flatMultiRoot = $fixture . '/flat-multi-root.zip';
    $zip = new ZipArchive();
    $zip->open($flatMultiRoot, ZipArchive::CREATE | ZipArchive::OVERWRITE);
    $zip->addFromString('theme.json', json_encode(['folder' => 'flat-multi-root', 'name' => 'Flat Multi Root', 'version' => '1.0.0']));
    $zip->addFromString('header.php', '<?php echo "flat";');
    $zip->addFromString('assets/css/theme.css', 'body{}');
    $zip->addFromString('templates/card.html', '<article></article>');
    $zip->addEmptyDir('partials/empty');
    $zip->close();
    $contractPdo = new ThemePackageContractPdo();
    $flatResult = install_theme_from_zip($contractPdo, $flatMultiRoot, false, null, 'flat-multi-root');
    $flatTarget = VIEWS_BASE . '/flat-multi-root';
    $check(($flatResult['success'] ?? false) === true && $contractPdo->registered
        && is_file($flatTarget . '/assets/css/theme.css') && is_file($flatTarget . '/templates/card.html')
        && is_dir($flatTarget . '/partials/empty')
        && (fileperms($flatTarget . '/header.php') & 0777) === 0644
        && (fileperms($flatTarget . '/assets') & 0777) === 0755,
        'flat multi-root package shape that previously required copy fallback publishes a complete permission-verified tree');

    $changedPackage = $fixture . '/changed-cleanup.zip';
    $zip = new ZipArchive();
    $zip->open($changedPackage, ZipArchive::CREATE | ZipArchive::OVERWRITE);
    $zip->addFromString('theme.json', json_encode(['folder' => 'changed-cleanup', 'name' => 'Changed Cleanup', 'version' => '1.0.0']));
    $zip->addFromString('header.php', '<?php echo "changed";');
    $zip->close();
    $changedTarget = VIEWS_BASE . '/changed-cleanup';
    $failingPdo = new ThemePackageContractPdo();
    $failingPdo->failRegistration = true;
    $failingPdo->mutationTarget = $changedTarget;
    $changedResult = install_theme_from_zip($failingPdo, $changedPackage, false, null, 'changed-cleanup');
    $check(!($changedResult['success'] ?? false) && is_file($changedTarget . '/foreign.txt')
        && is_file($changedTarget . '/header.php')
        && str_contains((string)$changedResult['message'], 'preserved because exact cleanup identity could not be verified'),
        'registration failure never removes a fresh publication whose exact identity changed after publication');

    $themeHelperSource = (string)file_get_contents($root . '/cfg/helpers/theme_helper.php');
    $installSource = substr(
        $themeHelperSource,
        (int)strpos($themeHelperSource, 'function install_theme_from_zip('),
        (int)strpos($themeHelperSource, '// Small helpers used by installer') - (int)strpos($themeHelperSource, 'function install_theme_from_zip(')
    );
    $check(str_contains($installSource, "package_private_directory(\$parent, 'theme-stage-'")
        && str_contains($installSource, 'package_archive_extract_files($zip, $files, $stage)')
        && substr_count($installSource, '@rename($stage, $destFs)') === 1
        && !str_contains($installSource, 'helper_recurse_copy') && !str_contains($installSource, 'extractTo('),
        'all theme package layouts stream only into a same-parent private stage and publish with one rename, never a live recursive copy');
} finally {
    if (is_dir($fixture)) {
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($fixture, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($iterator as $entry) $entry->isDir() ? @rmdir($entry->getPathname()) : @unlink($entry->getPathname());
        @rmdir($fixture);
    }
}

if ($failures !== []) exit(1);
echo "RESULT: ALL PASS\n";
