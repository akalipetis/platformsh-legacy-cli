<?php
declare(strict_types=1);

namespace Platformsh\Cli\Service;

use Platformsh\Client\Model\Activity;
use Platformsh\Client\Model\ActivityLog\LogItem;
use Platformsh\Client\Model\Project;
use Symfony\Component\Console\Helper\Helper;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Input\InputDefinition;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\NullOutput;
use Symfony\Component\Console\Output\OutputInterface;

class ActivityService implements InputConfiguringInterface
{

    private static $resultNames = [
        Activity::RESULT_FAILURE => 'failure',
        Activity::RESULT_SUCCESS => 'success',
    ];

    private static $stateNames = [
        Activity::STATE_PENDING => 'pending',
        Activity::STATE_COMPLETE => 'complete',
        Activity::STATE_IN_PROGRESS => 'in progress',
        Activity::STATE_CANCELLED => 'cancelled',
    ];

    protected $output;
    protected $config;
    protected $api;
    protected $stdErr;

    /**
     * @param OutputInterface $output
     * @param Config $config
     * @param Api $api
     */
    public function __construct(OutputInterface $output, Config $config, Api $api)
    {
        $this->output = $output;
        $this->config = $config;
        $this->api = $api;
        $this->stdErr = $this->output instanceof ConsoleOutputInterface ? $this->output->getErrorOutput() : $this->output;
    }

    /**
     * Indent a multi-line string.
     *
     * @param string $string
     * @param string $prefix
     *
     * @return string
     */
    private function indent(string $string, string $prefix = '    '): string
    {
        return preg_replace('/^/m', $prefix, $string);
    }

    /**
     * Wait for a single activity to complete, and display the log continuously.
     *
     * @param Activity $activity The activity.
     * @param int $pollInterval The interval between refreshing the activity (seconds).
     * @param bool|string $timestamps Whether to display timestamps (or pass in a date format).
     * @param bool $context Whether to add a context message.
     * @param OutputInterface|null $logOutput The output object for log messages (defaults to stderr).
     *
     * @return bool True if the activity succeeded, false otherwise.
     */
    public function waitAndLog(Activity $activity, $pollInterval = 3, $timestamps = false, $context = true, OutputInterface $logOutput = null)
    {
        $logOutput = $logOutput ?: $this->output;

        if ($context) {
            $this->stdErr->writeln(sprintf(
                'Waiting for the activity <info>%s</info> (%s):',
                $activity->id,
                self::getFormattedDescription($activity)
            ));
            $this->stdErr->writeln('');
        }

        // The progress bar will show elapsed time and the activity's state.
        $bar = $this->newProgressBar($this->stdErr);
        $overrideState = '';
        $bar->setPlaceholderFormatterDefinition('state', function () use ($activity, &$overrideState) {
            return $this->formatState($overrideState ?: $activity->state);
        });
        $startTime = $this->getStart($activity) ?: time();
        $bar->setPlaceholderFormatterDefinition('elapsed', function () use ($startTime) {
            return Helper::formatTime(time() - $startTime);
        });
        $bar->setFormat('[%bar%] %elapsed:6s% (%state%)');
        $bar->start();

        $logStream = $this->getLogStream($activity, $bar);
        $bar->advance();

        // Read the log while waiting for the activity to complete.
        $lastRefresh = microtime(true);
        $buffer = '';
        while (!feof($logStream) || (!$activity->isComplete() && $activity->state !== Activity::STATE_CANCELLED)) {
            // If $pollInterval has passed, or if there is nothing else left
            // to do, then refresh the activity.
            if (feof($logStream) || microtime(true) - $lastRefresh >= $pollInterval) {
                $activity->refresh();
                $overrideState = '';
                $lastRefresh = microtime(true);
            }

            // Update the progress bar.
            $bar->advance();

            // Wait to see if a read will not block the stream, for up to .2
            // seconds.
            if (!$this->canRead($logStream, 200000)) {
                continue;
            }

            // Parse the log.
            $items = $this->parseLog($logStream, $buffer);
            if (empty($items)) {
                continue;
            }

            // If there is log output, assume the activity must be in progress.
            if ($activity->state === Activity::STATE_PENDING) {
                $overrideState = Activity::STATE_IN_PROGRESS;
            }

            // Format log items.
            $formatted = $this->formatLog($items, $timestamps);

            // Clear the progress bar and ensure the current line is flushed.
            $bar->clear();
            $this->stdErr->write($this->stdErr->isDecorated() ? "\n\033[1A" : "\n");

            // Display the new log output.
            $logOutput->write($formatted);

            // Display the progress bar again.
            $bar->advance();
        }
        $bar->finish();
        $this->stdErr->writeln('');

        // Display the success or failure messages.
        switch ($activity->result) {
            case Activity::RESULT_SUCCESS:
                $this->stdErr->writeln("Activity <info>{$activity->id}</info> succeeded");
                return true;

            case Activity::RESULT_FAILURE:
                if ($activity->state === Activity::STATE_CANCELLED) {
                    $this->stdErr->writeln("The activity <error>{$activity->id}</error> was cancelled");
                } else {
                    $this->stdErr->writeln("Activity <error>{$activity->id}</error> failed");
                }
                return false;
        }

        $this->stdErr->writeln("The log for activity <info>{$activity->id}</info> finished with an unknown result");

        return false;
    }

    /**
     * Reads the log stream and returns LogItem objects.
     *
     * @param resource $stream
     *   The stream.
     * @param string   &$buffer
     *   A string where a buffer can be stored between stream updates.
     *
     * @return LogItem[]
     */
    private function parseLog($stream, &$buffer) {
        $buffer .= stream_get_contents($stream);
        $lastNewline = strrpos($buffer, "\n");
        if ($lastNewline === false) {
            return [];
        }
        $content = substr($buffer, 0, $lastNewline + 1);
        $buffer = substr($buffer, $lastNewline + 1);

        return LogItem::multipleFromJsonStream($content);
    }

    /**
     * Waits to see if a stream can be read (if the read will not block).
     *
     * @param resource $stream  The stream.
     * @param int      $microseconds A timeout in microseconds.
     *
     * @return bool
     */
    private function canRead($stream, $microseconds) {
        if (PHP_MAJOR_VERSION >= 8) {
            // Work around a bug: "Cannot cast a filtered stream on this system" which throws a ValueError in PHP 8+.
            // See https://github.com/platformsh/platformsh-cli/issues/1027#issuecomment-779170913
            \usleep($microseconds);
            return true;
        }
        $readSet = [$stream];
        $ignore = [];

        return (bool) stream_select($readSet, $ignore, $ignore, 0, $microseconds);
    }

    /**
     * Formats log items for display.
     *
     * @param LogItem[]   $items
     *   The log items.
     * @param bool|string $timestamps
     *   False for no timestamps, or a string date format or true to display timestamps
     *
     * @return string
     */
    public function formatLog(array $items, $timestamps = false) {
        $timestampFormat = false;
        if ($timestamps !== false) {
            $timestampFormat = $timestamps ?: $this->config->getWithDefault('application.date_format', 'Y-m-d H:i:s');
        }
        $formatItem = function (LogItem $item) use ($timestampFormat) {
            if ($timestampFormat !== false) {
                return '[' . $item->getTime()->format($timestampFormat) . '] '. $item->getMessage();
            }

            return $item->getMessage();
        };

        return implode('', array_map($formatItem, $items));
    }

    /**
     * Wait for multiple activities to complete.
     *
     * A progress bar tracks the state of each activity. The activity log is
     * only displayed at the end, if an activity failed.
     *
     * @param Activity[]      $activities
     * @param Project         $project
     *
     * @return bool
     *   True if all activities succeed, false otherwise.
     */
    public function waitMultiple(array $activities, Project $project): bool
    {
        $count = count($activities);
        if ($count == 0) {
            return true;
        } elseif ($count === 1) {
            return $this->waitAndLog(reset($activities));
        }

        $this->stdErr->writeln(sprintf('Waiting for %d activities...', $count));

        // The progress bar will show elapsed time and all of the activities'
        // states.
        $bar = $this->newProgressBar($this->stdErr);
        $states = [];
        foreach ($activities as $activity) {
            $state = $activity->state;
            $states[$state] = isset($states[$state]) ? $states[$state] + 1 : 1;
        }
        $bar->setPlaceholderFormatterDefinition('states', function () use (&$states) {
            $format = '';
            foreach ($states as $state => $count) {
                $format .= $count . ' ' . $this->formatState($state) . ', ';
            }

            return rtrim($format, ', ');
        });
        $bar->setFormat('  [%bar%] %elapsed:6s% (%states%)');
        $bar->start();

        // Get the most recent created date of each of the activities, as a Unix
        // timestamp, so that they can be more efficiently refreshed.
        $mostRecentTimestamp = 0;
        foreach ($activities as $activity) {
            $created = strtotime($activity->created_at);
            $mostRecentTimestamp = $created > $mostRecentTimestamp ? $created : $mostRecentTimestamp;
        }

        // Wait for the activities to be completed or cancelled, polling
        // (refreshing) all of them with a one-second delay.
        $done = 0;
        while ($done < $count) {
            sleep(1);
            $states = [];
            $done = 0;
            // Get a list of activities on the project. Any of our activities
            // which are not contained in this list must be refreshed
            // individually.
            $projectActivities = $project->getActivities(0, null, $mostRecentTimestamp ?: null);
            foreach ($activities as &$activityRef) {
                $refreshed = false;
                foreach ($projectActivities as $projectActivity) {
                    if ($projectActivity->id === $activityRef->id) {
                        $activityRef = $projectActivity;
                        $refreshed = true;
                        break;
                    }
                }
                if (!$refreshed && !$activityRef->isComplete() && $activityRef->state !== Activity::STATE_CANCELLED) {
                    $activityRef->refresh();
                }
                if ($activityRef->isComplete() || $activityRef->state === Activity::STATE_CANCELLED) {
                    $done++;
                }
                $state = $activityRef->state;
                $states[$state] = isset($states[$state]) ? $states[$state] + 1 : 1;
            }
            $bar->advance();
        }
        $bar->finish();
        $this->stdErr->writeln('');

        // Display success or failure messages for each activity.
        $success = true;
        foreach ($activities as $activity) {
            $description = self::getFormattedDescription($activity);
            switch ($activity['result']) {
                case Activity::RESULT_SUCCESS:
                    $this->stdErr->writeln(sprintf('Activity <info>%s</info> succeeded: %s', $activity->id, $description));
                    break;

                case Activity::RESULT_FAILURE:
                    $success = false;
                    $this->stdErr->writeln(sprintf('Activity <error>%s</error> failed', $activity->id));

                    // If the activity failed, show the complete log.
                    $this->stdErr->writeln('  Description: ' . $description);
                    $this->stdErr->writeln('  Log:');
                    $this->stdErr->writeln($this->indent($this->formatLog($activity->readLog())));
                    break;
            }
        }

        return $success;
    }

    /**
     * Format a state name.
     *
     * @param string $state
     *
     * @return string
     */
    public function formatState(string $state): string
    {
        return isset(self::$stateNames[$state]) ? self::$stateNames[$state] : $state;
    }

    /**
     * Format a result.
     *
     * @param string $result
     * @param bool   $decorate
     *
     * @return string
     */
    public function formatResult(string $result, bool $decorate = true): string
    {
        $name = isset(self::$resultNames[$result]) ? self::$resultNames[$result] : $result;

        if ($decorate && $result === Activity::RESULT_FAILURE) {
            return '<bg=red>' . $name . '</>';
        }

        return $name;
    }

    /**
     * Initialize a new progress bar.
     *
     * @param OutputInterface $output
     *
     * @return ProgressBar
     */
    private function newProgressBar(OutputInterface $output)
    {
        // If the console output is not decorated (i.e. it does not support
        // ANSI), use NullOutput to suppress the progress bar entirely.
        $progressOutput = $output->isDecorated() ? $output : new NullOutput();

        return new ProgressBar($progressOutput);
    }

    /**
     * Get the formatted description of an activity.
     *
     * @param \Platformsh\Client\Model\Activity $activity
     * @param bool                              $withDecoration
     *
     * @return string
     */
    public function getFormattedDescription(Activity $activity, bool $withDecoration = true): string
    {
        if (!$withDecoration) {
            return $activity->getDescription(false);
        }
        $value = $activity->getDescription(true);

        // Replace description HTML elements with Symfony Console decoration
        // tags.
        $value = preg_replace('@<[^/][^>]+>@', '<options=underscore>', $value);
        $value = preg_replace('@</[^>]+>@', '</>', $value);

        // Replace literal tags like "&lt;info&;gt;" with escaped tags like
        // "\<info>".
        $value = preg_replace('@&lt;(/?[a-z][a-z0-9,_=;-]*+)&gt;@i', '\\\<$1>', $value);

        // Decode other HTML entities.
        $value = html_entity_decode($value, ENT_QUOTES, 'utf-8');

        return $value;
    }

    /**
     * {@inheritdoc}
     *
     * Add both the --no-wait and --wait options.
     */
    public function configureInput(InputDefinition $definition): void
    {
        $description = 'Wait for the operation to complete';
        if (!$this->detectRunningInHook()) {
            $description = 'Wait for the operation to complete (default)';
        }

        $definition->addOption(new InputOption('no-wait', 'W', InputOption::VALUE_NONE, 'Do not wait for the operation to complete'));
        $definition->addOption(new InputOption('wait', null, InputOption::VALUE_NONE, $description));
    }

    /**
     * Returns whether we should wait for an operation to complete.
     *
     * @param \Symfony\Component\Console\Input\InputInterface $input
     *
     * @return bool
     */
    public function shouldWait(InputInterface $input): bool
    {
        if ($input->hasOption('no-wait') && $input->getOption('no-wait')) {
            return false;
        }
        if ($input->hasOption('wait') && $input->getOption('wait')) {
            return true;
        }
        if ($this->detectRunningInHook()) {
            $serviceName = $this->config->get('service.name');
            $message = "\n<comment>Warning:</comment> $serviceName hook environment detected: assuming <comment>--no-wait</comment> by default."
                . "\nTo avoid ambiguity, please specify either --no-wait or --wait."
                . "\n";
            $this->stdErr->writeln($message);

            return false;
        }

        return true;
    }

    /**
     * Detects a Platform.sh non-terminal Dash environment; i.e. a hook.
     *
     * @return bool
     */
    private function detectRunningInHook(): bool
    {
        $envPrefix = $this->config->get('service.env_prefix');
        if (getenv($envPrefix . 'PROJECT')
            && basename(getenv('SHELL')) === 'dash'
            && function_exists('posix_isatty')
            && !posix_isatty(STDIN)) {
            return true;
        }

        return false;
    }

    /**
     * Warn the user that the remote environment needs redeploying.
     */
    public function redeployWarning(): void
    {
        $this->stdErr->writeln([
            '',
            '<comment>The remote environment(s) must be redeployed for the change to take effect.</comment>',
            'To redeploy an environment, run: <info>' . $this->config->get('application.executable') . ' redeploy</info>',
        ]);
    }

    /**
     * @param Activity $activity
     *
     * @return false|int
     */
    private function getStart(Activity $activity) {
        return !empty($activity->started_at) ? strtotime($activity->started_at) : strtotime($activity->created_at);
    }

    /**
     * Returns the activity log as a PHP stream resource.
     *
     * @param Activity $activity
     * @param ProgressBar $bar
     *   Progress bar, updated when we retry.
     *
     * @return resource
     */
    private function getLogStream(Activity $activity, ProgressBar $bar) {
        $url = $activity->getLink('log');

        // Try fetching the stream with a 10 second timeout per call, and a .5
        // second interval between calls, for up to 2 minutes.
        $readTimeout = 10;
        $interval = .5;
        $stream = \fopen($url, 'r', false, $this->api->getStreamContext($readTimeout));
        $start = \microtime(true);
        while ($stream === false) {
            if (\microtime(true) - $start > 120) {
                throw new \RuntimeException('Failed to open activity log stream: ' . $url);
            }
            $bar->advance();
            \usleep((int) $interval * 1000000);
            $bar->advance();
            $stream = \fopen($url, 'r', false, $this->api->getStreamContext($readTimeout));
        }
        \stream_set_blocking($stream, false);

        return $stream;
    }
}