<?php

declare(strict_types=1);

namespace Te4g\UserBundle\Installer;

use Symfony\Component\Filesystem\Path;
use Symfony\Component\Yaml\Yaml;
use Te4g\UserBundle\Exception\InstallerConflict;

final class RouteConflictScanner
{
    private const int MAX_FILES = 4096;
    private const int MAX_FILE_BYTES = 2 * 1024 * 1024;
    private const int MAX_TOTAL_BYTES = 64 * 1024 * 1024;
    private const int MAX_IMPORT_DEPTH = 32;

    private ProjectLayout $layout;

    /** @var array<string, true> */
    private array $protectedNames = [];

    /** @var array<string, InstallerConflict> */
    private array $conflicts = [];

    /** @var array<string, true> */
    private array $visitedConfigurations = [];

    /** @var array<string, true> */
    private array $visitedControllers = [];

    /** @var array<string, true> */
    private array $importedControllers = [];

    private int $files = 0;
    private int $bytes = 0;

    /**
     * @param list<string> $protectedNames
     *
     * @return list<InstallerConflict>
     */
    public function scan(ProjectLayout $layout, array $protectedNames): array
    {
        $this->layout = $layout;
        $this->protectedNames = array_fill_keys($protectedNames, true);
        $this->conflicts = [];
        $this->visitedConfigurations = [];
        $this->visitedControllers = [];
        $this->importedControllers = [];
        $this->files = 0;
        $this->bytes = 0;

        $configurationFiles = [];
        foreach (['config/routes.yaml', 'config/routes.yml', 'config/routes.php'] as $relativePath) {
            $path = $this->safePath($relativePath);
            if (null === $path) {
                continue;
            }
            if (file_exists($path) || is_link($path)) {
                $configurationFiles[] = $relativePath;
            }
        }
        $routesDirectory = $this->safePath('config/routes');
        if (null !== $routesDirectory && is_dir($routesDirectory) && !is_link($routesDirectory)) {
            $configurationFiles = [
                ...$configurationFiles,
                ...$this->filesInDirectory($routesDirectory, ['yaml', 'yml', 'php'], 'config/routes'),
            ];
        }
        $configurationFiles = array_values(array_unique($configurationFiles));
        sort($configurationFiles, SORT_STRING);
        foreach ($configurationFiles as $relativePath) {
            if ('config/routes/te4g_user.yaml' === $relativePath) {
                continue;
            }
            $this->scanConfiguration($relativePath, '', [], 0);
        }

        $controllerDirectory = $this->safePath('src/Controller');
        if (null !== $controllerDirectory && is_dir($controllerDirectory) && !is_link($controllerDirectory)) {
            $this->scanControllerDirectory($controllerDirectory, '', false);
        }

        $conflicts = array_values($this->conflicts);
        usort($conflicts, static fn (InstallerConflict $left, InstallerConflict $right): int => [
            $left->relativePath() ?? '',
            $left->code(),
        ] <=> [
            $right->relativePath() ?? '',
            $right->code(),
        ]);

        return $conflicts;
    }

    /**
     * @param list<string> $stack
     */
    private function scanConfiguration(string $relativePath, string $namePrefix, array $stack, int $depth): void
    {
        if ($depth > self::MAX_IMPORT_DEPTH) {
            $this->add($relativePath, 'route-scan-limit-exceeded');

            return;
        }
        if (in_array($relativePath, $stack, true)) {
            $this->add($relativePath, 'uninspectable-route-resource');

            return;
        }
        $visitKey = $relativePath."\0".$namePrefix;
        if (isset($this->visitedConfigurations[$visitKey])) {
            return;
        }
        $this->visitedConfigurations[$visitKey] = true;

        try {
            $contents = $this->read($relativePath);
        } catch (InstallerConflict $conflict) {
            $this->add($relativePath, 'route-scan-limit-exceeded' === $conflict->code()
                ? 'route-scan-limit-exceeded'
                : 'uninspectable-route-resource');

            return;
        } catch (\Throwable) {
            $this->add($relativePath, 'invalid-route-configuration');

            return;
        }
        $stack[] = $relativePath;
        if (preg_match('/\.ya?ml$/i', $relativePath)) {
            try {
                $routes = Yaml::parse($contents);
            } catch (\Throwable) {
                $this->add($relativePath, 'invalid-route-configuration');

                return;
            }
            if (null === $routes) {
                return;
            }
            if (!is_array($routes)) {
                $this->add($relativePath, 'invalid-route-configuration');

                return;
            }
            $this->scanYamlEntries($routes, $relativePath, $namePrefix, $stack, $depth);

            return;
        }
        if (str_ends_with(strtolower($relativePath), '.php')) {
            $this->scanPhpConfiguration($contents, $relativePath, $namePrefix, $stack, $depth);

            return;
        }

        $this->add($relativePath, 'uninspectable-route-configuration');
    }

    /**
     * @param array<int|string, mixed> $routes
     * @param list<string>             $stack
     */
    private function scanYamlEntries(
        array $routes,
        string $relativePath,
        string $namePrefix,
        array $stack,
        int $depth,
    ): void {
        foreach ($routes as $name => $definition) {
            if (!is_string($name)) {
                $this->add($relativePath, 'invalid-route-configuration');

                continue;
            }
            if (str_starts_with($name, 'when@')) {
                if (!is_array($definition)) {
                    $this->add($relativePath, 'invalid-route-configuration');

                    continue;
                }
                $this->scanYamlEntries($definition, $relativePath, $namePrefix, $stack, $depth);

                continue;
            }
            if (is_array($definition) && array_key_exists('resource', $definition)) {
                $resource = $definition['resource'];
                $prefix = $definition['name_prefix'] ?? '';
                $type = $definition['type'] ?? null;
                if (!is_string($resource)
                    || !is_string($prefix)
                    || (null !== $type && !is_string($type))
                    || (array_key_exists('exclude', $definition) && null !== $definition['exclude'])
                ) {
                    $this->add($relativePath, 'uninspectable-route-resource');

                    continue;
                }
                $this->scanResource($resource, $type, $relativePath, $namePrefix.$prefix, $stack, $depth + 1);

                continue;
            }

            $this->recordRouteName($namePrefix.$name, $relativePath);
            if (!is_array($definition)) {
                $this->add($relativePath, 'invalid-route-configuration');
            }
        }
    }

    /** @param list<string> $stack */
    private function scanResource(
        string $resource,
        ?string $type,
        string $sourceRelativePath,
        string $namePrefix,
        array $stack,
        int $depth,
    ): void {
        if (str_starts_with($resource, '@')) {
            return;
        }
        if ('routing.controllers' === $resource) {
            $controllerDirectory = $this->safePath('src/Controller', $sourceRelativePath);
            if (null === $controllerDirectory || !is_dir($controllerDirectory) || is_link($controllerDirectory)) {
                $this->add($sourceRelativePath, 'uninspectable-route-resource');

                return;
            }
            $this->scanControllerDirectory($controllerDirectory, $namePrefix, true);

            return;
        }
        $resource = str_replace('%kernel.project_dir%', $this->layout->projectDir(), $resource);
        if (str_contains($resource, '%') || 1 === preg_match('/[*?\[\]{}]/', $resource)) {
            $this->add($sourceRelativePath, 'uninspectable-route-resource');

            return;
        }

        try {
            $source = $this->layout->path($sourceRelativePath);
            $candidate = Path::canonicalize(Path::makeAbsolute($resource, dirname($source)));
            if (!Path::isBasePath($this->layout->projectDir(), $candidate)) {
                throw InstallerConflict::unsafePath();
            }
            $relativePath = ltrim(substr($candidate, strlen($this->layout->projectDir())), '/\\');
            if ('' === $relativePath) {
                throw InstallerConflict::unsafePath();
            }
            $path = $this->layout->path(str_replace('\\', '/', $relativePath));
        } catch (\Throwable) {
            $this->add($sourceRelativePath, 'uninspectable-route-resource');

            return;
        }

        if (is_link($path) || (!is_file($path) && !is_dir($path))) {
            $this->add($sourceRelativePath, 'uninspectable-route-resource');

            return;
        }
        $relativePath = str_replace('\\', '/', $relativePath);
        if (is_dir($path)) {
            if (in_array(strtolower((string) $type), ['attribute', 'annotation'], true)) {
                $this->scanControllerDirectory($path, $namePrefix, true);

                return;
            }
            if (null !== $type && '' !== $type) {
                $this->add($sourceRelativePath, 'uninspectable-route-resource');

                return;
            }
            foreach ($this->filesInDirectory($path, ['yaml', 'yml', 'php'], $relativePath) as $child) {
                if (str_ends_with(strtolower($child), '.php') && !str_starts_with($child, 'config/')) {
                    $this->scanController($child, $namePrefix, true);
                } else {
                    $this->scanConfiguration($child, $namePrefix, $stack, $depth);
                }
            }

            return;
        }
        if (in_array(strtolower((string) $type), ['attribute', 'annotation'], true)) {
            if (!str_ends_with(strtolower($relativePath), '.php')) {
                $this->add($sourceRelativePath, 'uninspectable-route-resource');

                return;
            }
            $this->scanController($relativePath, $namePrefix, true);

            return;
        }
        if (null === $type
            && str_ends_with(strtolower($relativePath), '.php')
            && !str_starts_with($relativePath, 'config/')
        ) {
            $this->scanController($relativePath, $namePrefix, true);

            return;
        }
        if (!preg_match('/\.(?:ya?ml|php)$/i', $relativePath)) {
            $this->add($sourceRelativePath, 'uninspectable-route-resource');

            return;
        }
        $this->scanConfiguration($relativePath, $namePrefix, $stack, $depth);
    }

    /** @param list<string> $stack */
    private function scanPhpConfiguration(
        string $contents,
        string $relativePath,
        string $namePrefix,
        array $stack,
        int $depth,
    ): void {
        try {
            $tokens = array_values(\PhpToken::tokenize($contents, TOKEN_PARSE));
        } catch (\Throwable) {
            $this->add($relativePath, 'invalid-route-configuration');

            return;
        }

        $imports = $this->routeAttributeImports($contents);
        $routingVariables = $this->routingConfiguratorVariables($tokens, $imports);
        $count = count($tokens);
        $curlyDepth = 0;
        for ($index = 0; $index < $count; ++$index) {
            $token = $tokens[$index];
            if ('{' === $token->text) {
                ++$curlyDepth;

                continue;
            }
            if ('}' === $token->text) {
                $curlyDepth = max(0, $curlyDepth - 1);

                continue;
            }
            if (T_RETURN === $token->id && 0 === $curlyDepth) {
                $expression = $this->statementExpression($tokens, $index + 1);
                $significant = null === $expression ? [] : $this->significantTokens($expression);
                if ([] === $significant) {
                    $this->add($relativePath, 'uninspectable-route-configuration');
                } elseif ($this->startsClosure($significant)) {
                    // Configurator closures are scanned below without executing them.
                } elseif (null !== ($configuration = $this->literalArray($significant))) {
                    $this->scanYamlEntries($configuration, $relativePath, $namePrefix, $stack, $depth);
                } elseif (!$this->startsRoutesConfigCall($significant, $imports)) {
                    $this->add($relativePath, 'uninspectable-route-configuration');
                }
            }
            if (in_array($token->id, [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED], true)
                && $this->isRoutesHelper($token->text, $imports)
            ) {
                $doubleColon = $this->nextSignificant($tokens, $index + 1);
                $configMethod = null === $doubleColon ? null : $this->nextSignificant($tokens, $doubleColon + 1);
                $open = null === $configMethod ? null : $this->nextSignificant($tokens, $configMethod + 1);
                if (null !== $doubleColon
                    && T_DOUBLE_COLON === $tokens[$doubleColon]->id
                    && null !== $configMethod
                    && 'config' === strtolower($tokens[$configMethod]->text)
                    && null !== $open
                    && '(' === $tokens[$open]->text
                ) {
                    $call = $this->callArguments($tokens, $open);
                    $configuration = null === $call
                        ? null
                        : $this->literalArray($this->callArgument($call['arguments'], 'config', 0) ?? []);
                    if (null === $configuration) {
                        $this->add($relativePath, 'uninspectable-route-configuration');
                    } else {
                        $this->scanYamlEntries($configuration, $relativePath, $namePrefix, $stack, $depth);
                    }
                    $index = null === $call ? $index : $call['close'];

                    continue;
                }
            }
            if (T_VARIABLE === $token->id && isset($routingVariables[$token->text])) {
                $next = $this->nextSignificant($tokens, $index + 1);
                if (null !== $next && '(' === $tokens[$next]->text) {
                    $this->add($relativePath, 'uninspectable-route-configuration');
                }
            }
            if (T_OBJECT_OPERATOR !== $token->id && '?->' !== $token->text) {
                continue;
            }
            if (!$this->receiverIsRoutingConfigurator($tokens, $index, $routingVariables)) {
                continue;
            }
            $methodIndex = $this->nextSignificant($tokens, $index + 1);
            if (null === $methodIndex || T_STRING !== $tokens[$methodIndex]->id) {
                continue;
            }
            $method = strtolower($tokens[$methodIndex]->text);
            if (!in_array($method, ['add', 'alias', 'collection', 'import'], true)) {
                continue;
            }
            $open = $this->nextSignificant($tokens, $methodIndex + 1);
            if (null === $open || '(' !== $tokens[$open]->text) {
                $this->add($relativePath, 'uninspectable-route-configuration');

                continue;
            }
            $call = $this->callArguments($tokens, $open);
            if (null === $call || [] === $call['arguments']) {
                $this->add($relativePath, 'uninspectable-route-configuration');

                continue;
            }
            $argumentName = 'import' === $method ? 'resource' : 'name';
            $first = $this->literalArgument($this->callArgument($call['arguments'], $argumentName, 0) ?? []);
            if (null === $first) {
                $this->add($relativePath, 'uninspectable-route-configuration');

                continue;
            }
            if (in_array($method, ['add', 'alias'], true)) {
                if ($this->receiverChainContains($tokens, $index, 'collection')) {
                    continue;
                }
                $this->recordRouteName($namePrefix.$first, $relativePath);

                continue;
            }
            if ('collection' === $method) {
                $this->add($relativePath, 'uninspectable-route-configuration');

                continue;
            }

            $importPrefix = '';
            $typeArgument = $this->callArgument($call['arguments'], 'type', 1);
            $type = null;
            if (null !== $typeArgument && !$this->isNullLiteral($typeArgument)) {
                $type = $this->literalArgument($typeArgument);
                if (null === $type) {
                    $this->add($relativePath, 'uninspectable-route-configuration');

                    continue;
                }
            }
            $excludeArgument = $this->callArgument($call['arguments'], 'exclude', 3);
            if (null !== $excludeArgument && !$this->isNullLiteral($excludeArgument)) {
                $this->add($relativePath, 'uninspectable-route-configuration');

                continue;
            }
            for ($cursor = $call['close'] + 1; $cursor < $count && ';' !== $tokens[$cursor]->text; ++$cursor) {
                if (T_OBJECT_OPERATOR !== $tokens[$cursor]->id) {
                    continue;
                }
                $prefixMethod = $this->nextSignificant($tokens, $cursor + 1);
                if (null === $prefixMethod || 'nameprefix' !== strtolower($tokens[$prefixMethod]->text)) {
                    continue;
                }
                $prefixOpen = $this->nextSignificant($tokens, $prefixMethod + 1);
                $prefixCall = null === $prefixOpen ? null : $this->callArguments($tokens, $prefixOpen);
                $prefix = null === $prefixCall || [] === $prefixCall['arguments']
                    ? null
                    : $this->literalArgument($this->callArgument($prefixCall['arguments'], 'namePrefix', 0) ?? []);
                if (null === $prefix) {
                    $this->add($relativePath, 'uninspectable-route-configuration');

                    continue 2;
                }
                $importPrefix = $prefix;
                break;
            }
            $this->scanResource($first, $type, $relativePath, $namePrefix.$importPrefix, $stack, $depth + 1);
        }
    }

    /**
     * @param list<\PhpToken> $tokens
     *
     * @return list<\PhpToken>|null
     */
    private function statementExpression(array $tokens, int $offset): ?array
    {
        $expression = [];
        $round = 0;
        $square = 0;
        $curly = 0;
        for ($index = $offset, $count = count($tokens); $index < $count; ++$index) {
            $text = $tokens[$index]->text;
            if (';' === $text && 0 === $round && 0 === $square && 0 === $curly) {
                return $expression;
            }
            if ('(' === $text) {
                ++$round;
            } elseif (')' === $text) {
                --$round;
            } elseif ('[' === $text) {
                ++$square;
            } elseif (']' === $text) {
                --$square;
            } elseif ('{' === $text) {
                ++$curly;
            } elseif ('}' === $text) {
                --$curly;
            }
            $expression[] = $tokens[$index];
        }

        return null;
    }

    /** @param list<\PhpToken> $tokens */
    private function startsClosure(array $tokens): bool
    {
        return in_array($tokens[0]->id, [T_FUNCTION, T_FN], true)
            || (T_STATIC === $tokens[0]->id
                && isset($tokens[1])
                && in_array($tokens[1]->id, [T_FUNCTION, T_FN], true));
    }

    /**
     * @param list<\PhpToken>       $tokens
     * @param array<string, string> $imports
     */
    private function startsRoutesConfigCall(array $tokens, array $imports): bool
    {
        if (!in_array($tokens[0]->id, [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED], true)
            || !$this->isRoutesHelper($tokens[0]->text, $imports)
        ) {
            return false;
        }
        $doubleColon = $this->nextSignificant($tokens, 1);
        $method = null === $doubleColon ? null : $this->nextSignificant($tokens, $doubleColon + 1);

        return null !== $doubleColon
            && T_DOUBLE_COLON === $tokens[$doubleColon]->id
            && null !== $method
            && 'config' === strtolower($tokens[$method]->text);
    }

    /**
     * @param list<\PhpToken>       $tokens
     * @param array<string, string> $imports
     *
     * @return array<string, true>
     */
    private function routingConfiguratorVariables(array $tokens, array $imports): array
    {
        $variables = ['$routes' => true];
        foreach ($tokens as $index => $token) {
            if (T_RETURN === $token->id) {
                $function = $this->nextSignificant($tokens, $index + 1);
                if (null !== $function && T_STATIC === $tokens[$function]->id) {
                    $function = $this->nextSignificant($tokens, $function + 1);
                }
                if (null !== $function && in_array($tokens[$function]->id, [T_FUNCTION, T_FN], true)) {
                    $open = $this->nextSignificant($tokens, $function + 1);
                    $call = null === $open ? null : $this->callArguments($tokens, $open);
                    foreach ($call['arguments'][0] ?? [] as $parameterToken) {
                        if (T_VARIABLE === $parameterToken->id) {
                            $variables[$parameterToken->text] = true;

                            break;
                        }
                    }
                }
            }
            if (T_VARIABLE !== $token->id) {
                continue;
            }
            $typeIndex = $this->previousSignificant($tokens, $index - 1);
            if (null === $typeIndex
                || !in_array($tokens[$typeIndex]->id, [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED], true)
            ) {
                continue;
            }
            $type = strtolower($this->resolveImportedName($tokens[$typeIndex]->text, $imports));
            if (in_array($type, [
                'symfony\\component\\routing\\loader\\configurator\\routingconfigurator',
                'symfony\\component\\routing\\loader\\configurator\\collectionconfigurator',
            ], true)) {
                $variables[$token->text] = true;
            }
        }

        return $variables;
    }

    /**
     * @param list<\PhpToken>     $tokens
     * @param array<string, true> $routingVariables
     */
    private function receiverIsRoutingConfigurator(array $tokens, int $operator, array $routingVariables): bool
    {
        for ($index = $operator - 1; $index >= 0; --$index) {
            if (T_VARIABLE === $tokens[$index]->id) {
                return isset($routingVariables[$tokens[$index]->text]);
            }
            if (in_array($tokens[$index]->text, [';', '{', '}'], true)) {
                return false;
            }
        }

        return false;
    }

    /** @param list<\PhpToken> $tokens */
    private function receiverChainContains(array $tokens, int $operator, string $method): bool
    {
        for ($index = $operator - 1; $index >= 0; --$index) {
            if (in_array($tokens[$index]->text, [';', '{', '}'], true)) {
                return false;
            }
            if (T_STRING !== $tokens[$index]->id || strtolower($method) !== strtolower($tokens[$index]->text)) {
                continue;
            }
            $previous = $this->previousSignificant($tokens, $index - 1);
            if (null !== $previous && T_OBJECT_OPERATOR === $tokens[$previous]->id) {
                return true;
            }
        }

        return false;
    }

    /** @param list<\PhpToken> $tokens */
    private function isNullLiteral(array $tokens): bool
    {
        $tokens = $this->significantTokens($tokens);

        return 1 === count($tokens) && T_STRING === $tokens[0]->id && 'null' === strtolower($tokens[0]->text);
    }

    /** @param array<string, string> $imports */
    private function isRoutesHelper(string $name, array $imports): bool
    {
        $resolved = strtolower($this->resolveImportedName($name, $imports));

        return in_array($resolved, [
            'routes',
            'routesreference',
            'symfony\\component\\routing\\loader\\configurator\\routes',
            'symfony\\component\\routing\\loader\\configurator\\routesreference',
        ], true);
    }

    /** @param array<string, string> $imports */
    private function resolveImportedName(string $name, array $imports): string
    {
        $name = ltrim($name, '\\');
        $parts = explode('\\', $name, 2);
        foreach ($imports as $alias => $import) {
            if (0 !== strcasecmp($parts[0], $alias)) {
                continue;
            }

            return $import.(isset($parts[1]) ? '\\'.$parts[1] : '');
        }

        return $name;
    }

    private function scanControllerDirectory(string $directory, string $namePrefix, bool $imported): void
    {
        $relativeDirectory = ltrim(substr($directory, strlen($this->layout->projectDir())), '/\\');
        foreach ($this->filesInDirectory($directory, ['php'], str_replace('\\', '/', $relativeDirectory)) as $relativePath) {
            $this->scanController($relativePath, $namePrefix, $imported);
        }
    }

    private function scanController(string $relativePath, string $namePrefix, bool $imported): void
    {
        if ($imported) {
            $this->importedControllers[$relativePath] = true;
        } elseif (isset($this->importedControllers[$relativePath])) {
            return;
        }
        $visitKey = $relativePath."\0".$namePrefix;
        if (isset($this->visitedControllers[$visitKey])) {
            return;
        }
        $this->visitedControllers[$visitKey] = true;
        try {
            $contents = $this->read($relativePath);
            $tokens = array_values(\PhpToken::tokenize($contents, TOKEN_PARSE));
        } catch (InstallerConflict $conflict) {
            $this->add($relativePath, 'route-scan-limit-exceeded' === $conflict->code()
                ? 'route-scan-limit-exceeded'
                : 'uninspectable-route-resource');

            return;
        } catch (\Throwable) {
            $this->add($relativePath, 'invalid-route-configuration');

            return;
        }

        $imports = $this->routeAttributeImports($contents);
        $classPrefixes = [];
        $methodNames = [];
        $classAliases = [];
        $methodAliases = [];
        $methodRouteAttributes = 0;
        $ambiguous = false;
        for ($index = 0, $count = count($tokens); $index < $count; ++$index) {
            if (T_ATTRIBUTE !== $tokens[$index]->id) {
                continue;
            }
            $attributeClose = $this->attributeClose($tokens, $index);
            if (null === $attributeClose) {
                $ambiguous = true;

                break;
            }
            $declaration = $this->nextDeclaration($tokens, $attributeClose + 1);
            $attributes = $this->splitTopLevel(array_slice($tokens, $index + 1, $attributeClose - $index - 1));
            foreach ($attributes as $attribute) {
                $attribute = $this->significantTokens($attribute);
                if ([] === $attribute
                    || !in_array($attribute[0]->id, [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED], true)
                ) {
                    continue;
                }
                $routeAttribute = $this->isRouteAttribute($attribute[0]->text, $imports);
                if (false === $routeAttribute) {
                    continue;
                }
                if (null === $routeAttribute || null === $declaration) {
                    $ambiguous = true;

                    continue;
                }
                $arguments = [];
                if (isset($attribute[1])) {
                    if ('(' !== $attribute[1]->text) {
                        $ambiguous = true;

                        continue;
                    }
                    $call = $this->callArguments($attribute, 1);
                    if (null === $call || $call['close'] !== array_key_last($attribute)) {
                        $ambiguous = true;

                        continue;
                    }
                    $arguments = $call['arguments'];
                }
                [$routeName, $nameAmbiguous] = $this->routeName($arguments);
                [$aliases, $aliasesAmbiguous] = $this->routeAliases($arguments);
                $ambiguous = $ambiguous || $nameAmbiguous || $aliasesAmbiguous;
                if ('class' === $declaration) {
                    if (null !== $routeName) {
                        $classPrefixes[] = $routeName;
                    }
                    $classAliases = [...$classAliases, ...$aliases];
                } else {
                    ++$methodRouteAttributes;
                    if (null !== $routeName) {
                        $methodNames[] = $routeName;
                    }
                    $methodAliases = [...$methodAliases, ...$aliases];
                }
            }
            $index = $attributeClose;
        }

        $classPrefixes = array_values(array_unique($classPrefixes));
        if (count($classPrefixes) > 1) {
            $ambiguous = true;
        }
        $classPrefix = $classPrefixes[0] ?? '';
        $hasInvoke = $this->hasInvokeMethod($tokens);
        if (0 === $methodRouteAttributes && $hasInvoke && '' !== $classPrefix) {
            $this->recordRouteName($namePrefix.$classPrefix, $relativePath);
        }
        foreach ($methodNames as $methodName) {
            $this->recordRouteName($namePrefix.$classPrefix.$methodName, $relativePath);
        }
        foreach ($methodAliases as $alias) {
            $this->recordRouteName($namePrefix.$alias, $relativePath);
        }
        if ($hasInvoke) {
            foreach ($classAliases as $alias) {
                $this->recordRouteName($namePrefix.$alias, $relativePath);
            }
        }
        if ($ambiguous) {
            $this->add($relativePath, 'uninspectable-route-configuration');
        }
    }

    /** @param list<\PhpToken> $tokens */
    private function hasInvokeMethod(array $tokens): bool
    {
        foreach ($tokens as $index => $token) {
            if (T_FUNCTION !== $token->id) {
                continue;
            }
            $name = $this->nextSignificant($tokens, $index + 1);
            if (null !== $name && T_STRING === $tokens[$name]->id && '__invoke' === strtolower($tokens[$name]->text)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param list<list<\PhpToken>> $arguments
     *
     * @return array{list<string>, bool}
     */
    private function routeAliases(array $arguments): array
    {
        $argument = $this->callArgument($arguments, 'alias', 15);
        if (null === $argument) {
            return [[], false];
        }
        $literal = $this->literalString($argument);
        if (null !== $literal) {
            return [[$literal], false];
        }
        $argument = $this->significantTokens($argument);
        if ([] === $argument || '[' !== $argument[0]->text || ']' !== $argument[array_key_last($argument)]->text) {
            return [[], true];
        }
        $aliases = [];
        foreach ($this->splitTopLevel(array_slice($argument, 1, -1)) as $item) {
            $alias = $this->literalString($item);
            if (null === $alias) {
                return [[], true];
            }
            $aliases[] = $alias;
        }

        return [$aliases, false];
    }

    /** @return array<string, string> */
    private function routeAttributeImports(string $contents): array
    {
        $imports = [];
        if (!preg_match_all(
            '~^\s*use\s+(\\\\?[A-Za-z_][A-Za-z0-9_]*(?:\\\\[A-Za-z_][A-Za-z0-9_]*)*)(?:\s+as\s+([A-Za-z_][A-Za-z0-9_]*))?\s*;~mi',
            $contents,
            $matches,
            PREG_SET_ORDER,
        )) {
            return $imports;
        }
        foreach ($matches as $match) {
            $class = ltrim($match[1], '\\');
            $alias = $match[2] ?? substr($class, (int) strrpos('\\'.$class, '\\'));
            $imports[$alias] = $class;
        }

        return $imports;
    }

    /** @param array<string, string> $imports */
    private function isRouteAttribute(string $name, array $imports): ?bool
    {
        $name = $this->resolveImportedName($name, $imports);
        $target = 'Symfony\\Component\\Routing\\Attribute\\Route';
        if (0 === strcasecmp($target, $name)) {
            return true;
        }
        $shortName = substr(strrchr('\\'.$name, '\\') ?: '', 1);

        return 0 === strcasecmp('Route', $shortName) ? null : false;
    }

    /**
     * @param list<list<\PhpToken>> $arguments
     *
     * @return array{?string, bool}
     */
    private function routeName(array $arguments): array
    {
        $positional = [];
        foreach ($arguments as $argument) {
            $argument = $this->significantTokens($argument);
            if ([] === $argument) {
                continue;
            }
            if (count($argument) >= 2 && T_STRING === $argument[0]->id && ':' === $argument[1]->text) {
                if ('name' !== strtolower($argument[0]->text)) {
                    continue;
                }
                $value = $this->literalString(array_slice($argument, 2));

                return [$value, null === $value];
            }
            $positional[] = $argument;
        }
        if (!isset($positional[1])) {
            return [null, false];
        }
        $value = $this->literalString($positional[1]);

        return [$value, null === $value];
    }

    /**
     * @param list<list<\PhpToken>> $arguments
     *
     * @return list<\PhpToken>|null
     */
    private function callArgument(array $arguments, string $name, int $position): ?array
    {
        $positional = [];
        foreach ($arguments as $argument) {
            $significant = $this->significantTokens($argument);
            if (count($significant) >= 2 && T_STRING === $significant[0]->id && ':' === $significant[1]->text) {
                if (strtolower($name) === strtolower($significant[0]->text)) {
                    return array_slice($significant, 2);
                }

                continue;
            }
            $positional[] = $argument;
        }

        return $positional[$position] ?? null;
    }

    /**
     * @param list<\PhpToken> $tokens
     *
     * @return array<int|string, mixed>|null
     */
    private function literalArray(array $tokens): ?array
    {
        $tokens = $this->significantTokens($tokens);
        if ([] === $tokens) {
            return null;
        }
        if ('[' === $tokens[0]->text && ']' === $tokens[array_key_last($tokens)]->text) {
            $entries = $this->splitTopLevel(array_slice($tokens, 1, -1));
        } elseif (T_ARRAY === $tokens[0]->id
            && isset($tokens[1])
            && '(' === $tokens[1]->text
            && ')' === $tokens[array_key_last($tokens)]->text
        ) {
            $entries = $this->splitTopLevel(array_slice($tokens, 2, -1));
        } else {
            return null;
        }

        $result = [];
        foreach ($entries as $entry) {
            $entry = $this->significantTokens($entry);
            if ([] === $entry) {
                continue;
            }
            $arrow = $this->topLevelArrow($entry);
            if (null === $arrow) {
                return null;
            }
            $key = $this->literalString(array_slice($entry, 0, $arrow));
            if (null === $key) {
                return null;
            }
            $valueTokens = array_slice($entry, $arrow + 1);
            $value = $this->literalArray($valueTokens);
            if (null === $value) {
                $value = $this->literalString($valueTokens) ?? new \stdClass();
            }
            $result[$key] = $value;
        }

        return $result;
    }

    /**
     * @param list<\PhpToken> $tokens
     *
     * @return list<list<\PhpToken>>
     */
    private function splitTopLevel(array $tokens): array
    {
        $items = [];
        $current = [];
        $round = 0;
        $square = 0;
        $curly = 0;
        foreach ($tokens as $token) {
            if ('(' === $token->text) {
                ++$round;
            } elseif (')' === $token->text) {
                --$round;
            } elseif ('[' === $token->text) {
                ++$square;
            } elseif (']' === $token->text) {
                --$square;
            } elseif ('{' === $token->text) {
                ++$curly;
            } elseif ('}' === $token->text) {
                --$curly;
            }
            if (',' === $token->text && 0 === $round && 0 === $square && 0 === $curly) {
                $items[] = $current;
                $current = [];

                continue;
            }
            $current[] = $token;
        }
        if ([] !== $this->significantTokens($current)) {
            $items[] = $current;
        }

        return $items;
    }

    /** @param list<\PhpToken> $tokens */
    private function topLevelArrow(array $tokens): ?int
    {
        $round = 0;
        $square = 0;
        $curly = 0;
        foreach ($tokens as $index => $token) {
            if ('(' === $token->text) {
                ++$round;
            } elseif (')' === $token->text) {
                --$round;
            } elseif ('[' === $token->text) {
                ++$square;
            } elseif (']' === $token->text) {
                --$square;
            } elseif ('{' === $token->text) {
                ++$curly;
            } elseif ('}' === $token->text) {
                --$curly;
            } elseif (T_DOUBLE_ARROW === $token->id && 0 === $round && 0 === $square && 0 === $curly) {
                return $index;
            }
        }

        return null;
    }

    /** @param list<\PhpToken> $argument */
    private function literalArgument(array $argument): ?string
    {
        $argument = $this->significantTokens($argument);
        if (count($argument) >= 2 && T_STRING === $argument[0]->id && ':' === $argument[1]->text) {
            $argument = array_slice($argument, 2);
        }

        return $this->literalString($argument);
    }

    /** @param list<\PhpToken> $tokens */
    private function literalString(array $tokens): ?string
    {
        $tokens = $this->significantTokens($tokens);
        if (1 !== count($tokens) || T_CONSTANT_ENCAPSED_STRING !== $tokens[0]->id) {
            return null;
        }
        $literal = $tokens[0]->text;
        if (strlen($literal) < 2) {
            return null;
        }
        $quote = $literal[0];
        $value = substr($literal, 1, -1);
        if ("'" === $quote) {
            $value = str_replace(['\\\\', "\\'"], ['\\', "'"], $value);
        } else {
            if (1 === preg_match('/\\\\(?![\\\\"])/', $value)) {
                return null;
            }
            $value = strtr($value, ['\\\\' => '\\', '\\"' => '"']);
        }

        return str_contains($value, "\0") ? null : $value;
    }

    /**
     * @param list<\PhpToken> $tokens
     *
     * @return array{arguments: list<list<\PhpToken>>, close: int}|null
     */
    private function callArguments(array $tokens, int $open): ?array
    {
        if (!isset($tokens[$open]) || '(' !== $tokens[$open]->text) {
            return null;
        }
        $arguments = [];
        $current = [];
        $round = 1;
        $square = 0;
        $curly = 0;
        for ($index = $open + 1, $count = count($tokens); $index < $count; ++$index) {
            $text = $tokens[$index]->text;
            if ('(' === $text) {
                ++$round;
            } elseif (')' === $text) {
                --$round;
                if (0 === $round) {
                    if ([] !== $this->significantTokens($current)) {
                        $arguments[] = $current;
                    }

                    return ['arguments' => $arguments, 'close' => $index];
                }
            } elseif ('[' === $text) {
                ++$square;
            } elseif (']' === $text) {
                --$square;
            } elseif ('{' === $text) {
                ++$curly;
            } elseif ('}' === $text) {
                --$curly;
            }
            if (',' === $text && 1 === $round && 0 === $square && 0 === $curly) {
                $arguments[] = $current;
                $current = [];

                continue;
            }
            $current[] = $tokens[$index];
        }

        return null;
    }

    /** @param list<\PhpToken> $tokens */
    private function attributeClose(array $tokens, int $start): ?int
    {
        $depth = 1;
        for ($index = $start + 1, $count = count($tokens); $index < $count; ++$index) {
            if ('[' === $tokens[$index]->text) {
                ++$depth;
            } elseif (']' === $tokens[$index]->text && 0 === --$depth) {
                return $index;
            }
        }

        return null;
    }

    /** @param list<\PhpToken> $tokens */
    private function nextDeclaration(array $tokens, int $offset): ?string
    {
        for ($index = $offset, $count = count($tokens); $index < $count; ++$index) {
            if (T_ATTRIBUTE === $tokens[$index]->id) {
                $close = $this->attributeClose($tokens, $index);
                if (null === $close) {
                    return null;
                }
                $index = $close;

                continue;
            }
            if (T_CLASS === $tokens[$index]->id) {
                return 'class';
            }
            if (T_FUNCTION === $tokens[$index]->id) {
                return 'method';
            }
            if (';' === $tokens[$index]->text || T_VARIABLE === $tokens[$index]->id) {
                return null;
            }
        }

        return null;
    }

    /**
     * @param list<\PhpToken> $tokens
     *
     * @return list<\PhpToken>
     */
    private function significantTokens(array $tokens): array
    {
        return array_values(array_filter(
            $tokens,
            static fn (\PhpToken $token): bool => !in_array($token->id, [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true),
        ));
    }

    /** @param list<\PhpToken> $tokens */
    private function nextSignificant(array $tokens, int $offset): ?int
    {
        for ($index = $offset, $count = count($tokens); $index < $count; ++$index) {
            if (!in_array($tokens[$index]->id, [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                return $index;
            }
        }

        return null;
    }

    /** @param list<\PhpToken> $tokens */
    private function previousSignificant(array $tokens, int $offset): ?int
    {
        for ($index = $offset; $index >= 0; --$index) {
            if (!in_array($tokens[$index]->id, [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                return $index;
            }
        }

        return null;
    }

    /**
     * @param list<string> $extensions
     *
     * @return list<string>
     */
    private function filesInDirectory(string $directory, array $extensions, string $relativeDirectory): array
    {
        $files = [];
        try {
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS),
            );
            foreach ($iterator as $info) {
                if ($info->isLink()) {
                    $relativePath = $relativeDirectory.'/'.substr($info->getPathname(), strlen($directory) + 1);
                    $this->add(str_replace('\\', '/', $relativePath), 'uninspectable-route-resource');

                    continue;
                }
                if (!$info->isFile() || !in_array(strtolower($info->getExtension()), $extensions, true)) {
                    continue;
                }
                $relativePath = ltrim(substr($info->getPathname(), strlen($this->layout->projectDir())), '/\\');
                $files[] = str_replace('\\', '/', $relativePath);
            }
        } catch (\Throwable) {
            $this->add($relativeDirectory, 'uninspectable-route-resource');
        }
        sort($files, SORT_STRING);

        return $files;
    }

    private function read(string $relativePath): string
    {
        if (++$this->files > self::MAX_FILES) {
            throw InstallerConflict::forPath($relativePath, 'route-scan-limit-exceeded');
        }
        $path = $this->layout->path($relativePath);
        if (!is_file($path) || is_link($path)) {
            throw InstallerConflict::forPath($relativePath, 'uninspectable-route-resource');
        }
        $size = filesize($path);
        if (!is_int($size) || $size > self::MAX_FILE_BYTES || $this->bytes + $size > self::MAX_TOTAL_BYTES) {
            throw InstallerConflict::forPath($relativePath, 'route-scan-limit-exceeded');
        }
        $contents = file_get_contents($path);
        if (!is_string($contents)) {
            throw InstallerConflict::forPath($relativePath, 'invalid-route-configuration');
        }
        $this->bytes += $size;

        return $contents;
    }

    private function safePath(string $relativePath, ?string $conflictPath = null): ?string
    {
        try {
            return $this->layout->path($relativePath);
        } catch (InstallerConflict) {
            $this->add($conflictPath ?? $relativePath, 'uninspectable-route-resource');

            return null;
        }
    }

    private function recordRouteName(string $name, string $relativePath): void
    {
        if (!isset($this->protectedNames[$name])) {
            return;
        }
        $this->add($relativePath, 'route-name-'.str_replace('_', '-', $name).'-conflict');
    }

    private function add(string $relativePath, string $code): void
    {
        $key = $relativePath.'|'.$code;
        $this->conflicts[$key] = InstallerConflict::forPath($relativePath, $code);
    }
}
