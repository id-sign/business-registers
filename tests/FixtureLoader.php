<?php

declare(strict_types=1);

namespace IdSign\BusinessRegisters\Tests;

final class FixtureLoader
{
    private function __construct()
    {
    }

    /**
     * Returns the contents of a file below tests/Fixtures, e.g. read('Ares/find-cez.json').
     *
     * @throws \RuntimeException when the fixture does not exist or cannot be read
     */
    public static function read(string $relative): string
    {
        $path = __DIR__.'/Fixtures/'.$relative;

        if (!is_file($path)) {
            throw new \RuntimeException(\sprintf('Fixture "%s" does not exist.', $relative));
        }

        $content = file_get_contents($path);

        if (false === $content) {
            throw new \RuntimeException(\sprintf('Fixture "%s" cannot be read.', $relative));
        }

        return $content;
    }
}
