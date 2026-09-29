<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Filesystem;

final class PhpDirectoryReader
{
    public function read(string $directory): ?DirectoryListing
    {
        if (!is_dir($directory)) {
            return null;
        }

        $entries = scandir($directory);
        if ($entries === false) {
            // @codeCoverageIgnoreStart
            return null;
            // @codeCoverageIgnoreEnd
        }

        $files = [];
        $directories = [];
        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $directory . '/' . $entry;
            if (is_dir($path)) {
                $directories[] = $path;
            } elseif (str_ends_with($entry, '.php') && is_file($path)) {
                $files[] = $path;
            }
        }

        return new DirectoryListing($directory, $files, $directories);
    }

    /**
     * @return iterable<DirectoryListing> The directory, then every directory beneath it
     */
    public function walk(string $directory): iterable
    {
        $listing = $this->read($directory);
        if ($listing === null) {
            return;
        }

        yield $listing;
        foreach ($listing->directories as $child) {
            yield from $this->walk($child);
        }
    }
}
