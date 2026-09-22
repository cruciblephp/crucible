<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * All rights reserved.
 */

namespace LucianoPereira\Crucible\Configuration;

use LucianoPereira\Crucible\Exceptions\ConfigurationException;
use SimpleXMLElement;

use function implode;
use function in_array;
use function is_numeric;
use function libxml_use_internal_errors;
use function property_exists;
use function simplexml_load_string;
use function sort;
use function sprintf;
use function trim;
use function var_export;

/**
 * The one sanctioned read of phpunit.xml (ROADMAP: out-of-scope
 * declines XML *support*; a one-time conversion is how the support
 * debt is avoided). Reads a phpunit.xml document, emits the
 * equivalent crucible.php — and refuses to be silently lossy: whatever
 * it cannot map is named in the generated file's header comment, so
 * the migration's gaps are part of its output, not a surprise.
 *
 * Pure: XML string in, generated code + notes out. File IO belongs
 * to the CLI.
 */
final readonly class XmlMigrator
{
    /**
     * Root attributes that map 1:1 onto a boolean Builder method,
     * keyed by XML attribute name. Emitted only when they differ from
     * Crucible's (= the spec's) defaults.
     */
    private const array BOOLEAN_ATTRIBUTES = [
        'colors'                            => ['colors', false],
        'cacheResult'                       => ['cacheResult', true],
        'failOnDeprecation'                 => ['failOnDeprecation', false],
        'failOnIncomplete'                  => ['failOnIncomplete', false],
        'failOnNotice'                      => ['failOnNotice', false],
        'failOnRisky'                       => ['failOnRisky', false],
        'failOnSkipped'                     => ['failOnSkipped', false],
        'failOnWarning'                     => ['failOnWarning', false],
        'stopOnDefect'                      => ['stopOnDefect', false],
        'stopOnError'                       => ['stopOnError', false],
        'stopOnFailure'                     => ['stopOnFailure', false],
        'testdox'                           => ['testdox', false],
        'backupGlobals'                     => ['backupGlobals', false],
        'backupStaticProperties'            => ['backupStaticProperties', false],
        'beStrictAboutChangesToGlobalState' => ['beStrictAboutChangesToGlobalState', false],
        'requireCoverageMetadata'           => ['requireCoverageMetadata', false],
        'beStrictAboutCoverageMetadata'     => ['beStrictAboutCoverageMetadata', false],
    ];

    /**
     * @throws ConfigurationException when the document is not parseable XML
     */
    public function migrate(string $xml): MigrationResult
    {
        libxml_use_internal_errors(true);

        $document = simplexml_load_string($xml);

        if ($document === false) {
            throw new ConfigurationException('The phpunit.xml document cannot be parsed.');
        }

        $notes = [];
        $calls = [];
        $uses  = [\LucianoPereira\Crucible\Configuration\Crucible::class];

        $this->rootAttributes($document, $calls, $notes, $uses);
        $this->testSuites($document, $calls, $notes);
        $this->source($document, $calls, $notes);
        $this->php($document, $calls, $notes);

        foreach ($document->children() as $child) {
            $name = $child->getName();

            if (!in_array($name, ['testsuites', 'source', 'php'], true)) {
                $notes[] = sprintf('element <%s>', $name);
            }
        }

        return new MigrationResult($this->render($uses, $calls, $notes), $notes);
    }

    /**
     * @param list<non-empty-string> $calls
     * @param list<non-empty-string> $notes
     * @param list<non-empty-string> $uses
     */
    private function rootAttributes(SimpleXMLElement $document, array &$calls, array &$notes, array &$uses): void
    {
        foreach ($document->attributes() ?? [] as $name => $value) {
            $value = (string) $value;

            if ($name === 'bootstrap') {
                if ($value !== '') {
                    $calls[] = sprintf('->bootstrap(%s)', var_export($value, true));
                }

                continue;
            }

            if ($name === 'cacheDirectory') {
                if ($value !== '') {
                    $calls[] = sprintf('->cacheDirectory(%s)', var_export($value, true));
                }

                continue;
            }

            if ($name === 'executionOrder') {
                $order = ExecutionOrder::tryFrom($value);

                if ($order instanceof ExecutionOrder) {
                    if ($order !== ExecutionOrder::Declared) {
                        $uses[]  = \LucianoPereira\Crucible\Configuration\ExecutionOrder::class;
                        $calls[] = sprintf('->executionOrder(ExecutionOrder::%s)', $order->name);
                    }
                } else {
                    $notes[] = sprintf('attribute executionOrder="%s" (combined orders are implicit in Crucible: dependencies always resolve)', $value);
                }

                continue;
            }

            if (isset(self::BOOLEAN_ATTRIBUTES[$name])) {
                [$method, $default] = self::BOOLEAN_ATTRIBUTES[$name];
                $enabled            = $value === 'true';

                if ($enabled !== $default) {
                    $calls[] = sprintf('->%s(%s)', $method, $enabled ? 'true' : 'false');
                }

                continue;
            }

            $notes[] = sprintf('attribute %s="%s"', $name, $value);
        }
    }

    /**
     * @param list<non-empty-string> $calls
     * @param list<non-empty-string> $notes
     */
    private function testSuites(SimpleXMLElement $document, array &$calls, array &$notes): void
    {
        foreach ($document->testsuites->testsuite ?? [] as $suite) {
            $name        = (string) ($suite['name'] ?? '');
            $directories = [];
            $files       = [];
            $suffixes    = [];

            foreach ($suite->directory ?? [] as $directory) {
                $directories[] = (string) $directory;

                if (isset($directory['suffix'])) {
                    $suffixes[] = (string) $directory['suffix'];
                }

                foreach (['prefix', 'phpVersion', 'phpVersionOperator'] as $attribute) {
                    if (isset($directory[$attribute])) {
                        $notes[] = sprintf('testsuite "%s" <directory %s="%s">', $name, $attribute, (string) $directory[$attribute]);
                    }
                }
            }

            foreach ($suite->file ?? [] as $file) {
                $files[] = (string) $file;
            }

            foreach ($suite->exclude ?? [] as $excluded) {
                $notes[] = sprintf('testsuite "%s" <exclude>%s</exclude>', $name, (string) $excluded);
            }

            if ($name === '' || ($directories === [] && $files === [])) {
                $notes[] = sprintf('testsuite "%s" (empty or unnamed)', $name);

                continue;
            }

            $arguments = [var_export($name, true), $this->exportList($directories)];

            if ($files !== []) {
                $arguments[] = 'files: ' . $this->exportList($files);
            }

            if ($suffixes !== [] && $suffixes[0] !== 'Test.php') {
                $arguments[] = 'suffix: ' . var_export($suffixes[0], true);
            }

            $calls[] = sprintf('->testSuite(%s)', implode(', ', $arguments));
        }
    }

    /**
     * @param list<non-empty-string> $calls
     * @param list<non-empty-string> $notes
     */
    private function source(SimpleXMLElement $document, array &$calls, array &$notes): void
    {
        if (!property_exists($document, 'source') || $document->source === null) {
            return;
        }

        $source       = $document->source;
        $include      = [];
        $exclude      = [];
        $includeFiles = [];
        $excludeFiles = [];

        foreach ($source->include->directory ?? [] as $directory) {
            $include[] = (string) $directory;

            if (isset($directory['suffix']) && (string) $directory['suffix'] !== '.php') {
                $notes[] = sprintf('<source> <directory suffix="%s"> (Crucible sources are .php)', (string) $directory['suffix']);
            }
        }

        foreach ($source->include->file ?? [] as $file) {
            $includeFiles[] = (string) $file;
        }

        foreach ($source->exclude->directory ?? [] as $directory) {
            $exclude[] = (string) $directory;
        }

        foreach ($source->exclude->file ?? [] as $file) {
            $excludeFiles[] = (string) $file;
        }

        foreach ($source->attributes() ?? [] as $name => $value) {
            $notes[] = sprintf('<source> attribute %s="%s"', $name, (string) $value);
        }

        if ($include === [] && $exclude === [] && $includeFiles === [] && $excludeFiles === []) {
            return;
        }

        $arguments = [];

        if ($include !== []) {
            $arguments[] = 'include: ' . $this->exportList($include);
        }

        if ($exclude !== []) {
            $arguments[] = 'exclude: ' . $this->exportList($exclude);
        }

        if ($includeFiles !== []) {
            $arguments[] = 'includeFiles: ' . $this->exportList($includeFiles);
        }

        if ($excludeFiles !== []) {
            $arguments[] = 'excludeFiles: ' . $this->exportList($excludeFiles);
        }

        $calls[] = sprintf('->source(%s)', implode(', ', $arguments));
    }

    /**
     * @param list<non-empty-string> $calls
     * @param list<non-empty-string> $notes
     */
    private function php(SimpleXMLElement $document, array &$calls, array &$notes): void
    {
        if (!property_exists($document, 'php') || $document->php === null) {
            return;
        }

        $php = $document->php;

        foreach ($php->ini ?? [] as $ini) {
            $calls[] = sprintf(
                '->ini(%s, %s)',
                var_export((string) $ini['name'], true),
                var_export((string) $ini['value'], true),
            );
        }

        foreach ($php->env ?? [] as $env) {
            $calls[] = sprintf(
                '->env(%s, %s)',
                var_export((string) $env['name'], true),
                var_export((string) $env['value'], true),
            );

            if ((string) ($env['force'] ?? '') === 'true') {
                $notes[] = sprintf('<env name="%s" force="true"> (Crucible env values always apply)', (string) $env['name']);
            }
        }

        foreach ($php->const ?? [] as $constant) {
            $calls[] = sprintf(
                '->constant(%s, %s)',
                var_export((string) $constant['name'], true),
                $this->exportConstant((string) $constant['value']),
            );
        }

        foreach ($php->children() as $child) {
            $name = $child->getName();

            if (!in_array($name, ['ini', 'env', 'const'], true)) {
                $notes[] = sprintf('<php> element <%s name="%s">', $name, (string) $child['name']);
            }
        }
    }

    /**
     * The spec's <const> value coercion: "true"/"false" become
     * booleans, numeric strings become numbers, the rest stay strings.
     */
    private function exportConstant(string $value): string
    {
        if ($value === 'true' || $value === 'false') {
            return $value;
        }

        // A trimmed numeric string is already a valid PHP literal.
        if (is_numeric($value) && $value === trim($value)) {
            return $value;
        }

        return var_export($value, true);
    }

    /**
     * @param list<string> $items
     */
    private function exportList(array $items): string
    {
        $exported = [];

        foreach ($items as $item) {
            $exported[] = var_export($item, true);
        }

        return '[' . implode(', ', $exported) . ']';
    }

    /**
     * @param list<non-empty-string> $uses
     * @param list<non-empty-string> $calls
     * @param list<non-empty-string> $notes
     *
     * @return non-empty-string
     */
    private function render(array $uses, array $calls, array $notes): string
    {
        $header = "<?php\n\ndeclare(strict_types=1);\n\n/*\n * Migrated from phpunit.xml by `crucible migrate-config`.\n";

        if ($notes !== []) {
            $header .= " *\n * Not migrated (had no Crucible equivalent):\n";

            foreach ($notes as $note) {
                $header .= ' *   - ' . $note . "\n";
            }
        }

        $header .= " */\n\n";

        sort($uses);

        foreach ($uses as $use) {
            $header .= 'use ' . $use . ";\n";
        }

        $body = "\nreturn Crucible::configure()";

        foreach ($calls as $call) {
            $body .= "\n    " . $call;
        }

        return $header . $body . ";\n";
    }
}
