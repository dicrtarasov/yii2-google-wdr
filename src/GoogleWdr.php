<?php
/*
 * @copyright 2019-2020 Dicr http://dicr.org
 * @author Igor A Tarasov <develop@dicr.org>
 * @license MIT
 * @version 05.11.20 01:58:20
 */

declare(strict_types = 1);
namespace dicr\google\wdr;

use dicr\anticaptcha\AntiCaptchaModule;
use dicr\anticaptcha\method\CreateTaskRequest;
use dicr\anticaptcha\method\CreateTaskResponse;
use dicr\anticaptcha\method\GetTaskRequest;
use dicr\anticaptcha\method\GetTaskResponse;
use dicr\anticaptcha\task\NoCaptchaTaskProxyless;
use dicr\helper\StringHelper;
use Facebook\WebDriver\Exception\NoSuchElementException;
use Facebook\WebDriver\Remote\DesiredCapabilities;
use Facebook\WebDriver\Remote\RemoteWebDriver;
use Facebook\WebDriver\WebDriverBy;
use InvalidArgumentException;
use simplehtmldom\HtmlDocument;
use simplehtmldom\HtmlNode;
use Throwable;
use Yii;
use yii\base\Component;
use yii\base\Exception;
use yii\base\InvalidConfigException;

use function array_filter;
use function array_merge;
use function base64_encode;
use function count;
use function is_array;
use function is_string;
use function parse_url;
use function range;
use function sleep;
use function time;
use function trim;

use const PHP_URL_QUERY;

/**
 * Поисковик Google WDR.
 *
 * @property-read RemoteWebDriver $browser браузер
 */
class GoogleWdr extends Component
{
    /** @var string создание поисковой ссылки при помощи UULE */
    public const LINK_METHOD_UULE = 'uule';

    /** @var string создание поисковой ссылки при помощи Advert Preview Tool */
    public const LINK_METHOD_APT = 'apt';

    /** @var string адрес драйвера WebDriver */
    public $driverUrl;

    /** @var int задержка между запросами, сек */
    public $requestDelay = 2;

    /** @var array конфиг по-умолчанию запроса поиска */
    public $requestConfig = [];

    /** @var AntiCaptchaModule модуль решения капч */
    public $anticaptcha = 'anticaptcha';

    /** @var int время кеширования результатов запроса */
    public $cacheDuration = 86400;

    /** @var string метод создания поисковой ссылки */
    public $linkMethod = self::LINK_METHOD_UULE;

    /**
     * @inheritDoc
     * @throws InvalidConfigException
     */
    public function init() : void
    {
        parent::init();

        // адрес WebDriver
        if (empty($this->driverUrl)) {
            throw new InvalidConfigException('driverUrl');
        }

        // задержка запросов
        $this->requestDelay = (int)$this->requestDelay;
        if ($this->requestDelay < 0) {
            throw new InvalidConfigException('requestDelay');
        }

        // получаем модуль AntiCaptcha
        if (is_string($this->anticaptcha)) {
            $this->anticaptcha = Yii::$app->getModule($this->anticaptcha);
        } elseif (is_array($this->anticaptcha)) {
            // пытаемся получить модуль
            $this->anticaptcha = Yii::createObject($this->anticaptcha);

            // пытаемся получить компонент
            if (empty($this->anticaptcha)) {
                $this->anticaptcha = Yii::$app->get($this->anticaptcha);
            }
        }

        // проверяем модуль AntiCaptcha
        if (! $this->anticaptcha instanceof AntiCaptchaModule) {
            throw new InvalidConfigException('anticaptcha');
        }
    }

    /**
     * Получить/сохранить данные компонента.
     *
     * @param ?array $data если не null, то сохраняет новые данные
     * @return array текущие данные
     */
    private function moduleData(?array $data = null) : array
    {
        $key = [__CLASS__, $this->driverUrl];

        // получаем текущие данные
        $currentData = Yii::$app->cache->get($key) ?: [];

        // если заданы данные для установки
        if ($data !== null) {
            // добавляем новые данные
            $currentData = array_filter(array_merge($currentData, $data), static function ($val) : bool {
                return $val !== null;
            });

            // сохраняем новые данные
            Yii::$app->cache->set($key, $currentData);
        }

        // возвращаем текущие данные
        return $currentData;
    }

    /** @var RemoteWebDriver */
    private $_browser;

    /**
     * Подключается к браузеру
     *
     * @return RemoteWebDriver
     * @throws Exception
     */
    public function getBrowser() : RemoteWebDriver
    {
        // если уже имеется подключение к браузеру
        if ($this->_browser !== null) {
            try {
                // проверяем существующее подключение к браузеру
                $this->_browser->getCurrentURL();

                return $this->_browser;
            } catch (Throwable $ex) {
                // браузер уже закрыт
            }
        }

        // получаем данные сессии
        $sessionData = $this->moduleData();

        // попытка подключение к ранее открытому браузеру
        if (! empty($sessionData['sessionId'])) {
            try {
                $this->_browser = RemoteWebDriver::createBySessionID($sessionData['sessionId'], $this->driverUrl);

                // проверяем что браузер не закрыт
                $this->_browser->getCurrentURL();

                Yii::debug('Подключение к открытому браузеру: ' . $this->driverUrl .
                    ', sessionId=' . $sessionData['sessionId'], __METHOD__
                );

                return $this->_browser;
            } catch (Throwable $ex) {
                // браузер уже закрыт
            }
        }

        // открываем новый браузер
        try {
            $this->_browser = RemoteWebDriver::create(
                $this->driverUrl, DesiredCapabilities::chrome()
            );

            // сохраняем новую сессию открытого браузера в данных
            $this->moduleData(['sessionId' => $this->_browser->getSessionID()]);
        } catch (Throwable $ex) {
            $this->_browser = null;
            throw new Exception('Ошибка подключения к браузеру: ' . $this->driverUrl, 0, $ex);
        }

        return $this->_browser;
    }

    /**
     * Задержка между запросами.
     */
    public function pause() : void
    {
        if ($this->requestDelay > 0) {
            $sessionData = $this->moduleData();

            $lastTime = (int)($sessionData['lastRequestTime'] ?? 0);
            if ($lastTime > 0) {
                $pause = time() - $lastTime;
                if ($pause < $this->requestDelay) {
                    $pause = $this->requestDelay - $pause;
                    Yii::debug('Пауза: ' . $pause . ' сек', __METHOD__);
                    usleep((int)ceil($pause * 1000000));
                }
            }

            // сохраняем новое время последнего запроса
            $this->moduleData(['lastRequestTime' => time()]);
        }
    }

    /**
     * Проверяет и решает капчу на странице
     *
     * @param RemoteWebDriver $browser
     * @throws Exception
     */
    public function checkCaptcha(RemoteWebDriver $browser) : void
    {
        try {
            $captcha = $browser->findElement(WebDriverBy::id('recaptcha'));
        } /** @noinspection PhpRedundantCatchClauseInspection */
        catch (NoSuchElementException $ex) {
            // капчи нет, выходим
            return;
        }

        /** @var CreateTaskResponse $res задача решения капчи */
        $res = $this->anticaptcha
            ->request([
                'class' => CreateTaskRequest::class,
                'task' => new NoCaptchaTaskProxyless([
                    'websiteURL' => $browser->getCurrentURL(),
                    'websiteKey' => $captcha->getAttribute('data-sitekey')
                ])
            ])
            ->send();

        // решение капчи
        $gRecaptchaResponse = null;

        /** @var GetTaskRequest $req запрос решения капчи */
        $req = $this->anticaptcha->request([
            'class' => GetTaskRequest::class,
            'taskId' => $res->taskId
        ]);

        // делаем 3 попытки по 10 секунд получить решение капчи
        for ($i = 0; $i < 3; $i++) {
            // ждем 10 секунд
            sleep(10);

            // запрашиваем решение
            $res = $req->send();
            if ($res->status === GetTaskResponse::STATUS_READY && ! empty($res->solution['gRecaptchaResponse'])) {
                $gRecaptchaResponse = $res->solution['gRecaptchaResponse'];
                break;
            }
        }

        // проверяем готовность
        if ($gRecaptchaResponse === null) {
            throw new Exception('Капча не решена');
        }

        // находим текст для ввода ответа
        try {
            $responseElement = $captcha->findElement(WebDriverBy::id('g-recaptcha-response'));
        } /** @noinspection PhpRedundantCatchClauseInspection, BadExceptionsProcessingInspection */
        catch (NoSuchElementException $ex) {
            throw new Exception('Не найдено поле ввода ответа капчи');
        }

        // делаем элемент видимым
        $browser->executeScript('return document.getElementById("g-recaptcha-response").style.display = "block";');

        // отправляем форму ответа
        $responseElement->sendKeys($gRecaptchaResponse)->submit();
    }

    /**
     * Парсит выдачу Google.
     *
     * @param string $html html-страница с результатами поиска
     * @return array конфиг результата поиска
     */
    public static function parseHtml(string $html) : array
    {
        $results = [];

        if (! empty($html)) {
            $doc = new HtmlDocument($html, true, true, 'UTF-8');

            /** @var HtmlNode $rc */
            foreach ($doc->find('#search .g .rc') as $rc) {
                /** @var HtmlNode $h3 находим заголовок h3 */
                $h3 = $rc->find('a h3', 0);
                $title = (string)$h3->text();

                /** @var HtmlNode $a */
                $a = $h3->find_ancestor_tag('a');

                // пытаемся получить адрес переадресации google
                if (! empty($a->{'data-cthref'})) {
                    $query = parse_url($a->{'data-cthref'}, PHP_URL_QUERY);
                    if (! empty($query)) {
                        parse_str($query, $query);
                        /** @noinspection OffsetOperationsInspection */
                        $url = $query['url'] ?? null;
                    }
                }

                // если нет адреса переадресации, то используем прямой адрес
                if (empty($url)) {
                    /** @noinspection PhpUndefinedFieldInspection */
                    $url = (string)$a->href;
                }

                /** @var HtmlNode $span */
                $span = $rc->find('.aCOpRe span', -1);
                $snippet = $span ? (string)$span->text() : '';

                $h3->clear();
                $a->clear();
                $span->clear();

                $results[] = [
                    'pos' => count($results) + 1,
                    'url' => $url,
                    'title' => $title,
                    'snippet' => $snippet
                ];
            }
        }

        return $results;
    }

    /**
     * Генерирует параметр uule для указания региона поиска.
     *
     * @param string $city регион, город поиска
     * @return string параметр uule
     */
    public static function createUULE(string $city) : string
    {
        if (empty($city)) {
            throw new InvalidArgumentException('empty city');
        }

        $secretKey = array_merge(
            range('A', 'Z'), range('a', 'z'), range('0', '9'), ['-', '_']
        );

        $length = StringHelper::byteLength($city);
        $secretCode = $secretKey[$length % count($secretKey)];

        return trim('w+CAIQICI' . $secretCode . base64_encode($city), '=');
    }

    /**
     * Создать поисковый запрос.
     *
     * @param array $config
     * @return GoogleWdrRequest
     */
    public function searchRequest(array $config = []) : GoogleWdrRequest
    {
        return new GoogleWdrRequest($this, $config + $this->requestConfig);
    }
}
