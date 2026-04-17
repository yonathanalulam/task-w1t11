<?php
// -----------------------------------------------------------------------------
// TRUE REAL-HTTP API COVERAGE GATE
// -----------------------------------------------------------------------------
// Compares the authoritative controller-route inventory (parsed from
// backend/src/Controller/*.php) against endpoints hit by real-HTTP smoke
// tests (parsed from backend/tests/Smoke/*.php).
//
// "Real HTTP" means requests made via $this->request(...) or
// $this->requestMultipart(...) on AbstractHttpSmokeTestCase, which speak HTTP
// over stream_context_create. Symfony WebTestCase::createClient() calls are
// intentionally NOT counted — they are kernel-internal.
//
// Exits 0 when true coverage >= threshold (default 90 %).
// Exits 1 otherwise, printing the uncovered METHOD + PATH list.
//
// Usage:
//   php scripts/dev/http_coverage_check.php
//   php scripts/dev/http_coverage_check.php --threshold=95
//   php scripts/dev/http_coverage_check.php --json
// -----------------------------------------------------------------------------

declare(strict_types=1);

$repoRoot = realpath(__DIR__ . '/../..');
if ($repoRoot === false) {
    fwrite(STDERR, "[http-coverage] cannot resolve repository root\n");
    exit(2);
}

$threshold = 90.0;
$asJson = false;
foreach (array_slice($argv, 1) as $arg) {
    if (str_starts_with($arg, '--threshold=')) {
        $threshold = (float) substr($arg, strlen('--threshold='));
    } elseif ($arg === '--json') {
        $asJson = true;
    } elseif ($arg === '--help' || $arg === '-h') {
        echo "Usage: php scripts/dev/http_coverage_check.php [--threshold=N] [--json]\n";
        exit(0);
    }
}

$controllerDir = $repoRoot . '/backend/src/Controller';
$smokeDir = $repoRoot . '/backend/tests/Smoke';

/** @return list<array{method: string, path: string, source: string, regex: string}> */
function discoverRoutes(string $dir): array
{
    $routes = [];
    $files = glob($dir . '/*.php') ?: [];
    foreach ($files as $file) {
        $src = file_get_contents($file);
        if ($src === false) {
            continue;
        }

        // class-level prefix
        $classPrefix = '';
        if (preg_match('/#\[Route\(\s*\'([^\']*)\'\s*\)\]\s*(?:final\s+)?(?:abstract\s+)?class\s+\w+/', $src, $classMatch) === 1) {
            $classPrefix = $classMatch[1];
        }

        // per-method route attributes
        if (preg_match_all(
            "/#\\[Route\\(\\s*'([^']+)'\\s*,\\s*name:\\s*'[^']+'\\s*,\\s*methods:\\s*\\[\\s*'([^']+)'\\s*\\]\\s*\\)\\]/",
            $src,
            $matches,
            PREG_SET_ORDER,
        ) >= 1) {
            foreach ($matches as $match) {
                $path = $classPrefix . $match[1];
                $method = strtoupper($match[2]);
                // Build a PCRE that matches this template against literal paths
                $regex = '#^' . preg_replace('#\{[^/]+\}#', '[^/]+', preg_quote($path, '#')) . '$#';
                // preg_quote escapes { and }, so we need the replacement BEFORE quoting.
                $regex = '#^' . preg_replace('#\{[^/]+\}#', '[^/]+', preg_quote($path, '#')) . '$#';
                $routes[] = [
                    'method' => $method,
                    'path' => $path,
                    'source' => basename($file),
                    'regex' => $regex,
                ];
            }
        }
    }

    return $routes;
}

// The preg_quote above is called on a string that still contains literal `{...}`.
// We must build the regex in the right order; helper keeps intent clear.
function buildPathRegex(string $path): string
{
    // Replace templated params with a marker, preg_quote the rest, then substitute.
    $placeholder = "\x00PARAM\x00";
    $withPlaceholder = preg_replace('#\{[^/]+\}#', $placeholder, $path);
    $quoted = preg_quote((string) $withPlaceholder, '#');
    $final = str_replace(preg_quote($placeholder, '#'), '[^/]+', $quoted);

    return '#^' . $final . '$#';
}

/** @return list<array{method: string, path: string}> */
function discoverRealHttpCalls(string $dir): array
{
    $calls = [];
    $files = glob($dir . '/*.php') ?: [];
    foreach ($files as $file) {
        if (str_contains($file, 'AbstractHttpSmokeTestCase.php')) {
            // base class: only helper methods, no real endpoint assertions
            continue;
        }
        $src = file_get_contents($file);
        if ($src === false) {
            continue;
        }

        // $this->request('METHOD', '/api/...'  OR  $this->requestMultipart('METHOD', '/api/...'
        if (preg_match_all(
            '/\$this->request(?:Multipart)?\(\s*\'([A-Z]+)\'\s*,\s*(?:\'([^\']+)\'|sprintf\(\s*\'([^\']+)\')/',
            $src,
            $matches,
            PREG_SET_ORDER,
        )) {
            foreach ($matches as $match) {
                $method = $match[1];
                $raw = $match[2] !== '' ? $match[2] : ($match[3] ?? '');
                if ($raw === '') {
                    continue;
                }

                // Strip query string
                $path = explode('?', $raw, 2)[0];
                // Normalise sprintf format specifiers into path-segment wildcards
                $path = preg_replace('/%\d*[ds]/', '{param}', $path);
                $path = preg_replace('#/+#', '/', (string) $path);

                $calls[] = ['method' => $method, 'path' => $path];
            }
        }
    }

    return $calls;
}

$routes = [];
$files = glob($controllerDir . '/*.php') ?: [];
foreach ($files as $file) {
    $src = file_get_contents($file);
    if ($src === false) {
        continue;
    }
    $classPrefix = '';
    if (preg_match('/#\[Route\(\s*\'([^\']*)\'\s*\)\]\s*(?:final\s+)?(?:abstract\s+)?class\s+\w+/', $src, $classMatch) === 1) {
        $classPrefix = $classMatch[1];
    }

    if (preg_match_all(
        "/#\\[Route\\(\\s*'([^']+)'\\s*,\\s*name:\\s*'[^']+'\\s*,\\s*methods:\\s*\\[\\s*'([^']+)'\\s*\\]\\s*\\)\\]/",
        $src,
        $matches,
        PREG_SET_ORDER,
    )) {
        foreach ($matches as $match) {
            $path = $classPrefix . $match[1];
            $routes[] = [
                'method' => strtoupper($match[2]),
                'path' => $path,
                'source' => basename($file),
                'regex' => buildPathRegex($path),
            ];
        }
    }
}

$calls = discoverRealHttpCalls($smokeDir);

// For each real-HTTP call, pick the MOST SPECIFIC matching route.
// Specificity: fewer {params} wins; ties broken by longer literal body.
// This mirrors how Symfony routes literal paths ahead of parameterised ones,
// e.g. GET /api/reviewer/credentials/queue matches the queue route, not the
// /{submissionId} detail route.
$coveredKeys = [];
foreach ($calls as $call) {
    $candidates = [];
    foreach ($routes as $route) {
        if ($route['method'] !== $call['method']) {
            continue;
        }
        $normalisedCall = str_replace('{param}', 'PARAM', $call['path']);
        if (preg_match($route['regex'], $normalisedCall) === 1) {
            $candidates[] = $route;
        }
    }

    if ($candidates === []) {
        continue;
    }

    usort($candidates, static function (array $a, array $b): int {
        $aParams = substr_count($a['path'], '{');
        $bParams = substr_count($b['path'], '{');
        if ($aParams !== $bParams) {
            return $aParams <=> $bParams;
        }

        // Fewer placeholders is tied; prefer longer literal (more specific).
        return strlen($b['path']) <=> strlen($a['path']);
    });

    $best = $candidates[0];
    $coveredKeys[$best['method'] . ' ' . $best['path']] = true;
}

$uncovered = [];
foreach ($routes as $route) {
    $key = $route['method'] . ' ' . $route['path'];
    if (!isset($coveredKeys[$key])) {
        $uncovered[] = $key;
    }
}

$total = count($routes);
$covered = count($coveredKeys);
$percent = $total > 0 ? ($covered / $total) * 100 : 0.0;

if ($asJson) {
    echo json_encode([
        'total' => $total,
        'covered' => $covered,
        'percent' => round($percent, 2),
        'threshold' => $threshold,
        'uncovered' => $uncovered,
    ], JSON_PRETTY_PRINT), "\n";
} else {
    echo "[http-coverage] True real-HTTP API coverage gate\n";
    echo sprintf("  Total endpoints:  %d\n", $total);
    echo sprintf("  HTTP-covered:     %d\n", $covered);
    echo sprintf("  Coverage:         %.2f%%\n", $percent);
    echo sprintf("  Threshold:        %.2f%%\n", $threshold);
    if ($uncovered !== []) {
        echo "  Uncovered:\n";
        foreach ($uncovered as $endpoint) {
            echo "    - $endpoint\n";
        }
    } else {
        echo "  All endpoints are covered by real-HTTP smoke tests.\n";
    }
}

if ($percent + 1e-9 < $threshold) {
    fwrite(STDERR, sprintf(
        "[http-coverage] FAIL: coverage %.2f%% is below threshold %.2f%%\n",
        $percent,
        $threshold,
    ));
    exit(1);
}

exit(0);
