<?php

declare(strict_types=1);

namespace JournalingPostServer\Installation;

/**
 * 登録時に発行したinstallation。`apiKey`は平文であり、この応答でしか返さない。
 * Serverはhashだけを保存するため、後から再取得できない。
 */
final class IssuedInstallation
{
    public function __construct(
        public readonly string $id,
        public readonly string $apiKey,
    ) {
    }
}
