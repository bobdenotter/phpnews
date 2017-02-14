<?php

namespace Local\FeedListing;

use Bolt\Extension\SimpleExtension;
use Silex\Application;
use Silex\ControllerCollection;
use Bolt\Menu\MenuEntry;
use Symfony\Component\HttpFoundation\ParameterBag;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Yaml\Parser;


class FeedListingExtension extends SimpleExtension
{

    /** @var ParameterBag */
    private $config;

    protected function registerTwigFunctions()
    {
        return [
            'feedListing'    => 'feedListing'
        ];
    }

    /**
     * RSS aggregator admin menu route.
     *
     * @param Request $request
     *
     * @return string
     */
    public function feedListing()
    {
        $config = $this->getConfig();
        $app = $this->getContainer();

        // dump($config->get('feeds'));

        $query = "select author, datecreated from (select * from bolt_feeditems order by datecreated DESC) AS x GROUP BY author;";
        $stmt = $app['db']->query($query);

        $authors = [];

        foreach($stmt->fetchAll() as $row) {
            $authors[ $row['author'] ] = $row['datecreated'];
        }
        asort($authors);
        $authors = array_reverse($authors);

        return $authors;

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

        // Until https://github.com/bolt/bolt/issues/6319 is fixed.
        $yaml = new Parser();
        $raw = $yaml->parse(file_get_contents(dirname(dirname(dirname(dirname(__DIR__)))) . '/app/config/extensions/rssaggregator.local.yml'));
        // End.

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


}
