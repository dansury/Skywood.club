<?php
// Debug mode — surfaces errors and request traces when ?debug=1 is set.
// Enabled per-request via the `debug` query param (or the X-Sw-Debug header).

declare(strict_types=1);

function sw_debug_enabled(): bool
{
    static $on = null;
    if ($on === null) {
        $v = (string)($_GET['debug'] ?? ($_SERVER['HTTP_X_SW_DEBUG'] ?? ''));
        $on = ($v === '1' || $v === 'true' || $v === 'on');
    }
    return $on;
}

// Append-only request trace. Pass an entry to record it, omit to read the log.
function sw_debug_log(?array $entry = null): array
{
    static $log = [];
    if ($entry !== null) {
        $log[] = $entry;
    }
    return $log;
}

function sw_debug_add(string $tag, $data = null): void
{
    if (!sw_debug_enabled()) {
        return;
    }
    sw_debug_log(['tag' => $tag, 'time' => date('H:i:s'), 'data' => $data]);
}

function sw_debug_dump(): array
{
    return sw_debug_log();
}

// Hide secrets before a request/response body is written into the trace.
function sw_debug_mask(string $text): string
{
    return (string)preg_replace(
        '~(client_secret|secure_password|password|access_token)["\s:=]+[^&\s"\']+~i',
        '$1=***',
        $text
    );
}
