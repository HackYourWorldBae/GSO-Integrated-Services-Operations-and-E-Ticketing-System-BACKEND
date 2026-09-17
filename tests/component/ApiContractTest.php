<?php

use CodeIgniter\Test\CIUnitTestCase;

/**
 * Level 3 — Component tests for frontend <-> backend communication.
 *
 * Guarantees no data or action is lost between the Vue.js frontend and the
 * CodeIgniter 4 backend by proving, without needing a live server or database:
 *
 *  1. Every endpoint the frontend calls through apiClient exists in
 *     app/Config/Routes.php with the SAME HTTP verb (extracted
 *     automatically from GSO-Frontend/src, so drift fails loudly).
 *  2. Every routed handler resolves to a real controller + method
 *     (validates the TicketController split wiring).
 *  3. No route still grants the retired `dispatcher` role.
 *  4. Dispatch operations remain admin-gated.
 *
 * @internal
 */
final class ApiContractTest extends CIUnitTestCase
{
    private string $frontendSrc;
    private string $routesSource;
    private string $routesContent;

    /** @var list<array{verb: string, uri: string, file: string}> */
    private array $frontendCalls = [];

    /** @var list<array{verbs: list<string>, pattern: string, handler: string}> */
    private array $backendRoutes = [];

    protected function setUp(): void
    {
        parent::setUp();

        // The frontend checkout sits beside the backend directory.
        $candidates = [
            HOMEPATH . '/../GSO-Frontend/src',
            rtrim(HOMEPATH, '\\/') . '/../GSO-Frontend/src',
            dirname(rtrim(HOMEPATH, '\\/')) . '/Capstone Main Source Code - GSO E-Ticketing/GSO-Frontend/src',
        ];
        $this->frontendSrc = '';
        foreach ($candidates as $candidate) {
            $resolved = realpath($candidate);
            if ($resolved !== false && is_dir($resolved)) {
                $this->frontendSrc = $resolved;
                break;
            }
        }
        $this->routesSource = APPPATH . 'Config/Routes.php';
        $this->routesContent = file_get_contents($this->routesSource);

        $this->frontendCalls = $this->extractFrontendCalls();
        $this->backendRoutes = $this->parseBackendRoutes();
    }

    /**
     * Scan every frontend source file for apiClient.<verb>('<uri>') calls.
     * Template placeholders (${...}) become wildcards for pattern matching.
     *
     * @return list<array{verb: string, uri: string, file: string}>
     */
    private function extractFrontendCalls(): array
    {
        $calls = [];
        $this->assertNotSame('', $this->frontendSrc, 'Frontend src directory could not be located.');
        $files = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($this->frontendSrc, FilesystemIterator::SKIP_DOTS)
        );

        foreach ($files as $file) {
            /** @var SplFileInfo $file */
            if (!in_array($file->getExtension(), ['js', 'vue'], true)) {
                continue;
            }
            if (str_contains($file->getPathname(), '__tests__')) {
                continue;
            }

            $content = file_get_contents($file->getPathname());
            $pattern = '/apiClient\s*\.\s*(get|post|patch|put|delete)\s*\(\s*[`\'"]([^`\'"]+)[`\'"]/i';

            if (preg_match_all($pattern, $content, $matches, PREG_SET_ORDER) === false) {
                continue;
            }

            foreach ($matches as $match) {
                $uri = $match[2];
                // Drop query strings; they never affect routing.
                $uri = explode('?', $uri)[0];
                // Template interpolations match any single path segment.
                $uri = preg_replace('/\$\{[^}]+\}/', '__P__', $uri);
                $uri = ltrim($uri, '/');

                // Skip absolute backend URLs embedded for <img> tags etc.
                if (str_starts_with($uri, 'http') || str_starts_with($uri, 'api/v1/')) {
                    continue;
                }

                $calls[] = [
                    'verb' => strtolower($match[1]),
                    'uri'  => $uri,
                    'file' => basename($file->getPathname()),
                ];
            }
        }

        return $calls;
    }

    /**
     * Parse backend route definitions (single-verb + match() groups).
     *
     * @return list<array{verbs: list<string>, pattern: string, handler: string}>
     */
    private function parseBackendRoutes(): array
    {
        $source = $this->routesContent;
        $routes = [];

        $single = '/\$routes->(get|post|patch|put|delete)\s*\(\s*\'([^\']+)\'\s*,\s*\'([^\']+)\'/';
        preg_match_all($single, $source, $m, PREG_SET_ORDER);
        foreach ($m as $row) {
            $routes[] = ['verbs' => [$row[1]], 'pattern' => $row[2], 'handler' => $row[3]];
        }

        $multi = '/\$routes->match\s*\(\s*\[([^\]]+)\]\s*,\s*\'([^\']+)\'\s*,\s*\'([^\']+)\'/';
        preg_match_all($multi, $source, $m, PREG_SET_ORDER);
        foreach ($m as $row) {
            $verbs = array_map(
                static fn ($v): string => strtolower(trim($v, " \t\n\r\0\x0B'\"")),
                explode(',', $row[1])
            );
            $routes[] = ['verbs' => $verbs, 'pattern' => $row[2], 'handler' => $row[3]];
        }

        return $routes;
    }

    private function backendPatternToRegex(string $pattern): string
    {
        $pattern = str_replace(
            ['(:num)', '(:segment)', '(:any)'],
            ['[0-9]+', '[^/]+', '.+'],
            $pattern
        );

        return '#^api/v1/' . $pattern . '$#';
    }

    private function frontendUriToRegex(string $uri): string
    {
        return '#^' . str_replace('__P__', '[^/]+', preg_quote($uri, '#')) . '$#';
    }

    public function testFrontendCallExtractionFindsTheFullApiSurface(): void
    {
        // Sanity: the extractor must see the whole catalog (7 api modules +
        // direct view calls). A low count means the regex missed call styles.
        $this->assertGreaterThanOrEqual(
            40,
            count($this->frontendCalls),
            'Extractor found too few apiClient calls — call styles may have drifted.'
        );

        $uris = array_column($this->frontendCalls, 'uri');
        foreach (['tickets/intake', 'dispatch/assign', 'feedback', 'auth/login', 'director/analytics', 'projects'] as $key) {
            $found = false;
            foreach ($uris as $uri) {
                if (str_contains(str_replace('__P__', '', $uri), $key)) {
                    $found = true;
                    break;
                }
            }
            $this->assertTrue($found, "Expected frontend to call an endpoint under {$key}");
        }
    }

    public function testEveryFrontendCallMatchesABackendRouteWithTheSameVerb(): void
    {
        $failures = [];

        foreach ($this->frontendCalls as $call) {
            $wanted   = $this->frontendUriToRegex($call['uri']);
            $matched  = false;
            $uriKnown = false;

            foreach ($this->backendRoutes as $route) {
                // Strip the api/v1 prefix for frontend-relative comparison.
                $backendPath = preg_replace('#^api/v1/#', '', 'api/v1/' . $route['pattern']);
                $routeRx     = '#^' . str_replace(
                    ['\\(\\:num\\)', '\\(\\:segment\\)', '\\(\\:any\\)'],
                    ['[0-9]+', '[^/]+', '.+'],
                    preg_quote($backendPath, '#')
                ) . '$#';

                // Does the frontend URI shape fit this backend pattern at all?
                $probe = str_replace('__P__', 'SEGMENT', $call['uri']);
                if (preg_match($routeRx, $probe) === 1 || preg_match($routeRx, $call['uri']) === 1) {
                    $uriKnown = true;
                    if (in_array($call['verb'], $route['verbs'], true)) {
                        $matched = true;
                        break;
                    }
                }
            }

            // Fallback: direct regex-to-regex compatibility for templated URIs.
            if (!$matched && $uriKnown === false) {
                foreach ($this->backendRoutes as $route) {
                    if (!in_array($call['verb'], $route['verbs'], true)) {
                        continue;
                    }
                    $backendRx = $this->backendPatternToRegex($route['pattern']);
                    // Numeric sample: satisfies both (:num) and (:segment) slots,
                    // matching how the frontend sends integer assignment/user IDs.
                    $sample = 'api/v1/' . str_replace('__P__', '7', $call['uri']);
                    if (preg_match($backendRx, $sample) === 1) {
                        $matched = true;
                        break;
                    }
                }
            }

            if (!$matched) {
                $failures[] = strtoupper($call['verb']) . ' /' . $call['uri'] . " ({$call['file']})";
            }
        }

        $this->assertSame(
            [],
            $failures,
            'Frontend calls with no matching backend route/verb: ' . implode('; ', $failures)
        );
    }

    public function testEveryRoutedHandlerResolvesToARealControllerMethod(): void
    {
        $failures = [];

        foreach ($this->backendRoutes as $route) {
            $handler = explode('/', $route['handler'])[0]; // strip /$1 placeholders
            if (!str_contains($handler, '::')) {
                continue; // closure route (e.g. /health)
            }

            [$controller, $method] = explode('::', $handler);
            $fqcn = 'App\\Controllers\\API\\' . $controller;
            if (!class_exists($fqcn)) {
                // Non-API controllers (e.g. the default Home route).
                $fqcn = 'App\\Controllers\\' . $controller;
            }

            if (!class_exists($fqcn)) {
                $failures[] = "{$handler} — class {$fqcn} missing";
                continue;
            }
            if (!method_exists($fqcn, $method)) {
                $failures[] = "{$handler} — method {$method} missing on {$controller}";
            }
        }

        $this->assertSame([], $failures, 'Unresolvable handlers: ' . implode('; ', $failures));
    }

    public function testSplitControllersAreWiredForEveryTicketOperation(): void
    {
        foreach ([
            'App\\Controllers\\API\\TicketController'           => ['submitIntake', 'myRequests', 'cancel'],
            'App\\Controllers\\API\\TicketQueueController'      => ['pendingQueue', 'dispatchQueue', 'activeTickets', 'show', 'logs'],
            'App\\Controllers\\API\\TicketActionController'     => ['approve', 'complete', 'verifyAndClose', 'resolveIncident'],
            'App\\Controllers\\API\\TicketAttachmentController' => ['uploadAttachment', 'downloadAccomplishment'],
        ] as $class => $methods) {
            $this->assertTrue(class_exists($class), "{$class} must exist");
            foreach ($methods as $method) {
                $this->assertTrue(method_exists($class, $method), "{$class}::{$method} must exist");
            }
        }
    }

    public function testNoRouteGrantsTheRetiredDispatcherRole(): void
    {
        $this->assertDoesNotMatchRegularExpression(
            '/role:[^\]\'"]*dispatcher/',
            $this->routesContent,
            'A route still grants the retired dispatcher role.'
        );
    }

    public function testDispatchOperationsRemainAdminGated(): void
    {
        $this->assertMatchesRegularExpression(
            '/dispatch\/assign\'[^\n]*role:admin[\]\'"]/',
            $this->routesContent,
            'dispatch/assign must stay admin-gated.'
        );
        $this->assertMatchesRegularExpression(
            '/dispatch\/start\'[^\n]*role:admin,worker/',
            $this->routesContent,
            'dispatch/start must stay admin/worker-gated.'
        );
    }
}
