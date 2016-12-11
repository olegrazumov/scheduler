<?php

namespace Web;

use \Zend\Db\Adapter\Adapter as DbAdapter;

class Ui
{
    const TWIG_DIR = '/../twig/ui';

    protected static $searchLocations = [
        'Москва' => 180691,
        'Московская область' => 93644,
    ];

    protected static $searchPropertyTypes = [
        'Недвижимость' => '9,8,35,34,33,32,31,30,29,28,27,26,25,24,23,22,21,20,19,18,17,16,15,14,13,12,11,10',
        'Квартиры' => '21',
        'Недвижимость (иное)' => '20',
        'Движимое имущество (автотранспорт)' => '1',
    ];

    protected static $searchPeriods = [
        'Каждую минуту' => '*/1 * * * *',
        'Каждые 5 минут' => '*/5 * * * *',
        'Каждый час' => '@hourly',
        'Каждый день' => '@daily',
        'Каждую неделю' => '@weekly',
        'Каждый месяц' => '@monthly',
    ];

    protected static $searchStatuses = [
        'Обьявлен' => 2,
        'Несостоявшиеся из-за отказа заключения контракта' => 9,
        'Несостоявшиеся из-за отсутствия предложения повышения цены' => 10,
        'Несостоявшийся в связи с отсутствием допущенных участников' => 7,
        'Несостоявшийся из-за отказа заключения договора' => 23,
        'Несостоявшийся с единственным участником' => 6,
        'Отменен/аннулирован' => 4,
        'Приостановлен' => 12,
        'Состоявшийся' => 5,
        'Текущий' => 3,
    ];

    protected $request;

    protected $basePath;

    protected $db;

    protected $twig;

    public static function handleRequest()
    {
        (new static(\Zend\Diactoros\ServerRequestFactory::fromGlobals()))->run();
    }

    public function __construct(\Zend\Diactoros\ServerRequest $request)
    {
        $this->request = $request;
        $this->basePath = \Web\Config::get('ui')['basePath'];
    }

    public function run()
    {
        $uri = str_replace($this->basePath, '/', $this->request->getUri()->getPath());

        switch ($uri) {
            case '/':
                $this->mainAction();
                break;

            case '/search':
                $this->searchAction();
                break;

            case '/notifications':
                $this->notificationsAction();
                break;

            default:
                $this->notFoundAction();

        }
    }

    protected function mainAction()
    {
        $searches = $this->getSearches();

        foreach ($searches as &$search) {
            $search['params'] = json_decode($search['params'], true);

            try {
                $cronDate = \Cron\CronExpression::factory($search['params']['period'])->getNextRunDate();
            } catch (\Exception $e) {
                $cronDate = false;
            }

            if ($search['params']['period'] && $cronDate) {
                $search['next_execution'] = $cronDate->getTimestamp();
            }
        }

        $html = $this->getTwig()->render('search/list.twig', ['searches' => $searches, 'baseHref' => $this->basePath]);
        $this->emitResponse(new \Zend\Diactoros\Response\HtmlResponse($html));
    }

    protected function searchAction()
    {
        $params = $this->request->getQueryParams();

        if (!array_key_exists('action', $params)) {
            $params['action'] = 'new';
        }

        switch ($params['action']) {
            case 'new':
                $this->editSearch();
                break;

            case 'edit':
                if (array_key_exists('id', $params)) {
                    $this->editSearch($params['id']);
                }
                break;

            case 'delete':
                if (array_key_exists('id', $params)) {
                    $this->deleteSearch($params['id']);
                }
                break;

            case 'results':
                if (array_key_exists('id', $params) && array_key_exists('type', $params)) {
                    $this->showResults($params['id'], $params['type']);
                }
                break;

            default:
                $this->badMethodAction();
        }
    }

    protected function editSearch($id = null)
    {
        if ('POST' === $this->request->getMethod()) {
            $searchData = $this->request->getParsedBody();
            $searchData['params']['emails'] = explode(',', $searchData['params']['emails']);
            $searchData['params']['type'] = explode(',', $searchData['params']['type']);

            if ($searchData['cronExpression']) {
                $searchData['params']['period'] = $searchData['cronExpression'];
            }

            $searchData['params'] = json_encode($searchData['params']);

            if ($searchData['id']) {
                $sql = 'UPDATE search_scheduler SET name = (?), params = (?) WHERE id = (?)';
                $this->getDb()->query($sql, [$searchData['name'], $searchData['params'], $searchData['id']]);
            } else {
                $sql = 'INSERT INTO search_scheduler (name, params) VALUES ((?), (?))';
                $this->getDb()->query($sql, [$searchData['name'], $searchData['params']]);
            }

            return $this->redirect('./');
        }

        $params = [
            'periods' => self::$searchPeriods,
            'statuses' => self::$searchStatuses,
            'types' => self::$searchPropertyTypes,
            'locations' => self::$searchLocations,
            'baseHref' => $this->basePath,
        ];

        if ($id) {
            $sql = 'SELECT id, name, params, status, lastExecution FROM search_scheduler WHERE id = (?)';
            $search = $this->getDb()->query($sql, [$id])->current();
            $search['params'] = json_decode($search['params'], true);
            $search['params']['emails'] = implode(', ', $search['params']['emails']);
            $search['params']['type'] = implode(',', $search['params']['type']);
            $params['search'] = $search;
        }

        $html = $this->getTwig()->render('search/edit.twig', $params);
        $this->emitResponse(new \Zend\Diactoros\Response\HtmlResponse($html));
    }

    protected function showResults($id, $type)
    {
        $sql = 'SELECT lastResults FROM search_scheduler WHERE id = (?)';
        $search = $this->getDb()->query($sql, [$id])->current();
        $lastResults = json_decode($search['lastResults'], true);
        $html = array_key_exists($type, $lastResults) ? $lastResults[$type]['html'] : '';
        $this->emitResponse(new \Zend\Diactoros\Response\HtmlResponse($html));
    }

    protected function deleteSearch($id)
    {
        if ('POST' === $this->request->getMethod()) {
            $sql = 'DELETE FROM search_scheduler WHERE id = (?)';
            $this->getDb()->query($sql, [$id]);
            $sql = 'DELETE FROM search_history WHERE searchId = (?)';
            $this->getDb()->query($sql, [$id]);

            return $this->redirect('./');
        }

        $this->badMethodAction();
    }

    protected function notificationsAction()
    {
        $sql = 'SELECT id, lastResults FROM search_scheduler WHERE notified = 0';
        $result = $this->getDb()->query($sql, DbAdapter::QUERY_MODE_EXECUTE)->toArray();

        if ($result) {
            $this->getDb()->query('UPDATE search_scheduler SET notified = 1 WHERE notified = 0', DbAdapter::QUERY_MODE_EXECUTE);
        }

        $this->emitResponse(new \Zend\Diactoros\Response\JsonResponse($result));
    }

    protected function notFoundAction()
    {
        $this->emitResponse(new \Zend\Diactoros\Response\HtmlResponse($this->getTwig()->render('errors/404.twig')));
    }

    protected function badMethodAction()
    {
        $this->emitResponse(new \Zend\Diactoros\Response\HtmlResponse($this->getTwig()->render('errors/400.twig')));
    }

    /**
     * @return array
     */
    protected function getSearches()
    {
        $sql = 'SELECT id, name, params, status, lastExecution, lastProcessedCount FROM search_scheduler';
        $result = $this->getDb()->query($sql, DbAdapter::QUERY_MODE_EXECUTE)->toArray();

        return $result;
    }

    /**
     * @return DbAdapter
     */
    protected function getDb()
    {
        if (!$this->db) {
            $this->db = new DbAdapter(Config::get('db'));
        }

        return $this->db;
    }

    /**
     * @return Twig_Environment
     */
    protected function getTwig()
    {
        if (!$this->twig) {
            $this->twig = new \Twig_Environment(new \Twig_Loader_Filesystem(realpath(__DIR__ . self::TWIG_DIR)));
        }

        return $this->twig;
    }

    protected function emitResponse(\Zend\Diactoros\Response $response)
    {
        (new \Zend\Diactoros\Response\SapiEmitter)->emit($response);
    }

    protected function redirect($location)
    {
        $this->emitResponse(new \Zend\Diactoros\Response\RedirectResponse($this->basePath . $location));
    }
}
