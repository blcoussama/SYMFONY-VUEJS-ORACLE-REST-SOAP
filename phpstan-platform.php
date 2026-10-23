<?php

declare(strict_types=1);

// Sans ext-soap (poste local), les classes \SoapClient / \SoapServer n'existent pas :
// les tests SOAP de bout en bout sont alors exclus de l'analyse (ils sont analysés en CI / Docker).
return extension_loaded('soap') ? [] : [
    'parameters' => [
        'excludePaths' => [
            'analyseAndScan' => [
                __DIR__.'/tests/Soap/KernelSoapClient.php',
                __DIR__.'/tests/Soap/SoapEndpointTest.php',
            ],
        ],
    ],
];
