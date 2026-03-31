<?php declare(strict_types=1);

namespace app\components;

use yii\web\IdentityInterface;

class User implements IdentityInterface
{
    public int $id;
    public string $email;
    public string $name;
    private array $data;

    public function __construct(array $data)
    {
        $this->data = $data;
        $this->id = (int)($data['id'] ?? 0);
        $this->email = $data['email'] ?? '';
        $this->name = $data['name'] ?? '';
    }

    public static function findIdentity($id): ?static
    {
        return null; // not used — session disabled
    }

    public static function findIdentityByAccessToken($token, $type = null): ?static
    {
        return null; // handled by PassportAuthBehavior
    }

    public function getId(): int
    {
        return $this->id;
    }

    public function getAuthKey(): ?string
    {
        return null;
    }

    public function validateAuthKey($authKey): bool
    {
        return false;
    }

    public function getData(): array
    {
        return $this->data;
    }
}
