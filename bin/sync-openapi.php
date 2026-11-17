<?php

declare(strict_types=1);

/*
 * Refreshes openapi/api.openapi.json, the contract this SDK is generated from
 * and tested against.
 *
 *   php bin/sync-openapi.php                  fetch the published document
 *   php bin/sync-openapi.php --from <path>    copy a local document instead
 *
 * Then run php bin/generate.php and vendor/bin/phpunit: whatever fails is what
 * the SDK has to follow.
 */

$root = dirname(__DIR__);
$url = 'https://billionsend.com/docs/api.openapi.json';
$from = null;
for ($i = 1; $i < count($argv); $i++) {
    if ($argv[$i] === '--from') {
        $from = $argv[++$i] ?? '';
    } elseif ($argv[$i] === '--url') {
        $url = $argv[++$i] ?? '';
    } else {
        fwrite(STDERR, "Unknown argument: {$argv[$i]}\n");
        exit(1);
    }
}
$raw = $from !== null ? @file_get_contents($from) : @file_get_contents($url, false, stream_context_create(['http' => ['timeout' => 30, 'header' => 'Accept: application/json']]));
if ($raw === false) {
    fwrite(STDERR, 'Could not read ' . ($from ?? $url) . "\n");
    exit(1);
}
$doc = json_decode($raw, true);
if (!is_array($doc) || !isset($doc['openapi'], $doc['paths']) || $doc['paths'] === []) {
    fwrite(STDERR, "Not an OpenAPI document\n");
    exit(1);
}
file_put_contents("$root/openapi/api.openapi.json", json_encode($doc, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n");
printf("openapi/api.openapi.json: OpenAPI %s, %d paths\n", $doc['openapi'], count($doc['paths']));
