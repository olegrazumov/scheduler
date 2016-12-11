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
        $sql = 'SELECT id, name, params, lastExecution FROM search_scheduler WHERE status = 1';
        $searches = $db->query($sql, DbAdapter::QUERY_MODE_EXECUTE)->toArray();

        foreach ($searches as $search) {
            $search['params'] = json_decode($search['params'], true);

            try {
                $cronDate = Cron::factory($search['params']['period'])->getPreviousRunDate();
            } catch (\Exception $e) {
                $cronDate = false;
            }

            if ($cronDate && $search['lastExecution'] < $cronDate->getTimestamp()) {
                $results = $this->getApplication()->getCommand('worker')->executeWrapper([$search]);
                $notified = !empty($search['params']['openResultsInBrowser']) ? 0 : 1;
                $processedObjectsCount = $results['processedObjectsCount'];
                unset($results['processedObjectsCount']);
                $db->query('UPDATE search_scheduler SET lastExecution = (?), notified = (?), lastResults = (?), lastProcessedCount = (?) WHERE id = (?)', [time(), $notified, json_encode($results), $processedObjectsCount, $search['id']]);
            }
        }
    }
}
