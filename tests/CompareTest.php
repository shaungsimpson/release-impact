<?php

declare(strict_types=1);

namespace ReleaseImpact\Tests;

use PHPUnit\Framework\TestCase;

final class CompareTest extends TestCase
{
    private const SECRET = 's3cr3t-value-never-print';

    private string $repo;

    protected function setUp(): void
    {
        $this->repo = sys_get_temp_dir() . '/release-impact-' . bin2hex(random_bytes(6));
        mkdir($this->repo);
        $this->git('init', '-q', '-b', 'main');
        $this->git('config', 'user.email', 'test@example.invalid');
        $this->git('config', 'user.name', 'Test');
        $this->git('config', 'commit.gpgsign', 'false');
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->repo));
    }

    public function testFullComparison(): void
    {
        $this->commit('base', [
            'database/migrations/2024_01_01_000000_create_users.php' => '<?php // users',
            'database/migrations/2024_01_02_000000_create_posts.php' => '<?php // posts',
            'database/migrations/2024_01_03_000000_old_name.php' => "<?php\n" . str_repeat("// unchanged body line\n", 20),
            'config/app.php' => "<?php\nreturn [\n  'name' => env('APP_NAME', 'Laravel'),\n  'old' => env('OLD_KEY'),\n];\n",
            '.env.example' => "APP_NAME=Laravel\nOLD_KEY=" . self::SECRET . "\n",
            'composer.lock' => $this->lock(['laravel/framework' => '11.0.0', 'guzzlehttp/guzzle' => '7.8.0'], ['phpunit/phpunit' => '10.5.0', 'mockery/mockery' => '1.6.0']),
            'routes/console.php' => '<?php // schedule',
        ]);
        $this->commit('head', [
            'database/migrations/2024_01_01_000000_create_users.php' => '<?php // users changed',
            'database/migrations/2024_01_02_000000_create_posts.php' => null,
            'database/migrations/2024_01_03_000000_old_name.php' => null,
            'database/migrations/2024_01_03_000000_new name é.php' => "<?php\n" . str_repeat("// unchanged body line\n", 20),
            'database/migrations/2024_01_04_000000_add_index.php' => '<?php // new',
            'config/app.php' => "<?php\nreturn [\n  'name' => env('APP_NAME', 'Laravel'),\n  'new' => \\env('NEW_KEY', '" . self::SECRET . "'),\n  'documented' => env(key: 'DOC_KEY'),\n  'dyn' => env(\$name),\n  'concat' => env('PRE_' . \$x),\n];\n",
            '.env.example' => "APP_NAME=Laravel\n# comment=ignored\nexport DOC_KEY=\"" . self::SECRET . "\"\n",
            '.env' => 'NEW_KEY=' . self::SECRET,
            'composer.lock' => $this->lock(['laravel/framework' => '11.2.0', 'mockery/mockery' => '1.6.0', 'new/pkg' => '1.0.0'], ['phpunit/phpunit' => '10.5.0']),
            'routes/console.php' => '<?php // schedule changed',
            'Dockerfile' => 'FROM php',
            'app/Models/User.php' => '<?php // irrelevant',
        ]);

        [$code, $out, $err] = $this->cli('compare', 'base', 'head', '--format=json');
        $this->assertSame(0, $code, $err);
        $this->assertSame('', $err);
        $this->assertStringNotContainsString(self::SECRET, $out);
        $report = json_decode($out, true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(1, $report['schema_version']);
        $this->assertSame('base', $report['base']['ref']);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{40}$/', $report['head']['commit']);

        $types = array_map(fn ($f) => $f['type'] . ' ' . ($f['path'] ?? $f['key'] ?? $f['package']), $report['findings']);
        $this->assertSame([
            'dependency.added new/pkg',
            'dependency.changed laravel/framework',
            'dependency.changed mockery/mockery',
            'dependency.removed guzzlehttp/guzzle',
            'env.config_key.added DOC_KEY',
            'env.config_key.added NEW_KEY',
            'env.config_key.removed OLD_KEY',
            'env.config_key.undocumented NEW_KEY',
            'env.example_key.added DOC_KEY',
            'env.example_key.removed OLD_KEY',
            'migration.added database/migrations/2024_01_04_000000_add_index.php',
            'migration.deleted database/migrations/2024_01_02_000000_create_posts.php',
            'migration.modified database/migrations/2024_01_01_000000_create_users.php',
            'migration.renamed database/migrations/2024_01_03_000000_new name é.php',
            'operational_file.added Dockerfile',
            'operational_file.modified routes/console.php',
        ], $types);

        $byType = array_column($report['findings'], null, 'type');
        $this->assertSame('database/migrations/2024_01_03_000000_old_name.php', $byType['migration.renamed']['from']);
        $this->assertSame(['revision' => $report['head']['commit'], 'path' => 'config/app.php', 'line' => 4], $byType['env.config_key.undocumented']['locations'][0]);
        $this->assertSame(['version' => '1.6.0', 'reference' => null, 'scope' => 'runtime'], array_values(array_filter($report['findings'], fn ($f) => ($f['package'] ?? '') === 'mockery/mockery'))[0]['head']);
        $this->assertSame('scheduler', $byType['operational_file.modified']['category']);

        $dynamic = array_filter($report['limitations'], fn ($l) => $l['type'] === 'config.dynamic_env_reference');
        $this->assertSame([6, 7], array_values(array_column($dynamic, 'line')));

        // Deterministic output.
        $this->assertSame($out, $this->cli('compare', 'base', 'head', '--format=json')[1]);

        [$code, $text] = $this->cli('compare', 'base', 'head');
        $this->assertSame(0, $code);
        $this->assertStringNotContainsString(self::SECRET, $text);
        $this->assertStringContainsString('laravel/framework  11.0.0 -> 11.2.0', $text);
        $this->assertStringContainsString('NEW_KEY  config/app.php:4  (not in head .env.example; review)', $text);
    }

    public function testMissingAndInvalidFilesBecomeLimitations(): void
    {
        $this->commit('base', ['README' => 'x', 'config/bad.php' => '<?php return [ env(']);
        $this->commit('head', ['README' => 'y', 'composer.lock' => '{not json', 'config/bad.php' => '<?php return [ env(']);

        [$code, $out, $err] = $this->cli('compare', 'base', 'head', '--format=json');
        $this->assertSame(0, $code, $err);
        $report = json_decode($out, true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame([], $report['findings']);
        $this->assertSame(
            ['composer_lock.invalid', 'composer_lock.missing', 'config.parse_error', 'env_example.missing', 'env_example.missing'],
            array_column($report['limitations'], 'type'),
        );
    }

    public function testEmptyReportAndWorkingTreeIgnored(): void
    {
        $this->commit('base', ['config/app.php' => "<?php return ['a' => env('A')];", '.env.example' => "A=\n", 'composer.lock' => $this->lock([], [])]);
        file_put_contents($this->repo . '/config/app.php', "<?php return ['b' => env('UNCOMMITTED')];");
        mkdir($this->repo . '/database/migrations', recursive: true);
        file_put_contents($this->repo . '/database/migrations/x.php', '<?php');

        [$code, $out] = $this->cli('compare', 'base', 'base', '--format=json');
        $this->assertSame(0, $code);
        $report = json_decode($out, true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame([], $report['findings']);
        $this->assertSame([], $report['limitations']);

        $this->assertStringContainsString('No deployment-relevant changes', $this->cli('compare', 'base', 'base')[1]);
    }

    public function testNewlineInFileNameIsEscapedInText(): void
    {
        $this->commit('base', ['README' => 'x']);
        $this->commit('head', ["database/migrations/evil\n\e[31mname.php" => '<?php']);

        [$code, $out] = $this->cli('compare', 'base', 'head');
        $this->assertSame(0, $code);
        $this->assertStringContainsString('evil\x0A\x1B[31mname.php', $out);
        $this->assertStringNotContainsString("\e", $out);

        $json = json_decode($this->cli('compare', 'base', 'head', '--format=json')[1], true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame("database/migrations/evil\n\e[31mname.php", $json['findings'][0]['path']);
    }

    public function testNoPlantedSecretReachesStdoutOrStderr(): void
    {
        // Plain lowercase canaries do not look like secrets, so only "values are never read" keeps them out.
        $c = [];
        for ($i = 0; $i < 30; $i++) {
            $c[] = 'zzcanary' . substr(str_shuffle(str_repeat('abcdefghijklmnopqrstuvwxyz', 2)), 0, 16);
        }
        // Secret-shaped names that must be redacted wherever a name is reported.
        $stripe = 'sk_' . 'live_51HxYzAbCdEfGh12345678';
        $github = 'ghp_' . 'A1b2C3d4E5f6G7h8I9j0K1l2M3n4O5p6Q7r8';
        $aws = 'AKIA' . 'IOSFODNN7EXAMPLE';
        $gitlab = 'glpat-' . 'xYz123AbC456dEf789gH';

        $this->commit('base', [
            '.env.example' => "APP_NAME={$c[0]}\nOLD_KEY=\"{$c[1]}\"\n",
            'config/app.php' => "<?php return ['a' => env('OLD_KEY', '{$c[2]}')];",
            'composer.lock' => $this->lock(['laravel/framework' => '11.0.0'], []),
        ]);
        $lock = json_decode($this->lock(['laravel/framework' => '11.1.0', "acme/{$gitlab}" => '1.0.0'], []), true);
        $lock['packages'][0] += [
            'source' => ['type' => 'git', 'url' => "https://deploy:{$c[3]}@git.example.com/f.git", 'reference' => str_repeat('ab12', 10)],
            'dist' => ['type' => 'zip', 'url' => "https://x.example.com/{$c[4]}.zip"],
            'notification-url' => "https://x.example.com/{$c[5]}",
            'authors' => [['email' => "{$c[6]}@example.com"]],
            'extra' => ['token' => $c[7]],
        ];
        $this->git('commit', '-q', '--allow-empty', '-m', "msg {$c[8]}");
        $this->commit('head', [
            '.env.example' => "APP_NAME={$c[0]}\nPLAIN={$c[9]}\nQUOTED=\"{$c[10]}\"\nSINGLE='{$c[11]}'\nexport EXP={$c[12]}\n# COMMENTED={$c[13]}\n"
                . "MULTI=\"first\n{$c[14]}\nWORDS={$c[15]}\n\"\nEQ=a={$c[16]}\nINLINE=x # {$c[17]}\n{$github}=x\n",
            '.env' => "APP_KEY={$c[18]}",
            '.env.production' => "DB_PASSWORD={$c[19]}",
            'auth.json' => "{\"http-basic\":{\"x\":{\"password\":\"{$c[20]}\"}}}",
            'config/app.php' => "<?php\n// {$c[21]}\nreturn [\n  'a' => env('NEW_KEY', '{$c[22]}'),\n  'b' => env('NESTED', env('INNER', '{$c[23]}')),\n"
                . "  'c' => '{$c[24]}',\n  'd' => <<<TXT\n{$c[25]}\nTXT,\n  'e' => env('PRE_' . '{$c[26]}'),\n  'f' => env('{$stripe}'),\n];\n",
            'config/broken.php' => "<?php return [ '{$c[27]}' ",
            "database/migrations/2024_01_01_000000_{$aws}.php" => "<?php // {$c[28]}",
            'Dockerfile' => "ENV TOKEN={$c[29]}",
            'composer.lock' => json_encode($lock),
        ]);
        $this->git('tag', '-f', '-a', $stripe, '-m', "tag {$c[29]}");

        $runs = [
            $this->cli('compare', 'base', 'head'),
            $this->cli('compare', 'base', 'head', '--format=json'),
            $this->cli('compare', $stripe, 'base', '--format=json'),
            $this->cli('compare', 'base', $github),
            $this->cli('compare', 'base', 'head', "--{$github}"),
            $this->cli('compare', 'a', 'b', '--repo', sys_get_temp_dir() . "/{$aws}-missing"),
        ];

        foreach ($runs as $i => [, $out, $err]) {
            foreach ([...$c, $stripe, $github, $aws, $gitlab] as $secret) {
                $this->assertStringNotContainsString($secret, $out . $err, "run {$i} leaked {$secret}");
            }
        }

        $this->assertSame([0, 0, 0, 3, 2, 3], array_column($runs, 0));
        $json = json_decode($runs[1][1], true, flags: JSON_THROW_ON_ERROR);
        $names = array_map(fn ($f) => $f['key'] ?? $f['package'] ?? $f['path'], $json['findings']);
        // Ordinary names stay readable; secret-shaped ones become tokens.
        foreach (['NEW_KEY', 'NESTED', 'INNER', 'PLAIN', 'MULTI', 'laravel/framework', 'Dockerfile'] as $name) {
            $this->assertContains($name, $names);
        }
        $this->assertNotContains('WORDS', $names, 'Text inside a multi-line value is not a key');
        $this->assertGreaterThanOrEqual(4, substr_count($runs[1][1], '[REDACTED:'));
        $this->assertStringContainsString('[REDACTED:', $runs[3][2]);
    }

    public function testMultiLineEnvExampleValueIsNotReadAsKeys(): void
    {
        $pem = "MIIBOgIBAAJBAKj34GkxFhD90vcNLYLInFEX6Ppy1tPf9Cnzj4p4WGeKLs1Pt8Qu=";
        $this->commit('base', ['.env.example' => "A=1\n"]);
        $this->commit('head', ['.env.example' => "A=1\nPRIVATE_KEY=\"-----BEGIN KEY-----\n{$pem}\nINNER=\\\"still value\\\"\n-----END KEY-----\"\nSINGLE='x\nQUOTED=y'\nB=\"one line\"\nC=2\n"]);

        [$code, $out] = $this->cli('compare', 'base', 'head', '--format=json');
        $this->assertSame(0, $code);
        $this->assertStringNotContainsString($pem, $out);
        $report = json_decode($out, true, flags: JSON_THROW_ON_ERROR);
        $added = array_column(array_filter($report['findings'], fn ($f) => $f['type'] === 'env.example_key.added'), 'key');
        $this->assertSame(['B', 'C', 'PRIVATE_KEY', 'SINGLE'], array_values($added));
    }

    public function testUnreadableObjectIsAGitFailureNotAMissingFile(): void
    {
        $this->commit('base', ['composer.lock' => $this->lock([], [])]);
        $this->commit('head', ['composer.lock' => $this->lock(['a/b' => '1.0.0'], [])]);
        $blob = trim(shell_exec('git -C ' . escapeshellarg($this->repo) . ' rev-parse refs/tags/head:composer.lock'));
        unlink($this->repo . '/.git/objects/' . substr($blob, 0, 2) . '/' . substr($blob, 2));

        [$code, $out, $err] = $this->cli('compare', 'base', 'head');
        $this->assertSame([4, ''], [$code, $out]);
        $this->assertStringContainsString('Git could not read composer.lock', $err);
    }

    public function testOperationalFailures(): void
    {
        $this->commit('base', ['README' => 'x']);

        [$code, $out, $err] = $this->cli('compare', 'base', 'nope', '--format=json');
        $this->assertSame([3, ''], [$code, $out]);
        $this->assertStringContainsString('Revision does not resolve to a commit: nope', $err);

        $this->assertSame(3, $this->cli('compare', 'base', 'HEAD^{tree}')[0]);

        $notGit = sys_get_temp_dir() . '/release-impact-plain-' . bin2hex(random_bytes(4));
        mkdir($notGit);
        try {
            [$code, , $err] = $this->cli('compare', 'a', 'b', '--repo', $notGit);
            $this->assertSame(3, $code);
            $this->assertStringContainsStringIgnoringCase('not a git repository (or any', $err, "Git's own reason is shown");
            $this->assertSame(3, $this->cli('compare', 'a', 'b', '--repo', $notGit . '/missing')[0]);
        } finally {
            rmdir($notGit);
        }

        $this->assertSame(2, $this->cli('compare', 'base')[0]);
        $this->assertSame(2, $this->cli('compare', 'base', 'base', '--format=xml')[0]);
        $this->assertSame(2, $this->cli('compare', 'base', 'base', '--bogus')[0]);
        $this->assertSame(2, $this->cli('compare', 'base', 'base', '--repo')[0]);
        $this->assertSame(0, $this->cli('--help')[0]);
    }

    /** @param array<string, ?string> $files null deletes the file */
    private function commit(string $tag, array $files): void
    {
        foreach ($files as $path => $contents) {
            $full = $this->repo . '/' . $path;

            if ($contents === null) {
                $this->git('rm', '-q', '--', $path);
                continue;
            }

            @mkdir(dirname($full), recursive: true);
            file_put_contents($full, $contents);
            $this->git('add', '-f', '--', $path);
        }

        $this->git('commit', '-q', '--allow-empty', '-m', $tag);
        $this->git('tag', $tag);
    }

    private function lock(array $runtime, array $dev): string
    {
        $entries = fn (array $packages) => array_map(fn ($name, $version) => ['name' => $name, 'version' => $version], array_keys($packages), $packages);

        return json_encode(['packages' => $entries($runtime), 'packages-dev' => $entries($dev)], JSON_PRETTY_PRINT);
    }

    private function git(string ...$args): void
    {
        [$code, , $err] = $this->exec(['git', '-C', $this->repo, ...$args]);
        $this->assertSame(0, $code, $err);
    }

    /** @return array{int, string, string} */
    private function cli(string ...$args): array
    {
        $hasRepo = in_array('--repo', $args, true);

        return $this->exec([PHP_BINARY, dirname(__DIR__) . '/bin/release-impact', ...$args, ...($hasRepo ? [] : ['--repo=' . $this->repo])]);
    }

    private function exec(array $command): array
    {
        $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        $out = stream_get_contents($pipes[1]);
        $err = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return [proc_close($process), $out, $err];
    }
}
