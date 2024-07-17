<?php
use Contao\CoreBundle\DataContainer\PaletteManipulator;
use Contao\Database;

$GLOBALS['TL_DCA']['tl_page']['fields']['jbSitemaps'] = [
    'label' => &$GLOBALS['TL_LANG']['tl_page']['jbSitemaps'],
    'inputType' => 'select',
    'foreignKey' => 'tl_jb_sitemap.name',
    'options_callback' => ['tl_page_this', 'getSitemaps'],
    'eval' => ['tl_class' => 'clr', 'multiple'=>true, 'chosen'=>true],
    'sql' => ['type' => 'string', 'length' => 250, 'default' => ''],
];

$GLOBALS['TL_DCA']['tl_page']['config']['oninvalidate_cache_tags_callback'][] = ['jb_multiple_sitemaps.listener.sitemaps_changed', 'addSitemapCacheInvalidationTag'];

$GLOBALS['TL_DCA']['tl_calendar']['config']['oninvalidate_cache_tags_callback'][] = ['jb_multiple_sitemaps.listener.sitemaps_changed', 'addSitemapCacheInvalidationTag'];

$GLOBALS['TL_DCA']['tl_calendar_events']['config']['oninvalidate_cache_tags_callback'][] = ['jb_multiple_sitemaps.listener.sitemaps_changed', 'addSitemapCacheInvalidationTag'];

$GLOBALS['TL_DCA']['tl_news']['config']['oninvalidate_cache_tags_callback'][] = ['jb_multiple_sitemaps.listener.sitemaps_changed', 'addSitemapCacheInvalidationTag'];

$GLOBALS['TL_DCA']['tl_news_archive']['config']['oninvalidate_cache_tags_callback'][] = ['jb_multiple_sitemaps.listener.sitemaps_changed', 'addSitemapCacheInvalidationTag'];

PaletteManipulator::create()
    ->addField('jbSitemaps', 'expert_legend', PaletteManipulator::POSITION_APPEND)
    ->applyToPalette('regular', 'tl_page')
    ->applyToPalette('default', 'tl_page')

;

class tl_page_this {
     /**
	 * Get possible sitemaps and return them as array
	 *
	 * @return array
	 */
	public function getSitemaps()
	{
		$return = [];
		$sitemaps = Database::getInstance()->prepare("SELECT id, name FROM tl_jb_sitemap WHERE name != '' AND type = ?")->execute(1);

		while ($sitemaps->next())
		{
			$return[$sitemaps->id] = $sitemaps->name;
		}

		return $return;
	}
}