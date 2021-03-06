<?php
namespace Azura\Event;

use Azura\Console\Application;
use Symfony\Contracts\EventDispatcher\Event;

class BuildConsoleCommands extends Event
{
    /** @var Application */
    protected $cli;

    public function __construct(Application $cli)
    {
        $this->cli = $cli;
    }

    public function getConsole(): Application
    {
        return $this->cli;
    }
}