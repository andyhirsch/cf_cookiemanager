<?php

declare(strict_types=1);

namespace CodingFreaks\CfCookiemanager\Domain\Repository;

use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;
use TYPO3\CMS\Core\Context\LanguageAspect;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Extbase\Utility\DebuggerUtility;


/**
 * This file is part of the "Coding Freaks Cookie Manager" Extension for TYPO3 CMS.
 *
 * For the full copyright and license information, please read the
 * LICENSE.txt file that was distributed with this source code.
 *
 * (c) 2022
 */

/**
 * The repository for Cookies
 */
class CookieRepository extends \TYPO3\CMS\Extbase\Persistence\Repository
{

    /**
     * cookieServiceRepository
     *
     * @var \CodingFreaks\CfCookiemanager\Domain\Repository\CookieServiceRepository
     */
    protected CookieServiceRepository $cookieServiceRepository;


    /**
     * @var \CodingFreaks\CfCookiemanager\Domain\Repository\ApiRepository
     */
    private ApiRepository $apiRepository;


    /**
     * @param \CodingFreaks\CfCookiemanager\Domain\Repository\ApiRepository $apiRepository
     */
    public function injectApiRepository(\CodingFreaks\CfCookiemanager\Domain\Repository\ApiRepository $apiRepository)
    {
        $this->apiRepository = $apiRepository;
    }

    /**
     * @param \CodingFreaks\CfCookiemanager\Domain\Repository\CookieServiceRepository $cookieServiceRepository
     */
    public function injectCookieServiceRepository(\CodingFreaks\CfCookiemanager\Domain\Repository\CookieServiceRepository $cookieServiceRepository)
    {
        $this->cookieServiceRepository = $cookieServiceRepository;
    }

    public function getCookieBySysLanguage($storage, $langUid = 0)
    {
        $query = $this->createQuery();

        if ($langUid !== false) {
            $languageAspect = new LanguageAspect((int)$langUid, (int)$langUid, LanguageAspect::OVERLAYS_ON); //$languageAspect->getOverlayType());
            $query->getQuerySettings()->setLanguageAspect($languageAspect);
            $query->getQuerySettings()->setStoragePageIds($storage);
        }

        $query->getQuerySettings()->setIgnoreEnableFields(false)->setStoragePageIds($storage);
        $cookies = $query->execute();
        $allCookies = [];
        foreach ($cookies as $category) {
            $allCookies[] = $category;
        }
        return $allCookies;
    }

    /**
     * @param $identifier
     */
    public function getCookieByName($identifier, $langUid = 0, $storage = [1])
    {
        $query = $this->createQuery();
        $languageAspect = new LanguageAspect($langUid, $langUid, LanguageAspect::OVERLAYS_ON); //$languageAspect->getOverlayType());
        $query->getQuerySettings()->setLanguageAspect($languageAspect);
        $query->getQuerySettings()->setStoragePageIds($storage);
        $query->matching($query->logicalAnd($query->equals('name', $identifier)));
        $query->setOrderings(array("crdate" => \TYPO3\CMS\Extbase\Persistence\QueryInterface::ORDER_ASCENDING))->setLimit(1);
        return $query->execute();
    }

}
