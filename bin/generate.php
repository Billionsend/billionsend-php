<?php

declare(strict_types=1);

/*
 * Writes the operation surface of the SDK from the OpenAPI snapshot
 * (openapi/api.openapi.json):
 *
 *   - src/Service/*.php: one class per service, one method per operation,
 *     grouped by the operationId's dotted path (emails.receiving.attachments.list
 *     is $client->emails->receiving->attachments->list()), with a listAll-style
 *     generator for every cursor-paginated list;
 *   - src/ClientServices.php: the client's top-level services;
 *   - tests/Generated/CoverageCalls.php: a call of every operation, which the
 *     coverage test runs against a transport that knows only the snapshot.
 *
 * Usage, from the repository root:
 *
 *   php bin/generate.php           rewrite the generated files
 *   php bin/generate.php --check   fail when they differ from the snapshot
 */

$root = dirname(__DIR__);
$check = in_array('--check', $argv, true);
$doc = json_decode((string) file_get_contents("$root/openapi/api.openapi.json"), true, 512, JSON_THROW_ON_ERROR);

const METHODS = ['get', 'post', 'put', 'patch', 'delete'];

function studly(string $s): string
{
    $parts = preg_split('/[^A-Za-z0-9]+/', $s) ?: [];
    $out = '';
    foreach ($parts as $p) {
        $out .= ucfirst($p);
    }
    return $out;
}

function camel(string $s): string
{
    $parts = preg_split('/[^A-Za-z0-9]+/', $s) ?: [];
    $out = strtolower((string) array_shift($parts));
    foreach ($parts as $p) {
        $out .= ucfirst(strtolower($p));
    }
    return $out;
}

function docLines(string $text, string $indent): string
{
    $text = trim($text);
    if ($text === '') {
        return '';
    }
    $text = str_replace('*/', '*\/', $text);
    $out = '';
    foreach (explode("\n", $text) as $para) {
        $wrapped = wordwrap($para, 100 - strlen($indent) - 3, "\n", false);
        foreach (explode("\n", $wrapped) as $line) {
            $out .= rtrim("$indent * $line") . "\n";
        }
    }
    return $out;
}

function schemaOf(array $doc, ?array $schema): ?array
{
    if ($schema === null) {
        return null;
    }
    if (isset($schema['$ref'])) {
        $name = substr($schema['$ref'], strrpos($schema['$ref'], '/') + 1);
        return $doc['components']['schemas'][$name] ?? null;
    }
    return $schema;
}

/** Every operation, with what the generator needs of it. */
$ops = [];
$ids = [];
foreach ($doc['paths'] as $path => $item) {
    foreach (METHODS as $m) {
        if (isset($item[$m]['operationId'])) {
            $ids[$item[$m]['operationId']] = true;
        }
    }
}
foreach ($doc['paths'] as $path => $item) {
    foreach (METHODS as $m) {
        if (!isset($item[$m])) {
            continue;
        }
        $o = $item[$m];
        $id = $o['operationId'];
        $segs = explode('.', $id);
        // An operation that is also the prefix of others (emails.metrics, with
        // emails.metrics.links) becomes the service's get().
        foreach (array_keys($ids) as $other) {
            if (str_starts_with($other, "$id.")) {
                $segs[] = 'get';
                break;
            }
        }
        $params = $o['parameters'] ?? [];
        $pathParams = [];
        preg_match_all('/\{([^}]+)\}/', $path, $mm);
        foreach ($mm[1] as $name) {
            foreach ($params as $p) {
                if ($p['in'] === 'path' && $p['name'] === $name) {
                    $pathParams[] = $p;
                }
            }
        }
        $query = [];
        $headers = [];
        $idempotent = false;
        foreach ($params as $p) {
            if ($p['in'] === 'header' && strcasecmp($p['name'], 'Idempotency-Key') === 0) {
                $idempotent = true;
            } elseif ($p['in'] === 'query') {
                $query[] = $p;
            } elseif ($p['in'] === 'header') {
                $headers[] = $p;
            }
        }
        $stream = false;
        $resp = null;
        $codes = array_filter(array_keys($o['responses']), fn ($c) => str_starts_with((string) $c, '2'));
        sort($codes);
        if ($codes) {
            foreach ($o['responses'][$codes[0]]['content'] ?? [] as $ct => $c) {
                if ($ct === 'text/event-stream') {
                    $stream = true;
                } else {
                    $resp = schemaOf($doc, $c['schema'] ?? null);
                }
            }
        }
        $body = null;
        $bodyRequired = false;
        $fileFields = null;
        if (isset($o['requestBody'])) {
            $bodyRequired = (bool) ($o['requestBody']['required'] ?? false);
            foreach ($o['requestBody']['content'] as $ct => $c) {
                $body = schemaOf($doc, $c['schema'] ?? null);
                if ($ct === 'multipart/form-data') {
                    $fileFields = array_keys(array_filter($body['properties'] ?? [], fn ($p) => ($p['format'] ?? '') === 'binary'));
                } elseif ($ct !== 'application/json') {
                    fwrite(STDERR, "$id: unknown request content type $ct\n");
                    exit(1);
                }
            }
        }
        $paginated = false;
        foreach ($query as $p) {
            if ($p['name'] === 'after' && isset($resp['properties']['data'], $resp['properties']['has_more'])) {
                $paginated = true;
            }
        }
        $ops[] = [
            'id' => $id,
            'method' => strtoupper($m),
            'path' => $path,
            'service' => array_slice($segs, 0, -1),
            'name' => $segs[count($segs) - 1],
            'summary' => $o['summary'] ?? '',
            'description' => $o['description'] ?? '',
            'pathParams' => $pathParams,
            'query' => $query,
            'headers' => $headers,
            'idempotent' => $idempotent,
            'noAuth' => isset($o['security']) && $o['security'] === [],
            'stream' => $stream,
            'body' => $body,
            'bodyRequired' => $bodyRequired,
            'fileFields' => $fileFields,
            'paginated' => $paginated,
        ];
    }
}
usort($ops, fn ($a, $b) => strcmp($a['id'], $b['id']));

/** Service tree: key "a.b" => [children keys, operations]. */
$services = ['' => ['children' => [], 'ops' => []]];
foreach ($ops as $op) {
    for ($i = 1; $i <= count($op['service']); $i++) {
        $parent = implode('.', array_slice($op['service'], 0, $i - 1));
        $child = implode('.', array_slice($op['service'], 0, $i));
        $services[$child] ??= ['children' => [], 'ops' => []];
        if (!in_array($child, $services[$parent]['children'], true)) {
            $services[$parent]['children'][] = $child;
        }
    }
    $services[implode('.', $op['service'])]['ops'][] = $op;
}
ksort($services);

function className(string $key): string
{
    return implode('', array_map('studly', explode('.', $key))) . 'Service';
}

function lastSeg(string $key): string
{
    $p = strrpos($key, '.');
    return $p === false ? $key : substr($key, $p + 1);
}

function paramDoc(array $p): string
{
    $desc = trim(preg_replace('/\s+/', ' ', $p['description'] ?? '') ?? '');
    $enum = $p['schema']['enum'] ?? null;
    if ($enum) {
        $desc .= ' One of: ' . implode(', ', array_map(fn ($v) => "\"$v\"", array_filter($enum, fn ($v) => $v !== null))) . '.';
    }
    return $desc;
}

function phpType(?array $s): string
{
    $t = $s['type'] ?? null;
    if (is_array($t)) {
        $t = array_values(array_diff($t, ['null']))[0] ?? null;
    }
    return match ($t) {
        'integer' => 'int',
        'number' => 'float',
        'boolean' => 'bool',
        'array' => 'list<string>|string',
        default => 'string',
    };
}

function method(array $op): string
{
    $args = [];
    $call = [];
    $doc = '';
    $summary = $op['summary'];
    if ($op['description'] !== '' && $op['description'] !== $summary) {
        $summary .= "\n\n" . $op['description'];
    }
    $doc .= docLines($summary, '    ');
    $doc .= "     *\n     * {$op['method']} {$op['path']} ({$op['id']})\n";
    if ($op['idempotent']) {
        $doc .= "     *\n     * It always sends an Idempotency-Key: \$options['idempotency_key'], or a new UUID\n     * for this call that its retries reuse.\n";
    }
    $doc .= "     *\n";
    $pathArgs = [];
    foreach ($op['pathParams'] as $p) {
        $var = '$' . camel($p['name']);
        $args[] = "string $var";
        $pathArgs[] = var_export($p['name'], true) . " => $var";
        $doc .= rtrim('     * @param string ' . $var . ' ' . paramDoc($p)) . "\n";
    }
    $bodyArg = 'null';
    if ($op['body'] !== null) {
        $isList = ($op['body']['type'] ?? null) === 'array';
        $shape = $isList ? 'list<array<string, mixed>>' : 'array<string, mixed>';
        $desc = trim(preg_replace('/\s+/', ' ', $op['body']['description'] ?? '') ?? '');
        $desc = $desc === '' ? 'The request body.' : $desc;
        if ($op['fileFields'] !== null) {
            $fields = [];
            foreach ($op['body']['properties'] as $key => $p) {
                $isFile = in_array($key, $op['fileFields'], true);
                $optional = $isFile || in_array($key, $op['body']['required'] ?? [], true) ? '' : '?';
                $json = str_starts_with($p['description'] ?? '', 'JSON-encoded');
                $fields[] = "$key$optional: " . ($isFile ? '\\BillionSend\\FileUpload|resource|string' : ($json ? 'array<mixed>|string' : 'string'));
            }
            $shape = 'array{' . implode(', ', $fields) . '}';
            $desc = 'Sent as multipart/form-data; an array field is encoded as JSON. A file is its content as a string, an open stream or a FileUpload. The upload is repeated only after a 429.';
        }
        if ($op['bodyRequired']) {
            $args[] = 'array $body';
            $doc .= "     * @param $shape \$body $desc\n";
        } else {
            $args[] = '?array $body = null';
            $doc .= "     * @param $shape|null \$body $desc\n";
        }
        $bodyArg = '$body';
    }
    $hasParams = $op['query'] || $op['headers'];
    if ($hasParams) {
        $args[] = 'array $params = []';
        $shape = [];
        foreach (array_merge($op['query'], $op['headers']) as $p) {
            $key = preg_match('/^[a-z_][a-z0-9_]*$/', $p['name']) ? $p['name'] : "'" . $p['name'] . "'";
            $shape[] = "$key?: " . phpType($p['schema'] ?? null);
        }
        $doc .= '     * @param array{' . implode(', ', $shape) . "} \$params\n";
        foreach (array_merge($op['query'], $op['headers']) as $p) {
            $doc .= docLines("- {$p['name']}" . ($p['in'] === 'header' ? ' (header)' : '') . ': ' . paramDoc($p), '    ');
        }
    }
    $args[] = 'array $options = []';
    $doc .= "     * @param RequestOptions \$options\n";
    $headerNames = array_map(fn ($p) => var_export($p['name'], true), $op['headers']);
    $spec = sprintf(
        "new Operation(%s, %s, %s, [%s], %s, %s, [%s]%s%s)",
        var_export($op['id'], true),
        var_export($op['method'], true),
        var_export($op['path'], true),
        implode(', ', $pathArgs),
        $op['idempotent'] ? 'true' : 'false',
        $op['noAuth'] ? 'true' : 'false',
        implode(', ', $headerNames),
        ($op['body']['type'] ?? null) === 'array' ? ', listBody: true' : '',
        $op['fileFields'] === null ? '' : ', fileFields: [' . implode(', ', array_map(fn ($f) => var_export($f, true), $op['fileFields'])) . ']',
    );
    $params = $hasParams ? '$params' : '[]';
    $name = $op['name'];
    if ($op['stream']) {
        $doc .= "     * @param EventStreamOptions \$options\n     */\n";
        $doc = str_replace("     * @param RequestOptions \$options\n", '', $doc);
        $ends = $op['pathParams'] ? "['inbox.deleted', 'inbox.expired']" : '[]';
        $out = "    /**\n$doc    public function $name(" . implode(', ', $args) . "): EventStream\n    {\n";
        $out .= "        return \$this->core->stream($spec, $params, \$options, $ends);\n    }\n";
        return $out;
    }
    $doc .= "     * @return array<string, mixed>\n     */\n";
    $out = "    /**\n$doc    public function $name(" . implode(', ', $args) . "): array\n    {\n";
    $out .= "        return \$this->core->request($spec, $params, $bodyArg, \$options);\n    }\n";
    if ($op['paginated']) {
        $allArgs = [];
        $callArgs = [];
        foreach ($op['pathParams'] as $p) {
            $allArgs[] = 'string $' . camel($p['name']);
            $callArgs[] = '$' . camel($p['name']);
        }
        $allArgs[] = 'array $params = []';
        $allArgs[] = 'array $options = []';
        $callArgs[] = '$page';
        $callArgs[] = '$options';
        $out .= "\n    /**\n     * Walks every item of $name(), fetching pages as it goes: forward from\n";
        $out .= "     * \$params['after'], or backward when \$params['before'] is set. A failed page\n     * throws its BillionSendError.\n     *\n";
        foreach ($op['pathParams'] as $p) {
            $out .= '     * @param string $' . camel($p['name']) . "\n";
        }
        $out .= "     * @param array<string, mixed> \$params\n     * @param RequestOptions \$options\n";
        $out .= "     * @return \\Generator<int, array<string, mixed>>\n     */\n";
        $out .= "    public function {$name}All(" . implode(', ', $allArgs) . "): \\Generator\n    {\n";
        $out .= "        return Pagination::walk(fn (array \$page): array => \$this->$name(" . implode(', ', $callArgs) . "), \$params);\n    }\n";
    }
    return $out;
}

$files = [];
$header = "<?php\n\n// Code generated by bin/generate.php from openapi/api.openapi.json. DO NOT EDIT.\n\ndeclare(strict_types=1);\n\n";
foreach ($services as $key => $svc) {
    if ($key === '') {
        continue;
    }
    $class = className($key);
    $src = $header . "namespace BillionSend\\Service;\n\nuse BillionSend\\Core;\nuse BillionSend\\EventStream;\nuse BillionSend\\Operation;\nuse BillionSend\\Pagination;\n\n";
    $src .= "/**\n * The " . str_replace('.', ' ', $key) . " operations.\n *\n";
    $src .= " * @phpstan-import-type RequestOptions from Core\n * @phpstan-import-type EventStreamOptions from Core\n */\n";
    $src .= "final class $class\n{\n";
    foreach ($svc['children'] as $child) {
        $src .= '    public readonly ' . className($child) . ' $' . lastSeg($child) . ";\n";
    }
    if ($svc['children']) {
        $src .= "\n";
    }
    $src .= "    public function __construct(private readonly Core \$core)\n    {\n";
    foreach ($svc['children'] as $child) {
        $src .= '        $this->' . lastSeg($child) . ' = new ' . className($child) . "(\$core);\n";
    }
    $src .= "    }\n";
    foreach ($svc['ops'] as $op) {
        $src .= "\n" . method($op);
    }
    $src .= "}\n";
    $src = preg_replace("/\n\n\n+/", "\n\n", $src);
    $files["src/Service/$class.php"] = $src;
}

$top = $header . "namespace BillionSend;\n\n";
foreach ($services['']['children'] as $child) {
    $top .= 'use BillionSend\\Service\\' . className($child) . ";\n";
}
$top .= "\n/**\n * The client's top-level services, one per group of operations.\n */\ntrait ClientServices\n{\n";
foreach ($services['']['children'] as $child) {
    $top .= '    public readonly ' . className($child) . ' $' . $child . ";\n";
}
$top .= "\n    private function initServices(Core \$core): void\n    {\n";
foreach ($services['']['children'] as $child) {
    $top .= '        $this->' . $child . ' = new ' . className($child) . "(\$core);\n";
}
$top .= "    }\n}\n";
$files['src/ClientServices.php'] = $top;

$cov = $header . "namespace BillionSend\\Tests\\Generated;\n\nuse BillionSend\\Client;\n\n/**\n * One call of every operation, keyed by operationId.\n */\nfinal class CoverageCalls\n{\n";
$cov .= "    /**\n     * @return array<string, callable(Client): mixed>\n     */\n    public static function all(): array\n    {\n        return [\n";
foreach ($ops as $op) {
    $args = [];
    foreach ($op['pathParams'] as $i => $p) {
        $args[] = var_export('p' . ($i + 1), true);
    }
    if ($op['fileFields'] !== null) {
        $args[] = '[' . implode(', ', array_map(fn ($f) => var_export($f, true) . " => 'x'", $op['fileFields'])) . ']';
    } elseif ($op['body'] !== null) {
        $args[] = '[]';
    }
    $chain = '$c->' . implode('->', array_merge($op['service'], [$op['name']]));
    $call = "$chain(" . implode(', ', $args) . ')';
    if ($op['stream']) {
        $cov .= "            " . var_export($op['id'], true) . " => static function (Client \$c): void {\n                " . $call . "->close();\n            },\n";
    } else {
        $cov .= "            " . var_export($op['id'], true) . " => static fn (Client \$c): array => $call,\n";
    }
}
$cov .= "        ];\n    }\n}\n";
$files['tests/Generated/CoverageCalls.php'] = $cov;

$stale = false;
$existing = glob("$root/src/Service/*.php") ?: [];
foreach ($existing as $path) {
    $rel = substr($path, strlen($root) + 1);
    if (!isset($files[$rel])) {
        if ($check) {
            fwrite(STDERR, "$rel is no longer generated\n");
            $stale = true;
        } else {
            unlink($path);
        }
    }
}
foreach ($files as $rel => $content) {
    $path = "$root/$rel";
    if ($check) {
        if (!is_file($path) || file_get_contents($path) !== $content) {
            fwrite(STDERR, "$rel is out of date: run php bin/generate.php\n");
            $stale = true;
        }
        continue;
    }
    file_put_contents($path, $content);
}
if ($stale) {
    exit(1);
}
if (!$check) {
    printf("%d operations, %d services\n", count($ops), count($services) - 1);
}
