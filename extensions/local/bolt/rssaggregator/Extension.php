<?php

namespace Bolt\Extension\Bolt\RSSAggregator;

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

        \Dumper::dump($this->config['feeds']);

        foreach ($this->config['feeds'] as $author => $feed) {
            $this->parseFeed($author, $feed); 
        }

        return "<br><br><br> Done.";
    }
    private function parseFeed($author, $feed) 
    {
        $fastFeed = \FastFeed\Factory::create();

        $parser = new \FastFeed\Parser\RSSParser();
        $parser->pushAggregator(new \FastFeed\Aggregator\RSSContentAggregator());
        $fastFeed->pushParser($parser);
        $parser = new \FastFeed\Parser\AtomParser();
        $parser->pushAggregator(new \FastFeed\Aggregator\RSSContentAggregator());
        $fastFeed->pushParser($parser);

        $fastFeed->addFeed('default', $feed['feed']);
        $items = $fastFeed->fetch('default');

        // slice 
        $items = array_slice($items, 0, $this->config['itemAmount']);

        foreach ($items as $item) {

            // try to get an existing record for this item 
            $record = $this->app['storage']->getContent(
                'feeditems', array(
                    'itemid' => $item->getId(), 
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

            $date = $item->getDate();

            // \Dumper::dump($item);
            // die();

            if ($item->getContent() != false) {
                $raw = $item->getContent();
            } else { 
                $raw = $item->getIntro();
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

            if ($item->getImage() != "") {
                $image = $item->getImage();                
            } else {
                $image = $this->findImage($content, $feed['url']);
            }

            $values = array(
                'itemid' => $item->getId(),
                'title' => "" . $item->getName(),
                'raw' => "" . $raw,
                'content' => "" . $content,
                'source' => "" . $item->getSource(),
                'author' => $author,
                'image' => "" . $image,
                'status' => 'published',
                'sitetitle' => $feed['title'],
                'sitesource' => $feed['url']
            );

            if ($new || $date instanceof \DateTime) {
                $values['datecreated'] = ($date instanceof \DateTime) ? $date->format('Y-m-d H:i:s') : "";
                $values['datepublish'] = ($date instanceof \DateTime) ? $date->format('Y-m-d H:i:s') : "";
            }

            $record->setTaxonomy('tags', $item->getTags());
            $record->setTaxonomy('authors', $author);
            $record->setValues($values);
            $id = $this->app['storage']->saveContent($record);

            // \Dumper::dump($item);
            // \Dumper::dump($values);
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
