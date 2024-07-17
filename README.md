# Multiple Sitemaps bundle for Contao Open Source CMS

The extension provides a new way for Contao to manage multiple sitemaps and index files:

## Installation

Install the bundle via Composer:

```
composer require jb-support/multiple-sitemaps
```

## Features

- Setup multiple sitemaps with own filenames / url paths
- Choose sitemap indexing for every single page in pagetree
- Define entry priority and cache TTL for each sitemap individually
- Filter indexed pages by page tree (root or sub nodes possible - even multiple)
- Create index files for existing sitemaps
- Add Events, News and FAQs to Sitemap

## Credits

Thanks to terminal42 ([contao-url-rewrite bundle](https://github.com/terminal42/contao-url-rewrite)) for hints regarding cache rebuilding and routecollection changes. Parts of this extension are based on their code.
