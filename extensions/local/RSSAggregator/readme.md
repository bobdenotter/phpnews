Bolt RSS Aggregator
===================

## Fetch all

```
/bolt/extensions/rssaggregate
```

## Modify parameters:

```
/bolt/extensions/rssaggregate?feed=foo&amount=5&verbose=1&verbose=1
```

 - `feed=foo,bar`: Fetch/update only these feed(s). Takes comma separated values
 - `amount=10`: The number of items to parse per feed.
 - `verbose=1`: `dump()` the items, to inspect what is getting parsed
 - `key=123DECAFBAD`: The key from config, if calling the aggregator using `curl` or `wget`.

## See the list

```
/bolt/extensions/rssaggregate/list
```

Sample contenttype:

```
feeditems:
    name: Feeditems
    singular_name: Feeditem
    description: "Crawled feed items. Do not change manually."
    fields:
        title:
            type: text
            class: large
            group: content
        slug:
            type: slug
            uses: title
        itemid:
            type: text
            variant: inline
        content:
            type: html
        raw:
            type: textarea
        source:
            type: text
            variant: inline
        author:
            type: text
            variant: inline
        image:
            type: text
            variant: inline
        sitetitle:
            type: text
            variant: inline
        sitesource:
            type: text
            variant: inline
    taxonomy: [ tags, authors ]
    record_template: entry.twig
    listing_template: listing.twig
    listing_records: 10
    default_status: published
    sort: -datepublish
    recordsperpage: 10
```

Sample taxonomy:

```
tags:
    slug: tags
    singular_slug: tag
    behaves_like: tags
    postfix: "Add some freeform tags. Start a new tag by typing a comma or space."
    #listing_template: tag-listing.twig #custom template

authors:
    slug: authors
    singular_slug: author
    behaves_like: tags
    postfix: "The author (key) of this posting."
    #listing_template: tag-listing.twig #custom template

```
