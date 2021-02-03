<?php
/*
 * @copyright 2019-2021 Dicr http://dicr.org
 * @author Igor A Tarasov <develop@dicr.org>
 * @license MIT
 * @version 03.02.21 20:55:40
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
        return Yii::$app->get('googleWdr');
    }

    /**
     * Тест парсинга HTML-страницы результатов.
     *
     * @noinspection PhpMethodMayBeStaticInspection
     * @noinspection PhpUnitMissingTargetForTestInspection
     */
    public function testHtmlParse() : void
    {
        $html = file_get_contents(__DIR__ . '/data/results.html');

        $results = GoogleWdr::parseHtml($html);
        self::assertNotEmpty($results);
    }
}
