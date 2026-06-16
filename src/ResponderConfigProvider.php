<?php

declare(strict_types=1);

namespace Componenta\Http;

use Componenta\Detector\ConfigProvider as DetectorConfigProvider;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;

class ResponderConfigProvider extends \Componenta\Config\ConfigProvider
{
    protected function getProviders(): array
    {
        return [
            new DetectorConfigProvider(),
        ];
    }

    protected function getFactories(): array
    {
        return [
            Responder::class => static function (ContainerInterface $container): Responder {
                return new Responder(
                    $container->get(ResponseFactoryInterface::class),
                    $container->get(StreamFactoryInterface::class),
                );
            },
        ];
    }
}
