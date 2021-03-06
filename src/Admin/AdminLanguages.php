<?php
declare(strict_types=1);

namespace TMCms\Admin;

use TMCms\Admin\Entity\LanguageEntityRepository;
use TMCms\Config\Constants;

/**
 * Languages for admin panel
 *
 * Class AdminLanguages
 * @package TMCms\Admin
 */
class AdminLanguages
{
    /**
     * Available languages
     *
     * @return array
     */
    public static function getPairs(): array
    {
        $languages_from_site = new LanguageEntityRepository();

        // Default language for admin panel
        $default_languages = [
            Constants::ADMIN_LANGUAGE_DEFAULT_SHORT => Constants::ADMIN_LANGUAGE_DEFAULT_FULL
        ];

        return array_merge($default_languages, $languages_from_site->getPairs('full', 'short'));
    }
}
