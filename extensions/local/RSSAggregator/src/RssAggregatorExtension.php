<?php

namespace Local\RSSAggregator;

use Bolt\Extension\SimpleExtension;
use Bolt\Legacy\Content;
use Bolt\Menu\MenuEntry;
use Bolt\Storage\Entity;
use Maid\Maid;
use PicoFeed\Parser\Item;
use PicoFeed\PicoFeedException;
use PicoFeed\Reader\Reader;
use Silex\Application;
use Silex\ControllerCollection;
use Symfony\Component\HttpFoundation\ParameterBag;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Yaml\Parser;

/**
 * RSS Aggregator Extension:
 * RSS Feed reader and aggregator extension for Bolt
 *
 * @author Bob den Otter <bob@twokings.nl>
 * @author Gawain Lynch <gawain.lynch@gmail.com>
 */
class RssAggregatorExtension extends SimpleExtension
{
    /** @var ParameterBag */
    private $config;

    /**
     * {@inheritdoc}
     */
    protected function registerBackendRoutes(ControllerCollection $collection)
    {
        $collection->get('/extensions/rssaggregate', [$this, 'RSSAggregator']);
    }

    /**
     * {@inheritdoc}
     */
    public function registerServices(Application $app)
    {
        $app['twig'] = $app->extend(
            'twig',
            function ($twig) use ($app) {
                $config = $this->getConfig();
                $twig->addGlobal('rssfeeds', $config->get('feeds'));
                return $twig;
            }
        );
    }

    /**
     * {@inheritdoc}
     */
    protected function registerMenuEntries()
    {
        $menu = new MenuEntry('rssaggregator-menu', 'rssaggregate');
        $menu->setLabel('RSS Aggregator')
            ->setIcon('fa:leaf')
            ->setPermission('settings')
        ;

        return [
            $menu,
        ];
    }

    /**
     * RSS aggregator admin menu route.
     *
     * @param Request $request
     *
     * @return string
     */
    public function RSSAggregator(Request $request)
    {
        set_time_limit(0);

        $config = $this->getConfig();
        $app = $this->getContainer();
        $feeds = $config->get('feeds');

        $currentUser = $app['users']->getCurrentUser();

        $key = $config->getAlnum('key');
        if ($key !== '' && $key !== $request->query->get('key') && $currentUser === null) {
            return 'Key not correct.';
        }

        // Get some variables from the URL.
        $amount = (int) $app['request']->get('amount', $config->getInt('itemAmount'));
        $verbose = (bool) $app['request']->get('verbose', false);

        // Perhaps we only want one feed.
        if ($onlyfeed = $app['request']->get('feed')) {
            $feeds = array_intersect_key($feeds, array_flip(explode(',', $onlyfeed)));
        }

        foreach ($feeds as $author => $feed) {
            if ($feed->get('skip') != true) {
                $this->parseFeed($author, $feed, $amount, $verbose);
            }
        }

        return '<br><br><br> Done.';
    }

    /**
     * {@inheritdoc}
     *
     * @return ParameterBag
     */
    protected function getConfig()
    {
        if ($this->config !== null) {
            return $this->config;
        }
        $raw = parent::getConfig();

        $config = new ParameterBag($raw);
        $feeds = $raw['feeds'];
        foreach ($raw['feeds'] as $key => $value) {
            $feeds[$key] = new ParameterBag((array) $value);
        }
        $config->set('feeds', $feeds);

        return $this->config = $config;
    }

    /**
     * {@inheritdoc}
     */
    protected function getDefaultConfig()
    {
        return [
            'cacheMaxAge' => 30,
            'itemAmount'  => 4,
            'key'         => null,
            'feeds'       => [],
        ];
    }

    /**
     * Parse a single feed.
     *
     * @param string       $author
     * @param ParameterBag $feedParams
     * @param integer      $amount
     * @param bool         $verbose
     */
    private function parseFeed($author, ParameterBag $feedParams, $amount = 10, $verbose = false)
    {
        $config = $this->getConfig();
        $app = $this->getContainer();
        $reader = new Reader();

        echo "<b>" . $feedParams->get('name') . "</b><br>";
        echo "<small>" . $feedParams->get('feed') . "</small><br>";

        try {
            if ($app['cache']->contains($author)) {
                $result = $app['cache']->fetch($author);
                echo "[Cached]";
            } else {
                $resource = $reader->download($feedParams->get('feed'));
                $content = $resource->getContent();
                $content = str_replace('content:encoded', 'contentEncoded', $content);
                $result = [ 'url' => $resource->getUrl(), 'content' => $content, 'encoding' => $resource->getEncoding() ];
                $app['cache']->save($author, $result, 3600);
                echo "[Fetched]";
            }

            $parser = $reader->getParser($result['url'], $result['content'], $result['encoding']);
            $parsedfeed = $parser->execute();

            /** @var Item[] $items */
            $items = array_slice($parsedfeed->items, 0, $amount);
        } catch (PicoFeedException $e) {
            echo '<p><b>ERROR IN: ' . $feedParams->get('feed') . '</b></p>';
            $items = [];
            $app['cache']->delete($author);
        }

        /** @var Item $article */
        foreach ($items as $article) {
            $needsReview = false;

            if ($verbose) {
                dump($article);
            }

            // try to get an existing record for this item
            $record = $app['storage']->getContent(
                'feeditems', [
                    'itemid'       => $article->id,
                    'returnsingle' => true,
                ]);

            if (!$record) {
                // New one.
                $record = $app['storage']->getContentObject('feeditems');
                $new = true;
                echo '<br> [NEW] ';
            } else {
                $new = false;
                echo '<br> [UPD] ';
            }

            if ($article->publishedDate) {
                $date = $article->publishedDate;
            } else if ($article->date) {
                $date = $article->date;
            } else {
                $date = null;
                $needsReview = true;
                echo 'Date unknown';
            }

            // Le sigh. SimpleXML won't let us get <content:encoded> otherwise.
            $raw_encoded = $article->getTag('contentEncoded');

            if (is_array($raw_encoded) && !empty($raw_encoded[0])) {
                $raw = $raw_encoded[0];
            } else {
                $raw = $article->getContent();
            }

            // Sanitize/clean the HTML.
            $maid = new Maid(
                [
                    'output-format'   => 'html',
                    'allowed-tags'    => [ 'p', 'br', 'hr', 's', 'u', 'strong', 'em', 'i', 'b', 'li', 'ul', 'ol', 'menu', 'blockquote', 'pre', 'code', 'tt', 'h2', 'h3', 'h4', 'h5', 'h6', 'dd', 'dl', 'dh', 'table', 'tbody', 'thead', 'tfoot', 'th', 'td', 'tr', 'a', 'img'],
                    'allowed-attribs' => ['id', 'class', 'name', 'value', 'href', 'src'],
                ]
            );
            $content = $maid->clean($raw);
            $content = $this->fixWonkyEncoding($content);

            $image = $article->enclosureUrl ?: $this->findImage($article, $content, $feedParams->get('url'));
            $video = (string) $record['video'] ?: $this->getYouTubeUrl($image);

            $values = [
                'itemid'     => $article->id,
                'title'      => (string) $article->title,
                'slug'       => $app['slugify']->slugify($article->title),
                'raw'        => (string) $raw,
                'content'    => (string) $content,
                'source'     => (string) $article->url,
                'author'     => $author,
                'image'      => $image,
                'video'      => $video,
                'status'     => 'published',
                'sitetitle'  => $feedParams->get('title'),
                'sitesource' => $feedParams->get('url'),
            ];

            if ($new) {
                $date = ($date instanceof \DateTime) ? $date->format('Y-m-d H:i:s') : '';
                $now = new \DateTime(null, new \DateTimeZone("UTC"));
                $now = $now->format('Y-m-d H:i:s');

                // Some wonky feeds have no date, or the date set past 'now'. We only allow setting dates in the past.
                if ( (empty($date) || $date >= $now) ) {
                    $values['datepublish'] = $values['datecreated'] = date('Y-m-d 00:00:00');
                } else {
                    $values['datepublish'] = $values['datecreated'] = $date;
                }
            }

            $record->setTaxonomy('authors', $author);

            // Import '<category>' tags into a configured taxonomy.
            if ($feedParams->get('add_taxonomy') !== null && is_array($feedParams->get('add_taxonomy'))) {
                foreach ($feedParams->get('add_taxonomy') as $taxName => $taxValues) {
                    $record->setTaxonomy($taxName, $taxValues);
                }
            }

            // Add some additional taxonomies, if set.
            if ($this->config->get('import_taxonomy') !== null) {
                foreach ($article->getTag('category') as $taxonomy) {
                    $taxonomy = $app['slugify']->slugify($taxonomy);
                    if (!in_array($taxonomy, $this->config->get('ignore_taxonomy_terms'))) {
                        $record->setTaxonomy($this->config->get('import_taxonomy'), $taxonomy);
                    }
                }
            }

            if ($verbose) {
                dump($values);
            }

            $record->setValues($values);

            $id = $app['storage']->saveContent($record);

            if ($needsReview) {
                echo "\n\n<hr>\n\n";
                dump($article);
                dump($feedParams);
                dump($values['content']);
                echo "\n\n<hr>\n\n";
            }

            echo $values['sitetitle'] . ' - ' . $values['title'] . ' - ' . $id;
        }

        echo "<hr>";
        flush();
    }

    /**
     * First see if we van get it from some non-standard tag,
     *   <media:thumbnail url=""> — Youtube feed
     *   <media:content url=""> — paper.li feed
     *   <featuredImage> or <youtubeImage> — Cupfighter
     *   <enclosureUrl> — Bolt RSS feed
     *
     * @param Item   $article
     * @param string $html
     * @param string $baseUrl
     *
     * @return string
     */
    private function findImage(Item $article, $html, $baseUrl)
    {
        if ($article->hasNamespace('media')) {
            $value = $article->getTag('media:thumbnail', 'url');
            if (!empty($value)) {
                return $this->fixImageLink(current($value), $baseUrl);
            }

            $value = $article->getTag('media:content', 'url');
            if (!empty($value)) {
                return $this->fixImageLink(current($value), $baseUrl);
            }
        }

        if ($article->xml->featuredImage) {
            $value = $article->xml->featuredImage;
            if (!empty($value)) {
                return $this->fixImageLink(current($value), $baseUrl);
            }
        }

        if ($article->xml->youtubeImage) {
            $value = $article->xml->youtubeImage;
            if (!empty($value)) {
                return $this->fixImageLink(current($value), $baseUrl);
            }
        }

        if ($article->hasNamespace('image')) {
            $value = $article->getTag('image');
            if (!empty($value)) {
                return $this->fixImageLink(current($value), $baseUrl);
            }
        }

        if ($article->hasNamespace('enclosure')) {
            $value = $article->getTag('enclosure', 'url');
            if (!empty($value)) {
                return $this->fixImageLink(current($value), $baseUrl);
            }
        }

        // Find one in the parsed RSS item, perhaps?

        $doc = new \DOMDocument();
        @$doc->loadHTML($html);

        /** @var \DOMNodeList $tags */
        $tags = $doc->getElementsByTagName('img');

        /** @var \DOMElement $tag */
        foreach ($tags as $tag) {
            // Skip feedburner images.
            if (strpos($tag->getAttribute('src'), 'feedburner.com') > 0) {
                continue;
            }
            if (strpos($tag->getAttribute('src'), 'flattr.com') > 0) {
                continue;
            }
            // Medium tracking pixels.
            if (strpos($tag->getAttribute('src'), 'stat?event') > 0) {
                continue;
            }
            // wordpress.org emoji images.
            if (strpos($tag->getAttribute('src'), 's.w.org') > 0) {
                continue;
            }

            $image = $tag->getAttribute('src');

            return $this->fixImageLink($image, $baseUrl);
            // echo $tag->getAttribute('src') . "<br>\n";
            // printf("<img src='%s' width='100'>", $tag->getAttribute('src'));
        }

        return '';
    }

    /**
     * Hack a valid link
     *
     * @param string $image
     * @param string $baseUrl
     *
     * @return string
     */
    private function fixImageLink($image, $baseUrl)
    {
        if (strpos($image, 'http') === false) {
            $baseUrl = parse_url($baseUrl);
            $image = $baseUrl['scheme'] . '://' . $baseUrl['host'] . $image;
        }

        return $image;
    }

    /**
     * If we have a YouTube image URL, attempt to resolve the video's URL.
     *
     * @param string $imageUrl
     *
     * @return null|string
     */
    private function getYouTubeUrl($imageUrl)
    {
        if (strpos($imageUrl, 'ytimg.com') === false && strpos($imageUrl, 'youtube.com') === false) {
            return null;
        }
        $imageUrlParts = parse_url($imageUrl);
        $imageUrlParts = explode('/', ltrim($imageUrlParts['path'], '/'));
        $youTubeId = $imageUrlParts[1];

        return sprintf('https://www.youtube.com/watch?v=%s', $youTubeId);
    }

    private function fixWonkyEncoding($str)
    {
        $find = [
            'â€œ', // left side double smart quote
            'â€', // right side double smart quote
            'â€˜', // left side single smart quote
            'â€™', // right side single smart quote
            'â€¦', // elipsis
            'â€”', // em dash
            'â€“', // en dash
            'Â', // non breaking space
        ];

        $replace= [
            '"',
            '"',
            "'",
            "'",
            "...",
            "-",
            "-",
            " ",
        ];

        return str_replace($find, $replace, $str);
    }

}
