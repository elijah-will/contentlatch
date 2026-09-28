#!/usr/bin/env php
<?php
/**
 * Build languages/contentlatch.pot with WordPress string extraction.
 *
 * Usage: php bin/make-pot.php [/path/to/wp-cli.phar]
 *
 * @package ContentLatch
 */

declare(strict_types=1);

$root = dirname(__DIR__);
$pot  = $root . '/languages/contentlatch.pot';
$wp   = $argv[1] ?? '/tmp/wp-cli.phar';

if (!is_readable($wp) && $wp !== 'wp') {
    fwrite(STDERR, "WP-CLI not found at {$wp}. Pass the phar path as argv1.\n");
    exit(1);
}

$wpCmd = ($wp === 'wp')
    ? array('wp')
    : array(PHP_BINARY, $wp);

$command = array_merge($wpCmd, array(
    'i18n',
    'make-pot',
    $root,
    $pot,
    '--slug=contentlatch',
    '--domain=contentlatch',
    '--exclude=vendor,tests,node_modules,.git,bin',
    '--headers={"Project-Id-Version":"ContentLatch 1.0.0","Report-Msgid-Bugs-To":""}',
));

$cmd = implode(' ', array_map('escapeshellarg', $command));
passthru($cmd . ' 2>/dev/null', $code);
if ($code !== 0) {
    fwrite(STDERR, "Command failed ({$code}): {$cmd}\n");
    exit($code);
}

$existing  = (string) file_get_contents($pot);
$msgidCount = preg_match_all('/^msgid /m', $existing);
fwrite(STDOUT, "Wrote {$pot} ({$msgidCount} msgid entries).\n");
