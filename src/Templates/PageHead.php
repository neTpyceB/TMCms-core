<?php
declare(strict_types=1);

namespace TMCms\Templates;

use RuntimeException;
use TMCms\Config\Settings;
use TMCms\Files\Finder;
use TMCms\Files\MimeTypes;
use TMCms\Traits\singletonOnlyInstanceTrait;

defined('INC') or exit;

/**
 * Class PageHead
 * Generates HTML code for <head></head>
 */
class PageHead
{
    use singletonOnlyInstanceTrait;

    private
        $enabled = true,
        $doctype = '<!DOCTYPE HTML>',
        $title = '',
        $description = '',
        $keywords = '',
        $custom_strings = [],
        $meta = [],
        $css = [],
        $css_urls = [],
        $js_sequence = 0,
        $js_urls = [],
        $js = [],
        $rss = [],
        $favicon = [];
    private $html_tag_attributes = [];
    private $body_tag_attributes = '';
    private $apple_touch_icon_url = '';
    private $body_css_classes = [];
    private $replace_for_standard_html_tag = false;

    /**
     * @return $this
     */
    public function disableAutoGeneratedHead(): PageHead
    {
        $this->enabled = false;

        return $this;
    }

    /**
     * @param string $attr_string
     *
     * @return $this
     */
    public function addHtmlTagAttributes(string $attr_string): PageHead
    {
        $this->html_tag_attributes[] = $attr_string;

        return $this;
    }

    /**
     * @param string $class
     *
     * @return $this
     */
    public function addClassToBody(string $class): PageHead
    {
        $this->body_css_classes[] = $class;

        return $this;
    }

    /**
     * @return array
     */
    public function getBodyCssClasses(): array
    {
        return $this->body_css_classes;
    }

    /**
     * @param string $attr_string
     *
     * @return $this
     */
    public function setBodyTagAttributes(string $attr_string)
    {
        $this->body_tag_attributes = $attr_string;

        return $this;
    }

    /**
     * @param string $url
     *
     * @return $this
     */
    public function setAppleTouchIcon(string $url): PageHead
    {
        $this->apple_touch_icon_url = $url;

        return $this;
    }

    /**
     * @param string $doctype
     * @param string $version
     * @param string $type
     *
     * @return $this
     * @throws \RuntimeException
     */
    public function setDoctype(string $doctype = 'html', string $version = '5', string $type = 'dtd'): PageHead
    {
        $doc_types = [
            'html'             => [
                '5'    => ['dtd' => '<!DOCTYPE HTML>'],
                '4.01' => [
                    'strict'       => '<!DOCTYPE HTML PUBLIC "-//W3C//DTD HTML 4.01//EN" "http://www.w3.org/TR/html4/strict.dtd">',
                    'transitional' => '<!DOCTYPE HTML PUBLIC "-//W3C//DTD HTML 4.01 Transitional//EN" "http://www.w3.org/TR/html4/loose.dtd">',
                    'frameset'     => '<!DOCTYPE HTML PUBLIC "-//W3C//DTD HTML 4.01 Frameset//EN" "http://www.w3.org/TR/html4/frameset.dtd">'
                ],
                '2.0'  => ['dtd' => '<!DOCTYPE html PUBLIC "-//IETF//DTD HTML 2.0//EN">'],
                '3.2'  => ['dtd' => '<!DOCTYPE html PUBLIC "-//W3C//DTD HTML 3.2 Final//EN">']
            ],
            'xhtml'            => [
                '1.0' => [
                    'dtd'          => '<!DOCTYPE html PUBLIC "-//W3C//DTD XHTML Basic 1.0//EN" "http://www.w3.org/TR/xhtml-basic/xhtml-basic10.dtd">',
                    'strict'       => '<!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.0 Strict//EN" "http://www.w3.org/TR/xhtml1/DTD/xhtml1-strict.dtd">',
                    'transitional' => '<!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.0 Transitional//EN" "http://www.w3.org/TR/xhtml1/DTD/xhtml1-transitional.dtd">',
                    'frameset'     => '<!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.0 Frameset//EN" "http://www.w3.org/TR/xhtml1/DTD/xhtml1-frameset.dtd">'
                ],
                '1.1' => [
                    'dtd'   => '<!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.1//EN" "http://www.w3.org/TR/xhtml11/DTD/xhtml11.dtd">',
                    'basic' => '<!DOCTYPE html PUBLIC "-//W3C//DTD XHTML Basic 1.1//EN" "http://www.w3.org/TR/xhtml-basic/xhtml-basic11.dtd">'
                ]
            ],
            'mathml'           => [
                '2.0' => ['dtd' => '<!DOCTYPE math PUBLIC "-//W3C//DTD MathML 2.0//EN" "http://www.w3.org/TR/MathML2/dtd/mathml2.dtd">'],
                '1.1' => ['dtd' => '<!DOCTYPE math SYSTEM "http://www.w3.org/Math/DTD/mathml1/mathml.dtd">']
            ],
            'xhtml_mathml_svg' => [
                '1.1' => [
                    'basic' => '<!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.1 plus MathML 2.0 plus SVG 1.1//EN" "http://www.w3.org/2002/04/xhtml-math-svg/xhtml-math-svg.dtd">',
                    'xhtml' => '<!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.1 plus MathML 2.0 plus SVG 1.1//EN" "http://www.w3.org/2002/04/xhtml-math-svg/xhtml-math-svg.dtd">',
                    'svg'   => '<!DOCTYPE svg:svg PUBLIC "-//W3C//DTD XHTML 1.1 plus MathML 2.0 plus SVG 1.1//EN" "http://www.w3.org/2002/04/xhtml-math-svg/xhtml-math-svg.dtd">'
                ]
            ],
            'svg'              => [
                '1.1' => [
                    'full'  => '<!DOCTYPE svg PUBLIC "-//W3C//DTD SVG 1.1//EN" "http://www.w3.org/Graphics/SVG/1.1/DTD/svg11.dtd">',
                    'basic' => '<!DOCTYPE svg PUBLIC "-//W3C//DTD SVG 1.1 Basic//EN" "http://www.w3.org/Graphics/SVG/1.1/DTD/svg11-basic.dtd">',
                    'tiny'  => '<!DOCTYPE svg PUBLIC "-//W3C//DTD SVG 1.1 Tiny//EN" "http://www.w3.org/Graphics/SVG/1.1/DTD/svg11-tiny.dtd">'
                ],
                '1.0' => ['dtd' => '<!DOCTYPE svg PUBLIC "-//W3C//DTD SVG 1.0//EN" "http://www.w3.org/TR/2001/REC-SVG-20010904/DTD/svg10.dtd">']
            ]
        ];
        if (!isset($doc_types[$doctype], $doc_types[$doctype][$version], $doc_types[$doctype][$version][$type])) {
            throw new RuntimeException('Non-existing doctype requested');
        }

        $this->doctype = $doc_types[$doctype][$version][$type];

        return $this;
    }

    /**
     * @param $title
     *
     * @return $this
     */
    public function setBrowserTitle($title): PageHead
    {
        $this->title = strip_tags($title);

        return $this;
    }

    /**
     * @param string $icon_path
     * @param string $type
     *
     * @return $this
     */
    public function setFavicon(string $icon_path, string $type = 'image/x-icon'): PageHead
    {
        if (!$type) {
            $ext = pathinfo($icon_path, PATHINFO_EXTENSION);
            $type = MimeTypes::getMimeTypeByExt($ext ?: '');
        }

        $this->favicon = ['href' => $icon_path, 'type' => $type];

        return $this;
    }

    /**
     * @param string $rss
     * @param string $title
     *
     * @return $this
     */
    public function addRSSFeed(string $rss, string $title): PageHead
    {
        $this->rss[] = ['href' => $rss, 'title' => $title];

        return $this;
    }

    /**
     * Add custom string (element) into <head>
     *
     * @param $string
     *
     * @return $this
     */
    public function addCustomString(string $string): PageHead
    {
        $this->custom_strings[] = $string;

        return $this;
    }

    /**
     * @param string $url
     * @param string $media
     *
     * @return $this
     */
    public function addCssUrl(string $url, string $media = 'all'): PageHead
    {
        $this->css_urls[$url] = $media;

        return $this;
    }

    /**
     * @param string $url
     *
     * @return $this
     */
    public function addJsUrl(string $url): PageHead
    {
        if (!in_array($url, $this->js_urls, true)) {
            $this->js_urls[++$this->js_sequence] = $url;
        }

        return $this;
    }

    /**
     * @param string $js
     *
     * @return $this
     */
    public function addJs(string $js): PageHead
    {
        $js = str_ireplace(['<script>', '</script>'], '', $js);

        $this->js[++$this->js_sequence] = $js;

        return $this;
    }

    /**
     * @param string $css
     *
     * @return $this
     */
    public function addCss(string $css): PageHead
    {
        $this->css[] = $css;

        return $this;
    }

    /**
     * @param $content
     * @param string $name
     * @param string $http_equiv
     * @param string $property
     *
     * @return $this
     */
    public function addMeta(string $content, string $name = '', string $http_equiv = '', string $property = ''): PageHead
    {
        $this->meta[] = [
            'content'    => $content,
            'name'       => $name,
            'http_equiv' => $http_equiv,
            'property'   => $property
        ];

        return $this;
    }

    /**
     * @param string $kw
     *
     * @return $this
     */
    public function setMetaKeywords(string $kw): PageHead
    {
        $this->keywords = $kw;

        return $this;
    }

    /**
     * @param string $dsc
     *
     * @return $this
     */
    public function setMetaDescription(string $dsc): PageHead
    {
        $this->description = $dsc;

        return $this;
    }

    /**
     * @param string $html_tag
     *
     * @return $this
     */
    public function replaceStandardHtmlTag(string $html_tag): PageHead
    {
        $this->replace_for_standard_html_tag = $html_tag;

        return $this;
    }

    /**
     * @return string
     */
    public function __toString(): string
    {
        if (!$this->enabled) {
            return '';
        }

        ob_start();

        echo $this->doctype . "\n";
        if ($this->replace_for_standard_html_tag):
            echo $this->replace_for_standard_html_tag;
        else:
            ?><html<?= ($this->html_tag_attributes ? ' ' . implode(' ', $this->html_tag_attributes) : '') ?>>
        <?php endif; ?>
        <head>
            <?php if (!Settings::get('do_not_expose_generator')): ?>
                <meta name="generator" content="<?= CMS_NAME ?>, <?= CMS_SITE ?>">
            <?php endif; ?>
            <meta charset="utf-8">
            <title><?= htmlspecialchars($this->title, ENT_QUOTES) ?></title><?php
            // META
            foreach ($this->meta as $v): ?>
                <meta<?= ($v['name'] ? ' name="' . $v['name'] . '" ' : '') . ($v['http_equiv'] ? ' http-equiv="' . $v['http_equiv'] . '"' : '') . ($v['property'] ? ' property="' . $v['property'] . '"' : '') ?> content="<?= $v['content'] ?>">
            <?php endforeach;

            // CSS files
            foreach ($this->css_urls as $k => $v): $k = Finder::getInstance()->searchForRealPath($k); ?>
                <link rel="stylesheet" type="text/css" href="<?= $k ?>" media="<?= $v ?>">
            <?php endforeach;

            // CSS files
            foreach ($this->css as $v): ?>
                <style>
                    <?= $v ?>
                </style>
            <?php endforeach;

            // JS files and scripts
            for ($i = 1; $i <= $this->js_sequence; $i++) :
                if (isset($this->js_urls[$i])): $this->js_urls[$i] = Finder::getInstance()->searchForRealPath($this->js_urls[$i]); ?>
                    <script src="<?= $this->js_urls[$i] ?>"></script>
                <?php elseif (isset($this->js[$i])): ?>
                    <script><?= $this->js[$i] ?></script>
                <?php endif;
            endfor;

            // RSS feeds
            foreach ($this->rss as $v): ?>
                <link rel="alternate" type="application/rss+xml"
                      title="<?= htmlspecialchars($v['title'], ENT_QUOTES) ?>" href="<?= $v['href'] ?>">
            <?php endforeach;

            // RSS feeds
            if ($this->apple_touch_icon_url): ?>
                <link rel="apple-touch-icon"
                      href="<?= Finder::getInstance()->searchForRealPath($this->apple_touch_icon_url) ?>">
            <?php endif;

            // META keywords
            if ($this->keywords): ?>
                <meta name="keywords" content="<?= htmlspecialchars($this->keywords, ENT_QUOTES) ?>">
            <?php endif;

            // META description
            if ($this->description): ?>
                <meta name="description" content="<?= htmlspecialchars($this->description, ENT_QUOTES) ?>">
            <?php endif;

            // Any custom string appended into <head>
            foreach ($this->custom_strings as $v): ?>
                <?= $v ?>
            <?php endforeach;

            // Favicon
            if ($this->favicon) :
                $this->favicon['href'] = ltrim($this->favicon['href'], '/'); ?>
                <link rel="icon" href="<?= CFG_PROTOCOL ?>://<?= CFG_DOMAIN . '/' . $this->favicon['href'] ?>" type="<?= $this->favicon['type'] ?>">
                <link rel="shortcut icon" href="<?= CFG_PROTOCOL ?>://<?= CFG_DOMAIN . '/' . $this->favicon['href'] ?>" type="<?= $this->favicon['type'] ?>">
                <?php
            endif;

            // Google Analytics
            if ($ga = Settings::get('google_analytics_code')): ?>
                <script>
                    (function (i, s, o, g, r, a, m) {
                        i['GoogleAnalyticsObject'] = r;
                        i[r] = i[r] || function () {
                            (i[r].q = i[r].q || []).push(arguments)
                        }, i[r].l = 1 * new Date();
                        a = s.createElement(o),
                            m = s.getElementsByTagName(o)[0];
                        a.async = 1;
                        a.src = g;
                        m.parentNode.insertBefore(a, m)
                    })(window, document, 'script', 'https://www.google-analytics.com/analytics.js', 'ga');

                    ga('create', 'UA-<?=$ga?>', 'auto');
                    ga('require', 'displayfeatures');
                    ga('send', 'pageview');

                    /* Accurate bounce rate by time */
                    if (!document.referrer ||
                        document.referrer.split('/')[2].indexOf(location.hostname) != 0)
                        setTimeout(function () {
                            ga('send', 'event', 'New visitor', location.pathname);
                        }, 15000);

                </script>
            <?php endif;

            // Yandex.Metrika
            if ($ym = Settings::get('yandex_metrika_key')): ?>
                <!-- Yandex.Metrika counter -->
                <script type="text/javascript">
                    (function (d, w, c) {
                        (w[c] = w[c] || []).push(function () {
                            try {
                                w.yaCounter<?= $ym ?> = new Ya.Metrika({
                                    id:<?= $ym ?>,
                                    clickmap:true,
                                    trackLinks:true,
                                    accurateTrackBounce:true,
                                    webvisor:true,
                                    trackHash:true
                                });
                            } catch (e) {
                            }
                        });

                        var n = d.getElementsByTagName("script")[0],
                            s = d.createElement("script"),
                            f = function () {
                                n.parentNode.insertBefore(s, n);
                            };
                        s.type = "text/javascript";
                        s.async = true;
                        s.src = (d.location.protocol == "https:" ? "https:" : "http:") + "//mc.yandex.ru/metrika/watch.js";

                        if (w.opera == "[object Opera]") {
                            d.addEventListener("DOMContentLoaded", f, false);
                        } else {
                            f();
                        }
                    })(document, window, "yandex_metrika_callbacks");
                </script>
                <noscript>
                    <div><img src="//mc.yandex.ru/watch/<?= $ym ?>" style="position:absolute; left:-9999px;" alt=""/></div>
                </noscript>
                <!-- /Yandex.Metrika counter -->
            <?php endif;

            // Facebook Pixel
            if ($fp = Settings::get('facebook_pixel_key')): ?>
                <!-- Facebook Pixel Code -->
                <script>
                    !function(f, b, e, v, n, t, s){if(f.fbq)return;n=f.fbq=function(){n.callMethod?
                        n.callMethod.apply(n,arguments):n.queue.push(arguments)};if(!f._fbq)f._fbq=n;
                        n.push=n;n.loaded=!0;n.version="2.0";n.queue=[];t=b.createElement(e);t.async=!0;
                        t.src=v;s=b.getElementsByTagName(e)[0];s.parentNode.insertBefore(t,s)}(window,
                        document,"script","https://connect.facebook.net/en_US/fbevents.js");

                    fbq("init", "<?= $fp ?>");
                    fbq("track", "PageView");
                    fbq("track", "ViewContent");
                </script>
                <noscript><img height="1" width="1" style="display:none" src="https://www.facebook.com/tr?id=<?= $fp ?>&ev=PageView&noscript=1" /></noscript>
                <!-- End Facebook Pixel Code -->
            <?php endif;
            unset($fp);
            ?>
        </head>
        <?php

        return ob_get_clean();
    }
}