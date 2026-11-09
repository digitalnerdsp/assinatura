<?php

declare(strict_types=1);

namespace App\Support;

use Sentry\Event;
use Sentry\EventHint;

/** Remove de cada evento o que não pode sair do servidor: corpo da requisição, cookies e tokens na URL. */
final class SentryScrubber
{
    public static function beforeSend(Event $event, ?EventHint $hint = null): ?Event
    {
        $request = $event->getRequest();
        unset($request['data'], $request['cookies'], $request['query_string']);

        if (isset($request['url'])) {
            $request['url'] = self::url((string) $request['url']);
        }

        $event->setRequest($request);

        return $event;
    }

    /** Troca o token do link de assinatura e o código de verificação pelo nome do parâmetro. */
    public static function url(string $url): string
    {
        $url = (string) preg_replace('#/assinar/[^/?\#]+#', '/assinar/{token}', $url);

        return (string) preg_replace('#/verificar/[^/?\#]+#', '/verificar/{codigo}', $url);
    }
}
