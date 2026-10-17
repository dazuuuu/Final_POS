<?php
// app/helpers/ShopTheme.php
// Per-account colours for the POS shell (owner and staff, every package).
// Only the colours change — layout and look stay the template's. Stored on
// the account's top-level tenant (tenants.theme, JSON), so branches and staff
// get the same colours:
//
//   mode 'default' — the standard template colours
//   mode 'logo'    — picked from the shop's logo: its strongest colour for
//                    buttons / brand bar, a deep shade of it for the sidebar
//                    (re-picked whenever a new logo is uploaded)
//   mode 'custom'  — the owner's own brand + sidebar colours
//
// New accounts and accounts that upload a logo while still on the default
// get mode 'logo' automatically.

class ShopTheme
{
    const DEFAULT = ['brand' => '#3cb878', 'sidebar' => '#2c3e50'];

    /** ['mode' => …, 'brand' => '#rrggbb', 'sidebar' => '#rrggbb'] for the account $tenantId belongs to. */
    public static function get(?int $tenantId): array
    {
        $theme = ['mode' => 'default'] + self::DEFAULT;
        if ($tenantId === null) {
            return $theme;
        }
        try {
            self::ensureColumn();
            $st = Database::pdo()->prepare('SELECT theme FROM tenants WHERE id = ?');
            $st->execute([Features::accountId($tenantId)]);
            $saved = json_decode((string) $st->fetchColumn(), true);
            if (is_array($saved) && in_array($saved['mode'] ?? '', ['logo', 'custom'], true)
                && self::isHex($saved['brand'] ?? '') && self::isHex($saved['sidebar'] ?? '')) {
                $theme = ['mode' => $saved['mode'], 'brand' => strtolower($saved['brand']), 'sidebar' => strtolower($saved['sidebar'])];
            }
        } catch (Throwable $e) { /* default colours */ }
        return $theme;
    }

    public static function save(int $tenantId, string $mode, ?string $brand = null, ?string $sidebar = null): void
    {
        self::ensureColumn();
        $account = Features::accountId($tenantId);
        $value = null;
        if ($mode === 'custom' && self::isHex((string) $brand) && self::isHex((string) $sidebar)) {
            $value = json_encode(['mode' => 'custom', 'brand' => strtolower($brand), 'sidebar' => strtolower($sidebar)]);
        } elseif ($mode === 'logo' && ($picked = self::fromLogo(self::logoFile($account)))) {
            $value = json_encode(['mode' => 'logo'] + $picked);
        }
        Database::pdo()->prepare('UPDATE tenants SET theme = ? WHERE id = ?')->execute([$value, $account]);
    }

    /** After a logo upload: re-pick colours unless the owner chose custom colours. */
    public static function logoChanged(int $tenantId): void
    {
        if (self::get($tenantId)['mode'] !== 'custom') {
            self::save($tenantId, 'logo');
        }
    }

    /** <style> overriding the shell's colour tokens; '' on the default theme. */
    public static function style(?int $tenantId): string
    {
        $t = self::get($tenantId);
        if ($t['mode'] === 'default') {
            return '';
        }
        $b = $t['brand'];
        $s = $t['sidebar'];
        [$r, $g, $bl] = self::rgb($b);
        $vars = [
            '--tk-green'       => $b,
            '--tk-green-dark'  => self::mix($b, '#000000', .18),
            '--tk-green-light' => self::mix($b, '#ffffff', .88),
            '--tk-navy'        => $s,
            '--tk-navy-dark'   => self::mix($s, '#000000', .2),
            '--tk-navy-hover'  => self::mix($s, '#ffffff', .1),
            '--tk-nav-text'    => self::mix($s, '#ffffff', .78),
            '--pos-green'      => $b,
            '--pos-green-dark' => self::mix($b, '#000000', .18),
            '--pos-green-light'=> self::mix($b, '#ffffff', .88),
            '--tk-brand-ink'   => self::readableOn($b),
        ];
        $css = ':root{';
        foreach ($vars as $k => $v) {
            $css .= "$k:$v;";
        }
        $css .= '}';
        // Text on the brand bar / buttons: dark when the brand colour is light.
        $css .= '.tk-brand{color:var(--tk-brand-ink) !important;}'
              . ".form-control:focus,.form-select:focus{box-shadow:0 0 0 .2rem rgba($r,$g,$bl,.15) !important;}";
        return "<style id=\"shop-theme\">$css</style>";
    }

    // ------------------------------------------------------------ colour pick

    /**
     * Pick ['brand', 'sidebar'] from a logo image: count the logo's colours
     * (white, near-black, grey and transparent pixels don't count), take the
     * most common strong colour as the brand and a deep shade of it for the
     * sidebar. Null when the logo has no usable colour (e.g. black on white).
     */
    public static function fromLogo(?string $file): ?array
    {
        if (!$file || !is_file($file) || !function_exists('imagecreatefromstring')) {
            return null;
        }
        $img = @imagecreatefromstring((string) file_get_contents($file));
        if (!$img) {
            return null;
        }
        $w = imagesx($img);
        $h = imagesy($img);
        $step = max(1, (int) floor(max($w, $h) / 80)); // ~80×80 samples is plenty
        $buckets = [];
        for ($y = 0; $y < $h; $y += $step) {
            for ($x = 0; $x < $w; $x += $step) {
                $c = imagecolorsforindex($img, imagecolorat($img, $x, $y));
                if ($c['alpha'] > 100) continue; // transparent
                [$hue, $sat, $lum] = self::hsl($c['red'], $c['green'], $c['blue']);
                if ($sat < .25 || $lum < .12 || $lum > .9) continue; // grey / black / white
                $key = (int) round($hue / 15); // 24 hue buckets
                $buckets[$key]['n'] = ($buckets[$key]['n'] ?? 0) + 1 + $sat; // favour vivid colours
                $buckets[$key]['rgb'][] = [$c['red'], $c['green'], $c['blue']];
            }
        }
        if (!$buckets) {
            return null;
        }
        uasort($buckets, fn($a, $b) => $b['n'] <=> $a['n']);
        $top = reset($buckets)['rgb'];
        $avg = [0, 0, 0];
        foreach ($top as $p) { $avg[0] += $p[0]; $avg[1] += $p[1]; $avg[2] += $p[2]; }
        $n = count($top);
        [$hue, $sat, $lum] = self::hsl((int) ($avg[0] / $n), (int) ($avg[1] / $n), (int) ($avg[2] / $n));
        // Keep the brand colour usable for buttons and white text.
        $brand = self::hex(...self::fromHsl($hue, max($sat, .45), min(max($lum, .32), .5)));
        $sidebar = self::hex(...self::fromHsl($hue, min($sat, .45), .2));
        return ['brand' => $brand, 'sidebar' => $sidebar];
    }

    // ---------------------------------------------------------------- helpers

    public static function isHex(string $c): bool
    {
        return (bool) preg_match('/^#[0-9a-f]{6}$/i', $c);
    }

    private static function logoFile(int $account): ?string
    {
        $st = Database::pdo()->prepare('SELECT logo_path FROM tenants WHERE id = ?');
        $st->execute([$account]);
        $path = (string) $st->fetchColumn();
        if ($path === '') {
            return null;
        }
        $rel = ltrim(preg_replace('#^(https?://[^/]+)?/?(public/)?#', '', $path), '/');
        foreach ([PUBLIC_ROOT . '/' . $rel, ROOT_PATH . '/' . $rel] as $f) {
            if (is_file($f)) return $f;
        }
        return null;
    }

    private static function ensureColumn(): void
    {
        static $done = false;
        if ($done) return;
        $chk = Database::pdo()->query("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'tenants' AND column_name = 'theme'");
        if (!(int) $chk->fetchColumn()) {
            Database::pdo()->exec('ALTER TABLE tenants ADD COLUMN theme TEXT NULL');
        }
        $done = true;
    }

    private static function rgb(string $hex): array
    {
        return [hexdec(substr($hex, 1, 2)), hexdec(substr($hex, 3, 2)), hexdec(substr($hex, 5, 2))];
    }

    private static function hex(int $r, int $g, int $b): string
    {
        return sprintf('#%02x%02x%02x', max(0, min(255, $r)), max(0, min(255, $g)), max(0, min(255, $b)));
    }

    private static function mix(string $a, string $b, float $t): string
    {
        $x = self::rgb($a);
        $y = self::rgb($b);
        return self::hex((int) round($x[0] + ($y[0] - $x[0]) * $t), (int) round($x[1] + ($y[1] - $x[1]) * $t), (int) round($x[2] + ($y[2] - $x[2]) * $t));
    }

    /** '#fff' or a dark ink, whichever reads better on $hex. */
    public static function readableOn(string $hex): string
    {
        [$r, $g, $b] = self::rgb($hex);
        $lin = fn($c) => ($c /= 255) <= .03928 ? $c / 12.92 : (($c + .055) / 1.055) ** 2.4;
        $l = .2126 * $lin($r) + .7152 * $lin($g) + .0722 * $lin($b);
        return $l > .45 ? '#1f2330' : '#ffffff';
    }

    private static function hsl(int $r, int $g, int $b): array
    {
        $r /= 255; $g /= 255; $b /= 255;
        $max = max($r, $g, $b); $min = min($r, $g, $b);
        $l = ($max + $min) / 2;
        if ($max === $min) return [0.0, 0.0, $l];
        $d = $max - $min;
        $s = $l > .5 ? $d / (2 - $max - $min) : $d / ($max + $min);
        $h = $max === $r ? ($g - $b) / $d + ($g < $b ? 6 : 0) : ($max === $g ? ($b - $r) / $d + 2 : ($r - $g) / $d + 4);
        return [$h * 60, $s, $l];
    }

    private static function fromHsl(float $h, float $s, float $l): array
    {
        $c = (1 - abs(2 * $l - 1)) * $s;
        $x = $c * (1 - abs(fmod($h / 60, 2) - 1));
        $m = $l - $c / 2;
        [$r, $g, $b] = $h < 60 ? [$c, $x, 0] : ($h < 120 ? [$x, $c, 0] : ($h < 180 ? [0, $c, $x] : ($h < 240 ? [0, $x, $c] : ($h < 300 ? [$x, 0, $c] : [$c, 0, $x]))));
        return [(int) round(($r + $m) * 255), (int) round(($g + $m) * 255), (int) round(($b + $m) * 255)];
    }
}
