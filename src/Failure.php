<?php

declare(strict_types=1);

namespace ReleaseImpact;

/** An operational failure; the code is the process exit code. */
final class Failure extends \RuntimeException
{
    public const USAGE = 2;
    public const TARGET = 3;
    public const GIT = 4;
}
