<?php

declare(strict_types=1);

namespace ReleaseImpact;

/**
 * Last line of defence before anything leaves the process. The analyser never reads values into the
 * report; this replaces secret-shaped text in names, paths, refs and messages with a keyed hash.
 */
final class Redactor
{
    private const PATTERNS = [
        '/-----BEGIN[A-Z0-9 ]*-----.*?(?:-----END[A-Z0-9 ]*-----|$)/s', // PEM blocks
        '/(?<=:\/\/)[^\s\/@:]*:[^\s\/@]*(?=@)/', // user:password@ in URLs
        '/base64:[A-Za-z0-9+\/=]{16,}/', // Laravel APP_KEY
        '/(?:sk|rk|pk|whsec)_(?:live|test)_[A-Za-z0-9]{8,}/', // Stripe
        '/(?:gh[pousr]_[A-Za-z0-9]{20,}|github_pat_[A-Za-z0-9_]{20,})/', // GitHub
        '/glpat-[A-Za-z0-9_-]{20,}/', // GitLab
        '/xox[abprs]-[A-Za-z0-9-]{10,}/', // Slack
        '/(?:AKIA|ASIA)[0-9A-Z]{16}(?![0-9A-Z])/', // AWS access key IDs
        '/AIza[0-9A-Za-z_-]{35}/', // Google API keys
        '/eyJ[A-Za-z0-9_-]{5,}\.[A-Za-z0-9_-]{5,}\.[A-Za-z0-9_-]*/', // JWTs
        '/(?<![0-9A-Fa-f])[0-9A-Fa-f]{32,}(?![0-9A-Fa-f])/', // long hex
    ];

    /** Base64/base62 runs; "_", "-", "." and "/" break runs so identifiers and paths are not caught. */
    private const RANDOM_RUN = '/[A-Za-z0-9+=]{20,}/';

    /** Fields that hold Git object IDs, which identify commits and are not secrets. */
    private const OBJECT_ID_FIELDS = ['commit', 'revision', 'reference'];

    private readonly string $key;

    public function __construct()
    {
        // Fresh per run and never stored, so tokens cannot be reversed even for short secrets.
        $this->key = random_bytes(32);
    }

    public function report(array $report): array
    {
        array_walk_recursive($report, function (mixed &$value, int|string $field): void {
            $isObjectId = in_array($field, self::OBJECT_ID_FIELDS, true) && is_string($value) && preg_match('/^[0-9a-f]{40}(?:[0-9a-f]{24})?$/', $value);

            if (is_string($value) && !$isObjectId) {
                $value = $this->text($value);
            }
        });

        return $report;
    }

    public function text(string $value): string
    {
        foreach (self::PATTERNS as $pattern) {
            $value = preg_replace_callback($pattern, fn (array $m) => $this->token($m[0]), $value);
        }

        return preg_replace_callback(self::RANDOM_RUN, fn (array $m) => self::looksRandom($m[0]) ? $this->token($m[0]) : $m[0], $value);
    }

    private function token(string $secret): string
    {
        return '[REDACTED:' . substr(hash_hmac('sha256', $secret, $this->key), 0, 16) . ']';
    }

    /** Random tokens mix several digits, upper and lower case; identifiers like OAuth2Client do not. */
    private static function looksRandom(string $run): bool
    {
        return preg_match_all('/[0-9]/', $run) >= 2
            && preg_match_all('/[A-Z]/', $run) >= 2
            && preg_match_all('/[a-z]/', $run) >= 2;
    }
}
