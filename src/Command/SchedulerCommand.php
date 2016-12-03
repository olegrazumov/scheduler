<?php

namespace Web\Command;

use Zend\Db\Adapter\Adapter as DbAdapter;
use Web\Config as Config;
use Cron\CronExpression as Cron;

class SchedulerCommand extends \CLIFramework\Command
{
    public function execute()
    {
        $db = new DbAdapter(Config::get('db'));
        $sql = 'SELECT id, name, params, last_execution FROM search_scheduler WHERE status = 1';
        $searches = $db->query($sql, DbAdapter::QUERY_MODE_EXECUTE)->toArray();

        foreach ($searches as $search) {
            $search['params'] = json_decode($search['params'], true);
            $cron = Cron::factory($search['params']['period']);

            if ($search['last_execution'] < $cron->getPreviousRunDate()->getTimestamp()) {
                $this->getApplication()->getCommand('worker')->executeWrapper([$search]);
                $db->query('UPDATE search_scheduler SET last_execution = (?) WHERE id = (?)', [time(), $search['id']]);
            }
        }
    }
}
