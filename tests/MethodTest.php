<?php
/*
 * @copyright 2019-2020 Dicr http://dicr.org
 * @author Igor A Tarasov <develop@dicr.org>
 * @license MIT
 * @version 05.11.20 02:04:16
 */

declare(strict_types = 1);
namespace dicr\tests;

use dicr\google\wdr\GoogleWdr;
use PHPUnit\Framework\TestCase;
use Yii;
use yii\base\InvalidConfigException;

use function file_get_contents;

/**
 * Class ReceiptTest
 */
class MethodTest extends TestCase
{
    /**
     * Модуль.
     *
     * @return GoogleWdr
     * @throws InvalidConfigException
     */
    private static function googleWdr() : GoogleWdr
    {
        /** @noinspection PhpIncompatibleReturnTypeInspection */
        return Yii::$app->get('googleWdr');
    }

    /**
     * Тест парсинга HTML-страницы результатов.
     *
     * @noinspection PhpMethodMayBeStaticInspection
     */
    public function testHtmlParse() : void
    {
        $html = file_get_contents(__DIR__ . '/data/results.html');

        $results = GoogleWdr::parseHtml($html);
        self::assertNotEmpty($results);
    }
}
