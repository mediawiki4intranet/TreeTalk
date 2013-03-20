<?php

/**
 * Export comments from Wikilog extension in TreeTalk format
 * (c) 2013, Vitaliy Filippov
 * License: GPL 3.0 or later (see http://www.fsf.org/licenses/gpl.html)
 */

$IP = '../../';
require_once(dirname(__FILE__) . '/../../maintenance/Maintenance.php');

class TreeTalkWikilogExporter extends Maintenance
{
    var $mDescription = 'Wikilog comments exporter for TreeTalk extension.
Prints XML file with all Wikilog discussions in a format suitable for import
into MediaWiki via Special:Import.';

    function execute()
    {
        $dbw = wfGetDB(DB_SLAVE);
        $res = $dbw->select(
            array('wikilog_comments', 'p1' => 'page', 'revision', 'text', 'p2' => 'page'),
            'p2.page_title post_title, wlc_user_text author, wlc_timestamp timestamp, old_text, wlc_id, wlc_parent',
            array('wlc_comment_page=p1.page_id', 'p1.page_latest=rev_id', 'rev_text_id=old_id', 'wlc_post=p2.page_id'),
            NULL, array('ORDER BY' => 'post_title, wlc_thread')
        );
        $rows = array();
        $text = $last_post = $last_timestamp = $export = '';
        foreach ($res as $row)
        {
            $row->post_title = 'Blog_talk:'.$row->post_title;
            if ($row->wlc_parent)
            {
                $row->r_author = $rows[$row->wlc_parent]->author;
                $row->r_timestamp = $rows[$row->wlc_parent]->timestamp;
            }
            $rows[$row->wlc_id] = $row;
            if ($row->post_title != $last_post)
            {
                $export .= self::singlePage($last_post, $last_timestamp, $text);
                $text = '';
                $last_post = $row->post_title;
            }
            $last_timestamp = $row->timestamp;
            $text .= "<comment author=\"{$row->author}\" timestamp=\"".wfTimestamp(TS_DB, $row->timestamp)."\"".
                ($row->wlc_parent ? " r_author=\"{$row->r_author}\" r_timestamp=\"".wfTimestamp(TS_DB, $row->r_timestamp)."\"" : '').
                ">{$row->old_text}</comment>\n";
        }
        $export .= self::singlePage($last_post, $last_timestamp, $text);
        global $wgSitename;
        $base = Title::newMainPage()->getFullUrl();
        print <<<"EOF"
<?xml version="1.0" encoding="utf-8"?>
<mediawiki xmlns="http://www.mediawiki.org/xml/export-0.3/" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance" xsi:schemaLocation="http://www.mediawiki.org/xml/export-0.3/ http://www.mediawiki.org/xml/export-0.3.xsd" version="0.3" xml:lang="ru">
  <siteinfo>
    <sitename>$wgSitename</sitename>
    <base>$base</base>
    <generator>TreeTalk/Wikilog comment exporter</generator>
    <case>first-letter</case>
      <namespaces>
      <namespace key="-2">Медиа</namespace>
      <namespace key="-1">Служебная</namespace>
      <namespace key="0" />
      <namespace key="1">Обсуждение</namespace>
      <namespace key="2">Участник</namespace>
      <namespace key="3">Обсуждение участника</namespace>
      <namespace key="4">CustisWiki</namespace>
      <namespace key="5">Обсуждение CustisWiki</namespace>
      <namespace key="6">Файл</namespace>
      <namespace key="7">Обсуждение файла</namespace>
      <namespace key="8">MediaWiki</namespace>
      <namespace key="9">Обсуждение MediaWiki</namespace>
      <namespace key="10">Шаблон</namespace>
      <namespace key="11">Обсуждение шаблона</namespace>
      <namespace key="12">Справка</namespace>
      <namespace key="13">Обсуждение справки</namespace>
      <namespace key="14">Категория</namespace>
      <namespace key="15">Обсуждение категории</namespace>
      <namespace key="100">Блог</namespace>
      <namespace key="101">Обсуждение блога</namespace>
    </namespaces>
  </siteinfo>
$export
</mediawiki>

EOF;
    }

    static function singlePage($last_post, $last_timestamp, $text)
    {
        if ($last_post)
        {
            $ts = wfTimestamp(TS_ISO_8601, $last_timestamp);
            $text = htmlspecialchars($text);
            return <<<"EOF"
  <page>
    <title>{$last_post}</title>
    <revision>
      <timestamp>{$ts}</timestamp>
      <contributor>
        <username>WikiSysop</username>
        <id>1</id>
      </contributor>
      <text xml:space="preserve">$text</text>
    </revision>
  </page>

EOF;
        }
        return '';
    }
}

$maintClass = "TreeTalkWikilogExporter";
require_once(RUN_MAINTENANCE_IF_MAIN);
