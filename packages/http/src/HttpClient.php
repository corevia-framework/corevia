<?php
declare(strict_types=1);
namespace Corevia\Http;
interface HttpClient
{
    /** Successful response bytes only. Implementations must close resources on abandonment. */
    public function stream(string $method,string $url,array $headers=[],string $body=''):iterable;
}
