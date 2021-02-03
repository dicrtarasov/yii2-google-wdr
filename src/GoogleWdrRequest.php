<?php
/*
 * @copyright 2019-2021 Dicr http://dicr.org
 * @author Igor A Tarasov <develop@dicr.org>
 * @license MIT
 * @version 03.02.21 20:56:55
 */

declare(strict_types = 1);
namespace dicr\google\wdr;

use dicr\validate\ValidateException;
use Facebook\WebDriver\Exception\NoSuchElementException;
use Facebook\WebDriver\Exception\TimeoutException;
use Facebook\WebDriver\Remote\RemoteWebDriver;
use Facebook\WebDriver\Remote\RemoteWebElement;
use Facebook\WebDriver\WebDriver;
use Facebook\WebDriver\WebDriverBy;
use Throwable;
use Yii;
use yii\base\Exception;
use yii\base\Model;

use function array_keys;
use function http_build_query;
use function sprintf;

/**
 * Запрос поиска в Google.
 *
 * @property-read string $html HTML-страница с результатами поиска
 * @property-read array $results результаты поиска
 */
class GoogleWdrRequest extends Model
{
    /** @var string адрес поиска */
    public const URL_SEARCH = 'https://google.com/search';

    /** @var string адрес AdWords Preview Tool */
    public const ADWORDS_PREVIEW_TOOL = 'https://adwords.google.com/anon/AdPreview';

    /** @var int */
    public const DEVICE_PC = 30000;

    /** @var int */
    public const DEVICE_MOBILE = 3001;

    /** @var int */
    public const DEVICE_TABLET = 3002;

    /** @var string[] */
    public const DEVICES = [
        self::DEVICE_PC => 'Настольный',
        self::DEVICE_MOBILE => 'Мобильный',
        self::DEVICE_TABLET => 'Планшет'
    ];

    /** Количество результатов на странице */
    public const LIMIT_MIN = 1;

    /** @var int */
    public const LIMIT_MAX = 100;

    /** @var int */
    public const LIMIT_DEFAULT = 10;

    /** @var int максимальная длина запроса */
    public const QUERY_MAX = 256;

    /** @var string поисковый запрос */
    public $query;

    /** @var ?int код региона (при кодировании ссылок методом APT) */
    public $region;

    /** @var ?string "Регион, Город" (используется при кодировании ссылок методом UULE) */
    public $regionCity;

    /** @var ?int код устройства (PC, TABLET, MOBILE) */
    public $device;

    /** @var ?int кол-во результатов на странице */
    public $limit;

    /** @var GoogleWdr */
    private $_googleWdr;

    /**
     * GoogleWdrRequest constructor.
     *
     * @param GoogleWdr $googleWdr
     * @param array $config
     */
    public function __construct(GoogleWdr $googleWdr, array $config = [])
    {
        $this->_googleWdr = $googleWdr;

        parent::__construct($config);
    }

    /**
     * @inheritDoc
     */
    public function attributeLabels(): array
    {
        return [
            'query' => 'Запрос',
            'region' => 'Регион',
            'device' => 'Устройство',
            'limit' => 'Кол-во результатов',
        ];
    }

    /**
     * {@inheritDoc}
     */
    public function attributeHints(): array
    {
        return [
            'device' => 'По-умолчанию - компьютер',
            'region' => 'По-умолчанию по местоположению',
            'limit' => sprintf('По-умолчанию %d', self::LIMIT_DEFAULT)
        ];
    }

    /**
     * {@inheritDoc}
     */
    public function rules(): array
    {
        return [
            ['query', 'trim'],
            ['query', 'required'],
            ['query', 'string', 'max' => self::QUERY_MAX],

            ['region', 'default'],
            ['region', 'integer', 'min' => 1],
            ['region', 'filter', 'filter' => 'intval', 'skipOnEmpty' => true],

            ['regionCity', 'trim'],
            ['regionCity', 'default'],

            ['device', 'default'],
            ['device', 'in', 'range' => array_keys(self::DEVICES)],
            ['device', 'filter', 'filter' => 'intval', 'skipOnEmpty' => true],

            ['limit', 'default'],
            ['limit', 'integer', 'min' => self::LIMIT_MIN, 'max' => self::LIMIT_MAX],
            ['limit', 'filter', 'filter' => 'intval', 'skipOnEmpty' => true]
        ];
    }

    /**
     * Получает гео-ссылку через сайт Adwords Preview Tools
     *
     * @return string
     * @throws NoSuchElementException
     * @throws TimeOutException
     */
    private function createLinkByAPT(): string
    {
        $query = [
            'lang' => 'ru',
            'st' => $this->query,
        ];

        if (! empty($this->region)) {
            $query['loc'] = $this->region;
        }

        if (! empty($this->device)) {
            $query['device'] = $this->device;
        }

        // получаем браузер
        $browser = $this->_googleWdr->browser;

        // делаем паузу перед запросом
        $this->_googleWdr->pause();

        // переходим на адрес APT
        $urlApt = self::ADWORDS_PREVIEW_TOOL . '?' . http_build_query($query);
        Yii::debug('Переход на страницу APT: ' . $urlApt, __METHOD__);
        $browser->get($urlApt);

        // ожидаем 10 сек загрузки фрейма
        $frame = $browser->wait(10)->until(static function(RemoteWebDriver $browser): RemoteWebElement {
            /** @var WebDriver $driver */
            return $browser->findElement(WebDriverBy::tagName('iframe'));
        }, 'Не удалось найти iframe');

        // берем URL из фрейма Adwords и добавляем ему парамер кол-ва результатов
        $url = $frame->getAttribute('src');
        if (! empty($this->limit)) {
            $url .= '&num=' . ($this->limit < self::LIMIT_MAX ? $this->limit + 1 : self::LIMIT_MAX);
        }

        return $url;
    }

    /**
     * Создает гео-ссылку методом создания UULE-параметра
     *
     * @return string
     */
    private function createLinkByUULE(): string
    {
        $query = [
            'q' => $this->query,        // поисковый запрос
            'newwindow' => 1,           // результаты в новом окне
            'noj' => 1,
            'igu' => 1,
            'ip' => '0.0.0.0',          // сброс IP
            'source_ip' => '0.0.0.0',   // сброс IP
            'ie' => 'UTF-8',            // кодировка запроса
            'oe' => 'UTF-8',            // кодировка результатов
            'hl' => 'ru',               // язык результатов,
            'pws' => 0                  // отключение персонализации поиска
        ];

        if (! empty($this->limit)) {
            $query['num'] = $this->limit;
        }

        $url = self::URL_SEARCH . '?' . http_build_query($query);

        // UULE-параметр содержит "+", который нельзя кодировать в http_build_query
        if (! empty($this->regionCity)) {
            $url .= '&uule=' . GoogleWdr::createUULE($this->regionCity);
        }

        return $url;
    }

    /**
     * Отправка поискового запроса.
     *
     * @return string $html html-страница с результатами поиска
     * @throws ValidateException
     * @throws Exception
     */
    public function send(): string
    {
        if ($this->validate() === false) {
            throw new ValidateException($this);
        }

        /** @var RemoteWebDriver $browser */
        $browser = null;

        try {
            // кэшируем результаты запроса
            $key = $this->getAttributes();

            $data = Yii::$app->cache->get($key);
            if (! empty($data)) {
                return $data;
            }

            // делаем паузу перед запросом
            $this->_googleWdr->pause();

            // создаем поисковую ссылку
            $urlSearch = $this->_googleWdr->linkMethod === GoogleWdr::LINK_METHOD_APT ?
                $this->createLinkByAPT() : $this->createLinkByUULE();

            // переходим на страницу поиска
            $browser = $this->_googleWdr->browser;
            Yii::debug('Перед на адрес: ' . $urlSearch);
            $browser->get($urlSearch);

            // проверяем капчу
            $this->_googleWdr->checkCaptcha($browser);

            // дожидаемся появления результатов поиска
            $browser->wait(10)->until(
                static fn(RemoteWebDriver $browser
                ): RemoteWebElement => $browser->findElement(WebDriverBy::id('search')),
                'Не удалось найти результаты поиска'
            );

            // сохраняем в кеше страницу
            $html = $browser->getPageSource();

            Yii::$app->cache->set($key, $html, $this->_googleWdr->cacheDuration);

            return $html;
        } catch (Throwable $ex) {
            // закрываем браузер в котором произошла ошибка поиска
            if ($browser !== null) {
                try {
                    $browser->quit();
                } catch (Throwable $th) {
                    Yii::error($th, __METHOD__);
                }
            }

            throw new Exception('Ошибка поиска: ' . $ex->getMessage(), 0, $ex);
        }
    }

    /** @var string */
    private $_html;

    /**
     * Страница с результатами поиска.
     *
     * @return string
     * @throws Exception
     * @throws ValidateException
     */
    public function getHtml(): string
    {
        if ($this->_html === null) {
            $this->_html = $this->send();
        }

        return $this->_html;
    }

    /** @var array */
    private $_results;

    /**
     * Результаты поиска.
     *
     * @return array
     * @throws Exception
     * @throws ValidateException
     */
    public function getResults(): array
    {
        if ($this->_results === null) {
            $this->results = GoogleWdr::parseHtml($this->getHtml());
        }

        return $this->_results;
    }
}
