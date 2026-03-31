<?php

namespace tests;

class MockRequest extends \yii\web\Request
{
    private array $_bodyParams = [];

    public function setBodyParams(array $params): void
    {
        $this->_bodyParams = $params;
    }

    public function getBodyParams(): array
    {
        return $this->_bodyParams;
    }
}
