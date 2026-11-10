<?php
// tools/diag-manifest.php — makes public/diagnose-files.php: the list of every app
// file with a fingerprint, so public/diagnose.php can tell which files are missing
// or different on the server (a half-finished upload is the usual cause of a sudden 500).
//
//   php tools/diag-manifest.php
//
// Run it again whenever you change files and are about to upload them. Fingerprints
// ignore line endings, so a Windows/Mac difference does not matter.
$root = str_replace('\\', '/', dirname(__DIR__));
$files = [];
$skip = '#/(vendor|storage|uploads|config|node_modules|\.git|maintenance|db_sample)/#';
foreach (['app', 'public'] as $dir) {
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/' . $dir, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $f) {
        $p = str_replace('\\', '/', $f->getPathname());
        $rel = ltrim(substr($p, strlen($root)), '/');
        if (preg_match($skip, '/' . $rel) || preg_match('#^public/diagnose#', $rel)) continue;
        if (!preg_match('/\.(php|js)$/', $rel)) continue;
        $files[$rel] = sha1(str_replace("\r", '', (string) file_get_contents($p)));
    }
}
foreach (['index.php', '.htaccess'] as $rel) {
    if (is_file($root . '/' . $rel)) $files[$rel] = sha1(str_replace("\r", '', (string) file_get_contents($root . '/' . $rel)));
}
ksort($files);
$out = "<?php\n// Made by tools/diag-manifest.php — used by diagnose.php. Prints nothing when opened on its own.\nreturn " . var_export(['generated' => date('Y-m-d H:i'), 'files' => $files], true) . ";\n";
file_put_contents($root . '/public/diagnose-files.php', $out);
echo count($files) . " files listed → public/diagnose-files.php\n";
