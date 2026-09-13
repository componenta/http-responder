<?php

declare(strict_types=1);

namespace Componenta\Http;

use Componenta\Detector\ConfigProvider as DetectorConfigProvider;
use Componenta\Detector\DetectorInterface;
use Componenta\Detector\MimeMapInterface;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;
use UnexpectedValueException;

/** Adds the responder to the MIME detector's registrations. */
class ResponderConfigProvider extends DetectorConfigProvider
{
    protected function getFactories(): array
    {
        return [
            ...parent::getFactories(),
            Responder::class => static function (ContainerInterface $container): Responder {
                $responseFactory = $container->get(ResponseFactoryInterface::class);
                $streamFactory = $container->get(StreamFactoryInterface::class);
                if (!$responseFactory instanceof ResponseFactoryInterface || !$streamFactory instanceof StreamFactoryInterface) {
                    throw new UnexpectedValueException('Responder requires PSR-17 response and stream factories.');
                }
                $detector = $container->get(DetectorInterface::class);
                $mimeMap = $container->get(MimeMapInterface::class);
                if (!$detector instanceof DetectorInterface || !$mimeMap instanceof MimeMapInterface) {
                    throw new UnexpectedValueException('Responder requires MIME detector and map services.');
                }
                return new Responder($responseFactory, $streamFactory, $detector, $mimeMap);
            },
        ];
    }
}
