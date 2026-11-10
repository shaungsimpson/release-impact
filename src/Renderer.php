<?php

declare(strict_types=1);

namespace ReleaseImpact;

final class Renderer
{
    private const SECTIONS = [
        'migration.' => 'Migrations',
        'env.config_key.' => 'Config env() keys',
        'env.example_key.' => '.env.example keys',
        'dependency.' => 'Composer dependencies',
        'operational_file.' => 'Queue, scheduler and deployment files',
    ];

    public static function json(array $report): string
    {
        return json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR) . "\n";
    }

    public static function text(array $report): string
    {
        $out = sprintf(
            "Release Impact: %s (%s) -> %s (%s)\n",
            self::clean($report['base']['ref']), substr($report['base']['commit'], 0, 12),
            self::clean($report['head']['ref']), substr($report['head']['commit'], 0, 12),
        );

        foreach (self::SECTIONS as $prefix => $title) {
            $lines = [];

            foreach ($report['findings'] as $finding) {
                if (str_starts_with($finding['type'], $prefix)) {
                    $lines[] = sprintf('  %-12s %s', substr($finding['type'], strlen($prefix)), self::describe($finding));
                }
            }

            if ($lines !== []) {
                $out .= "\n{$title} (" . count($lines) . ")\n" . implode("\n", $lines) . "\n";
            }
        }

        if ($report['findings'] === []) {
            $out .= "\nNo deployment-relevant changes found in the analysed paths.\n";
        }

        if ($report['limitations'] !== []) {
            $out .= "\nLimitations (" . count($report['limitations']) . ")\n";

            foreach ($report['limitations'] as $limit) {
                $where = isset($limit['path']) ? self::clean($limit['path']) . (isset($limit['line']) ? ":{$limit['line']}" : '') : '';
                $at = isset($limit['revision']) ? ' @' . substr($limit['revision'], 0, 12) : '';
                $out .= "  {$where}{$at}: {$limit['message']}\n";
            }
        }

        return $out;
    }

    private static function describe(array $finding): string
    {
        if (isset($finding['package'])) {
            $version = fn (?array $side) => $side === null ? '(none)' : ($side['version'] ?? '?') . ($side['scope'] === 'dev' ? ' [dev]' : '');

            return self::clean($finding['package']) . '  ' . match ($finding['type']) {
                'dependency.added' => $version($finding['head']),
                'dependency.removed' => $version($finding['base']),
                default => $version($finding['base']) . ' -> ' . $version($finding['head']),
            };
        }

        if (isset($finding['key'])) {
            $where = array_map(fn ($l) => self::clean($l['path']) . ':' . $l['line'], $finding['locations']);
            $note = $finding['type'] === 'env.config_key.undocumented' ? '  (not in head .env.example; review)' : '';

            return self::clean($finding['key']) . '  ' . implode(', ', $where) . $note;
        }

        $path = isset($finding['from'])
            ? self::clean($finding['from']) . ' -> ' . self::clean($finding['path']) . " ({$finding['similarity']}%)"
            : self::clean($finding['path']);

        return $path . (isset($finding['category']) ? "  [{$finding['category']}]" : '');
    }

    /** Escape control characters so file names cannot break lines or inject terminal sequences. */
    private static function clean(string $value): string
    {
        return preg_replace_callback('/[\x00-\x1F\x7F]/', fn ($m) => sprintf('\\x%02X', ord($m[0])), $value);
    }
}
