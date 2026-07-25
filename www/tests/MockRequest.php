<?php declare(strict_types=1);

namespace tests;

class MockRequest extends \yii\web\Request
{
    private array $_mockBodyParams = [];
    private bool $_useRawBody = false;

    public function setBodyParams($values): void
    {
        $this->_mockBodyParams = (array)$values;
    }

    /**
     * Switches to an unparsed payload so the configured content-type parsers
     * run for real — the only way to cover malformed input.
     *
     * Named differently from the inherited setRawBody(), whose untyped
     * signature cannot be narrowed here.
     */
    public function useRawBody(string $body): void
    {
        $this->setRawBody($body);
        $this->_useRawBody = true;
    }

    public function getBodyParams(): array
    {
        if ($this->_useRawBody) {
            return (array)parent::getBodyParams();
        }

        return $this->_mockBodyParams;
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
