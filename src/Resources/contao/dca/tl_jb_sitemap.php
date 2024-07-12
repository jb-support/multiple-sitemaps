<?php

use Contao\DataContainer;

$GLOBALS['TL_DCA']['tl_jb_sitemap'] = array
(
    // Config
    'config' => array
    (
        'dataContainer'               => \Contao\DC_Table::class,
        'enableVersioning'            => true,
        'onsubmit_callback' => [
            ['jb_multiple_sitemaps.listener.sitemaps_changed', 'onRecordsModified'],
        ],
        'ondelete_callback' => [
            ['jb_multiple_sitemaps.listener.sitemaps_changed', 'onRecordsModified'],
        ],
        'oncopy_callback' => [
            ['jb_multiple_sitemaps.listener.sitemaps_changed', 'onRecordsModified'],
        ],
        'onrestore_callback' => [
            ['jb_multiple_sitemaps.listener.sitemaps_changed', 'onRecordsModified'],
        ],
        'sql' => array
        (
            'keys' => array
            (
                'id' => 'primary',
            )
        )
    ),

    // List
    'list' => array
    (
        'sorting' => array
        (
            'mode'                    => DataContainer::MODE_SORTABLE,
            'fields'                  => array('name'),
            'panelLayout'             => 'filter;sort,search,limit'
        ),
        'label' => array
        (
            'fields'                  => array('name', 'filename', 'type', 'indexMode'),
            'showColumns'             => true,
        ),
        'global_operations' => array
        (
            'all' => array
            (
                'href'                => 'act=select',
                'class'               => 'header_edit_all',
                'attributes'          => 'onclick="Backend.getScrollOffset()" accesskey="e"'
            )
        ),
        'operations' => array
        (
            'edit' => array
            (
                'href'                => 'act=edit',
                'icon'                => 'edit.svg'
            ),
            'copy' => array
            (
                'href'                => 'act=copy',
                'icon'                => 'copy.svg'
            ),
            'delete' => array
            (
                'href'                => 'act=delete',
                'icon'                => 'delete.svg',
                'attributes'          => 'onclick="if(!confirm(\'' . ($GLOBALS['TL_LANG']['MSC']['deleteConfirm'] ?? null) . '\'))return false;Backend.getScrollOffset()"'
            ),
            'toggle' => array
            (
                'href'                => 'act=toggle&amp;field=published',
                'icon'                => 'visible.svg',
                //'button_callback'     => array('tl_article', 'toggleIcon'),
                'showInHeader'        => true
            ),
            'show' => array
            (
                'href'                => 'act=show',
                'icon'                => 'show.svg'
            ),
        ),
    ),

    // Palettes
    'palettes' => array
    (
        '__selector__'                => ['type'],
        'default'                     => '{sitemap_legend},type',
        \JBSupport\MultipleSitemapsBundle\MultipleSitemapsConfig::TYPE_SITEMAP => '{sitemap_legend},published,type,name,filename,indexMode,maxAge,priority,rootPages',
        \JBSupport\MultipleSitemapsBundle\MultipleSitemapsConfig::TYPE_INDEX => '{sitemap_legend},published,type,name,filename,maxAge,domain,sitemaps',
    ),

    // Fields
    'fields' => array
    (
        'id' => array
        (
            'sql'                     => "int(10) unsigned NOT NULL auto_increment"
        ),
        'tstamp' => array
        (
            'sql'                     => "int(10) unsigned NOT NULL default 0"
        ),
        'type' => array
        (
            'exclude'                 => true,
            'inputType'               => 'select',
            'options'                 => \JBSupport\MultipleSitemapsBundle\MultipleSitemapsConfig::$types,
            'reference'               => &$GLOBALS['TL_LANG']['tl_jb_sitemap']['typeOptions'],
            'eval'                    => array('tl_class'=>'w50', 'submitOnChange'=>true),
            'sql'                     => "int(10) NOT NULL default '0'"
        ),
        'name' => array
        (
            'exclude'                 => true,
            'search'                  => true,
            'sorting'                 => true,
            'flag'                    => DataContainer::SORT_INITIAL_LETTER_ASC,
            'inputType'               => 'text',
            'eval'                    => array('mandatory'=>true, 'maxlength'=>150, 'tl_class'=>'w50 clr'),
            'sql'                     => "varchar(150) NOT NULL default ''"
        ),
        'filename' => array
        (
            'exclude'                 => true,
            'search'                  => true,
            'sorting'                 => true,
            'flag'                    => DataContainer::SORT_INITIAL_LETTER_ASC,
            'inputType'               => 'text',
            'eval'                    => array('mandatory'=>true, 'maxlength'=>150, 'tl_class'=>'w50'),
            'sql'                     => "varchar(150) NOT NULL default ''"
        ),
        'maxAge' => array
        (
            'exclude'                 => true,
            'inputType'               => 'text',
            'eval'                    => array('mandatory'=>true, 'tl_class'=>'w50', 'rgxp'=>'natural'),
            'sql'                     => "int(10) NOT NULL default '2592000'"
        ),
        'priority' => array
        (
            'exclude'                 => true,
            'inputType'               => 'text',
            'eval'                    => array('tl_class'=>'w50', 'rgxp'=>'digit'),
            'sql'                     => "decimal(2,1) NOT NULL default '0.0'"
        ),
        'rootPages' => array
        (
            'exclude'                 => true,
            'inputType'               => 'pageTree',
            'eval'                    => array('tl_class'=>'clr', 'fieldType'=>'checkbox', 'multiple'=>true),
            'sql'                     => "blob NULL",
        ),
        'indexMode' => array
        (
            'exclude'                 => true,
            'inputType'               => 'select',
            'options'                 => \JBSupport\MultipleSitemapsBundle\MultipleSitemapsConfig::$indexModes,
            'reference'               => &$GLOBALS['TL_LANG']['tl_jb_sitemap']['indexModeOptions'],
            'eval'                    => array('tl_class'=>'w50'),
            'sql'                     => "int(10) NOT NULL default '0'"
        ),
        'sitemaps' => array
        (
            'exclude'                 => true,
            'inputType'               => 'checkboxWizard',
            'foreignKey'              => 'tl_jb_sitemap.name',
            'eval'                    => array('tl_class'=>'clr', 'multiple'=>true),
            'sql'                     => "blob NULL",
        ),
        'domain' => array
        (
            'exclude'                 => true,
            'inputType'               => 'select',
            'options_callback'        => ["tl_jb_sitemap", "getDomainOptions"],
            'eval'                    => array('tl_class'=>'w50'),
            'sql'                     => "varchar(150) NOT NULL default ''"
        ),
        'published' => array
        (
            'exclude'                 => true,
            'toggle'                  => true,
            'filter'                  => true,
            'inputType'               => 'checkbox',
            'eval'                    => array('doNotCopy'=>true),
            'sql'                     => "char(1) NOT NULL default ''",
            'save_callback' => [
                ['jb_multiple_sitemaps.listener.sitemaps_changed', 'onInactiveSaveCallback'],
            ],
        ),

    )
);

class tl_jb_sitemap
{
    public function getDomainOptions()
    {
        $options = ["" => &$GLOBALS['TL_LANG']['tl_jb_sitemap']['calledDomain']];
        $db = \Contao\Database::getInstance();
        $result = $db->prepare('SELECT `dns`, `useSSL` FROM `tl_page` WHERE `type` = ?')->execute(['root']);

        while ($result->next()) {
            if (!empty($result->dns)) {
                $domain = ($result->useSSL==1 ? "https://" : "http://") . $result->dns;
                $options[$domain] = $domain;
            }
        }

        return $options;
    }
}
