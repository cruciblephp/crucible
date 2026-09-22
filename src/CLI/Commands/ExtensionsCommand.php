<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * All rights reserved.
 */

namespace LucianoPereira\Crucible\CLI\Commands;

use LucianoPereira\Crucible\CLI\CliOptions;
use LucianoPereira\Crucible\Configuration\Loader;
use LucianoPereira\Crucible\Console\Components\SelectPrompt;
use LucianoPereira\Crucible\Console\Exceptions\NonInteractiveException;
use LucianoPereira\Crucible\Console\Output\Table;
use LucianoPereira\Crucible\Console\Runtime\Runtime;
use LucianoPereira\Crucible\Exceptions\Exception;
use LucianoPereira\Crucible\Filesystem\WorkingDirectory;
use LucianoPereira\Crucible\Reporting\Document\SampleDocument;
use LucianoPereira\Crucible\Reporting\ProgressView\ProgressViewRegistry;
use LucianoPereira\Crucible\Reporting\Registry\{AbstractRegistry, PluginDescriptor, SampleRun};
use LucianoPereira\Crucible\Reporting\ReportFormat\{ReportContext, ReportFormatRegistry};
use LucianoPereira\Crucible\Reporting\Subscriber\SubscriberRegistry;
use LucianoPereira\Crucible\Version;

use function array_column;
use function array_key_exists;
use function array_map;
use function fclose;
use function file_put_contents;
use function filesize;
use function fopen;
use function implode;
use function in_array;
use function json_encode;
use function printf;
use function rewind;
use function sprintf;
use function stream_get_contents;
use function strlen;
use function sys_get_temp_dir;

use const JSON_PRETTY_PRINT;
use const PHP_EOL;

/**
 * `crucible extensions`: introspects the report-format, subscriber, and
 * progress-view plugins registered in `crucible.php` — what's wired,
 * whether each is available in this environment, and (via `--preview`)
 * a live, code-driven sample, so a plugin's actual output is never
 * something you have to trust documentation for. All three kinds
 * share the same class-string + `#[Attribute]` + reflection
 * registration shape (`AbstractRegistry`), so `list()`/`detail()` walk
 * them through one `array<string, AbstractRegistry>` map rather than a
 * per-kind branch that grows with every new kind — adding a fourth
 * kind later touches one map entry, not a new branch here.
 */
final class ExtensionsCommand
{
    /**
     * @param list<string>     $argv
     */
    public function execute(CliOptions $options, array $argv, WorkingDirectory $workingDirectory): int
    {
        try {
            $loaded = (new Loader())->load($workingDirectory, $options->configuration);
        } catch (Exception $e) {
            print $e->getMessage() . PHP_EOL;

            return 1;
        }

        $reportFormats = new ReportFormatRegistry($loaded->configuration->reportFormats);
        $subscribers   = new SubscriberRegistry($loaded->configuration->subscribers);
        $progressViews = new ProgressViewRegistry($loaded->configuration->progressViews);

        /** @var array<string, AbstractRegistry> */
        $registries = [
            'report-format' => $reportFormats,
            'subscriber'    => $subscribers,
            'progress-view' => $progressViews,
        ];

        if ($options->preview) {
            return $this->preview($reportFormats, $subscribers, $progressViews, $loaded->configuration->subscribers, $loaded->configuration->progressViews, $options);
        }

        if ($options->key !== null) {
            return $this->detail($registries, $options->key, $options->json);
        }

        return $this->list($registries, $options->json);
    }

    /** @param array<string, AbstractRegistry> $registries */
    private function list(array $registries, bool $json): int
    {
        /** @var list<array{kind: string, descriptor: PluginDescriptor}> $rows */
        $rows = [];

        foreach ($registries as $kind => $registry) {
            foreach ($registry->all() as $d) {
                $rows[] = ['kind' => $kind, 'descriptor' => $d];
            }
        }

        if ($json) {
            print json_encode(array_map(
                static fn(array $row): array => ['kind' => $row['kind']] + $row['descriptor']->toArray(),
                $rows,
            ), JSON_PRETTY_PRINT) . PHP_EOL;

            return 0;
        }

        if ($rows === []) {
            print 'Nothing registered — add a report format via ->reportFormat(), a subscriber via ->subscriber(), or a progress view via ->progressView() in crucible.php.' . PHP_EOL;

            return 0;
        }

        $tableRows = [];

        foreach ($rows as $row) {
            $d = $row['descriptor'];

            $tableRows[] = [
                $row['kind'],
                $d->key,
                $d->description,
                $d->available ? 'yes' : 'no',
                $d->available ? $this->orDash(implode(', ', $d->requires)) : implode(', ', $d->missingRequirements),
            ];
        }

        print (new Table(['KIND', 'KEY', 'DESCRIPTION', 'AVAILABLE', 'REQUIREMENTS'], $tableRows))->toString();

        return 0;
    }

    /** @param array<string, AbstractRegistry> $registries */
    private function detail(array $registries, string $key, bool $json): int
    {
        $d = null;

        foreach ($registries as $registry) {
            if ($registry->has($key)) {
                $d = $registry->describe($key);

                break;
            }
        }

        if ($d === null) {
            printf('"%s" is not registered as a report format, a subscriber, or a progress view.' . PHP_EOL, $key);

            return 1;
        }

        if ($json) {
            print json_encode($d->toArray(), JSON_PRETTY_PRINT) . PHP_EOL;

            return 0;
        }

        printf('%s' . PHP_EOL, $d->key);
        printf('  %s' . PHP_EOL, $d->description);

        if ($d->comment !== null) {
            printf(PHP_EOL . '%s' . PHP_EOL, $d->comment);
        }

        printf(
            PHP_EOL . 'Available: %s' . PHP_EOL,
            $d->available ? 'yes' : sprintf('no (%s)', implode(', ', $d->missingRequirements)),
        );

        if ($d->params !== []) {
            $paramRows = [];

            foreach ($d->params as $p) {
                $paramRows[] = [$p['name'], $p['type'], $p['required'] ? 'required' : 'optional', $p['description']];
            }

            print PHP_EOL . 'Params:' . PHP_EOL;
            print (new Table(['NAME', 'TYPE', 'REQUIRED', 'DESCRIPTION'], $paramRows))->toString();
        }

        return 0;
    }

    /**
     * @param array<string, array{class: class-string, params: array<string, mixed>}> $configuredSubscribers
     * @param array<string, array{class: class-string, params: array<string, mixed>}> $configuredViews
     */
    private function preview(
        ReportFormatRegistry $reportFormats,
        SubscriberRegistry $subscribers,
        ProgressViewRegistry $progressViews,
        array $configuredSubscribers,
        array $configuredViews,
        CliOptions $options,
    ): int {
        try {
            $key = $options->key ?? $this->chooseKey($reportFormats, $subscribers, $progressViews);
        } catch (NonInteractiveException) {
            // The prompt refused to answer for a script, and this command
            // knows the flag that would have. Its own contract is kept:
            // the same message and the same exit code it always gave.
            print '--preview requires --key=<name>.' . PHP_EOL;

            return 1;
        }

        if ($key === null) {
            print 'Nothing is registered to preview — add one via ->reportFormat(), ->subscriber(), or ->progressView() in crucible.php.' . PHP_EOL;

            return 1;
        }

        if ($reportFormats->has($key)) {
            return $this->previewReportFormat($reportFormats, $key, $options->out);
        }

        if ($subscribers->has($key)) {
            return $this->previewSubscriber($configuredSubscribers, $key, $options->out);
        }

        if ($progressViews->has($key)) {
            return $this->previewProgressView($configuredViews, $key, $options->out);
        }

        printf('"%s" is not registered as a report format, a subscriber, or a progress view.' . PHP_EOL, $key);

        return 1;
    }

    /**
     * Ask which plugin to preview, when the command line did not say.
     *
     * The prompt carries no default on purpose: off a terminal it raises
     * {@see \LucianoPereira\Crucible\Console\Exceptions\NonInteractiveException}
     * rather than previewing whichever plugin happened to register first.
     * Null here means the list itself is empty, which is a different answer.
     */
    private function chooseKey(AbstractRegistry ...$registries): ?string
    {
        $choices = [];

        foreach ($registries as $registry) {
            foreach ($registry->all() as $d) {
                $choices[$d->key] = $d->available ? $d->key : $d->key . '  (unavailable)';
            }
        }

        if ($choices === []) {
            return null;
        }

        $prompt = new SelectPrompt(
            label: 'Which plugin should be previewed?',
            options: $choices,
            hint: 'Or pass --key=<name>.',
        );

        Runtime::run($prompt);

        $chosen = $prompt->value();

        return $chosen === null ? null : (string) $chosen;
    }

    private function previewReportFormat(ReportFormatRegistry $registry, string $key, ?string $out): int
    {
        $d = $registry->describe($key);

        if (!$d->available) {
            printf('Report format "%s" is not available: %s.' . PHP_EOL, $key, implode(', ', $d->missingRequirements));

            return 1;
        }

        /** @var array<string, mixed> $params */
        $params = [];

        foreach ($d->params as $param) {
            if (array_key_exists('default', $param)) {
                $params[$param['name']] = $param['default'];
            }
        }

        $instance = $registry->instantiate($key);
        $document = SampleDocument::build();
        $context  = new ReportContext('Sample Report', Version::AUTHOR, sprintf('Crucible %s', Version::NUMBER), null, 0.0);
        $rendered = $instance->render($document, $context, $params);

        $path = $out ?? sprintf('%s/crucible-preview-%s.out', sys_get_temp_dir(), $key);

        if (file_put_contents($path, $rendered) === false) {
            printf('Cannot write %s.' . PHP_EOL, $path);

            return 1;
        }

        printf('Wrote preview to %s (%d bytes).' . PHP_EOL, $path, strlen($rendered));

        return 0;
    }

    /**
     * A subscriber has no static Document to render — it only shows
     * what it does by actually receiving events (via {@see SampleRun}).
     * Unlike a report format, whose "output" param is vestigial at
     * preview time (the caller writes the returned string itself),
     * "output" is what a subscriber's constructor actually opens, so
     * it is always forced to the computed preview path here — the
     * schema's own default (if any) is not enough to make it write
     * anywhere real.
     *
     * @param array<string, array{class: class-string, params: array<string, mixed>}> $configuredSubscribers
     */
    private function previewSubscriber(array $configuredSubscribers, string $key, ?string $out): int
    {
        $d = (new SubscriberRegistry($configuredSubscribers))->describe($key);

        if (!$d->available) {
            printf('Subscriber "%s" is not available: %s.' . PHP_EOL, $key, implode(', ', $d->missingRequirements));

            return 1;
        }

        $path = $out ?? sprintf('%s/crucible-preview-%s.out', sys_get_temp_dir(), $key);

        $overridden = $configuredSubscribers;

        if (in_array('output', array_column($d->params, 'name'), true)) {
            $overridden[$key] = [
                'class'  => $overridden[$key]['class'],
                'params' => [...$overridden[$key]['params'], 'output' => $path],
            ];
        }

        $instance = (new SubscriberRegistry($overridden))->resolve($key);

        SampleRun::through($instance);

        $size = filesize($path);

        printf('Wrote preview to %s (%d bytes).' . PHP_EOL, $path, $size === false ? 0 : $size);

        return 0;
    }

    /**
     * A progress view writes to a stream, not a user-chosen path — so
     * unlike a subscriber's preview, there's no "output" param to
     * force. Buffered through a memory stream instead (mirroring
     * {@see previewReportFormat()}'s buffer-then-write shape), then
     * written to the preview path once the run through
     * {@see SampleRun} has finished.
     *
     * @param array<string, array{class: class-string, params: array<string, mixed>}> $configuredViews
     */
    private function previewProgressView(array $configuredViews, string $key, ?string $out): int
    {
        $registry = new ProgressViewRegistry($configuredViews);
        $d        = $registry->describe($key);

        if (!$d->available) {
            printf('Progress view "%s" is not available: %s.' . PHP_EOL, $key, implode(', ', $d->missingRequirements));

            return 1;
        }

        $stream = fopen('php://memory', 'w+');

        if ($stream === false) {
            print 'Cannot open an in-memory stream for the preview.' . PHP_EOL;

            return 1;
        }

        $instance = $registry->resolve($key, $stream);

        SampleRun::through($instance);

        rewind($stream);
        $rendered = (string) stream_get_contents($stream);
        fclose($stream);

        $path = $out ?? sprintf('%s/crucible-preview-%s.out', sys_get_temp_dir(), $key);

        if (file_put_contents($path, $rendered) === false) {
            printf('Cannot write %s.' . PHP_EOL, $path);

            return 1;
        }

        printf('Wrote preview to %s (%d bytes).' . PHP_EOL, $path, strlen($rendered));

        return 0;
    }

    private function orDash(string $value): string
    {
        return $value === '' ? '—' : $value;
    }
}
