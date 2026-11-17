<?php

namespace Icinga\Module\Starlink;

use Icinga\Module\Starlink\Api\ApiException;
use Icinga\Module\Starlink\Api\Client;

/**
 * Single-process stream consumer.
 *
 * The stream call itself lingers up to 15s, which gives Starlink's
 * recommended ~4 requests/minute without any extra scheduling.
 */
class Daemon
{
    /** @var bool */
    protected $stop = false;

    /** @var bool */
    protected $verbose;

    /** @var ?Client */
    protected $client;

    /** @var int */
    protected $lastCleanup = 0;

    /** @var int */
    protected $lastAccount = 0;

    public function __construct(bool $verbose = false)
    {
        $this->verbose = $verbose;
    }

    public function log(string $level, string $message): void
    {
        if ($level === 'debug' && ! $this->verbose) {
            return;
        }
        // systemd/journald adds timestamps; keep lines short and greppable
        fwrite(STDERR, sprintf("<%s> %s\n", $level, $message));
    }

    protected function installSignalHandlers(): void
    {
        if (! function_exists('pcntl_async_signals')) {
            $this->log('warning', 'php-pcntl not loaded, SIGTERM will interrupt mid-batch');
            return;
        }
        pcntl_async_signals(true);
        $handler = function ($signo) {
            $this->log('notice', "Got signal $signo, stopping after current batch");
            $this->stop = true;
        };
        pcntl_signal(SIGTERM, $handler);
        pcntl_signal(SIGINT, $handler);
    }

    protected function client(): Client
    {
        if ($this->client === null) {
            $this->client = Client::fromCredentialsFile(Settings::credentialsFile());
        }

        return $this->client;
    }

    protected function heartbeat(bool $success, ?string $error = null): void
    {
        try {
            if ($success) {
                Db::exec('UPDATE starlink_daemon SET last_poll_at = now(), last_success_at = now() WHERE id = 1');
            } else {
                Db::exec(
                    'UPDATE starlink_daemon SET last_poll_at = now(), last_error = ?, last_error_at = now() WHERE id = 1',
                    [substr((string) $error, 0, 2000)]
                );
            }
        } catch (\Throwable $e) {
            $this->log('error', 'Heartbeat failed: ' . $e->getMessage());
            Db::reset();
        }
    }

    protected function periodic(): void
    {
        $now = time();
        if ($now - $this->lastCleanup >= 600) {
            $this->lastCleanup = $now;
            $deleted = Collector::cleanup();
            $this->log('info', sprintf('Cleanup: %d samples, %d other rows', $deleted['samples'], $deleted['other']));
        }
        if ($now - $this->lastAccount >= 21600) {
            $this->lastAccount = $now;
            try {
                $account = $this->client()->account()['content'] ?? [];
                Db::exec(
                    'UPDATE starlink_daemon SET account_number = ?, account_name = ? WHERE id = 1',
                    [$account['accountNumber'] ?? null, $account['accountName'] ?? null]
                );
            } catch (ApiException $e) {
                // optional (needs "Account information, View"); never fatal
                $this->log('info', 'Account info unavailable: ' . $e->getMessage());
            }
        }
    }

    public function runOnce(): array
    {
        $response = $this->client()->stream(1000, 15000);
        $stats = (new Collector())->ingest($response);
        $this->heartbeat(true);

        return $stats;
    }

    public function run(): void
    {
        $this->installSignalHandlers();
        $created = Db::ensureSchema();
        if ($created) {
            $this->log('notice', 'Created database schema');
        }
        Db::exec(
            'UPDATE starlink_daemon SET hostname = ?, pid = ?, started_at = now() WHERE id = 1',
            [gethostname(), getmypid()]
        );
        $this->log('notice', 'Starlink collector started');

        $backoff = 5;
        while (! $this->stop) {
            $started = microtime(true);
            try {
                $stats = $this->runOnce();
                $this->log('debug', sprintf(
                    'Batch: %d rows (u=%d r=%d i=%d other=%d), %d new samples',
                    $stats['rows'], $stats['u'], $stats['r'], $stats['i'], $stats['other'], $stats['new_samples']
                ));
                $this->periodic();
                $backoff = 5;
                // never spin faster than 1 req/s, even when catching up
                $elapsed = microtime(true) - $started;
                if ($elapsed < 1) {
                    usleep((int) ((1 - $elapsed) * 1e6));
                }
            } catch (ApiException $e) {
                $wait = $e->retryAfter ?? $backoff;
                $this->log('warning', $e->getMessage() . " (retry in {$wait}s)");
                $this->heartbeat(false, $e->getMessage());
                if ($e->httpCode === 401 || $e->httpCode === 403) {
                    $this->client = null; // re-read credentials, maybe they were rotated
                }
                $this->sleep($wait);
                $backoff = min(300, $backoff * 2);
            } catch (\Throwable $e) {
                $this->log('error', get_class($e) . ': ' . $e->getMessage());
                Db::reset();
                $this->heartbeat(false, $e->getMessage());
                $this->sleep($backoff);
                $backoff = min(300, $backoff * 2);
            }
        }
        $this->log('notice', 'Starlink collector stopped');
    }

    protected function sleep(int $seconds): void
    {
        $until = time() + $seconds;
        while (! $this->stop && time() < $until) {
            sleep(1);
        }
    }
}
