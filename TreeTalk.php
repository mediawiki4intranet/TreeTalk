<?php

/**
 * TreeTalk - VERY FAST AND SIMPLE hierarchical discussion extension
 * TODO: Manage subscriptions
 * (c) Vitaliy Filippov, 2013
 */

$wgHooks['ParserFirstCallInit'][] = 'TreeTalkHooks::ParserFirstCallInit';
$wgHooks['ParserAfterParse'][] = 'TreeTalkHooks::ParserAfterParse';
$wgHooks['ArticleEditUpdates'][] = 'TreeTalkHooks::ArticleEditUpdates';
$wgHooks['LoadExtensionSchemaUpdates'][] = 'TreeTalkSync::LoadExtensionSchemaUpdates';
$wgSpecialPages['TreeTalk'] = 'TreeTalkSpecial';
$wgSpecialPageGroups['TreeTalk'] = 'changes';
$wgExtensionMessagesFiles['TreeTalk'] = dirname(__FILE__).'/TreeTalk.i18n.php';

/**
 * Minimal class used for proper autoloading of the extension
 */
class TreeTalkHooks
{
    static $comments, $disable;

    /**
     * Set parser hook
     */
    static function ParserFirstCallInit(&$parser)
    {
        $parser->setHook('comment', 'TreeTalk::commentTag');
        return true;
    }

    /**
     * Render discussions for the page
     */
    static function ParserAfterParse(&$parser, &$text, &$stripState)
    {
        if (!empty($parser->_comments))
        {
            TreeTalk::renderComments($parser, $text);
        }
        return true;
    }

    /**
     * Clear variable for ArticleEditUpdates
     */
    static function ArticlePrepareTextForEdit($article, $popts)
    {
        self::$comments = false;
        return true;
    }

    /**
     * Record new comments from parsed article and send notification emails
     */
    static function ArticleEditUpdates(&$article, &$editInfo, $changed)
    {
        if (self::$comments && !self::$disable)
        {
            TreeTalkSync::syncComments($article, self::$comments);
            self::$comments = false;
        }
        return true;
    }
}

/**
 * TreeTalk renderer
 */
class TreeTalk
{
    /**
     * Render discussion threads for current parser output
     */
    static function renderComments(&$parser, &$text)
    {
        global $wgUser, $wgOut;
        $pageview = false;
        if ($wgUser->getId())
        {
            $parser->disableCache();
            $dbw = wfGetDB(DB_MASTER);
            $where = array(
                'tv_user' => $wgUser->getId(),
                'tv_page' => $parser->mTitle->getArticleId(),
            );
            $pageview = $dbw->selectField('treetalk_pageview', 'tv_timestamp', $where, __METHOD__);
            $set = array('tv_timestamp' => wfTimestampNow());
            if ($pageview !== false)
            {
                $dbw->update('treetalk_pageview', $set, $where, __METHOD__);
            }
            else
            {
                $dbw->insert('treetalk_pageview', array($where+$set), __METHOD__);
            }
        }
        $comments = $parser->_comments;
        $tree = $parser->_tree;
        unset($parser->_comments);
        unset($parser->_tree);
        $pageId = $parser->mTitle->userCan('edit') ? $parser->mTitle->getArticleId() : false;
        $thread = self::thread($comments, $pageview, $wgUser->getId() ? $wgUser->getName() : false, $pageId);
        $text .= $parser->parse($thread, $parser->mTitle, $parser->mOptions, true, false)->getText();
        if ($pageId)
        {
            $spec = Title::newFromText('Special:TreeTalk');
            $editText = wfMsg('treetalk-edit');
            $replyText = wfMsg('treetalk-reply');
            $permalinkText = wfMsg('treetalk-permalink');
            $tools = '<ul class="wl-comment-tools">'.
                '<li class="wl-comment-action-reply"><a href="'.$spec->getFullUrl(array(
                    'action' => 'edit',
                    'page' => $pageId,
                    'replyto' => 'C_A/C_T',
                )).'">'.$replyText.'</a></li>'.
                '<li class="wl-comment-action-link"><a href="#cmt-C_A-C_T">'.$permalinkText.'</a></li>'.
                '<li class="wl-comment-action-edit"><a href="'.$spec->getFullUrl(array(
                    'action' => 'edit',
                    'page' => $pageId,
                    'edit' => 'C_A/C_T',
                )).'">'.$editText.'</a></li>'.
                '</ul>';
            $text = preg_replace_callback('#<ul class="wl-comment-tools">([^<]+)/([^</]+)</ul>#s', function($m) use($tools) {
                return strtr($tools, array('C_A' => $m[1], 'C_T' => $m[2]));
            }, $text);
            $text .= self::replyForm($pageId);
/* TODO: Enable WikiEditor for comments (at least editing, also maybe for new ones). Something like this:
            $wgOut->addModules(array('jquery.wikiEditor', 'jquery.wikiEditor.toolbar', 'jquery.wikiEditor.toolbar.config'));
            $ins = <<<EOF
<script language="JavaScript">
$( document ).ready( function() {
    $( '#tt-comment' ).wikiEditor();
    $( '#tt-comment' ).wikiEditor(
        'addModule', $.wikiEditor.modules.toolbar.config.getDefaultConfig()
    );
} );
</script>
EOF
;
        $rnd = "{$parser->mUniqPrefix}-item-{$parser->mMarkerIndex}-" . Parser::MARKER_SUFFIX;
        $parser->mMarkerIndex++;
        $parser->mStripState->addNoWiki( $rnd, $ins );
        $text .= $rnd;
*/
        }
        TreeTalkHooks::$comments = $tree;
    }

    /**
     * Render reply form
     */
    static function replyForm($pageId, $text = '', $params = array())
    {
        global $wgUser;
        $spec = Title::newFromText('Special:TreeTalk');
        return '<fieldset id="tt-comment-form"><legend>'.wfMsg('treetalk-form-title').'</legend>'.
            '<form method="POST" action="'.$spec->getFullUrl($params + array('action' => 'edit', 'page' => $pageId)).'">'.
            '<input type="hidden" name="token" value="'.$wgUser->getEditToken().'">'.
            '<p><label for="tt-comment">'.wfMsg('treetalk-form-textfield').'</label></p>'.
            '<textarea rows="5" cols="40" id="tt-comment" name="text">'.htmlspecialchars($text).'</textarea>'.
            '<p><input type="submit" name="save" value="'.wfMsg('treetalk-form-submit').'">'.
            '<input type="submit" name="preview" value="'.wfMsg('treetalk-form-preview').'">'.
            ' &nbsp; <input type="checkbox" id="tt-subscribe" checked="checked" value="1" name="subscribe">'.
            '&nbsp;<label for="tt-subscribe">'.wfMsg('treetalk-form-subscribe').'</label></p></form></fieldset>';
    }

    /**
     * Correct <div>...</div> tag structure if !$removeComments
     * Remove <comment> and </comment> tags if $removeComments
     * (all with respect to parser tag hooks)
     */
    static function filterTags($text, $removeComments)
    {
        global $wgParser;
        $tags = $wgParser->mTagHooks;
        $tags[$removeComments ? 'comment' : 'div'] = true;
        $re = implode('|', array_keys($tags));
        $re = "#<(/?)($re)(?:\s*[^<>]+)?>#is";
        $r = '';
        $inDiv = 0;
        $inHook = false;
        while (preg_match($re, $text, $m, PREG_OFFSET_CAPTURE))
        {
            $ok = true;
            if ($inHook)
            {
                if ($m[1][0] && mb_strtolower($m[2][0]) === $inHook)
                {
                    $inHook = false;
                }
            }
            elseif ($removeComments && strtolower($m[2][0]) === 'comment')
            {
                $ok = false;
            }
            elseif (!$removeComments && $m[2][0] === 'div')
            {
                $inDiv += $m[1][0] ? -1 : 1;
                if ($inDiv < 0)
                {
                    $inDiv = 0;
                    $ok = false;
                }
            }
            else
            {
                $inHook = mb_strtolower($m[2][0]);
            }
            $cut = $m[0][1]+strlen($m[0][0]);
            if ($ok)
            {
                $r .= substr($text, 0, $cut);
            }
            else
            {
                $r .= substr($text, 0, $m[0][1]);
                $r .= htmlspecialchars($m[0][0]);
            }
            $text = substr($text, $cut);
        }
        $r .= $text;
        if (!$removeComments)
        {
            while ($inDiv > 0)
            {
                $r .= '</div>';
            }
        }
        return $r;
    }

    /**
     * Format comment thread
     */
    static function thread($list, $pageview, $curuser, $pageId)
    {
        global $wgLang;
        $thread = '<div class="wl-thread">';
        $minFold = 4;
        $stack = array();
        while ($list)
        {
            $c = array_shift($list);
            $mwa = str_replace(' ', '_', $a = $c['author']);
            $mwts = preg_replace('/\D+/', '', $ts = $c['timestamp']);
            $thread .= '<div class="wl-comment wl-comment-by-user'.
                ($curuser && $curuser !== $a && (!$pageview || strcmp($pageview, $ts) < 0) ? ' wl-comment-highlight' : '').
                '" id="'.Sanitizer::escapeId('cmt-'.$mwa.'-'.$mwts).'">';
            $thread .= '<div class="wl-comment-text">'.self::filterTags($c['text'], false).'</div>';
            $thread .= '<div class="wl-comment-footer">— [[User:'.$a.']] • [[#cmt-'.$mwa.'-'.$mwts.'|'.$wgLang->timeanddate($mwts, true).']]</div>';
            if ($pageId)
            {
                $thread .= "<ul class=\"wl-comment-tools\">$mwa/$mwts</ul>";
            }
            $thread .= '</div>';
            if (!empty($c['replies']))
            {
                $fold = 0;
                if (count($stack) >= $minFold)
                {
                    $last = 0;
                    foreach ($c['replies'] as $sub)
                    {
                        $last = !empty($sub['replies']);
                        $fold += $last;
                    }
                    $fold -= $last;
                }
                if ($fold)
                {
                    $list = array_merge($c['replies'], $list);
                }
                else
                {
                    $stack[] = $list;
                    $list = $c['replies'];
                    $thread .= '<div class="wl-thread">';
                }
            }
            else
            {
                while (!$list && $stack)
                {
                    $thread .= '</div>';
                    $list = array_pop($stack);
                }
            }
        }
        return $thread.'</div>';
    }

    /**
     * <comment> parser tag hook
     */
    static function commentTag($text, $args, $parser)
    {
        $a = @$args['author'];
        $t = @$args['timestamp'];
        $pa = @$args['r_author'];
        $pt = @$args['r_timestamp'];
        if ($a && $t)
        {
            $comment = array(
                'text' => $text,
                'author' => $a,
                'timestamp' => $t,
            );
            if ($pa && $pt)
            {
                $comment['replyto_author'] = $pa;
                $comment['replyto_timestamp'] = $pt;
                if (!isset($parser->_tree["$pa/$pt"]))
                {
                    $parser->_tree["$pa/$pt"] = array(
                        'text' => '',
                        'author' => $pa,
                        'timestamp' => $pt,
                    );
                    $parser->_comments[] = &$parser->_tree["$pa/$pt"];
                }
                $parser->_tree["$a/$t"] = &$comment;
                $parser->_tree["$pa/$pt"]['replies'][] = &$comment;
            }
            elseif (isset($parser->_tree["$a/$t"]))
            {
                $parser->_tree["$a/$t"] += $comment;
            }
            else
            {
                $parser->_comments[] = &$comment;
                $parser->_tree["$a/$t"] = &$comment;
            }
        }
        // This removes extra whitespace
        return '<!-- -->';
    }
}

/**
 * TreeTalk updater
 */
class TreeTalkSync
{
    /**
     * Initialise database tables
     */
    static function LoadExtensionSchemaUpdates($updater)
    {
        $dir = dirname(__FILE__);
        if (!$updater)
        {
            global $wgDBtype, $wgExtNewTables;
            if ($wgDBtype == 'mysql')
            {
                $wgExtNewTables[] = array('treetalk_comments', "$dir/treetalk-tables.sql");
                return true;
            }
        }
        elseif ($updater->getDB()->getType() == 'mysql')
        {
            $updater->addExtensionUpdate(array('addTable', 'treetalk_comments', "$dir/treetalk-tables.sql", true));
            return true;
        }
        die("TreeTalk extension only support MySQL at the moment\n");
    }

    /**
     * Synchronize DB records with comments parsed from wikitext
     */
    static function syncComments($article, &$tree)
    {
        $dbw = wfGetDB(DB_MASTER);
        $articleId = $article->getId();
        $res = $dbw->select('treetalk_comments', '*', array('tt_page' => $articleId), __METHOD__);
        $del = array();
        $add = $tree;
        foreach ($res as $row)
        {
            if (isset($add[$row->tt_user_text.'/'.$row->tt_timestamp]) &&
                $row->tt_text === $add[$row->tt_user_text.'/'.$row->tt_timestamp]['text'])
            {
                unset($add[$row->tt_user_text.'/'.$row->tt_timestamp]);
            }
            else
            {
                // Either delete or replace the comment
                $del[] = $row->tt_id;
            }
        }
        if ($del)
        {
            $dbw->delete('treetalk_comments', array('tt_id' => $del), __METHOD__);
        }
        if ($add)
        {
            $new = array();
            foreach ($add as &$c)
            {
                $new[] = array(
                    'tt_page' => $articleId,
                    'tt_user_text' => $c['author'],
                    'tt_timestamp' => wfTimestamp(TS_MW, $c['timestamp']),
                    'tt_text' => $c['text'],
                );
            }
            $dbw->insert('treetalk_comments', $new, __METHOD__);
        }
    }

    /**
     * Subscription check order:
     * Global -> Namespace -> Talk namespace -> Page -> Subpage -> Talk:Page -> Talk:Subpage
     */
    static function getWatchers($title, $userId = false)
    {
        $dbw = wfGetDB(DB_MASTER);
        $dbkey = explode('/', $title->getDBkey());
        $ns = $title->getNamespace();
        $talkns = MWNamespace::getTalk($ns);
        $where = array();
        $l = count($dbkey);
        $i = 1;
        $cur = $dbkey[0];
        while ($i < $l)
        {
            $where[$ns][$cur] = $where[$talkns][$cur] = true;
            $cur .= '/'.$dbkey[$i++];
        }
        foreach ($where as $ns => &$k)
        {
            $k = "(page_namespace=$ns AND page_title IN (".$dbw->makeList(array_keys($k))."))";
        }
        $res = $dbw->select(
            array('treetalk_subscribers', 'page'),
            'ts_type, ts_user, ts_yes, ts_page, page_namespace, page_title',
            array(
                "(ts_type=0 OR (ts_type=1 AND ts_page IN ($ns, $talkns)) OR (ts_type=2 AND page_id IS NOT NULL))" .
                ($userId ? " AND ts_user=".intval($userId) : '')
            ),
            __METHOD__,
            array(
                'ORDER BY' => "ts_type ASC, (IFNULL(page_namespace, ts_page) = $talkns) ASC, LENGTH(page_title) ASC"
            ),
            array(
                'page' => array('LEFT JOIN', $where ? 'ts_type=2 AND ts_page=page_id AND ('.implode(' OR ', $where).')' : '0=1')
            )
        );
        $watchers = array();
        foreach ($res as $row)
        {
            if ($row['ts_yes'])
            {
                $watchers[$row['ts_user']] = $row;
            }
            else
            {
                unset($watchers[$row['ts_user']]);
            }
        }
        return $watchers;
    }

    /**
     * Get related users from the DB for id->name translation
     */
    static function selectUsers($watchers, $newComments)
    {
        $dbw = wfGetDB(DB_MASTER);
        $userids = array_keys($watchers);
        $usernames = array();
        foreach ($newComments as $c)
        {
            if (!empty($c['replyto']))
            {
                $usernames[$c['replyto']['author']] = true;
            }
            $usernames[$c['author']] = true;
        }
        $where = array();
        if ($userids)
        {
            $where[] = 'user_id IN ('.$dbw->makeList($userids).')';
        }
        if ($usernames)
        {
            $where[] = 'user_name IN ('.$dbw->makeList(array_keys($usernames)).')';
        }
        if (!$where)
        {
            return;
        }
        $res = $dbw->select('user', '*', implode(' OR ', $where), __METHOD__);
        $users = array();
        foreach ($res as $row)
        {
            $users[$row->user_id] = $users[$row->user_name] = $row;
        }
        return $users;
    }

    /**
     * Notify subscribers about new comments by email
     * Subscriber = comment author, page subscribers, parent page subscribers
     */
    static function sendCommentEmails($article, $newComments)
    {
        $title = $article->getTitle();
        $watchers = self::getWatchers($title);
        $users = self::selectUsers($watchers, $newComments);
        if (!$users)
        {
            // Nobody to notify :-(
            return;
        }
        foreach ($newComments as $c)
        {
            self::notifyOne($title, $watchers, $users, $c);
        }
    }

    static function notifyOne(&$title, &$watchers, &$users, $c)
    {
        global $wgParser, $wgPasswordSender, $wgServer, $wgScript;
        $theseWatchers = $watchers;
        // Email parent comment author, but...
        if (!empty($c['replyto']) && !empty($users[$c['replyto']['author']]))
        {
            $theseWatchers[$users[$c['replyto']['author']]->user_id] = array('ts_type' => 3);
        }
        // ...do not send user his own comments
        $u = @$users[$c['author']];
        if ($u)
        {
            unset($theseWatchers[$u->user_id]);
        }
        if (!$theseWatchers)
        {
            // Nobody to notify again :-(
            return;
        }
        $subjTitle = $title->getSubjectPage();
        $talkTitle = $title->getTalkPage();
        $args = array(
            0 => $c['author'],
            1 => $title->getSubpageText(),
            2 => $talkTitle->getNsText(),
            3 => $subjTitle->getPrefixedText(),
            4 => $talkTitle->getPrefixedText(),
            5 => $c['text'],
            6 => 'cmt-'.$c['author'].'-'.preg_replace('/\D+/', '', $c['timestamp']),
            7 => '',
            8 => '',
        );
        if (!empty($c['replyto']))
        {
            $args[7] = $c['replyto']['author'];
            $args[8] = $c['replyto']['text'];
        }
        // Build message subject and body
        $oldExpand = self::expandLocalUrls();
        $popt = new ParserOptions($u ? User::newFromRow($u) : NULL);
        $subject = $wgParser->parse(wfMsgNoTrans('treetalk-email-subject', $args), $title, $popt, false, false);
        $subject = trim(strip_tags($subject->getText()));
        $body = $wgParser->parse(wfMsgNoTrans('treetalk-email-body', $args), $title, $popt, true, false);
        $body = $body->getText();
        self::expandLocalUrls($oldExpand);
        // Unsubscribe link is appended to e-mails of all users except the parent comment author
        $unsubscribe = wfMsgNoTrans(
            'treetalk-email-unsubscribe',
            array(
                $title->getSubpageText(),
                Title::newFromText('Special:TreeTalk', array(
                    'action' => 'subscribe',
                    'yes' => 0,
                    'type' => 2,
                    'page' => $title->getArticleId(),
                ))->getFullUrl()
            )
        );
        // Build e-mail lists (with unsubscribe link, without unsubscribe link)
        $to_with = array();
        $to_without = array();
        foreach ($theseWatchers as $userid => $true)
        {
            if (!empty($users[$userid]) &&
                $users[$userid]->user_email_authenticated &&
                $users[$userid]->user_email)
            {
                $email = new MailAddress($users[$userid]->user_email);
                if (!empty($c['replyto']['author']) &&
                    $users[$userid]->user_name === $c['replyto']['author'])
                {
                    $to_without[] = $email;
                }
                else
                {
                    $to_with[] = $email;
                }
            }
        }
        // Send e-mails using $wgPasswordSender as from address
        $from = new MailAddress($wgPasswordSender, wfMsg('treetalk-email-from'));
        if ($to_with)
        {
            UserMailer::send($to_with, $from, $subject, $body . $unsubscribe);
        }
        if ($to_without)
        {
            UserMailer::send($to_without, $from, $subject, $body);
        }
    }

    /**
     * Enable expansion of local URLs.
     *
     * In order to output stand-alone content with all absolute links, it is
     * necessary to expand local URLs. MediaWiki tries to do this in a few
     * places by sniffing into the 'action' GET request parameter, but this
     * fails in many ways. This function tries to remedy this.
     *
     * This function pre-expands all base URL fragments used by MediaWiki,
     * and also enables URL expansion in the Wikilog::GetLocalURL hook.
     * The original values of all URLs are saved when $enable = true, and
     * restored back when $enabled = false.
     *
     * The proper way to use this function is:
     * @code
     *   $saveExpUrls = WikilogParser::expandLocalUrls();
     *   # ...code that uses $wgParser in order to parse articles...
     *   WikilogParser::expandLocalUrls( $saveExpUrls );
     * @endcode
     *
     * @note Using this function changes the behavior of Parser. When enabled,
     *   parsed content should be cached under a different key.
     */
    static function expandLocalUrls($enable = true)
    {
        global $wgScript, $wgScriptPath, $wgArticlePath, $wgUploadPath, $wgStylePath, $wgMathPath, $wgLocalFileRepo;
        static $originalPaths = NULL;
        $prev = $originalPaths && true;
        if ($enable && !$originalPaths)
        {
            // Save original values
            $originalPaths = array($wgScript, $wgArticlePath, $wgScriptPath, $wgUploadPath,
                $wgStylePath, $wgMathPath, $wgLocalFileRepo['url']);
            // Expand paths
            $wgScript = wfExpandUrl($wgScript);
            $wgArticlePath = wfExpandUrl($wgArticlePath);
            $wgScriptPath = wfExpandUrl($wgScriptPath);
            $wgUploadPath = wfExpandUrl($wgUploadPath);
            $wgStylePath = wfExpandUrl($wgStylePath);
            $wgMathPath = wfExpandUrl($wgMathPath);
            $wgLocalFileRepo['url'] = wfExpandUrl($wgLocalFileRepo['url']);
            // Destroy existing RepoGroup, if any
            RepoGroup::destroySingleton();
        }
        elseif (!$enable && $originalPaths)
        {
            // Restore original values.
            list($wgScript, $wgArticlePath, $wgScriptPath, $wgUploadPath, $wgStylePath, $wgMathPath,
                $wgLocalFileRepo['url']) = $originalPaths;
            // Destroy existing RepoGroup, if any
            RepoGroup::destroySingleton();
        }
        return $prev;
    }
}

/**
 * TreeTalk special page - used to post/edit comments and manage subscriptions
 */
class TreeTalkSpecial extends SpecialPage
{
    static $actions = array('manage' => 1, 'subscribe' => 1, 'reply' => 1, 'post' => 1, 'edit' => 1);

    function __construct()
    {
        parent::__construct('TreeTalk');
    }

    /**
     * Actions:
     * action=manage
     * action=subscribe yes=? type=? page=?
     * action=edit page=? replyto=?-? edit=?-? text=? save=? preview=?
     */
    public function execute($par)
    {
        global $wgRequest;
        $action = $wgRequest->getVal('action');
        if (!self::$actions[$action])
        {
            $action = 'manage';
        }
        $action = 'action'.$action;
        $this->$action();
    }

    public function actionManage()
    {
        // TODO
    }

    public function actionSubscribe()
    {
        // TODO
    }

    public function actionEdit()
    {
        global $wgRequest, $wgOut, $wgTitle, $wgUser;
        $page = $wgRequest->getInt('page');
        $reply = $wgRequest->getVal('replyto');
        $editId = $wgRequest->getVal('edit');
        $save = $wgRequest->getVal('save');
        $preview = $wgRequest->getVal('preview');
        $text = $wgRequest->getVal('text');
        $token = $wgRequest->getVal('token');
        $title = $page ? Title::newFromId($page) : false;
        if ($title && !$title->userCan('edit'))
        {
            $title = false;
        }
        $reply = $reply ? explode('/', $reply, 2) : false;
        $edit = $editId ? explode('/', $editId, 2) : false;
        $text = preg_replace('#<(/?comment(?:\s+[^<>]*)?)>#is', '&lt;\1&gt;', $text);
        $dbw = wfGetDB(DB_MASTER);
        if ($edit)
        {
            $edit = $dbw->select('treetalk_comments', '*', array(
                'tt_page' => $page,
                'tt_user_text' => $edit[0],
                'tt_timestamp' => $edit[1],
            ), __METHOD__)->fetchRow();
        }
        if ($reply)
        {
            $reply = $dbw->select('treetalk_comments', '*', array(
                'tt_page' => $page,
                'tt_user_text' => $reply[0],
                'tt_timestamp' => $reply[1],
            ), __METHOD__)->fetchRow();
        }
        if ($title && ($save || $preview) && $wgUser->matchEditToken($token))
        {
            if ($save)
            {
                $title->getArticleId(Title::GAID_FOR_UPDATE);
                $article = new WikiPage($title);
                TreeTalkHooks::$disable = true;
                $articleText = $article->getText();
                if ($edit)
                {
                    $dbw->update(
                        'treetalk_comments', array('tt_text' => $text),
                        array('tt_id' => $edit['tt_id']), __METHOD__
                    );
                    $anchor = $edit['tt_user_text'].'-'.$edit['tt_timestamp'];
                    // Update comment in wikitext
                    if (preg_match('#(<comment[^<>]*\sauthor="'.strtr(preg_quote($edit['tt_user_text']), array(' ' => '[ _]', '_' => '[ _]')).
                        '"[^<>]*\stimestamp="'.preg_quote(wfTimestamp(TS_DB, $edit['tt_timestamp'])).'"[^<>]*>).*?</comment\s*>#is', $articleText, $m, PREG_OFFSET_CAPTURE))
                    {
                        $articleText = substr($articleText, 0, $m[0][1]) . $m[1][0] . $text . '</comment>' . substr($articleText, $m[0][1]+strlen($m[0][0]));
                        $article->doEdit($articleText, wfMsg('treetalk-rc-changed-comment', $edit['tt_user_text'], $edit['tt_timestamp']));
                    }
                }
                else
                {
                    $new = array(
                        'tt_page' => $page,
                        'tt_user_text' => $wgUser->getName(),
                        'tt_timestamp' => wfTimestampNow(TS_MW),
                        'tt_text' => $text,
                    );
                    $dbw->insert('treetalk_comments', $new);
                    $new['tt_timestamp'] = wfTimestamp(TS_DB, $new['tt_timestamp']);
                    $anchor = $new['tt_user_text'].'-'.$new['tt_timestamp'];
                    // Insert new comment into wikitext
                    $articleText = rtrim($articleText)."\n<comment".
                        ($reply ? ' r_author="'.$reply['tt_user_text'].'" r_timestamp="'.wfTimestamp(TS_DB, $reply['tt_timestamp']).'"' : '').
                        ' author="'.$new['tt_user_text'].'" timestamp="'.$new['tt_timestamp'].
                        "\">".$new['tt_text']."</comment>\n";
                    $article->doEdit($articleText, wfMsg('treetalk-rc-new-comment', $reply['tt_user_text']));
                    // Send notifications
                    $new = array(
                        'author' => $new['tt_user_text'],
                        'timestamp' => $new['tt_timestamp'],
                        'text' => $new['tt_text'],
                        'replyto' => $reply ? array(
                            'author' => $reply['tt_user_text'],
                            'timestamp' => $reply['tt_timestamp'],
                            'text' => $reply['tt_text'],
                        ) : false,
                    );
                    TreeTalkSync::sendCommentEmails($article, array($new));
                }
                $anchor = str_replace(' ', '_', $anchor);
                TreeTalkHooks::$disable = false;
                $wgOut->redirect($title->getFullUrl().'#'.Sanitizer::escapeId('cmt-'.$anchor));
                return;
            }
            elseif ($preview)
            {
                // TODO Add preview warning
                $wgOut->addWikiText($text);
            }
        }
        $wgOut->setPageTitle(wfMsg('treetalk-special-reply'));
        if (!$title)
        {
            $wgOut->addHTML(wfMsg('treetalk-special-cannot-edit'));
        }
        // TODO Different form texts for add/edit
        $wgOut->addHTML(TreeTalk::replyForm($page, $edit ? $edit['tt_text'] : '', $edit ? array('edit' => $editId) : array()));
    }
}
