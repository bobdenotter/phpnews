<?php

namespace Bolt\Extension\Bobdenotter\RSSAggregator;

use Bolt\Extension\SimpleExtension;
use PicoFeed\Reader\Reader;
use PicoFeed\PicoFeedException;
use Silex\ControllerCollection;
use Bolt\Menu\MenuEntry;

/**
 * RSS Aggregator Extension:
 * RSS Feed reader and aggregator extension for Bolt
 *
 */
class RSSAggregatorExtension extends SimpleExtension
{

    protected function registerBackendRoutes(ControllerCollection $collection)
    {
        // GET requests on the /bolt/koala route
        $collection->get('/extend/rssaggregate', [$this, 'RSSAggregator']);
    }


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

    public function RSSAggregator()
    {
        $config = $this->getConfig();
        $app = $this->getContainer();

        $currentuser = $app['users']->currentuser;

        if (!empty($config['key']) && ($config['key'] !== $_GET['key']) && (empty($currentuser['username']))) {
            return "Key not correct.";
        }

        foreach ($config['feeds'] as $author => $feed) {
            $this->parseFeed($author, $feed);
        }

        return "<br><br><br> Done.";
    }


    private function parseFeed($author, $feed)
    {
        $config = $this->getConfig();
        $app = $this->getContainer();

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

            $items = array_slice($parsedfeed->items, 0, $config['itemAmount']);

        }
        catch (PicoFeedException $e) {
            echo "<p><b>ERROR IN: " . $feed['feed'] . "</b></p>";
            $items = [];
        }

        foreach ($items as $article) {

            // try to get an existing record for this item
            $record = $app['storage']->getContent(
                'feeditems', array(
                    'itemid' => $article->id,
                    'returnsingle' => true
                ) );

            if (!$record) {
                // New one.
                $record = $app['storage']->getContentObject('feeditems');
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
                'slug' => $app['slugify']->slugify($article->title),
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
            $id = $app['storage']->saveContent($record);

            // dump($article);
            // dump($values);
            // echo "<hr><hr>";

            echo $values['sitetitle'] . " - " . $values['title'] . ' - ' . $id;
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
