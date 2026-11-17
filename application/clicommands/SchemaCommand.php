<?php

namespace Icinga\Module\Starlink\Clicommands;

use Icinga\Cli\Command;
use Icinga\Module\Starlink\Db;

/**
 * Database schema management
 */
class SchemaCommand extends Command
{
    /**
     * Create the schema if it doesn't exist yet (the daemon also does this)
     *
     * USAGE
     *
     *   icingacli starlink schema apply
     */
    public function applyAction()
    {
        echo Db::ensureSchema() ? "Schema created\n" : "Schema already present\n";
    }
}
