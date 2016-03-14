<?php
if (!defined('TYPO3_MODE')) {
    die('Access denied.');
}

\TYPO3\CMS\Extbase\Utility\ExtensionUtility::configurePlugin(
    'S3b0.RegionalSeo',
    'Add',
    [
        'Standard' => 'addTags'
    ],
    // non-cacheable actions
    [
        'Standard' => 'addTags'
    ]
);
