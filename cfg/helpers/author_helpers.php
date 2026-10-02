<?php
// sudah di panggil oleh bootsrtap /jyavani-cfg/helpers/author_helpers.php
// user dengan role author cuma bisa edit postsnya sendiri

function user_avatar_display_name(array $user, string $fallback = 'User'): string {
    foreach (['name', 'username', 'email'] as $field) {
        $value = trim((string)($user[$field] ?? ''));
        if ($value !== '') return $value;
    }

    $fallback = trim($fallback);
    return $fallback !== '' ? $fallback : 'User';
}

function user_avatar_image_url(mixed $value): string {
    $url = trim((string)$value);
    if ($url === '') return '';

    if (in_array($url, ['/static/img/jyavani.svg', '/static/img/person.svg'], true)) return '';
    if (str_starts_with(strtolower($url), 'https://ui-avatars.com/api/')) return '';

    return $url;
}

function user_avatar_initial(string $displayName, string $fallback = '?'): string {
    $displayName = trim($displayName);
    $fallback = trim($fallback) !== '' ? trim($fallback) : '?';
    if ($displayName === '') return $fallback;

    $initial = function_exists('mb_substr')
        ? mb_substr($displayName, 0, 1, 'UTF-8')
        : substr($displayName, 0, 1);

    return function_exists('mb_strtoupper')
        ? mb_strtoupper($initial, 'UTF-8')
        : strtoupper($initial);
}

function user_avatar_html(mixed $imageUrl, string $displayName, array $options = []): string {
    $imageUrl = user_avatar_image_url($imageUrl);
    $displayName = trim($displayName) !== '' ? trim($displayName) : 'User';
    $imageClass = trim('user-avatar-image ' . (string)($options['image_class'] ?? ''));
    $fallbackClass = trim('user-avatar-fallback ' . (string)($options['fallback_class'] ?? ''));
    $alt = (string)($options['alt'] ?? $displayName);
    $imageAttributes = is_array($options['image_attributes'] ?? null) ? $options['image_attributes'] : [];
    $fallbackAttributes = is_array($options['fallback_attributes'] ?? null) ? $options['fallback_attributes'] : [];

    $renderAttributes = static function (array $attributes): string {
        $html = '';
        foreach ($attributes as $name => $value) {
            $name = strtolower(trim((string)$name));
            if ($name === '' || !preg_match('/^[a-z][a-z0-9:_-]*$/', $name) || $value === false || $value === null) continue;
            $html .= ' ' . htmlspecialchars($name, ENT_QUOTES, 'UTF-8');
            if ($value !== true) {
                $html .= '="' . htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '"';
            }
        }
        return $html;
    };

    foreach (['src', 'alt', 'class', 'hidden', 'onerror'] as $reserved) unset($imageAttributes[$reserved]);
    foreach (['class', 'hidden', 'role', 'aria-label'] as $reserved) unset($fallbackAttributes[$reserved]);

    $imageBase = [
        'class' => $imageClass,
        'alt' => $alt,
        'onerror' => 'this.hidden=true;this.nextElementSibling.hidden=false',
    ];
    if ($imageUrl !== '') {
        $imageBase['src'] = $imageUrl;
    } else {
        $imageBase['hidden'] = true;
    }

    $fallbackBase = [
        'class' => $fallbackClass,
        'role' => 'img',
        'aria-label' => $alt,
    ];
    if ($imageUrl !== '') $fallbackBase['hidden'] = true;

    return '<img' . $renderAttributes(array_merge($imageBase, $imageAttributes)) . '>'
        . '<span' . $renderAttributes(array_merge($fallbackBase, $fallbackAttributes)) . '>'
        . htmlspecialchars(user_avatar_initial($displayName), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
        . '</span>';
}

function require_author_owns_post(array $post, PDO $pdo): void {
    if (session_status() === PHP_SESSION_NONE) session_start();
    $uid = (int)($_SESSION['user_id'] ?? 0);
    $role = $_SESSION['user_role'] ?? null;
    if (!$role && $uid > 0) {
        $stmt = $pdo->prepare("SELECT role FROM users WHERE id = :id LIMIT 1");
        $stmt->execute([':id' => $uid]);
        $role = $stmt->fetchColumn();
        $_SESSION['user_role'] = $role;
    }
    if ($role === 'author' && (int)$post['created_by'] !== $uid) {
        http_response_code(403);
        exit('<p>Akses ditolak: Anda hanya dapat mengedit artikel buatan sendiri.</p>');
    }
}

// hanya boleh hapus tulisannya sendiri
function require_can_delete_post(array $post, PDO $pdo): void {
    if (session_status() === PHP_SESSION_NONE) session_start();
    $uid  = (int)($_SESSION['user_id'] ?? 0);
    $role = $_SESSION['user_role'] ?? null;

    if (!$role && $uid > 0) {
        $stmt = $pdo->prepare("SELECT role FROM users WHERE id = :id LIMIT 1");
        $stmt->execute([':id' => $uid]);
        $role = $stmt->fetchColumn();
        $_SESSION['user_role'] = $role;
    }

    if ($role === 'author' && (int)$post['created_by'] !== $uid) {
        http_response_code(403);
        exit('Akses ditolak: Anda hanya dapat menghapus artikel buatan sendiri.');
    }
}
