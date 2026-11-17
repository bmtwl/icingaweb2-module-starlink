<?php

namespace Icinga\Module\Starlink\Clicommands;

use Icinga\Cli\Command;
use Icinga\Module\Starlink\Daemon;

/**
 * Starlink telemetry collector
 */
class DaemonCommand extends Command
{
    /**
     * Run the collector in the foreground (systemd)
     *
     * USAGE
     *
     *   icingacli starlink daemon run [--verbose]
     */
    public function runAction()
    {
        (new Daemon((bool) $this->params->get('verbose')))->run();
    }

    /**
     * Fetch and store a single stream batch, then exit (troubleshooting)
     *
     * Note: this consumes stream data just like the daemon does. Don't run it
     * with the same service account while the daemon is running.
     *
     * USAGE
     *
     *   icingacli starlink daemon once
     */
    public function onceAction()
    {
        $daemon = new Daemon(true);
        $stats = $daemon->runOnce();
        echo json_encode($stats, JSON_PRETTY_PRINT) . "\n";
    }
}
