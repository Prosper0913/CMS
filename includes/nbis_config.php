<?php
// ============================================================
//  includes/nbis_config.php
//  Single source of truth for running mindtct / bozorth3.
//
//  Dev machine  = Windows, NBIS built inside WSL2/Ubuntu ->
//                 must shell out via `wsl.exe -d Ubuntu <bin> ...`
//                 and translate C:\... paths to /mnt/c/... first.
//  Production   = the Linux droplet itself -> NBIS binaries run
//                 directly, no wsl.exe, no path translation.
//
//  bio_match.php and bio_enroll.php both include this file and
//  call runNbis() instead of hand-building a wsl.exe command, so
//  the same code works unchanged on both machines. Detection is
//  automatic (PHP_OS_FAMILY) — nothing to toggle by hand.
// ============================================================

// ── WSL (Windows dev machine) settings ─────────────────────────
define('WSL_USER',      'dances');
define('WSL_DISTRO',    'Ubuntu');
define('MINDTCT_BIN_WSL',  '/home/' . WSL_USER . '/nbis/mindtct/bin/mindtct');
define('BOZORTH3_BIN_WSL', '/home/' . WSL_USER . '/nbis/bozorth3/bin/bozorth3');

// ── Linux (production droplet) settings ────────────────────────
// After installing NBIS on the droplet, run `which mindtct` and
// `which bozorth3` (or note wherever you built/installed them) and
// put the real paths here. These are NOT used at all on Windows.
define('MINDTCT_BIN_LINUX',  '/usr/local/bin/mindtct');
define('BOZORTH3_BIN_LINUX', '/usr/local/bin/bozorth3');

/**
 * Translate a Windows-style path (C:\foo\bar) to the equivalent
 * WSL/POSIX path (/mnt/c/foo/bar). Only relevant when we're on
 * Windows and about to shell out via wsl.exe. No-op elsewhere.
 */
function winToWsl(string $winPath): string {
    $p = str_replace('\\', '/', $winPath);
    if (preg_match('#^([A-Za-z]):/(.*)$#', $p, $m)) {
        return '/mnt/' . strtolower($m[1]) . '/' . $m[2];
    }
    return $p; // already posix-style
}

/**
 * Run either mindtct or bozorth3 with the given args, transparently
 * handling the Windows+WSL dev setup vs. direct Linux production.
 *
 * @param string $binWsl    Full WSL-side path to the binary (see MINDTCT_BIN_WSL / BOZORTH3_BIN_WSL above)
 * @param string $binLinux  Full Linux-side path to the binary (see MINDTCT_BIN_LINUX / BOZORTH3_BIN_LINUX above)
 * @param array  $args      Positional args to pass, as real filesystem paths on THIS machine
 *                           (Windows paths on the dev box, POSIX paths on the droplet).
 * @return array{output: string, cmd: string}  Raw stdout+stderr from the command, and the command that was run (for logging).
 */
function runNbis(string $binWsl, string $binLinux, array $args): array {
    if (PHP_OS_FAMILY === 'Windows') {
        $translated = array_map('winToWsl', $args);
        $escaped    = array_map('escapeshellarg', $translated);
        $cmd = 'wsl.exe -d ' . WSL_DISTRO . ' ' . $binWsl . ' ' . implode(' ', $escaped) . ' 2>&1';
    } else {
        // Linux production: paths are already POSIX, call the binary directly.
        $escaped = array_map('escapeshellarg', $args);
        $cmd = escapeshellarg($binLinux) . ' ' . implode(' ', $escaped) . ' 2>&1';
    }

    $output = (string) shell_exec($cmd);
    return ['output' => $output, 'cmd' => $cmd];
}