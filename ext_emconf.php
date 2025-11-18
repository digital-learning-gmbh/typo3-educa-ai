<?php

$EM_CONF[$_EXTKEY] = [
    'title' => 'educa AI Typo3 SEO',
    'description' => 'Berechnet SEO-Daten mittels Planer-Task und Button.',
    'category' => 'module',
    'author' => 'Ihr Name',
    'author_email' => 'ihre@email.com',
    'state' => 'alpha',
    'clearCacheOnLoad' => 1,
    'version' => '0.1.0',
    'constraints' => [
        'depends' => [
            'typo3' => '12.4.0-12.4.99',
            'frontend' => '12.4.0-12.4.99', 
        ],
        'conflicts' => [],
        'suggests' => [],
    ],
];
