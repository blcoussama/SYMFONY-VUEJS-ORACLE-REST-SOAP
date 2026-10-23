<?php

declare(strict_types=1);

namespace App\Soap;

/**
 * Sert le WSDL versionné (config/soap/incidents.wsdl) avec l'adresse réelle du service.
 */
final class WsdlProvider
{
    public const LOCATION_PLACEHOLDER = '__SOAP_LOCATION__';

    public function __construct(
        private readonly string $templatePath,
        private readonly string $cacheDir,
    ) {
    }

    public function contents(string $location): string
    {
        $template = file_get_contents($this->templatePath);
        if (false === $template) {
            throw new \RuntimeException(\sprintf('WSDL introuvable : %s', $this->templatePath));
        }

        return str_replace(self::LOCATION_PLACEHOLDER, htmlspecialchars($location, \ENT_XML1 | \ENT_QUOTES), $template);
    }

    /** Fichier WSDL (dans le cache Symfony) lu par \SoapServer, qui exige un chemin ou une URL. */
    public function path(string $location): string
    {
        $path = \sprintf('%s/incidents-%s.wsdl', $this->cacheDir, hash('xxh128', $location.'|'.(string) filemtime($this->templatePath)));
        if (!is_file($path)) {
            if (!is_dir($this->cacheDir) && !mkdir($this->cacheDir, 0o775, true) && !is_dir($this->cacheDir)) {
                throw new \RuntimeException(\sprintf('Impossible de créer %s', $this->cacheDir));
            }
            $tmp = $path.'.'.bin2hex(random_bytes(4));
            file_put_contents($tmp, $this->contents($location));
            rename($tmp, $path);
        }

        return $path;
    }
}
