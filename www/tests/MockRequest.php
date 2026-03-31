<?php declare(strict_types=1);

namespace tests;

class MockRequest extends \yii\web\Request
{
    private array $_bodyParams = [];

    public function setBodyParams($values): void
    {
        $this->_bodyParams = (array)$values;
    }

    public function getBodyParams(): array
    {
        return $this->_bodyParams;
    }

    public function getBaseUrl(): string
    {
        return '';
    }

    public function getScriptUrl(): string
    {
        return '/index.php';
    }
}
