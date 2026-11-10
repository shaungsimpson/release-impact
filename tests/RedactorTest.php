<?php

declare(strict_types=1);

namespace ReleaseImpact\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReleaseImpact\Redactor;

final class RedactorTest extends TestCase
{
    public static function secrets(): array
    {
        // Built by concatenation so this file does not trip secret scanners.
        return [
            'stripe live' => ['sk_' . 'live_51HxYzAbCdEfGh12345678'],
            'stripe webhook' => ['whsec_' . 'test_AbCdEf1234567890'],
            'github classic' => ['ghp_' . 'A1b2C3d4E5f6G7h8I9j0K1l2M3n4O5p6Q7r8'],
            'github fine-grained' => ['github_pat_' . '11ABCDEFG0123456789_abcdefghijklmnop'],
            'gitlab' => ['glpat-' . 'xYz123AbC456dEf789gH'],
            'slack' => ['xoxb-' . '123456789012-abcdefABCDEF'],
            'aws key id' => ['AKIA' . 'IOSFODNN7EXAMPLE'],
            'google api' => ['AIza' . 'SyA1b2C3d4E5f6G7h8I9j0K1l2M3n4O5p6Q'],
            'jwt' => ['eyJhbGciOiJIUzI1NiJ9' . '.eyJzdWIiOiIxMjM0NTY3ODkwIn0.dozjgNryP4J3jVmNHl0w5N_XgL0n3I9PlFUP0THsR8U'],
            'laravel app key' => ['base64:' . 'dGhpc2lzYXRlc3RrZXlmb3JsYXJhdmVsMTIzNDU2Nzg='],
            'pem' => ["-----BEGIN RSA PRIVATE KEY-----\nMIIBOgIBAAJBAKj34GkxFhD90vcNLYLInFEX6Ppy1tPf9Cnzj4p4WGeKLs1Pt8Qu\n-----END RSA PRIVATE KEY-----"],
            'hex secret' => ['9f86d081884c7d659a2feaa0c55ad015a3bf4f1b2b0b822cd15d6c15b0f00a08'],
            'random base62' => ['Zq8Xk2Lm9Np4Rt7Vw1Yb3Hd6'],
        ];
    }

    #[DataProvider('secrets')]
    public function testSecretShapesAreRedactedInsideOtherText(string $secret): void
    {
        $out = (new Redactor())->text("prefix {$secret} suffix");

        $this->assertStringNotContainsString($secret, $out);
        $this->assertMatchesRegularExpression('/^prefix \[REDACTED:[0-9a-f]{16}\] suffix$/', $out);
    }

    public function testUrlCredentialsAreRedactedButTheHostIsKept(): void
    {
        $out = (new Redactor())->text('https://deploy:hunter2@repo.example.com/pkg.git');

        $this->assertStringNotContainsString('hunter2', $out);
        $this->assertMatchesRegularExpression('#^https://\[REDACTED:[0-9a-f]{16}\]@repo\.example\.com/pkg\.git$#', $out);
    }

    public function testTokensAreStableWithinARunAndDifferBetweenRuns(): void
    {
        $secret = self::secrets()['stripe live'][0];
        $redactor = new Redactor();

        $this->assertSame($redactor->text($secret), $redactor->text($secret));
        $this->assertNotSame($redactor->text($secret), $redactor->text($secret . 'X'));
        $this->assertNotSame($redactor->text($secret), (new Redactor())->text($secret));
    }

    public static function ordinary(): array
    {
        return [
            ['AWS_SECRET_ACCESS_KEY'], ['STRIPE_SECRET'], ['DB_PASSWORD'], ['APP_KEY'],
            ['database/migrations/2024_01_03_000000_add_oauth2_tokens_to_users_table.php'],
            ['app/Http/Controllers/Api/V2/OAuth2ClientController.php'],
            ['laravel/framework'], ['v11.12.0'], ['dev-main'], ['2.0.0-beta3'],
            ['.github/workflows/build-multi-base-image.yml'],
            ['release-v6.3.7-2026-11-04'],
            ['env() call without a literal key; the key cannot be determined statically.'],
        ];
    }

    #[DataProvider('ordinary')]
    public function testOrdinaryNamesAreLeftAlone(string $value): void
    {
        $this->assertSame($value, (new Redactor())->text($value));
    }

    public function testReportKeepsGitObjectIdsButRedactsEverythingElse(): void
    {
        $sha = str_repeat('a1b2c3d4e5', 4);
        $secret = self::secrets()['github classic'][0];
        $report = (new Redactor())->report([
            'base' => ['ref' => $secret, 'commit' => $sha],
            'findings' => [['key' => $secret, 'locations' => [['revision' => $sha, 'path' => "x/{$secret}.php"]]]],
            'dep' => ['reference' => $sha, 'version' => $sha],
        ]);

        $this->assertStringNotContainsString($secret, json_encode($report));
        $this->assertSame($sha, $report['base']['commit']);
        $this->assertSame($sha, $report['findings'][0]['locations'][0]['revision']);
        $this->assertSame($sha, $report['dep']['reference']);
        $this->assertStringStartsWith('[REDACTED:', $report['dep']['version'], 'Object-ID exemption is limited to Git ID fields');
        $this->assertSame($report['base']['ref'], $report['findings'][0]['key'], 'Same secret, same token within a run');
    }
}
