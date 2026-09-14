#!/usr/bin/env php
<?php
/**
 * Build languages/contentguard.pot from WordPress gettext calls and soft
 * I18n::translate / Text::translate helpers (which wp i18n make-pot skips).
 *
 * Usage: php bin/make-pot.php [/path/to/wp-cli.phar]
 *
 * @package ContentGuard
 */

declare(strict_types=1);

$root = dirname(__DIR__);
$pot  = $root . '/languages/contentguard.pot';
$wp   = $argv[1] ?? '/tmp/wp-cli.phar';

if (!is_readable($wp) && $wp !== 'wp') {
    fwrite(STDERR, "WP-CLI not found at {$wp}. Pass the phar path as argv1.\n");
    exit(1);
}

$wpCmd = ($wp === 'wp')
    ? array('wp')
    : array(PHP_BINARY, $wp);

$baseCmd = array_merge($wpCmd, array(
    'i18n',
    'make-pot',
    $root,
    $pot,
    '--slug=contentguard',
    '--domain=contentguard',
    '--exclude=vendor,tests,node_modules,.git,bin',
    '--headers={"Project-Id-Version":"ContentGuard 1.0.0","Report-Msgid-Bugs-To":""}',
));

run($baseCmd);

$literals = array();
$plurals  = array();

$iterator = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($root . '/includes', FilesystemIterator::SKIP_DOTS)
);

foreach ($iterator as $file) {
    if (!$file->isFile() || $file->getExtension() !== 'php') {
        continue;
    }

    $code = (string) file_get_contents($file->getPathname());
    $rel  = substr($file->getPathname(), strlen($root) + 1);

    if (preg_match_all(
        "/(?:I18n|Text)::translate\(\s*'((?:\\\\'|[^'])*)'\s*\)/",
        $code,
        $matches,
        PREG_OFFSET_CAPTURE
    )) {
        foreach ($matches[1] as $match) {
            $literal = stripcslashes($match[0]);
            $line    = substr_count(substr($code, 0, $match[1]), "\n") + 1;
            $literals[$literal][$rel . ':' . $line] = true;
        }
    }

    if (preg_match_all(
        "/(?:I18n|Text)::sprintf\(\s*'((?:\\\\'|[^'])*)'\s*[,)]/",
        $code,
        $matches,
        PREG_OFFSET_CAPTURE
    )) {
        foreach ($matches[1] as $match) {
            $literal = stripcslashes($match[0]);
            $line    = substr_count(substr($code, 0, $match[1]), "\n") + 1;
            $literals[$literal][$rel . ':' . $line] = true;
        }
    }

    if (preg_match_all(
        "/I18n::translatePlural\(\s*'((?:\\\\'|[^'])*)'\s*,\s*'((?:\\\\'|[^'])*)'\s*,/",
        $code,
        $matches,
        PREG_SET_ORDER
    )) {
        foreach ($matches as $match) {
            $single = stripcslashes($match[1]);
            $plural = stripcslashes($match[2]);
            $plurals[$single . "\0" . $plural] = array($single, $plural, $rel);
        }
    }
}

$existing = (string) file_get_contents($pot);
$added    = 0;

foreach ($literals as $literal => $refs) {
    $msgid = potEscape($literal);
    if (str_contains($existing, "msgid \"{$msgid}\"")) {
        continue;
    }

    $block  = "\n";
    foreach (array_keys($refs) as $ref) {
        $block .= '#: ' . $ref . "\n";
    }
    $block .= "msgid \"{$msgid}\"\nmsgstr \"\"\n";
    $existing .= $block;
    $added++;
}

foreach ($plurals as $pair) {
    [$single, $plural, $rel] = $pair;
    $msgid = potEscape($single);
    if (str_contains($existing, "msgid \"{$msgid}\"\nmsgid_plural")) {
        continue;
    }
    if (str_contains($existing, "msgid \"{$msgid}\"\nmsgstr")) {
        continue;
    }

    $existing .= "\n#: {$rel}\nmsgid \"" . potEscape($single) . "\"\nmsgid_plural \""
        . potEscape($plural) . "\"\nmsgstr[0] \"\"\nmsgstr[1] \"\"\n";
    $added++;
}

file_put_contents($pot, $existing);

$msgidCount = preg_match_all('/^msgid /m', $existing);
fwrite(STDOUT, "Wrote {$pot} ({$msgidCount} msgid entries; added {$added} soft-i18n strings).\n");

/**
 * @param list<string> $command
 */
function run(array $command): void
{
    $cmd = implode(' ', array_map('escapeshellarg', $command));
    passthru($cmd . ' 2>/dev/null', $code);
    if ($code !== 0) {
        fwrite(STDERR, "Command failed ({$code}): {$cmd}\n");
        exit($code);
    }
}

function potEscape(string $text): string
{
    return str_replace(
        array('\\', '"', "\n", "\r", "\t"),
        array('\\\\', '\\"', '\\n', '\\r', '\\t'),
        $text
    );
}
