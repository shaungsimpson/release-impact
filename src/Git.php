<?php

declare(strict_types=1);

namespace ReleaseImpact;

/**
 * Read-only access to committed Git objects. Never touches the working tree or index.
 */
final class Git
{
    public function __construct(private readonly string $repo)
    {
    }

    /** @throws Failure when the path is not a Git repository */
    public function assertRepository(): void
    {
        if (!is_dir($this->repo)) {
            throw new Failure("Repository path does not exist: {$this->repo}", Failure::TARGET);
        }

        [$code, , $err] = $this->run(['rev-parse', '--git-dir']);

        // Git's reason matters: "dubious ownership" needs safe.directory, not a different path.
        if ($code !== 0) {
            throw new Failure("Not a readable Git repository: {$this->repo}\n" . trim($err), Failure::TARGET);
        }
    }

    /** @throws Failure when the revision does not resolve to a commit */
    public function resolve(string $revision): string
    {
        [$code, $out] = $this->run(['rev-parse', '--verify', '--quiet', '--end-of-options', $revision . '^{commit}']);

        if ($code !== 0 || trim($out) === '') {
            throw new Failure("Revision does not resolve to a commit: {$revision}", Failure::TARGET);
        }

        return trim($out);
    }

    /**
     * @return list<array{status: string, path: string, from: ?string, similarity: ?int}>
     */
    public function diff(string $base, string $head): array
    {
        $parts = explode("\0", rtrim($this->mustRun(['diff', '--name-status', '-z', '-M', '--no-ext-diff', '--no-textconv', $base, $head, '--']), "\0"));
        $changes = [];

        for ($i = 0; $i < count($parts) && $parts[$i] !== ''; $i++) {
            $status = $parts[$i];

            if ($status[0] === 'R') {
                $changes[] = ['status' => $status[0], 'path' => $parts[$i + 2], 'from' => $parts[$i + 1], 'similarity' => (int) substr($status, 1)];
                $i += 2;
            } else {
                $changes[] = ['status' => $status[0], 'path' => $parts[$i + 1], 'from' => null, 'similarity' => null];
                $i++;
            }
        }

        return $changes;
    }

    /** @return list<string> */
    public function files(string $commit, string $directory): array
    {
        $out = $this->mustRun(['ls-tree', '-r', '-z', '--name-only', $commit, '--', $directory]);

        return $out === '' ? [] : explode("\0", rtrim($out, "\0"));
    }

    public function blob(string $commit, string $path): ?string
    {
        [$code, $out, $err] = $this->run(['cat-file', 'blob', $commit . ':' . $path]);

        if ($code === 0) {
            return $out;
        }

        // Only a path that is not a file in the commit counts as missing; anything else is a Git failure.
        if (preg_match('/^\d+ blob /', $this->mustRun(['ls-tree', '-z', $commit, '--', $path]))) {
            throw new Failure("Git could not read {$path} at " . substr($commit, 0, 12) . ': ' . trim($err), Failure::GIT);
        }

        return null;
    }

    private function mustRun(array $args): string
    {
        [$code, $out, $err] = $this->run($args);

        if ($code !== 0) {
            throw new Failure('Git command failed (git ' . $args[0] . '): ' . trim($err), Failure::GIT);
        }

        return $out;
    }

    /** @return array{int, string, string} */
    private function run(array $args): array
    {
        // Ignore inherited repository overrides so --repo stays authoritative.
        $env = array_diff_key(getenv(), array_flip(['GIT_DIR', 'GIT_WORK_TREE', 'GIT_INDEX_FILE', 'GIT_OBJECT_DIRECTORY', 'GIT_NAMESPACE']));
        $env['GIT_TERMINAL_PROMPT'] = '0';
        $env['GIT_OPTIONAL_LOCKS'] = '0';
        $env['GIT_LITERAL_PATHSPECS'] = '1';

        $command = ['git', '-C', $this->repo, ...$args];
        $process = @proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, $env);

        if (!is_resource($process)) {
            throw new Failure('Unable to run git; is it installed and on PATH?', Failure::GIT);
        }

        // shortcut: reads stdout before stderr, which would block if git wrote >64 KB to stderr; use stream_select if that ever happens.
        $out = stream_get_contents($pipes[1]);
        $err = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $code = proc_close($process);

        if ($code === 127) {
            throw new Failure('Unable to run git; is it installed and on PATH?', Failure::GIT);
        }

        return [$code, (string) $out, (string) $err];
    }
}
