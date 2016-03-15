<?php
namespace S3b0\RegionalSeo\Controller;

/***************************************************************
 *
 *  Copyright notice
 *
 *  (c) 2016 Sebastian Iffland <sebastian.iffland@ecom-ex.com>, ecom  instruments GmbH
 *
 *  All rights reserved
 *
 *  This script is part of the TYPO3 project. The TYPO3 project is
 *  free software; you can redistribute it and/or modify
 *  it under the terms of the GNU General Public License as published by
 *  the Free Software Foundation; either version 3 of the License, or
 *  (at your option) any later version.
 *
 *  The GNU General Public License can be found at
 *  http://www.gnu.org/copyleft/gpl.html.
 *
 *  This script is distributed in the hope that it will be useful,
 *  but WITHOUT ANY WARRANTY; without even the implied warranty of
 *  MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 *  GNU General Public License for more details.
 *
 *  This copyright notice MUST APPEAR in all copies of the script!
 ***************************************************************/
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * StandardController
 */
class StandardController extends \TYPO3\CMS\Extbase\Mvc\Controller\ActionController
{

    const HIDE_DEFAULT_TRANSLATION    = 1;
    const HIDE_PAGE_IF_NO_TRANSLATION = 2;

    /**
     * @var \S3b0\RegionalSeo\Domain\Repository\LanguageRepository
     * @inject
     */
    protected $languageRepository;

    /**
     * @var \TYPO3\CMS\Frontend\Page\PageRepository
     * @inject
     */
    protected $pageRepository;

    /**
     * @var string
     */
    protected $defaultIsoLanguage = 'en';

    /**
     * action addTags
     *
     * @return void
     */
    public function addTagsAction()
    {
        $this->setDefaultIsoLanguage();
        $getVars = GeneralUtility::_GET();
        $xDefault = $this->getTyposcriptFrontendController()->id === (int)$this->settings[ 'domainRoot' ];
        $pageI18nConfiguration = (int)$this->getTyposcriptFrontendController()->page[ 'l18n_cfg' ];
        $arguments = [];

        /** Add arguments to keep in urls */
        if ($this->settings[ 'includeParams' ] && $getVars) {
            /** @var string $regexp */
            foreach (GeneralUtility::trimExplode(',', $this->settings[ 'includeParams' ]) as $regexp) {
                foreach ($getVars as $key => $value) {
                    if (preg_match("/{$regexp}/i", $key)) {
                        $arguments[ $key ] = $value;
                    }
                }
            }
        }

        /** Add initial language parameter to arguments */
        $arguments[ 'L' ] = 0;

        /** @var array $hrefLangCollection */
        $hrefLangCollection[ 0 ] = [
            'hreflang'  => $this->defaultIsoLanguage,
            'arguments' => $arguments,
            'pageUid'   => null
        ];
        /** Override if alternate target is set by typoscript */
        if (is_array($this->settings[ 'alternateTarget' ]) && array_key_exists($this->defaultIsoLanguage, $this->settings[ 'alternateTarget' ])) {
            $xDefault = (int)$this->settings[ 'alternateTarget' ][ $this->defaultIsoLanguage ] === (int)$this->settings[ 'domainRoot' ];
            $hrefLangCollection[ 0 ] = [
                'hreflang'  => $this->defaultIsoLanguage,
                'arguments' => $arguments,
                'pageUid'   => $this->settings[ 'alternateTarget' ][ $this->defaultIsoLanguage ]
            ];
        }

        /** Reset hrefLang array, if default language is active */
        if ($this->getTyposcriptFrontendController()->sys_language_uid < 1 || ($pageI18nConfiguration & self::HIDE_DEFAULT_TRANSLATION)) {
            $hrefLangCollection = [];
        }

        /** @var array|\TYPO3\CMS\Extbase\Persistence\QueryResultInterface $languages */
        if ($languages = $this->languageRepository->findAll()) {
            /** @var array|null $alternatePageLanguages */
            $alternatePageLanguages = $this->getAlternateLanguage();

            /**
             * @var integer                                 $offset
             * @var \S3b0\RegionalSeo\Domain\Model\Language $language
             */
            foreach ($languages as $offset => $language) {
                /** Skip for current language */
                if ($language->getUid() === $this->getTyposcriptFrontendController()->sys_language_uid) {
                    continue;
                }

                /** Override initial language parameter */
                $arguments[ 'L' ] = $language->getUid();
                $processed = false;

                /** Process existing alternate page languages */
                if (is_array($alternatePageLanguages) && in_array($language->getUid(), $alternatePageLanguages)) {
                    $hrefLangCollection[ $language->getUid() ] = [
                        'hreflang'  => $language->getHrefLang(),
                        'arguments' => $arguments,
                        'pageUid'   => null
                    ];
                    $processed = true;
                }

                /** Override if alternate target is set (TypoScript) */
                if (is_array($this->settings[ 'alternateTarget' ]) && array_key_exists($language->getHrefLang(), $this->settings[ 'alternateTarget' ])) {
                    $hrefLangCollection[ $language->getUid() ] = [
                        'hreflang'  => $language->getHrefLang(),
                        'arguments' => $arguments,
                        'pageUid'   => $this->settings[ 'alternateTarget' ][ $language->getHrefLang() ]
                    ];
                    $processed = true;
                }

                /** Proceed if page mode is NOT set to 'Hide page if no translation for current language exists' */
                if ($this->settings[ 'handleLanguages' ] && !GeneralUtility::inList($this->settings[ 'handleLanguages' ], $language->getUid())) {
                    continue;
                }

                /** Process any language, that has NO pages_language_overlay record and/or alternateTarget */
                if ($processed === false && ($pageI18nConfiguration & self::HIDE_PAGE_IF_NO_TRANSLATION) === 0) {
                    $hrefLangCollection[ $language->getUid() ] = [
                        'hreflang'  => $language->getHrefLang(),
                        'arguments' => $arguments,
                        'pageUid'   => null
                    ];
                }
            }
        }

        if ($this->settings[ 'canonical' ]) {
            $arguments[ 'L' ] = $this->getTyposcriptFrontendController()->sys_language_uid;
            $canonical = ['arguments' => $arguments];
        } else {
            $canonical = null;
        }

        $this->view->assign('data', [
            'xDefault'  => $xDefault ? $this->getTyposcriptFrontendController()->baseUrl : null,
            'canonical' => $canonical,
            'hrefLang'  => $hrefLangCollection
        ]);
    }

    /**
     * @return array|null
     */
    private function getAlternateLanguage()
    {
        $result = $this->getDatabaseConnection()->exec_SELECTgetRows(
            'sys_language_uid',
            'pages_language_overlay',
            'pid=' . (int)$this->getTyposcriptFrontendController()->id . $this->pageRepository->enableFields('pages_language_overlay')
        );
        if ($result) {
            array_walk($result, function(&$value) {
                $value = (int)$value[ 'sys_language_uid' ];
            });
        }

        return $result;
    }

    /**
     * @return void
     */
    public function setDefaultIsoLanguage()
    {
        if ($this->settings[ 'defaultIsoLanguage' ]) {
            $this->defaultIsoLanguage = $this->settings[ 'defaultIsoLanguage' ];
        }
    }

    /**
     * @return \TYPO3\CMS\Frontend\Controller\TypoScriptFrontendController
     */
    private static function getTyposcriptFrontendController()
    {
        return $GLOBALS[ 'TSFE' ];
    }

    /**
     * @return \TYPO3\CMS\Core\Database\DatabaseConnection
     */
    private static function getDatabaseConnection()
    {
        return $GLOBALS[ 'TYPO3_DB' ];
    }

}