<?php
/*
 * @copyright 2019-2020 Dicr http://dicr.org
 * @author Igor A Tarasov <develop@dicr.org>
 * @license MIT
 * @version 05.11.20 01:11:05
 */

declare(strict_types = 1);

/** среда разработки */
defined('YII_ENV') || define('YII_ENV', 'dev');

/** режим отладки */
defined('YII_DEBUG') || define('YII_DEBUG', true);

require_once(dirname(__DIR__) . '/vendor/autoload.php');
require_once(dirname(__DIR__) . '/vendor/yiisoft/yii2/Yii.php');

/** @noinspection PhpUnhandledExceptionInspection */
new yii\console\Application([
    'id' => 'test',
    'basePath' => dirname(__DIR__),
    'components' => [
        'urlManager' => [
            'hostInfo' => 'https://dicr.org'
        ],
        'cache' => [
            'class' => yii\caching\FileCache::class
        ],
        'log' => [
            'targets' => [
                [
                    'class' => yii\log\FileTarget::class,
                    'levels' => ['error', 'warning', 'info', 'trace']
                ]
            ]
        ],
        'googleWdr' => [
            'class' => dicr\google\wdr\GoogleWdr::class,
            'driverUrl' => 'some url'
        ]
    ],
    'modules' => [
        'anticaptcha' => [
            'class' => dicr\anticaptcha\AntiCaptchaModule::class,
            'clientKey' => 'some key'
        ]
    ],
    'bootstrap' => ['log']
]);
