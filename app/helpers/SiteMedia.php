<?php
// app/helpers/SiteMedia.php — a picture or video behind the landing hero and beside the sign-in forms.
// The value is an uploaded file (assets/uploads/platform/…) or a link: a YouTube address, a direct
// .mp4/.webm link, or a picture link. Videos autoplay muted, loop and play inline (phones included).
class SiteMedia
{
    const VIDEO_EXT = ['mp4', 'webm', 'ogg', 'mov', 'm4v'];

    /** Stored value → address to use ('' = none). Uploaded paths become site addresses; links stay as they are. */
    public static function resolve(string $v): string
    {
        $v = trim($v);
        if ($v === '') { return ''; }
        return preg_match('#^https?://#i', $v) ? $v : public_url(Branding::normalizeStoredPath($v));
    }

    /** YouTube video id from watch / youtu.be / embed / shorts / live links, else null. */
    public static function youtubeId(string $url): ?string
    {
        if (preg_match('#^https?://(?:www\.|m\.)?(?:youtube\.com/(?:watch\?(?:[^\#]*&)?v=|embed/|shorts/|live/|v/)|youtu\.be/|youtube-nocookie\.com/embed/)([A-Za-z0-9_-]{11})#i', $url, $m)) {
            return $m[1];
        }
        return null;
    }

    /** 'youtube' | 'video' | 'image' | '' */
    public static function kind(string $url): string
    {
        if ($url === '') { return ''; }
        if (self::youtubeId($url)) { return 'youtube'; }
        $ext = strtolower(pathinfo((string) parse_url($url, PHP_URL_PATH), PATHINFO_EXTENSION));
        return in_array($ext, self::VIDEO_EXT, true) ? 'video' : 'image';
    }

    /** The background layer (absolutely positioned, fills its parent) plus a dark tint for readable text. */
    public static function layer(string $url, string $tint = 'rgba(8,30,20,.45)'): string
    {
        $k = self::kind($url);
        if ($k === '') { return ''; }
        $e = fn($s) => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
        if ($k === 'youtube') {
            $id = self::youtubeId($url);
            $src = "https://www.youtube-nocookie.com/embed/$id?autoplay=1&mute=1&controls=0&loop=1&playlist=$id&playsinline=1&modestbranding=1&rel=0&disablekb=1&iv_load_policy=3";
            $m = '<iframe class="sm-yt" src="' . $e($src) . '" title="" allow="autoplay; encrypted-media" tabindex="-1" loading="lazy" referrerpolicy="strict-origin-when-cross-origin"></iframe>';
        } elseif ($k === 'video') {
            $m = '<video class="sm-v" src="' . $e($url) . '" autoplay muted loop playsinline preload="auto" disablepictureinpicture tabindex="-1"></video>';
        } else {
            $m = '<img class="sm-v" src="' . $e($url) . '" alt="">';
        }
        return '<div class="sm-layer" aria-hidden="true">' . $m . '<span class="sm-tint" style="background:' . $e($tint) . '"></span></div>';
    }

    public static function css(): string
    {
        return '.sm-layer{position:absolute;inset:0;overflow:hidden;z-index:0;pointer-events:none}'
            . '.sm-v{position:absolute;inset:0;width:100%;height:100%;object-fit:cover}'
            . '.sm-yt{position:absolute;left:50%;top:50%;width:max(100%,177.78vh);height:max(100%,56.25vw);min-width:100%;min-height:100%;transform:translate(-50%,-50%);border:0}'
            . '.sm-tint{position:absolute;inset:0}';
    }

    /** Lighter / darker shade of #rrggbb ($f<0 darker, >0 lighter). */
    public static function shade(string $hex, float $f): string
    {
        $c = [hexdec(substr($hex, 1, 2)), hexdec(substr($hex, 3, 2)), hexdec(substr($hex, 5, 2))];
        foreach ($c as &$x) { $x = (int) round($f < 0 ? $x * (1 + $f) : $x + (255 - $x) * $f); }
        return sprintf('#%02x%02x%02x', ...$c);
    }
}
