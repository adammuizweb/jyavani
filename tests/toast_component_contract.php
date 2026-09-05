<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$script = (string)file_get_contents($root . '/public/static/components/toast/toast.js');
$style = (string)file_get_contents($root . '/public/static/components/toast/toast.css');
$failures = [];
$checks = 0;
$check = static function (bool $condition, string $message) use (&$failures, &$checks): void {
    $checks++;
    echo ($condition ? 'PASS' : 'FAIL') . ' ' . $message . PHP_EOL;
    if (!$condition) $failures[] = $message;
};

$check(str_contains($script, "typeof rawAction.onClick === 'function'")
    && str_contains($script, "request ? function(){ return runRequestAction(request); }")
    && str_contains($script, "label: String(rawAction.label).trim()"), 'toast actions require a label and a callback or request');
$check(str_contains($script, "credentials: 'same-origin'")
    && str_contains($script, "'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8'")
    && str_contains($script, 'data.ok !== true'), 'declarative actions POST same-origin form data and require an explicit success response');
$check(str_contains($script, 'error.terminal = response.status === 409')
    && str_contains($script, 'if (terminal)')
    && str_contains($script, 'removeToast(toast);'), 'expired or conflicting Undo actions close instead of remaining retryable');
$check(str_contains($script, 'let finalDuration = action ? 0')
    && str_contains($script, 'Number(rawDuration) >= 0'), 'action toasts remain available longer and explicit persistent toasts are supported');
$check(str_contains($script, 'lucide-undo-2')
    && str_contains($script, 'newnotif-toast__action')
    && strpos($script, "'  ' + leading") < strpos($script, 'newnotif-toast__content'), 'Undo renders in the leading slot before notification text');
foreach (['lucide-circle-check', 'lucide-alert-triangle', 'lucide-info', 'lucide-circle-x'] as $icon) {
    $check(str_contains($script, $icon), $icon . ' is available as a Lucide status icon');
}
$check(str_contains($script, "toast.addEventListener('mouseenter'")
    && str_contains($script, "toast.addEventListener('focusin'")
    && str_contains($style, '.newnotif-toast.is-paused'), 'hover and keyboard focus pause dismissal and progress');
$check(str_contains($script, "actionBtn.setAttribute('aria-busy', 'true')")
    && str_contains($script, "Promise.resolve(result).then"), 'asynchronous actions expose and guard their pending state');
$check(str_contains($style, '@media (prefers-reduced-motion: reduce)')
    && str_contains($style, '.newnotif-toast__action:focus-visible'), 'action styling supports keyboard focus and reduced motion');

if ($failures !== []) {
    fwrite(STDERR, count($failures) . " toast component contract check(s) failed.\n");
    exit(1);
}
echo "Toast component contract passed ({$checks} checks).\n";
