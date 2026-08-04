# Generated Shadcn fixture prerequisite

`GeneratedShadcnApplicationTest` never downloads or discovers a Tailwind
binary. Before running it (or the full PHPUnit suite), provision the official
Tailwind CSS 4.3.3 standalone binary for the runner and export its explicit
path:

```shell
export TE4G_TAILWIND_BINARY=/absolute/path/to/tailwindcss
php vendor/bin/phpunit tests/Integration/GeneratedShadcnApplicationTest.php
```

The file must be a regular executable and not a symlink. The fixture verifies
its output reports exactly version 4.3.3 and checks its SHA-256 against the
official release digest before executing it. Pinned release assets currently
cover macOS arm64/x86_64, Linux arm64/x86_64, and Windows x86_64. A later CI
job can download the matching asset into its runner cache, mark it executable,
and set `TE4G_TAILWIND_BINARY`; PHPUnit itself remains network-independent.

Official release and checksum source:
<https://github.com/tailwindlabs/tailwindcss/releases/tag/v4.3.3>
