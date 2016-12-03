<?php

namespace Web\Command;

use Symfony\Component\DomCrawler\Crawler;
use Zend\Db\Adapter\Adapter as DbAdapter;
use Web\Config as Config;

class WorkerCommand extends \CLIFramework\Command
{
    const TWIG_DIR = '/../../twig/mails';

    const CADNUMBER_PATTERN = '\d+:\d+:\d+:\d+';
    const CONNUMBER_PATTERN = '\d+-\d+-\d+\/\d+\/\d+-\d+';
    const SHARE_PROPERTY_PATTERN = 'дол(я|и|евой)';

    const TORGI_URL = 'https://torgi.gov.ru';
    const TORGI_INIT_URL = 'https://torgi.gov.ru/lotSearch1.html?bidKindId=13';
    const TORGI_INIT_SEARCH_URL = 'https://torgi.gov.ru/;jsessionid=%JSESSIONID%?wicket:interface=:0:search_panel:buttonsPanel:show_extended::IActivePageBehaviorListener:0:&wicket:ignoreIfNotActive=true&random=0.3480220019065243';
    const TORGI_SEARCH_URL = 'https://torgi.gov.ru/lotSearch1.html?wicket:interface=:0:search_panel:buttonsPanel:search::IActivePageBehaviorListener:0:&wicket:ignoreIfNotActive=true&random=0.004927608748009571';
    const TORGI_SEARCH_INIT_PTYPES_URL = 'https://torgi.gov.ru/lotSearch1.html?wicket:interface=:0:search_panel:search_lot_panel:search_form:extended:propertyTypes:link::IBehaviorListener:0:-1&random=0.9423911924014701';
    const TORGI_SEARCH_PTYPES_URL = 'https://torgi.gov.ru/lotSearch1.html?wicket:interface=:0:search_panel:search_lot_panel:search_form:extended:propertyTypes:multiSelectPopup:content:buttons:save::IActivePageBehaviorListener:0:-1&wicket:ignoreIfNotActive=true&random=0.5071652749802144';
    const TORGI_SEARCH_NEXT_URL = 'https://torgi.gov.ru/lotSearch1.html?wicket:interface=:0:search_panel:resultTable:list:bottomToolbars:2:toolbar:span:navigator:next::IBehaviorListener:0:-1&random=0.07301306954381293';

    const REESTR_URL = 'https://rosreestr.ru';
    const REESTR_INIT_URL = 'https://rosreestr.ru/wps/portal/p/cc_ib_portal_services/online_request';

    const AVITO_URL = 'https://www.avito.ru';
    const AVITO_SEARCH_URL = 'https://www.avito.ru/rossiya/kvartiry/prodam';

    CONST GOOGLE_URL = 'https://www.google.ru';
    CONST GOOGLE_MAPS_SEARCH_URL = 'https://www.google.ru/maps/search';

    protected static $curlOptions = [
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_COOKIESESSION => true,
        CURLOPT_COOKIEJAR => '/tmp/curl_cookie',
        CURLOPT_COOKIEFILE => '/tmp/curl_cookie',
        CURLOPT_USERAGENT => 'Mozilla/5.0 (X11; Ubuntu; Linux x86_64; rv:50.0) Gecko/20100101 Firefox/50.0',
        CURLOPT_HEADERFUNCTION => [self::class, 'parseHeaders'],
        CURLOPT_VERBOSE => true,
//        CURLOPT_PROXY => 'socks5://37.57.117.41:45554',
//        CURLOPT_SSL_VERIFYHOST => false,
//        CURLOPT_SSL_VERIFYPEER => false,
//        CURLOPT_SSLVERSION => CURL_SSLVERSION_TLSv1,
//        CURLOPT_SSL_CIPHER_LIST => 'SSLv3',
    ];

    protected static $torgiSearchParams = [
        'priceFrom' => 'extended:stringPriceFrom',
        'priceTo' => 'extended:stringPriceTo',
        'kladrId' => 'extended:kladrIdStr',
        'status' => 'extended:bidNumberExtended:bidStatusId',
        'type' => 'extended:propertyTypes:multiSelectPopup:content:panel:container:checkListForm:list:%PTYPE%:selected',
    ];

    protected static $avitoSquareParams = [
        10 => 13983, 15 => 13984, 20 => 13985, 25 => 13986, 30 => 13987, 40 => 13988,
        50 => 13989, 60 => 13990, 70 => 13991, 80 => 13992, 90 => 13993, 100 => 13994,
        110 => 13995, 120 => 13996, 130 => 13997, 140 => 13998, 150 => 13999, 160 => 14000,
        170 => 14001, 180 => 14002, 190 => 14003, 200 => 14004,
    ];

    protected static $filterAddressKeywords = [
        'кв.', 'товарищество', 'тов.', 'помещение', 'пом.', 'район', 'р-н', 'снт',
        'участок', 'уч.', 'с/с',
    ];

    protected $torgiCookies = [];

    protected $searchHistory = [];

    private $ch;

    private $twig;

    public function execute(array $search)
    {
        $this->loadSeachHistory($search['id']);
        $data = $this->getData($search);

        if ($search['params']['emails']) {
            if ($data['foundedObjects']) {
                $msg = $this->getFoundedObjectsMessage($data['foundedObjects']);
                $msg['subject'] = 'Результат поиска ' . ($search['name'] ? '"' . $search['name'] .'"' : '');
                $this->sendMail($search['params']['emails'], $msg);
            }

            if ($data['notFoundedObjects']) {
                $msg = $this->getNotFoundedObjectsMessage($data['notFoundedObjects']);
                $msg['subject'] = 'Обьекты, не найденные в Росреестре по поиску ' . ($search['name'] ? '"' . $search['name'] .'"' : '');
                $this->sendMail($search['params']['emails'], $msg);
            }
        }
    }

    protected function getData(array $search)
    {
        $result = [
            'foundedObjects' => [],
            'notFoundedObjects' => [],
        ];

        $lots = $this->searchLots($search);

        foreach ($lots as &$lot) {
            $lot['info'] = $this->getLotData($lot['lotUrl']);
            $lot['gmapsUrl'] = $this->getGoogleMapsUrl($lot);

            foreach ($lot['matches'] as $lotNumber) {
                $reestrData = $this->getReestrData($lotNumber);

                if ($reestrData) {
                    $lot['reestr'][$lotNumber] = $reestrData;
                }

                usleep(mt_rand(500000, 1000000));
            }

            $lot['avito'] = $this->getAvitoData($lot);

            if ($lot['reestr'] && !$this->isShareProperty($lot)) {
                $result['foundedObjects'][$lot['info']['propertyType']][] = $lot;
            } else {
                $result['notFoundedObjects'][$lot['info']['propertyType']][] = $lot;
            }

            usleep(mt_rand(1000000, 2000000));
        }

        foreach ($result as &$resultByType) {
            foreach ($resultByType as &$lotsByPropertyType) {
                foreach ($lotsByPropertyType as &$lot) {
                    $lot['cadSum'] = 0;

                    foreach ($lot['reestr'] as $lotReestr) {
                        $lot['cadSum'] += $lotReestr['cadPrice'];
                    }

                    $lot['cadDelta'] = $lot['cadSum'] - $lot['info']['startPrice'];

                    if ($lot['avito']) {
                        $lot['avito']['avitoDelta'] = $lot['avito']['avitoPrice'] - $lot['info']['startPrice'];
                    }
                }
            }
        }

        foreach ($result['foundedObjects'] as &$lotsByPropertyType) {
            usort($lotsByPropertyType, function($a, $b) {
                return $a['cadDelta'] < $b['cadDelta'] ? 1 : -1;
            });
        }

        foreach ($result['notFoundedObjects'] as &$lotsByPropertyType) {
            usort($lotsByPropertyType, function($a, $b) {
                if (!array_key_exists('avitoDelta', $a['avito']) || !array_key_exists('avitoDelta', $b['avito'])) {
                    return 1;
                }

                return $a['avito']['avitoDelta'] < $b['avito']['avitoDelta'] ? 1 : -1;
            });
        }

        ksort($result['foundedObjects']);
        ksort($result['notFoundedObjects']);

        $this->saveSearchHistory($lots);

        return $result;
    }

    protected function getLotData($lotUrl)
    {
        $result = [
            'allData' => [],
        ];
        $response = $this->loadUrl($lotUrl);
        $crawler = new Crawler;
        $crawler->addHtmlContent(str_replace('<?xml version="1.0" encoding="UTF-8"?>', '', $response), 'UTF-8');
        $crawler->filter('.tabpanels-container > div > div > div > table > tr')->each(function($node) use (&$result, $lotUrl) {
            if ($node->filter('td')->count() < 2) {
                return;
            }
            if (false !== mb_strpos($node->text(), 'окончания подачи заявок')) {
                $result['applicationsEndDate'] = trim($node->filter('td')->eq(1)->text());
            }
            $result['allData'][trim($node->filter('td')->eq(0)->text())] = trim($node->filter('td')->eq(1)->text());
        });
        $crawler->filter('.tabpanels-container > div > div > table > tr')->each(function($node) use (&$result, $lotUrl) {
            if ($node->filter('td')->count() < 2) {
                return;
            }
            if (false !== mb_strpos($node->text(), 'Тип имущества')) {
                $result['propertyType'] = trim($node->filter('td')->eq(1)->text());
            } elseif (false !== mb_strpos($node->text(), 'Место нахождения')) {
                $result['location'] = trim($node->filter('td')->eq(1)->text());
            } elseif (false !== mb_strpos($node->text(), 'Детальное местоположение')) {
                $result['locationFull'] = trim($node->filter('td')->eq(1)->text());
            } elseif (false !== mb_strpos($node->text(), 'Начальная цена') || false !== mb_strpos($node->text(), 'Начальная продажная цена')) {
                $filterPrice = $node->filter('td')->eq(1)->text();
                preg_match('/\((?<rub>.+)\)/', $filterPrice, $matchPrice);
                $result['startPrice'] = (float)preg_replace('/\s/u', '', !empty($matchPrice['rub']) ? $matchPrice['rub'] : $filterPrice);
            }
            $result['allData'][trim($node->filter('td')->eq(0)->text())] = trim($node->filter('td')->eq(1)->text());
        });

        return $result;
    }

    protected function getAvitoData(array $lot)
    {
        $result = [];

        $queryParams = [
            's' => 1,
        ];

        $reestrInfo = array_shift($lot['reestr']);

        if ($reestrInfo && array_key_exists('square', $reestrInfo)) {
            foreach (array_reverse(self::$avitoSquareParams, true) as $minSquare => $minSquareParam) {
                if ($minSquare <= $reestrInfo['square']) {
                    $squareQuery = '59_' . $minSquareParam;
                    foreach (self::$avitoSquareParams as $maxSquare => $maxSquareParam) {
                        if ($maxSquare >= $reestrInfo['square']) {
                            $squareQuery .= 'b' . $maxSquareParam;
                            break;
                        }
                    }
                    $queryParams['f'] = $squareQuery;
                    break;
                }
            }
        }

        foreach ([$this->filterAddress($lot['info']['locationFull']), $lot['info']['locationFull'], $lot['info']['location']] as $address) {
            $queryParams['q'] = $address;
            $searchUrl = self::AVITO_SEARCH_URL . '?' . http_build_query($queryParams);
            $response = $this->loadUrl($searchUrl);
            $crawler = new Crawler($response);
            $searchResult = $crawler->filter('.item_table .about');

            if ($searchResult->count()) {
                $result['avitoPrice'] = (float)str_replace(' ', '', $crawler->filter('.item_table .about')->eq(0)->text());
                $result['avitoUrl'] = $searchUrl;
                break;
            } else {
                usleep(mt_rand(3000000, 4000000));
            }
        }

        return $result;
    }

    protected function searchLots(array $search = [])
    {
        $result = [];
        $searchParams = $search['params'];

        $this->loadUrl(self::TORGI_INIT_URL, ['curl' => [CURLOPT_FOLLOWLOCATION => false]]);
        $this->loadUrl(str_replace('%JSESSIONID%', $this->torgiCookies['JSESSIONID'], self::TORGI_INIT_SEARCH_URL));

        if ($searchParams['type']) {
            $this->loadUrl(self::TORGI_SEARCH_INIT_PTYPES_URL, ['curl' => [
                CURLOPT_HTTPHEADER => ['Wicket-Ajax: true'],
            ]]);

            $postTypes = [
                'extended:propertyTypes:multiSelectPopup:content:buttons:save' => 1,
                'extended:propertyTypes:multiSelectPopup:content:panel:container:checkListForm:dummyInput' => '',
            ];

            foreach ($searchParams['type'] as $type) {
                $postTypes[str_replace('%PTYPE%', $type, self::$torgiSearchParams['type'])] = 'on';
            }

            $this->loadUrl(self::TORGI_SEARCH_PTYPES_URL, ['curl' => [
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => http_build_query($postTypes),
            ]]);
        }

        $searchPost = [];

        if (array_key_exists('priceFrom', $searchParams)) {
            $searchPost[self::$torgiSearchParams['priceFrom']] = $searchParams['priceFrom'];
        }

        if (array_key_exists('priceTo', $searchParams)) {
            $searchPost[self::$torgiSearchParams['priceTo']] = $searchParams['priceTo'];
        }

        if (array_key_exists('status', $searchParams)) {
            $searchPost[self::$torgiSearchParams['status']] = $searchParams['status'];
        }

        if (array_key_exists('kladrId', $searchParams)) {
            $searchPost[self::$torgiSearchParams['kladrId']] = $searchParams['kladrId'];
        }

        $searchUrl = self::TORGI_SEARCH_URL;

        do {
            $nextPage = false;
            $curlOptions = [];

            if (self::TORGI_SEARCH_URL === $searchUrl) {
                $curlOptions[CURLOPT_POST] = true;
                $curlOptions[CURLOPT_POSTFIELDS] = http_build_query($searchPost);
            }

            $searchResponse = $this->loadUrl($searchUrl, ['curl' => $curlOptions]);

            $xmlCrawler = new Crawler($searchResponse);
            $htmlCrawler = new Crawler;
            $htmlCrawler->addHtmlContent($xmlCrawler->filter('ajax-response > component')->eq(self::TORGI_SEARCH_URL === $searchUrl ? 1 : 0)->text(), 'UTF-8');
            $htmlCrawler->filter('tbody tr')->each(function(Crawler $node) use (&$result, $search) {
                $lotUrl = '';

                $node->filter('.action-panel a')->each(function(Crawler $node) use (&$lotUrl) {
                    if (false !== strpos($node->attr('href'), 'restricted/notification/notificationView')) {
                        $lotUrl = self::TORGI_URL . '/' . $node->attr('href');
                    }
                });

                if (!$lotUrl || !$this->isNewLot($search['id'], $lotUrl)) {
                    return;
                }

                preg_match_all('/' . self::CADNUMBER_PATTERN . '|' . self::CONNUMBER_PATTERN . '/', $node->text(), $matches);

                $lot = [
                    'searchId' => $search['id'],
                    'lotUrl' => $lotUrl,
                    'matches' => $matches[0] ?: [],
                    'reestr' => [],
                    'shortInfo' => trim($node->filter('td')->eq(3)->text()),
                ];

                $result[] = $lot;
            });

            $htmlCrawler->filter('.navigation a')->each(function(Crawler $node) use (&$nextPage) {
                if (false !== strpos($node->text(), 'Вперед')) {
                    $nextPage = true;
                }
            });

            if ($nextPage) {
                $searchUrl = self::TORGI_SEARCH_NEXT_URL;
                usleep(mt_rand(500000, 1000000));
            }
        } while ($nextPage);

        return $result;
    }

    protected function getReestrData($objNumber)
    {
        $result = [];
        $postData = [
            'search_action' => 'true',
        ];

        if (preg_match('/' . self::CADNUMBER_PATTERN . '/', $objNumber)) {
            $postData['cad_num'] = $objNumber;
            $postData['search_type'] = 'CAD_NUMBER';
        } else {
            $postData['obj_num'] = $objNumber;
            $postData['search_type'] = 'OBJ_NUMBER';
        }

        $reestrInitResponse = $this->loadUrl(self::REESTR_INIT_URL);
        $reestrInitCrawler= new Crawler($reestrInitResponse);
        $reestrSearchUrl = self::REESTR_URL . $reestrInitCrawler->filter('form')->eq(1)->attr('action');
        $reestrSearchResponse = $this->loadUrl($reestrSearchUrl, ['curl' => [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query($postData),
        ]]);
        $reestrSearchCrawler = new Crawler($reestrSearchResponse);
        $reestrSearchResults = $reestrSearchCrawler->filter('tr[id$=_js_oTr0] a');

        $reestr = [];
        if ($reestrSearchResults->count()) {
            $reestrInfoUrl = self::REESTR_URL . $reestrSearchResults->attr('href');
            $reestrInfoResponse = $this->loadUrl($reestrInfoUrl);
            $reestrInfoCrawler = new Crawler($reestrInfoResponse);
            $reestrInfoCrawler->filter('tr')->each(function($node) use (&$reestr) {
                if (false !== mb_strpos($node->text(), 'Площадь')) {
                    $reestr['square'] = trim($node->filter('td')->eq(1)->text());
                } elseif (false !== mb_strpos($node->text(), 'Кадастровая стоимость')) {
                    $reestr['cadPrice'] = (float)trim($node->filter('td')->eq(1)->text());
                } elseif (false !== mb_strpos($node->text(), 'Адрес')) {
                    $reestr['address'] = trim($node->filter('td')->eq(1)->text());
                }
            });
            $reestr['reestrUrl'] = $reestrInfoUrl;
        }

        if (!empty($reestr['cadPrice'])) {
            $result = $reestr;
        }

        return $result;
    }

    protected function sendMail(array $emails, $message)
    {
        $message['sender'] = 'torgiSearch@domain.name';
        $this->getApplication()->getCommand('mailer')->executeWrapper([$emails, $message]);
    }

    protected function getFoundedObjectsMessage(array $data)
    {
        return [
            'html' => $this->getTwig()->render('search_result.twig', ['data' => $data]),
        ];
    }

    protected function getNotFoundedObjectsMessage(array $data)
    {
        return [
            'html' => $this->getTwig()->render('search_result.twig', ['data' => $data]),
        ];
    }

    protected function loadUrl($url, array $params = [])
    {
        $curl = $this->getCurl();
        $options = self::$curlOptions;

        if (array_key_exists('curl', $params)) {
            $options = array_replace($options, $params['curl']);
        }

        $options[CURLOPT_URL] = $url;
        curl_setopt_array($curl, $options);
        $result = curl_exec($curl);

        return $result;
    }

    protected function getCurl()
    {
        if (!$this->ch) {
            $this->ch = curl_init();
        } else {
            curl_reset($this->ch);
        }

        return $this->ch;
    }

    protected function parseHeaders($ch, $headerLine)
    {
        if (preg_match('/Set-Cookie: (?<cookie>.[^;]+);/', $headerLine, $match)) {
            list($key, $value) = explode('=', $match['cookie']);
            $this->torgiCookies[$key] = $value;
        }

        return strlen($headerLine);
    }

    protected function loadSeachHistory($searchId)
    {
        $this->searchHistory[$searchId] = [];
        $db = new DbAdapter(Config::get('db'));
        $db->query('DELETE FROM search_history WHERE searchId = (?) AND dateEnd < (?)', [$searchId, time()]);
        $sql = 'SELECT lotUrl FROM search_history WHERE searchId = (?)';
        $history = $db->query($sql, [$searchId])->toArray();

        foreach ($history as $item) {
            $this->searchHistory[$searchId][] = $item['lotUrl'];
        }
    }

    protected function isNewLot($searchId, $lotUrl)
    {
        return !in_array($lotUrl, $this->searchHistory[$searchId]);
    }

    protected function saveSearchHistory(array $lots)
    {
        if (!$lots) {
            return;
        }

        $db = new DbAdapter(Config::get('db'));
        $sql = 'INSERT INTO search_history (searchid, lotUrl, dateEnd) VALUES ';
        $values = [];

        foreach ($lots as $lot) {
            $values[] = '(' . $lot['searchId'] . ',' . $db->getPlatform()->quoteValue($lot['lotUrl']) . ',' . (int)strtotime($lot['info']['applicationsEndDate']) . ')';
        }

        $sql .= implode(',', $values);
        $db->query($sql, DbAdapter::QUERY_MODE_EXECUTE);
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

    protected function isShareProperty(array $lot)
    {
        return preg_match('/' . self::SHARE_PROPERTY_PATTERN . '/ui', $lot['shortInfo']);
    }

    protected function getGoogleMapsUrl(array $lot)
    {
        $address = urlencode($this->filterAddress($lot['info']['locationFull']));

        return self::GOOGLE_MAPS_SEARCH_URL . '/' . $address;
    }

    protected function filterAddress($address)
    {
        $result = '';
        $addressParts = explode(',', $address);

        foreach ($addressParts as $part) {
            if (preg_match('/^[А-Я]{1,3}$/u', $part)) {
                continue;
            }

            foreach (self::$filterAddressKeywords as $word) {
                if (false !== mb_strpos($part, $word)) {
                    continue 2;
                }
            }

            $result .= $part;
        }

        return trim(str_replace(',', '', $result));
    }
}