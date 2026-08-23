<?php
declare(strict_types=1);

if (!isset($GLOBALS['__jy_mail_transports']) || !is_array($GLOBALS['__jy_mail_transports'])) {
    $GLOBALS['__jy_mail_transports'] = [];
}

if (!function_exists('jy_mail_transport_name_is_valid')) {
    function jy_mail_transport_name_is_valid(string $name): bool
    {
        return preg_match('/^[a-z][a-z0-9_-]{0,63}$/', $name) === 1;
    }
}

if (!function_exists('jy_mail_utf8_is_valid')) {
    function jy_mail_utf8_is_valid(string $value): bool
    {
        return function_exists('mb_check_encoding')
            ? mb_check_encoding($value, 'UTF-8')
            : preg_match('//u', $value) === 1;
    }
}

if (!function_exists('jy_mail_header_text_is_valid')) {
    function jy_mail_header_text_is_valid(string $value, int $maxBytes, bool $allowEmpty = true): bool
    {
        if ((!$allowEmpty && $value === '') || strlen($value) > $maxBytes || !jy_mail_utf8_is_valid($value)) {
            return false;
        }
        return preg_match('/[\x00-\x1F\x7F]/', $value) !== 1;
    }
}

if (!function_exists('jy_mail_address_is_valid')) {
    function jy_mail_address_is_valid(string $address): bool
    {
        $address = trim($address);
        return $address !== ''
            && strlen($address) <= 254
            && preg_match('/[\x00-\x20\x7F,;]/', $address) !== 1
            && filter_var($address, FILTER_VALIDATE_EMAIL) !== false;
    }
}

if (!function_exists('jy_mail_register_transport')) {
    function jy_mail_register_transport(string $name, callable $sender, array $metadata = []): bool
    {
        $name = strtolower(trim($name));
        if ($name === 'native' || !jy_mail_transport_name_is_valid($name)
            || isset($GLOBALS['__jy_mail_transports'][$name])) {
            return false;
        }

        $unknown = array_diff(array_keys($metadata), ['label', 'available']);
        if ($unknown !== []) return false;
        $label = isset($metadata['label']) && is_string($metadata['label'])
            ? trim($metadata['label'])
            : $name;
        if ($label === '' || strlen($label) > 100 || !jy_mail_utf8_is_valid($label)
            || preg_match('/[\x00-\x1F\x7F]/', $label) === 1) {
            return false;
        }
        $available = $metadata['available'] ?? true;
        if (!is_bool($available) && !is_callable($available)) return false;

        $GLOBALS['__jy_mail_transports'][$name] = [
            'sender' => $sender,
            'label' => $label,
            'available' => $available,
            'builtin' => false,
        ];
        return true;
    }
}

if (!function_exists('jy_mail_transport_available')) {
    function jy_mail_transport_available(string $name): bool
    {
        $transport = $GLOBALS['__jy_mail_transports'][$name] ?? null;
        if (!is_array($transport)) return false;
        $available = $transport['available'] ?? false;
        try {
            return is_callable($available) ? (bool)$available() : $available === true;
        } catch (Throwable $error) {
            return false;
        }
    }
}

if (!function_exists('jy_mail_transports')) {
    function jy_mail_transports(): array
    {
        $rows = [];
        foreach ($GLOBALS['__jy_mail_transports'] as $name => $transport) {
            if (!is_array($transport)) continue;
            $rows[$name] = [
                'name' => $name,
                'label' => (string)($transport['label'] ?? $name),
                'available' => jy_mail_transport_available($name),
                'builtin' => ($transport['builtin'] ?? false) === true,
            ];
        }
        ksort($rows);
        return $rows;
    }
}

if (!function_exists('jy_mail_setting')) {
    function jy_mail_setting(PDO $pdo, string $key, string $environmentKey, string $default = ''): string
    {
        $environment = function_exists('env') ? env($environmentKey, $default) : getenv($environmentKey);
        if (!is_string($environment)) $environment = $default;
        return function_exists('settings_get')
            ? (settings_get($pdo, $key, $environment) ?? $environment)
            : $environment;
    }
}

if (!function_exists('jy_mail_config')) {
    function jy_mail_config(PDO $pdo): array
    {
        $siteTitle = function_exists('settings_get') ? (settings_get($pdo, 'site_title', 'Jyavani') ?? 'Jyavani') : 'Jyavani';
        $fromName = jy_mail_setting($pdo, 'mail_from_name', 'MAIL_FROM_NAME', $siteTitle);
        return [
            'transport' => strtolower(trim(jy_mail_setting($pdo, 'mail_transport', 'MAIL_TRANSPORT', 'native'))),
            'fallback_transport' => strtolower(trim(jy_mail_setting($pdo, 'mail_fallback_transport', 'MAIL_FALLBACK_TRANSPORT', ''))),
            'from_address' => trim(jy_mail_setting($pdo, 'mail_from_address', 'MAIL_FROM_ADDRESS', '')),
            'from_name' => trim($fromName),
            'reply_to' => trim(jy_mail_setting($pdo, 'mail_reply_to', 'MAIL_REPLY_TO', '')),
            'log' => strtolower(trim(jy_mail_setting($pdo, 'mail_log', 'MAIL_LOG', 'failures'))),
        ];
    }
}

if (!function_exists('jy_mail_result')) {
    function jy_mail_result(
        string $id,
        bool $ok,
        string $status,
        string $code,
        ?string $transport = null,
        bool $fallbackUsed = false
    ): array {
        return [
            'id' => $id,
            'ok' => $ok,
            'status' => $status,
            'code' => $code,
            'transport' => $transport,
            'fallback_used' => $fallbackUsed,
        ];
    }
}

if (!function_exists('jy_mail_normalize_mailbox')) {
    function jy_mail_normalize_mailbox(mixed $mailbox, string $defaultEmail = '', string $defaultName = ''): ?array
    {
        if ($mailbox === null) {
            $mailbox = ['email' => $defaultEmail, 'name' => $defaultName];
        }
        if (!is_array($mailbox) || array_diff(array_keys($mailbox), ['email', 'name']) !== []) return null;
        $email = $mailbox['email'] ?? '';
        $name = $mailbox['name'] ?? '';
        if (!is_string($email) || !is_string($name)) return null;
        $email = trim($email);
        $name = trim($name);
        if (!jy_mail_address_is_valid($email) || !jy_mail_header_text_is_valid($name, 200)) return null;
        return ['email' => $email, 'name' => $name];
    }
}

if (!function_exists('jy_mail_normalize_message')) {
    function jy_mail_normalize_message(PDO $pdo, array $message): array
    {
        if (array_diff(array_keys($message), ['to', 'subject', 'body', 'content_type', 'from', 'reply_to']) !== []) {
            return ['ok' => false, 'code' => 'invalid_message'];
        }
        $to = $message['to'] ?? null;
        if (!is_array($to) || !array_is_list($to) || $to === [] || count($to) > 100) {
            return ['ok' => false, 'code' => 'invalid_recipient'];
        }
        $recipients = [];
        foreach ($to as $address) {
            if (!is_string($address) || !jy_mail_address_is_valid($address)) {
                return ['ok' => false, 'code' => 'invalid_recipient'];
            }
            $recipients[] = trim($address);
        }
        if (count(array_unique(array_map('strtolower', $recipients))) !== count($recipients)) {
            return ['ok' => false, 'code' => 'invalid_recipient'];
        }

        $subject = $message['subject'] ?? null;
        if (!is_string($subject) || !jy_mail_header_text_is_valid(trim($subject), 255, false)) {
            return ['ok' => false, 'code' => 'invalid_subject'];
        }
        $subject = trim($subject);
        $body = $message['body'] ?? null;
        if (!is_string($body) || $body === '' || strlen($body) > 5 * 1024 * 1024
            || str_contains($body, "\0") || !jy_mail_utf8_is_valid($body)) {
            return ['ok' => false, 'code' => 'invalid_body'];
        }
        $contentType = $message['content_type'] ?? 'text/plain';
        if (!is_string($contentType) || !in_array($contentType, ['text/plain', 'text/html'], true)) {
            return ['ok' => false, 'code' => 'invalid_content_type'];
        }

        $config = jy_mail_config($pdo);
        $from = jy_mail_normalize_mailbox(
            $message['from'] ?? null,
            (string)$config['from_address'],
            (string)$config['from_name']
        );
        if ($from === null) return ['ok' => false, 'code' => 'invalid_sender'];

        $replyValue = $message['reply_to'] ?? null;
        if ($replyValue === null && (string)$config['reply_to'] !== '') {
            $replyValue = ['email' => (string)$config['reply_to'], 'name' => ''];
        }
        $replyTo = null;
        if ($replyValue !== null) {
            $replyTo = jy_mail_normalize_mailbox($replyValue);
            if ($replyTo === null) return ['ok' => false, 'code' => 'invalid_reply_to'];
        }

        return [
            'ok' => true,
            'message' => [
                'to' => $recipients,
                'subject' => $subject,
                'body' => $body,
                'content_type' => $contentType,
                'from' => $from,
                'reply_to' => $replyTo,
            ],
        ];
    }
}

if (!function_exists('jy_mail_encode_header')) {
    function jy_mail_encode_header(string $value): string
    {
        if (preg_match('/^[\x20-\x7E]*$/', $value) === 1) return $value;
        if (function_exists('mb_encode_mimeheader')) {
            return mb_encode_mimeheader($value, 'UTF-8', 'B', "\r\n");
        }
        return '=?UTF-8?B?' . base64_encode($value) . '?=';
    }
}

if (!function_exists('jy_mail_format_mailbox')) {
    function jy_mail_format_mailbox(array $mailbox): string
    {
        $email = (string)$mailbox['email'];
        $name = (string)($mailbox['name'] ?? '');
        if ($name === '') return $email;
        $display = preg_match('/^[\x20-\x7E]*$/', $name) === 1
            ? '"' . addcslashes($name, "\\\"") . '"'
            : jy_mail_encode_header($name);
        return $display . ' <' . $email . '>';
    }
}

if (!function_exists('jy_mail_native_transport')) {
    function jy_mail_native_transport(array $message, array $context): array
    {
        if (!function_exists('mail')) return ['status' => 'temporary_failure'];
        $headers = [
            'MIME-Version' => '1.0',
            'Content-Type' => $message['content_type'] . '; charset=UTF-8',
            'From' => jy_mail_format_mailbox($message['from']),
        ];
        if (is_array($message['reply_to'] ?? null)) {
            $headers['Reply-To'] = jy_mail_format_mailbox($message['reply_to']);
        }

        $warningRaised = false;
        set_error_handler(static function () use (&$warningRaised): bool {
            $warningRaised = true;
            return true;
        });
        try {
            $accepted = mail(
                implode(', ', $message['to']),
                jy_mail_encode_header($message['subject']),
                $message['body'],
                $headers
            );
        } catch (Throwable $error) {
            $accepted = false;
        } finally {
            restore_error_handler();
        }
        return (!$warningRaised && $accepted) ? ['status' => 'accepted'] : ['status' => 'temporary_failure'];
    }
}

if (!isset($GLOBALS['__jy_mail_transports']['native'])) {
    $GLOBALS['__jy_mail_transports']['native'] = [
        'sender' => 'jy_mail_native_transport',
        'label' => 'PHP mail()',
        'available' => static fn(): bool => function_exists('mail'),
        'builtin' => true,
    ];
}

if (!function_exists('jy_mail_invoke_transport')) {
    function jy_mail_invoke_transport(string $name, array $message, string $id): array
    {
        $transport = $GLOBALS['__jy_mail_transports'][$name] ?? null;
        if (!is_array($transport) || !jy_mail_transport_available($name)) {
            return jy_mail_result($id, false, 'failed', 'transport_unavailable', $name);
        }
        try {
            if (function_exists('do_action')) do_action('jy_mail_before_send', $message, $name, $id);
        } catch (Throwable $error) {
            return jy_mail_result($id, false, 'failed', 'hook_failed', $name);
        }
        try {
            $response = call_user_func($transport['sender'], $message, ['id' => $id, 'transport' => $name]);
        } catch (Throwable $error) {
            return jy_mail_result($id, false, 'failed', 'transport_exception', $name);
        }
        if (!is_array($response) || array_diff(array_keys($response), ['status']) !== []) {
            return jy_mail_result($id, false, 'failed', 'transport_contract_invalid', $name);
        }
        return match ($response['status'] ?? null) {
            'accepted' => jy_mail_result($id, true, 'accepted', 'accepted', $name),
            'temporary_failure' => jy_mail_result($id, false, 'failed', 'transport_temporary_failure', $name),
            'permanent_failure' => jy_mail_result($id, false, 'failed', 'transport_permanent_failure', $name),
            default => jy_mail_result($id, false, 'failed', 'transport_contract_invalid', $name),
        };
    }
}

if (!function_exists('jy_mail_log_result')) {
    function jy_mail_log_result(PDO $pdo, array $result, int $recipientCount): void
    {
        $mode = (string)(jy_mail_config($pdo)['log'] ?? 'failures');
        if (!in_array($mode, ['off', 'failures', 'all'], true) || $mode === 'off') return;
        if ($mode === 'failures' && ($result['ok'] ?? false) === true) return;
        error_log(sprintf(
            '[jy-mail] id=%s status=%s code=%s transport=%s fallback=%d recipients=%d',
            (string)($result['id'] ?? '-'),
            (string)($result['status'] ?? 'failed'),
            (string)($result['code'] ?? 'unknown'),
            (string)($result['transport'] ?? '-'),
            ($result['fallback_used'] ?? false) === true ? 1 : 0,
            max(0, $recipientCount)
        ));
    }
}

if (!function_exists('jy_mail_send')) {
    function jy_mail_send(PDO $pdo, array $message, array $options = []): array
    {
        try {
            $id = bin2hex(random_bytes(12));
        } catch (Throwable $error) {
            $id = hash('sha256', uniqid('', true) . microtime(true));
        }
        if (array_diff(array_keys($options), ['transport', 'fallback_transport', 'dry_run']) !== []) {
            $result = jy_mail_result($id, false, 'rejected', 'invalid_options');
            jy_mail_log_result($pdo, $result, 0);
            return $result;
        }

        $config = jy_mail_config($pdo);
        $transport = $options['transport'] ?? $config['transport'];
        $fallback = $options['fallback_transport'] ?? $config['fallback_transport'];
        $dryRun = $options['dry_run'] ?? false;
        if (!is_string($transport) || !is_string($fallback) || !is_bool($dryRun)) {
            $result = jy_mail_result($id, false, 'rejected', 'invalid_options');
            jy_mail_log_result($pdo, $result, 0);
            return $result;
        }
        $transport = strtolower(trim($transport));
        $fallback = strtolower(trim($fallback));
        if (!jy_mail_transport_name_is_valid($transport)
            || ($fallback !== '' && (!jy_mail_transport_name_is_valid($fallback) || $fallback === $transport))) {
            $result = jy_mail_result($id, false, 'rejected', 'invalid_options');
            jy_mail_log_result($pdo, $result, 0);
            return $result;
        }

        $normalized = jy_mail_normalize_message($pdo, $message);
        if (($normalized['ok'] ?? false) !== true) {
            $result = jy_mail_result($id, false, 'rejected', (string)($normalized['code'] ?? 'invalid_message'));
            jy_mail_log_result($pdo, $result, 0);
            return $result;
        }
        $normalizedMessage = $normalized['message'];
        if (function_exists('apply_filters')) {
            try {
                $filtered = apply_filters('jy_mail_message', $normalizedMessage, $options);
            } catch (Throwable $error) {
                $result = jy_mail_result($id, false, 'rejected', 'hook_failed');
                jy_mail_log_result($pdo, $result, count($normalizedMessage['to']));
                return $result;
            }
            if (!is_array($filtered)) {
                $result = jy_mail_result($id, false, 'rejected', 'hook_failed');
                jy_mail_log_result($pdo, $result, count($normalizedMessage['to']));
                return $result;
            }
            $renormalized = jy_mail_normalize_message($pdo, $filtered);
            if (($renormalized['ok'] ?? false) !== true) {
                $result = jy_mail_result($id, false, 'rejected', (string)($renormalized['code'] ?? 'invalid_message'));
                jy_mail_log_result($pdo, $result, count($normalizedMessage['to']));
                return $result;
            }
            $normalizedMessage = $renormalized['message'];
        }

        if (function_exists('apply_filters')) {
            try {
                $selected = apply_filters('jy_mail_transport', $transport, $normalizedMessage, $options);
            } catch (Throwable $error) {
                $result = jy_mail_result($id, false, 'rejected', 'hook_failed');
                jy_mail_log_result($pdo, $result, count($normalizedMessage['to']));
                return $result;
            }
            if (!is_string($selected) || !jy_mail_transport_name_is_valid($selected)) {
                $result = jy_mail_result($id, false, 'rejected', 'hook_failed');
                jy_mail_log_result($pdo, $result, count($normalizedMessage['to']));
                return $result;
            }
            $transport = $selected;
            if ($fallback === $transport) $fallback = '';
        }

        if ($dryRun) {
            $result = jy_mail_transport_available($transport)
                ? jy_mail_result($id, true, 'skipped', 'dry_run', $transport)
                : jy_mail_result($id, false, 'failed', 'transport_unavailable', $transport);
            jy_mail_log_result($pdo, $result, count($normalizedMessage['to']));
            return $result;
        }

        $result = jy_mail_invoke_transport($transport, $normalizedMessage, $id);
        $retryable = in_array($result['code'], [
            'transport_unavailable',
            'transport_temporary_failure',
            'transport_exception',
        ], true);
        if (!$result['ok'] && $retryable && $fallback !== '') {
            $result = jy_mail_invoke_transport($fallback, $normalizedMessage, $id);
            $result['fallback_used'] = true;
        }

        try {
            if (function_exists('do_action')) do_action('jy_mail_after_send', $result, $normalizedMessage);
        } catch (Throwable $error) {
            error_log('[jy-mail] after-send observer failed id=' . $id);
        }
        jy_mail_log_result($pdo, $result, count($normalizedMessage['to']));
        return $result;
    }
}
