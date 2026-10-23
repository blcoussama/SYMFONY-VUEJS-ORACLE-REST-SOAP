<?php

declare(strict_types=1);

namespace App\Tests\Soap;

use Symfony\Bundle\FrameworkBundle\KernelBrowser;

/**
 * \SoapClient dont le transport HTTP est remplacé par le KernelBrowser de Symfony.
 */
final class KernelSoapClient extends \SoapClient
{
    public function __construct(private readonly KernelBrowser $browser, string $wsdl)
    {
        parent::__construct($wsdl, ['cache_wsdl' => \WSDL_CACHE_NONE, 'exceptions' => true, 'features' => \SOAP_SINGLE_ELEMENT_ARRAYS]);
    }

    public function __doRequest(string $request, string $location, string $action, int $version, bool $oneWay = false, ?string $uriParserClass = null): string
    {
        $this->browser->request('POST', (string) parse_url($location, \PHP_URL_PATH), [], [], [
            'CONTENT_TYPE' => 'text/xml; charset=utf-8',
            'HTTP_SOAPACTION' => '"'.$action.'"',
        ], $request);

        return (string) $this->browser->getResponse()->getContent();
    }
}
