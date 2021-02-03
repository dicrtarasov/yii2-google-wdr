# Поиск Google через WebDriver для Yii2

- php-webdriver: https://github.com/php-webdriver/php-webdriver
- ChromeDriver: https://sites.google.com/a/chromium.org/chromedriver/downloads
- GeckoDriver: https://github.com/mozilla/geckodriver/releases

## Настройка компонента

```php
$config = [
    'components' => [
        'googleWdr' => [
            'class' => dicr\google\wdr\GoogleWdr::class,
            'driverUrl' => 'url web-драйвера'
        ]
    ]
];
```

## Использование

```php
use dicr\google\wdr\GoogleWdr;
use dicr\google\wdr\GoogleWdrRequest;

/** @var GoogleWdr $googleWdr модуль */
$googleWdr = Yii::$app->get('googleWdr');

/** @var GoogleWdrRequest $req запрос создания задачи */
$req = $googleWdr->searchRequest([
    'query' => 'мыльная опера'
]);

// выводим результаты
foreach ($req->results as $res) {
    echo 'URL: ' . $res['url'] . "\n";
    echo 'Title: ' . $res['title'] . "\n";
}
```
