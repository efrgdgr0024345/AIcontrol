<?php
// Generated from ui/Ui.php.tpl and assets/*.css. Run php bin/build-ui.php.
if (!class_exists('AIcontrolUi', false)) {
final class AIcontrolUi
{
    private static ?string $nonce = null;
    public static function js(): string
    {
        return <<<'AICONTROL_UI_JS'
@@JS@@
AICONTROL_UI_JS;
    }
    public static function css(): string
    {
        return <<<'AICONTROL_UI_CSS'
@@CSS@@
AICONTROL_UI_CSS;
    }

    public static function e(string|int $value): string
    {
        return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    public static function favicon(): string
    {
        $svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 32 32"><rect width="32" height="32" rx="8" fill="#b7eedb"/><path d="m16 6 3 7 7 3-7 3-3 7-3-7-7-3 7-3z" fill="none" stroke="#182b27" stroke-width="1.6"/></svg>';
        return '<link rel="icon" href="data:image/svg+xml;base64,' . base64_encode($svg) . '">';
    }

    public static function icon(string $name): string
    {
        $paths = [
            'spark' => '<path d="m12 3 2.5 6.5L21 12l-6.5 2.5L12 21l-2.5-6.5L3 12l6.5-2.5Z"/>',
            'grid' => '<rect x="3" y="3" width="7" height="7" rx="1.5"/><rect x="14" y="3" width="7" height="7" rx="1.5"/><rect x="3" y="14" width="7" height="7" rx="1.5"/><rect x="14" y="14" width="7" height="7" rx="1.5"/>',
            'folder' => '<path d="M3 7a2 2 0 0 1 2-2h5l2 2h7a2 2 0 0 1 2 2v10a1 1 0 0 1-1 1H4a1 1 0 0 1-1-1Z"/>',
            'task' => '<rect x="5" y="4" width="14" height="17" rx="2"/><path d="M9 4V2h6v2M9 11l2 2 4-4M9 17h6"/>',
            'robot' => '<rect x="4" y="7" width="16" height="13" rx="4"/><path d="M12 7V3m-2 0h4M8 12h.01M16 12h.01M9 16h6M1 11v5m22-5v5"/>',
            'plug' => '<path d="m8 3 4 4m5-4-4 4M7 8l9 9M4 11l7-7 9 9-7 7a5 5 0 0 1-7-7l1-1M3 21l4-4"/>',
            'clock' => '<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/>',
            'upload' => '<path d="M12 16V3m-5 5 5-5 5 5M4 15v4a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2v-4"/>',
            'lock' => '<rect x="5" y="10" width="14" height="11" rx="2"/><path d="M8 10V7a4 4 0 0 1 8 0v3m-4 4v3"/>',
            'check' => '<path d="m5 12 4 4L19 6"/>',
            'arrow' => '<path d="M4 12h16m-6-6 6 6-6 6"/>',
            'plus' => '<path d="M12 5v14M5 12h14"/>',
            'search' => '<circle cx="10.5" cy="10.5" r="6.5"/><path d="m16 16 5 5"/>',
            'github' => '<path d="M9 19c-4 1-4-2-6-2m12 5v-4a3.5 3.5 0 0 0-1-3c3-.3 6-1.5 6-5a4 4 0 0 0-1-3c.3-1 .3-2 0-3-2 0-3 1-3 1a11 11 0 0 0-6 0s-1-1-3-1c-.3 1-.3 2 0 3a4 4 0 0 0-1 3c0 3.5 3 4.7 6 5a3.5 3.5 0 0 0-1 3v4"/>',
            'server' => '<rect x="3" y="3" width="18" height="7" rx="2"/><rect x="3" y="14" width="18" height="7" rx="2"/><path d="M7 6.5h.01M7 17.5h.01M12 6.5h5M12 17.5h5"/>',
            'receipt' => '<path d="M5 3h14v19l-3-2-4 2-4-2-3 2Zm4 5h6M9 12h6"/>',
        ];
        return '<svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . ($paths[$name] ?? $paths['spark']) . '</svg>';
    }

    public static function brand(string $href): string
    {
        return '<a class="ac-brand" href="' . self::e($href) . '"><span class="ac-mark">' . self::icon('spark') . '</span><span>AIcontrol</span></a>';
    }

    public static function time(string|int $value): string
    {
        try {
            $date = is_int($value) ? new DateTimeImmutable('@' . $value) : new DateTimeImmutable($value);
            return $date->setTimezone(new DateTimeZone('Australia/Perth'))->format('j M Y, g:i a') . ' AWST';
        } catch (Throwable) {
            return 'Time unavailable';
        }
    }

    public static function begin(string $title, string $entry): void
    {
        $nonce = base64_encode(random_bytes(18));
        self::$nonce = $nonce;
        header("Content-Security-Policy: default-src 'none'; style-src 'nonce-" . $nonce . "'; script-src 'nonce-" . $nonce . "'; connect-src 'self'; img-src data:; form-action 'self'; frame-ancestors 'none'; base-uri 'none'");
        echo '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>' . self::e($title) . ' · AIcontrol</title>' . self::favicon() . '<style nonce="' . self::e($nonce) . '">' . self::css() . '</style></head><body><a class="skip-link" href="#main-content">Skip to content</a><div class="auth-layout"><aside class="auth-aside">' . self::brand($entry) . '<div class="auth-story"><h2>Your projects.<br>A clearer picture.</h2><p>A workspace for the brief, the work, and everything that happens along the way.</p><div class="auth-feature">' . self::icon('folder') . 'Projects &amp; tasks</div><div class="auth-feature">' . self::icon('robot') . 'Agent run history</div><div class="auth-feature">' . self::icon('upload') . 'Verified application updates</div></div><div class="auth-caption">YOUR WORKSPACE. YOUR CONTROL.</div></aside><main class="auth-main" id="main-content">';
    }

    public static function end(): void
    {
        echo '</main></div><script nonce="' . self::e(self::$nonce ?? '') . '">' . self::js() . '</script></body></html>';
    }
}
}
