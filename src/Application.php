<?php

namespace Web;

class Application extends \CLIFramework\Application
{
    public $showAppSignature = false;

    public function init()
    {
        parent::init();
        $this->command('scheduler');
        $this->command('worker');
        $this->command('mailer');
    }
}
