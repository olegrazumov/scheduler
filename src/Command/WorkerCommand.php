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
    const VINNUMBER_PATTERN = '[A-ZА-Я0-9]{12}\d{5}';

    const SHARE_PROPERTY_PATTERN = 'дол(я|и|евой)';

    const PTYPE_CAR = '1';

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
    const AVITO_SEARCH_REALTY_URL = 'https://www.avito.ru/rossiya/kvartiry/prodam';
    const AVITO_SEARCH_CAR_URL = 'https://www.avito.ru/rossiya/avtomobili';

    const GOOGLE_URL = 'https://www.google.ru';
    const GOOGLE_MAPS_SEARCH_URL = 'https://www.google.ru/maps/search';

    const VIN_AUTO_URL = 'http://vin.auto.ru/resolve.html';

    const POGAZAM_URL = 'http://pogazam.ru/vin';

    const CENAMASHIN_URL = 'http://cenamashin.ru/cena';

    protected static $curlOptions = [
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_COOKIESESSION => true,
        CURLOPT_COOKIEJAR => '/tmp/curl_cookie',
        CURLOPT_COOKIEFILE => '/tmp/curl_cookie',
        CURLOPT_USERAGENT => 'Mozilla/5.0 (X11; Ubuntu; Linux x86_64; rv:50.0) Gecko/20100101 Firefox/50.0',
        CURLOPT_HEADERFUNCTION => [self::class, 'parseHeaders'],
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false,
        CURLOPT_SSLVERSION => CURL_SSLVERSION_TLSv1,
        CURLOPT_SSL_CIPHER_LIST => 'SSLv3',
//        CURLOPT_PROXY => 'socks5://37.57.117.41:45554',
//        CURLOPT_VERBOSE => true,
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

    protected static $avitoCarYearsParams = [
        1960 => 771, 1970 => 782, 1980 => 873, 1985 => 878, 1990 => 883, 1991 => 884,
        1992 => 885, 1993 => 886, 1994 => 887, 1995 => 888, 1996 => 889, 1997 => 890,
        1998 => 891, 1999 => 892, 2000 => 893, 2001 => 894, 2002 => 895, 2003 => 896,
        2004 => 897, 2005 => 898, 2006 => 899, 2007 => 900, 2008 => 901, 2009 => 902,
        2010 => 2844, 2011 => 2845, 2012 => 6045, 2013 => 8581, 2014 => 11017, 2015 => 13978,
        2016 => 16381,
    ];

    protected static $filterAddressKeywords = [
        'кв.', 'товарищество', 'тов.', 'помещение', 'пом.', 'район', 'р-н', 'снт',
        'участок', 'уч.', 'с/с', 'мкр',
    ];

    protected static $filterLotKeywords = [
        'прицеп', 'полуприцеп', 'тягач', 'автопогрузчик', 'трактор', 'грузовой', 'экскаватор',
        'фургон', 'кран', 'автофургон', 'фургон', 'комбайн',
    ];

    protected $torgiCookies = [];

    protected $searchHistory = [];

    private $ch;

    private $twig;

    public function execute(array $search)
    {
        $this->loadSearchHistory($search['id']);
        $data = $this->getData($search);
        $results = [];

        if ($data['foundedObjects']) {
            $msg = $this->getFoundedObjectsMessage($data['foundedObjects']);
            $msg['subject'] = 'Результат поиска ' . ($search['name'] ? '"' . $search['name'] .'"' : '');

            if (empty($search['params']['openResultsInBrowser'])) {
                $this->sendMail($search['params']['emails'], $msg);
            }

            $results['foundedObjects'] = $msg;
        }

        if ($data['notFoundedObjects']) {
            $msg = $this->getNotFoundedObjectsMessage($data['notFoundedObjects']);
            $msg['subject'] = 'Обьекты, не найденные в Росреестре по поиску ' . ($search['name'] ? '"' . $search['name'] .'"' : '');

            if (empty($search['params']['openResultsInBrowser'])) {
                $this->sendMail($search['params']['emails'], $msg);
            }

            $results['notFoundedObjects'] = $msg;
        }

        $results['processedObjectsCount'] = $data['lotsCount'];

        return $results;
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
            $lot['reestr'] = [];

            foreach ($lot['matches'] as $lotNumber) {
                if (preg_match('/' . self::VINNUMBER_PATTERN . '/', $lotNumber, $vinMatch)) {
                    $lot['car'] = $this->parseCarData($vinMatch[0], $lot);
                } else {
                    $reestrData = $this->getReestrData($lotNumber);

                    if ($reestrData) {
                        $lot['reestr'][$lotNumber] = $reestrData;
                    }
                }
            }

            if (!empty($lot['car'])) {
                $lot['avito'] = $this->getAvitoCarData($lot);
                $lot['cenamashin'] = $this->getCenamashinData($lot);
            } elseif ([self::PTYPE_CAR] != $search['params']['type']) {
                $lot['avito'] = $this->getAvitoRealtyData($lot);
            }

            if (($lot['reestr'] && !$this->isShareProperty($lot)) || (!empty($lot['cenamashin']['cenamashinPrice'])) && !$this->isFilteredLot($lot)) {
                $result['foundedObjects'][$lot['info']['propertyType']][] = $lot;
            } else {
                $result['notFoundedObjects'][$lot['info']['propertyType']][] = $lot;
            }

            usleep(mt_rand(1000000, 2000000));
        }

        $this->closeCurl();

        foreach ($result as &$resultByType) {
            foreach ($resultByType as &$lotsByPropertyType) {
                foreach ($lotsByPropertyType as &$lot) {
                    $lot['cadSum'] = 0;

                    foreach ($lot['reestr'] as $lotReestr) {
                        $lot['cadSum'] += $lotReestr['cadPrice'];
                    }

                    if (isset($lot['avito']['avitoPrice'])) {
                        $lot['avito']['avitoDelta'] = $lot['avito']['avitoPrice'] - $lot['info']['startPrice'];
                    }

                    if (isset($lot['cenamashin']['cenamashinPrice'])) {
                        $lot['cadDelta'] = $lot['cenamashin']['cenamashinPrice'] - $lot['info']['startPrice'];
                    } elseif ($lot['reestr']) {
                        $lot['cadDelta'] = $lot['cadSum'] - $lot['info']['startPrice'];
                    }
                }
            }
        }

        foreach ($result['foundedObjects'] as &$lotsByPropertyType) {
            $lotsWithCadDelta = [];
            $lotsWithoutCadDelta = [];

            foreach ($lotsByPropertyType as $lotToSort) {
                if (!isset($lotToSort['cadDelta'])) {
                    $lotsWithoutCadDelta[] = $lotToSort;
                } else {
                    $lotsWithCadDelta[] = $lotToSort;
                }
            }

            usort($lotsWithCadDelta, function($a, $b) {
                return $a['cadDelta'] < $b['cadDelta'] ? 1 : -1;
            });

            $lotsByPropertyType = array_merge($lotsWithCadDelta, $lotsWithoutCadDelta);
        }

        foreach ($result['notFoundedObjects'] as &$lotsByPropertyType) {
            $lotsWithAvitoDelta = [];
            $lotsWithoutAvitoDelta = [];

            foreach ($lotsByPropertyType as $lotToSort) {
                if (!isset($lotToSort['avito']['avitoDelta'])) {
                    $lotsWithoutAvitoDelta[] = $lotToSort;
                } else {
                    $lotsWithAvitoDelta[] = $lotToSort;
                }
            }

            usort($lotsWithAvitoDelta, function($a, $b) {
                return $a['avito']['avitoDelta'] < $b['avito']['avitoDelta'] ? 1 : -1;
            });

            $lotsByPropertyType = array_merge($lotsWithAvitoDelta, $lotsWithoutAvitoDelta);
        }

        ksort($result['foundedObjects']);
        ksort($result['notFoundedObjects']);

        $this->saveSearchHistory($lots);

        $result['lotsCount'] = count($lots);

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

    protected function getAvitoCarData(array $lot)
    {
        $result = [];

        $queryParams = [
            's' => 1,
        ];

        if (array_key_exists('year', $lot['car'])) {
            foreach (array_reverse(self::$avitoCarYearsParams, true) as $minYear => $minYearParam) {
                if ($minYear <= (int)$lot['car']['year']) {
                    $yearQuery = '188_' . $minYearParam;
                    foreach (self::$avitoCarYearsParams as $maxYear => $maxYearParam) {
                        if ($maxYear >= (int)$lot['car']['year']) {
                            $yearQuery .= 'b' . $maxYearParam;
                            break;
                        }
                    }
                    $queryParams['f'] = $yearQuery;
                    break;
                }
            }
        }

        $queryParams['q'] = (isset($lot['car']['brand']) ? $lot['car']['brand'] : '') . ' ' . (isset($lot['car']['model']) ? $lot['car']['model'] : '');

        if (!trim($queryParams['q'])) {
            return $result;
        }

        $searchUrl = self::AVITO_SEARCH_CAR_URL . '?' . http_build_query($queryParams);
        $response = $this->loadUrl($searchUrl);
        $crawler = new Crawler($response);
        $searchResult = $crawler->filter('.item_table .about');

        if ($searchResult->count()) {
            $prices = [];

            $crawler->filter('.item_table .about')->each(function(Crawler $node) use (&$prices) {
                if ($node->filter('.param > span')->count() && false !== mb_strpos($node->filter('.param > span')->eq(0)->text(), 'Битый')) {
                    return;
                }

                $prices[] = (float)str_replace(' ', '', $node->text());
            });

            $result['avitoPrice'] = min($prices);
            $result['avitoUrl'] = $searchUrl;
        } 

        return $result;
    }

    protected function getAvitoRealtyData(array $lot)
    {
        $result = [];

        $queryParams = [
            's' => 1,
        ];

        $reestrInfo = array_shift($lot['reestr']);
        $square = false;

        if ($reestrInfo && array_key_exists('square', $reestrInfo)) {
            $square = $reestrInfo['square'];
        } else {
            
        }

        if ($square) {
            foreach (array_reverse(self::$avitoSquareParams, true) as $minSquare => $minSquareParam) {
                if ($minSquare <= $square) {
                    $squareQuery = '59_' . $minSquareParam;
                    foreach (self::$avitoSquareParams as $maxSquare => $maxSquareParam) {
                        if ($maxSquare >= $square) {
                            $squareQuery .= 'b' . $maxSquareParam;
                            break;
                        }
                    }
                    $queryParams['f'] = $squareQuery;
                    break;
                }
            }
        }

        foreach ([$this->filterAddress($lot['info']['locationFull']), $lot['info']['location']] as $address) {
            $queryParams['q'] = $address;
            $searchUrl = self::AVITO_SEARCH_REALTY_URL . '?' . http_build_query($queryParams);
            $response = $this->loadUrl($searchUrl);
            $crawler = new Crawler($response);
            $searchResult = $crawler->filter('.item_table .about');

            if ($searchResult->count()) {
                $result['avitoPrice'] = (float)str_replace(' ', '', $crawler->filter('.item_table .about')->eq(0)->text());
                $result['avitoUrl'] = $searchUrl;
                break;
            } else {
                usleep(mt_rand(2000000, 3000000));
            }
        }

        return $result;
    }

    protected function parseCarData($vin, array $lot)
    {
        $result = [
            'vin' => $vin,
            'brand' => '',
            'year' => '',
            'model' => '',
        ];

        $shortInfo = mb_strtolower($lot['shortInfo']);
        $pogazamCrawler = new Crawler($this->loadUrl(self::POGAZAM_URL . '/?vin=' . $vin));
 
        if ($pogazamCrawler->filter('#table_result_search tr')->count()) {
            $resultnum = (int)$pogazamCrawler->filter('#table_result_search tr')->last()->filter('td')->eq(0)->text();
            $makenum = 1;

            do {
                $pogazamInfoCrawler = new Crawler($this->loadUrl(self::POGAZAM_URL . '/?vin=' . $vin . ($makenum ? '&makenum=' . $makenum : '')));
                $brandFound = false;
                $pogazamInfoCrawler->filter('#table_result_search tr')->each(function(Crawler $node) use (&$result, &$brandFound, $shortInfo, $resultnum) {
                    if (false !== mb_strpos($node->filter('td')->eq(0)->text(), 'Марка')) {
                        $brand = mb_strtolower(preg_replace('/значение не определено/ui', '', trim($node->filter('td')->eq(1)->text())));
                        $brandParts = preg_split('/ -/', $brand);
                        $partExists = false;
                        foreach ($brandParts as $part) {
                            if ($part && false !== mb_strpos($shortInfo, $part)) {
                                $partExists = true;
                                break;
                            }
                        }
                        if ($brand && ($partExists || !$resultnum)) {
                            $result['brand'] = str_replace(' ', '-', $brand);
                            $brandFound = true;
                        }
                    } elseif ($result['brand'] && !$result['year'] && false !== mb_strpos($node->filter('td')->eq(0)->text(), 'Модельный год')) {
                        $result['year'] = preg_replace('/значение не определено/ui', '', trim($node->filter('td')->eq(1)->text()));
                    } elseif ($result['brand'] && !$result['year'] && false !== mb_strpos($node->filter('td')->eq(0)->text(), 'Период производства') && empty($result['year']) && preg_match('/\d{4}/', trim($node->filter('td')->eq(1)->text()), $yearMatch)) {
                        $result['year'] = $yearMatch[0];
                    } elseif ($result['brand'] && !$result['model'] && false !== mb_strpos($node->filter('td')->eq(0)->text(), 'Модель')) {
                        $model = mb_strtolower(preg_replace('/значение не определено/ui', '', trim($node->filter('td')->eq(1)->text())));
                        $modelParts = preg_split('/ \//', $model);
                        $partExists = false;
                        foreach ($modelParts as $part) {
                            if ($part && false !== mb_strpos($shortInfo, $part)) {
                                $partExists = true;
                                break;
                            }
                        }
                        if ($model && $brandFound) {
                            $result['model'] = str_replace(' ', '-', $model);
                        }
                    }
                });
                $makenum++;
            } while ($makenum <= $resultnum);
        }

        if (!$result['brand'] || !$result['model']) {
            $crawler = new Crawler($this->loadUrl(self::VIN_AUTO_URL . '?vin=' . $vin));

            $crawler->filter('.def-list.md')->each(function(Crawler $dataNode) use (&$result, $shortInfo) {
                $index = 0;
                $dataNode->filter('dt')->each(function(Crawler $node) use (&$result, &$index, $dataNode, $shortInfo) {
                    if (!$result['brand'] && false !== mb_strpos($node->text(), 'Марка')) {
                        $brand = mb_strtolower(preg_replace('/значение не определено/ui', '', trim($dataNode->filter('dd')->eq($index)->text())));
                        if ($brand && false !== mb_strpos($shortInfo, $brand)) {
                            $result['brand'] = str_replace(' ', '-', $brand);
                        }
                    } elseif (!$result['year'] && false !== mb_strpos($node->text(), 'Модельный год')) {
                        $result['year'] = preg_replace('/значение не определено/ui', '', trim($dataNode->filter('dd')->eq($index)->text()));
                    } elseif (!$result['year'] && false !== mb_strpos($node->text(), 'Период производства') && empty($result['year']) && preg_match('/\d{4}/', trim($dataNode->filter('dd')->eq($index)->text()), $yearMatch)) {
                        $result['year'] = $yearMatch[0];
                    } elseif (!$result['model'] && false !== mb_strpos($node->text(), 'Модель')) {
                        $model = mb_strtolower(preg_replace('/значение не определено/ui', '', trim($dataNode->filter('dd')->eq($index)->text())));
                        $modelParts = explode(' ', $model);
                        $partExists = false;
                        foreach ($modelParts as $part) {
                            if ($part && false !== mb_strpos($shortInfo, $part)) {
                                $partExists = true;
                                break;
                            }
                        }
                        if ($model && $partExists) {
                            $result['model'] = str_replace(' ', '-', $model);
                        }
                    }
                    $index++;
                });
            });
        }

        if (preg_match('/\D*(?<year>\d{4})\s*г[\.]{0,1}в/u', $lot['shortInfo'], $yearMatch)) {
            $result['year'] = $yearMatch['year'];
        }

        return $result;
    }

    protected function searchLots(array $search = [])
    {
        $result = [];
        $searchParams = $search['params'];

        $this->loadUrl(self::TORGI_INIT_URL, ['curl' => [CURLOPT_FOLLOWLOCATION => false, CURLOPT_COOKIE => 'JSESSIONID=']]);

        if (!array_key_exists('JSESSIONID', $this->torgiCookies)) {
            return $result;
        }

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

            if ($xmlCrawler->filter('ajax-response > component')->count()) {
                $htmlCrawler->addHtmlContent($xmlCrawler->filter('ajax-response > component')->eq(self::TORGI_SEARCH_URL === $searchUrl ? 1 : 0)->text(), 'UTF-8');
            }

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

                preg_match_all('/' . self::CADNUMBER_PATTERN . '|' . self::CONNUMBER_PATTERN . '|' . self::VINNUMBER_PATTERN . '/u', $node->text(), $matches);

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
        $reestrBaseUrl = $reestrInitCrawler->filter('base')->count() ? $reestrInitCrawler->filter('base')->attr('href') : self::REESTR_URL;
        $reestrSearchUrl = $reestrBaseUrl . $reestrInitCrawler->filter('form')->eq(1)->attr('action');
        $reestrSearchResponse = $this->loadUrl($reestrSearchUrl, ['curl' => [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query($postData),
        ]]);
        $reestrSearchCrawler = new Crawler($reestrSearchResponse);
        $reestrSearchResults = $reestrSearchCrawler->filter('tr[id$=js_oTr0] a');
        $reestr = [];

        if ($reestrSearchResults->count()) {
            $reestrInfoUrl = $reestrBaseUrl . $reestrSearchResults->attr('href');
            $reestrInfoResponse = $this->loadUrl($reestrInfoUrl);
            $reestrInfoCrawler = new Crawler;
            $reestrInfoCrawler->addHtmlContent($reestrInfoResponse);
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

    protected function loadSearchHistory($searchId)
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

    protected function closeCurl()
    {
        curl_close($this->ch);
        $this->ch = null;
    }

    protected function isFilteredLot(array $lot)
    {
        foreach (self::$filterLotKeywords as $word) {
            if (false !== mb_strpos($lot['shortInfo'], $word)) {
                return true;
            }
        }

        return false;
    }

    protected function getCenamashinData(array $lot)
    {
        $result = [];

        if (empty($lot['car']['brand'])) {
            return $result;
        }

        $searchUrl = self::CENAMASHIN_URL . '/' . $lot['car']['brand'];

        if (!empty($lot['car']['model'])) {
            $searchUrl .= '/' . $lot['car']['model'];

            if (!empty($lot['car']['year'])) {
                $searchUrl .= '/' . $lot['car']['year'];
            }
        }

        $response = $this->loadUrl($searchUrl);
        $crawler = new Crawler($response);
        $searchResult = $crawler->filter('.cornerText_o font');

        if ($searchResult->count()) {
            if (preg_match('/от(?<minPrice>\d+)до/ui', preg_replace('/\s/','', $crawler->filter('.cornerText_o div')->text()), $matchPrice)) {
                $result['cenamashinPrice'] = $matchPrice['minPrice'];
            }
            $result['cenamashinPriceAvg'] = (int)preg_filter('/[^\d]/u', '', $searchResult->eq(0)->text());
            $result['cenamashinUrl'] = $searchUrl;
        }

        return $result;
    }
}
