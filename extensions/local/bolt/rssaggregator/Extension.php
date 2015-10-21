<?php

namespace Bolt\Extension\Bolt\RSSAggregator;

use PicoFeed\Reader\Reader;
use PicoFeed\PicoFeedException;

/**
 * RSS Aggregator Extension for Bolt
 *
 * @author Sebastian Klier <sebastian@sebastianklier.com>
 * @author Gawain Lynch <gawain.lynch@gmail.com>
 */
class Extension extends \Bolt\BaseExtension
{
    const NAME = 'RSSAggregator';

    public function getName()
    {
        return Extension::NAME;
    }

    /**
     * Initialize RSS Aggregator
     */
    public function initialize()
    {

        $path = $this->app['config']->get('general/branding/path') . '/rssaggregate';
        $this->app->match($path, array($this, "RSSAggregator"));

        /*
         * Frontend
         */
        if ($this->app['config']->getWhichEnd() == 'frontend') {
            // // Add CSS file
            // $this->addCSS("css/rssaggregator.css");

            // // Initialize the Twig function
            // $this->addTwigFunction('rss_aggregator', 'twigRssAggregator');

            $this->app['twig']->addGlobal('rssfeeds', $this->config['feeds']);


        }
    }

    public function RSSAggregator()
    {

        if (!empty($this->config['key']) && ($this->config['key'] !== $_GET['key'])) {
            return "Key not correct.";
        }

        $cachedir = $this->app['resources']->getPath('cache') . '/rssaggregator/';

        // dump($this->config['feeds']);

        foreach ($this->config['feeds'] as $author => $feed) {
            $this->parseFeed($author, $feed);
        }

        return "<br><br><br> Done.";
    }
    private function parseFeed($author, $feed)
    {

        $reader = new Reader;

        try {
            $resource = $reader->download($feed['feed']);

            $parser = $reader->getParser(
                $resource->getUrl(),
                $resource->getContent(),
                $resource->getEncoding()
            );

            $parsedfeed = $parser->execute();

            // dump($parsedfeed);

            $items = array_slice($parsedfeed->items, 0, $this->config['itemAmount']);

        }
        catch (PicoFeedException $e) {
            echo "<p><b>ERROR IN: " . $feed['feed'] . "</b></p>";
            $items = [];
        }

        foreach ($items as $article) {

            // try to get an existing record for this item
            $record = $this->app['storage']->getContent(
                'feeditems', array(
                    'itemid' => $article->id,
                    'returnsingle' => true
                ) );

            if (!$record) {
                // New one.
                $record = $this->app['storage']->getContentObject('feeditems');
                echo "<br> [NEW] ";
                $new = true;
            } else {
                echo "<br> [UPD] ";
                $new = false;
            }

            if ($article->date) {
                $date = $article->date;
            } else {
                echo "datum onbekend";
                dump($article);
            }

            if ($article->content) {
                $raw = $article->content;
            } else {
                echo "item onbekend.";
                dump($article);
                dump($feed);
                // $raw = $item->getIntro();
            }


            // Sanitize/clean the HTML.
            $maid = new \Maid\Maid(
                array(
                    'output-format' => 'html',
                    'allowed-tags' => array( 'p', 'br', 'hr', 's', 'u', 'strong', 'em', 'i', 'b', 'li', 'ul', 'ol', 'menu', 'blockquote', 'pre', 'code', 'tt', 'h2', 'h3', 'h4', 'h5', 'h6', 'dd', 'dl', 'dh', 'table', 'tbody', 'thead', 'tfoot', 'th', 'td', 'tr', 'a', 'img'),
                    'allowed-attribs' => array('id', 'class', 'name', 'value', 'href', 'src')
                )
            );
            $content = $maid->clean($raw);

            if ($item->enclosure_url != "") {
                $image = $item->enclosure_url;
            } else {
                $image = $this->findImage($content, $feed['url']);
            }

            $values = array(
                'itemid' => $article->id,
                'title' => "" . $article->title,
                'slug' => $this->app['slugify']->slugify($article->title),
                'raw' => "" . $raw,
                'content' => "" . $content,
                'source' => "" . $article->url,
                'author' => $author,
                'image' => $image,
                'status' => 'published',
                'sitetitle' => $feed['title'],
                'sitesource' => $feed['url']
            );

            if ($new || $date instanceof \DateTime) {
                $values['datecreated'] = ($date instanceof \DateTime) ? $date->format('Y-m-d H:i:s') : "";
                $values['datepublish'] = ($date instanceof \DateTime) ? $date->format('Y-m-d H:i:s') : "";
            }

            // $record->setTaxonomy('tags', $item->getTags());
            $record->setTaxonomy('authors', $author);
            $record->setValues($values);
            $id = $this->app['storage']->saveContent($record);

            // dump($article);
            // dump($values);
            // echo "<hr><hr>";

            echo $values['sitetitle'] . " - " . $values['title'];
        }

    }


    private function findImage($html, $baseurl)
    {
        $doc = new \DOMDocument();
        @$doc->loadHTML($html);

        $tags = $doc->getElementsByTagName('img');

        foreach ($tags as $tag) {
            // Skip feedburner images..
            if (strpos($tag->getAttribute('src'), "feedburner.com") > 0) {
                continue;
            }
            if (strpos($tag->getAttribute('src'), "flattr.com") > 0) {
                continue;
            }


            $image = $tag->getAttribute('src');

            if (strpos($image, "http") === false) {
                $baseurl = parse_url($baseurl);
                $image = $baseurl['scheme'] . "://" . $baseurl['host'] . $image;
            }

            return $image;
            // echo $tag->getAttribute('src') . "<br>\n";
            // printf("<img src='%s' widht='100'>", $tag->getAttribute('src'));
        }

        return "";

    }

}
