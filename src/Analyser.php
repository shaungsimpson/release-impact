<?php

declare(strict_types=1);

namespace ReleaseImpact;

use PhpParser\Error as ParseError;
use PhpParser\Node;
use PhpParser\NodeFinder;
use PhpParser\Parser;
use PhpParser\ParserFactory;

/**
 * Builds the report for two resolved commits. Every value comes from committed Git objects.
 */
final class Analyser
{
    public const SCHEMA_VERSION = 1;

    /** Operational paths matched with fnmatch(FNM_PATHNAME), so "*" never crosses "/". */
    public const OPERATIONAL_PATHS = [
        'config/queue.php' => 'queue',
        'config/horizon.php' => 'queue',
        'routes/console.php' => 'scheduler',
        'app/Console/Kernel.php' => 'scheduler',
        'bootstrap/app.php' => 'scheduler',
        'Procfile' => 'deployment',
        'Dockerfile' => 'deployment',
        'Dockerfile.*' => 'deployment',
        'docker-compose*.yml' => 'deployment',
        'docker-compose*.yaml' => 'deployment',
        'compose*.yml' => 'deployment',
        'compose*.yaml' => 'deployment',
        'Envoy.blade.php' => 'deployment',
        'deploy.php' => 'deployment',
        'supervisor*.conf' => 'deployment',
        '.github/workflows/*.yml' => 'deployment',
        '.github/workflows/*.yaml' => 'deployment',
    ];

    private const STATUSES = ['A' => 'added', 'M' => 'modified', 'D' => 'deleted', 'R' => 'renamed', 'T' => 'modified'];

    private array $findings = [];
    private array $limitations = [];
    private ?Parser $parser = null;

    public function __construct(private readonly Git $git)
    {
    }

    public function analyse(string $baseRef, string $headRef): array
    {
        $base = $this->git->resolve($baseRef);
        $head = $this->git->resolve($headRef);
        $this->findings = $this->limitations = [];

        $changes = $this->git->diff($base, $head);
        $this->migrations($changes);
        $this->operationalFiles($changes);
        $configKeys = $this->configEnvKeys($base, $head);
        $this->envExample($base, $head, $configKeys);
        $this->dependencies($base, $head);

        usort($this->findings, fn ($a, $b) => self::sortKey($a) <=> self::sortKey($b));
        usort($this->limitations, fn ($a, $b) => self::sortKey($a) <=> self::sortKey($b));

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'tool' => 'release-impact',
            'base' => ['ref' => $baseRef, 'commit' => $base],
            'head' => ['ref' => $headRef, 'commit' => $head],
            'findings' => $this->findings,
            'limitations' => $this->limitations,
        ];
    }

    private function migrations(array $changes): void
    {
        $isMigration = fn (?string $path) => $path !== null && str_starts_with($path, 'database/migrations/') && str_ends_with($path, '.php');

        foreach ($changes as $change) {
            if ($isMigration($change['path']) || $isMigration($change['from'])) {
                $this->findings[] = ['type' => 'migration.' . self::STATUSES[$change['status']]] + self::changeEvidence($change);
            }
        }
    }

    private function operationalFiles(array $changes): void
    {
        foreach ($changes as $change) {
            $category = self::operationalCategory($change['path']) ?? self::operationalCategory($change['from']);

            if ($category !== null) {
                $this->findings[] = ['type' => 'operational_file.' . self::STATUSES[$change['status']]] + self::changeEvidence($change) + ['category' => $category];
            }
        }
    }

    /** @return array<string, list<array>> head config keys with their locations */
    private function configEnvKeys(string $base, string $head): array
    {
        $headFiles = $this->configFiles($head);
        $baseFiles = $this->configFiles($base);
        $headKeys = $this->envReferences($head, $headFiles, []);
        // Unchanged files would only repeat head limitations, so base limitations cover changed files only.
        $baseKeys = $this->envReferences($base, $baseFiles, array_intersect_assoc($baseFiles, $headFiles));

        foreach (['added' => [$headKeys, $baseKeys], 'removed' => [$baseKeys, $headKeys]] as $kind => [$from, $other]) {
            foreach (array_diff_key($from, $other) as $key => $locations) {
                $this->findings[] = ['type' => "env.config_key.$kind", 'key' => (string) $key, 'locations' => $locations];
            }
        }

        return $headKeys;
    }

    /** @return array<string, string> path => contents */
    private function configFiles(string $commit): array
    {
        $files = [];

        foreach ($this->git->files($commit, 'config') as $path) {
            if (str_ends_with($path, '.php') && ($contents = $this->git->blob($commit, $path)) !== null) {
                $files[$path] = $contents;
            }
        }

        return $files;
    }

    private function envReferences(string $commit, array $files, array $quiet): array
    {
        $keys = [];
        $this->parser ??= (new ParserFactory())->createForNewestSupportedVersion();

        foreach ($files as $path => $contents) {
            $report = !isset($quiet[$path]);

            try {
                $ast = $this->parser->parse($contents) ?? [];
            } catch (ParseError $e) {
                if ($report) {
                    $this->limit('config.parse_error', 'Could not parse file; its env() references were not analysed.', $commit, $path, $e->getStartLine() > 0 ? $e->getStartLine() : null);
                }
                continue;
            }

            $calls = (new NodeFinder())->find($ast, fn (Node $node) => $node instanceof Node\Expr\FuncCall
                && $node->name instanceof Node\Name
                && $node->name->toLowerString() === 'env');

            foreach ($calls as $call) {
                $arg = $call->isFirstClassCallable() ? null : ($call->getArgs()[0] ?? null);
                $literal = $arg !== null && !$arg->unpack && ($arg->name === null || $arg->name->name === 'key') && $arg->value instanceof Node\Scalar\String_;

                if ($literal) {
                    $keys[$arg->value->value][] = ['revision' => $commit, 'path' => $path, 'line' => $call->getStartLine()];
                } elseif ($report) {
                    $this->limit('config.dynamic_env_reference', 'env() call without a literal key; the key cannot be determined statically.', $commit, $path, $call->getStartLine());
                }
            }
        }

        ksort($keys, SORT_STRING);

        return $keys;
    }

    private function envExample(string $base, string $head, array $headConfigKeys): void
    {
        $baseKeys = $this->envExampleKeys($base);
        $headKeys = $this->envExampleKeys($head);

        if ($baseKeys !== null && $headKeys !== null) {
            foreach (['added' => [$headKeys, $baseKeys], 'removed' => [$baseKeys, $headKeys]] as $kind => [$from, $other]) {
                foreach (array_diff_key($from, $other) as $key => $locations) {
                    $this->findings[] = ['type' => "env.example_key.$kind", 'key' => (string) $key, 'locations' => $locations];
                }
            }
        }

        if ($headKeys !== null) {
            foreach ($this->findings as $finding) {
                if ($finding['type'] === 'env.config_key.added' && !isset($headKeys[$finding['key']])) {
                    $this->findings[] = ['type' => 'env.config_key.undocumented', 'key' => $finding['key'], 'locations' => $headConfigKeys[$finding['key']]];
                }
            }
        }
    }

    /** @return array<string, list<array>>|null key => locations; null when the file is absent */
    private function envExampleKeys(string $commit): ?array
    {
        $contents = $this->git->blob($commit, '.env.example');

        if ($contents === null) {
            $this->limit('env_example.missing', '.env.example is absent at this revision; its keys were not compared.', $commit, '.env.example');

            return null;
        }

        $keys = [];
        $openQuote = null;

        // Only the key is reported; values are never read into the report.
        foreach (preg_split('/\R/', $contents) as $index => $line) {
            // Lines inside a multi-line quoted value are value text, even when they look like KEY=.
            if ($openQuote !== null) {
                if (preg_match('/(?<!\\\\)' . $openQuote . '/', $line)) {
                    $openQuote = null;
                }
                continue;
            }

            if (preg_match('/^\s*(?:export\s+)?([A-Za-z_][A-Za-z0-9_]*)\s*=\s*(["\'])?(.*)$/', $line, $match)) {
                $keys[$match[1]][] = ['revision' => $commit, 'path' => '.env.example', 'line' => $index + 1];

                if ($match[2] !== '' && !preg_match('/(?<!\\\\)' . $match[2] . '/', $match[3])) {
                    $openQuote = $match[2];
                }
            }
        }

        ksort($keys, SORT_STRING);

        return $keys;
    }

    private function dependencies(string $base, string $head): void
    {
        $basePackages = $this->lockPackages($base);
        $headPackages = $this->lockPackages($head);

        if ($basePackages === null || $headPackages === null) {
            return;
        }

        foreach (array_keys($basePackages + $headPackages) as $name) {
            $from = $basePackages[$name] ?? null;
            $to = $headPackages[$name] ?? null;
            $type = match (true) {
                $from === null => 'dependency.added',
                $to === null => 'dependency.removed',
                $from !== $to => 'dependency.changed',
                default => null,
            };

            if ($type !== null) {
                $this->findings[] = ['type' => $type, 'package' => (string) $name, 'base' => $from, 'head' => $to];
            }
        }
    }

    /** @return array<string, array{version: ?string, reference: ?string, scope: string}>|null */
    private function lockPackages(string $commit): ?array
    {
        $contents = $this->git->blob($commit, 'composer.lock');

        if ($contents === null) {
            $this->limit('composer_lock.missing', 'composer.lock is absent at this revision; dependencies were not compared.', $commit, 'composer.lock');

            return null;
        }

        $lock = json_decode($contents, true);

        if (!is_array($lock) || !is_array($lock['packages'] ?? null) || !is_array($lock['packages-dev'] ?? [])) {
            $this->limit('composer_lock.invalid', 'composer.lock is not valid lock JSON; dependencies were not compared.', $commit, 'composer.lock');

            return null;
        }

        $packages = [];

        foreach (['runtime' => $lock['packages'], 'dev' => $lock['packages-dev'] ?? []] as $scope => $list) {
            foreach ($list as $package) {
                if (!is_string($package['name'] ?? null)) {
                    $this->limit('composer_lock.invalid_entry', 'A lock entry without a package name was skipped.', $commit, 'composer.lock');
                    continue;
                }

                $reference = $package['source']['reference'] ?? $package['dist']['reference'] ?? null;
                $packages[$package['name']] = [
                    'version' => is_string($package['version'] ?? null) ? $package['version'] : null,
                    'reference' => is_string($reference) ? $reference : null,
                    'scope' => $scope,
                ];
            }
        }

        return $packages;
    }

    private function limit(string $type, string $message, ?string $revision, ?string $path, ?int $line = null): void
    {
        $this->limitations[] = array_filter(compact('type', 'revision', 'path', 'line', 'message'), fn ($value) => $value !== null);
    }

    private static function changeEvidence(array $change): array
    {
        return $change['status'] === 'R'
            ? ['path' => $change['path'], 'from' => $change['from'], 'similarity' => $change['similarity']]
            : ['path' => $change['path']];
    }

    private static function operationalCategory(?string $path): ?string
    {
        foreach (self::OPERATIONAL_PATHS as $pattern => $category) {
            if ($path !== null && fnmatch($pattern, $path, FNM_PATHNAME | FNM_PERIOD)) {
                return $category;
            }
        }

        return null;
    }

    private static function sortKey(array $item): array
    {
        return [$item['type'], $item['path'] ?? $item['key'] ?? $item['package'] ?? '', $item['from'] ?? '', $item['revision'] ?? '', $item['line'] ?? 0];
    }
}
